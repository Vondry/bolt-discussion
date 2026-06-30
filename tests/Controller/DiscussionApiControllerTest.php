<?php

declare(strict_types=1);

namespace Bolt\Discussion\Tests\Controller;

use Bolt\Discussion\Controller\Api\DiscussionApiController;
use Bolt\Discussion\Entity\DiscussionComment;
use Bolt\Discussion\Enum\CommentStatus;
use Bolt\Discussion\Exception\ValidationException;
use Bolt\Discussion\Service\DiscussionManager;
use Bolt\Discussion\Service\VisitorTokenProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

class DiscussionApiControllerTest extends TestCase
{
    private DiscussionManager&MockObject $manager;
    private VisitorTokenProvider&MockObject $visitor;
    private CsrfTokenManagerInterface&MockObject $csrfTokenManager;
    private TranslatorInterface&MockObject $translator;

    protected function setUp(): void
    {
        $this->manager = $this->createMock(DiscussionManager::class);
        $this->visitor = $this->createMock(VisitorTokenProvider::class);
        $this->csrfTokenManager = $this->createMock(CsrfTokenManagerInterface::class);
        $this->translator = $this->createMock(TranslatorInterface::class);
        $this->translator->method('trans')->willReturnCallback(
            static fn (string $key, array $params = []): string => strtr($key, $params)
        );
    }

    public function testCsrfTokenReturnsFreshNonCacheableToken(): void
    {
        $this->visitor->expects(self::once())->method('applyCookie');
        $this->csrfTokenManager->expects(self::once())
            ->method('getToken')
            ->with('bolt_discussion')
            ->willReturn(new CsrfToken('bolt_discussion', 'fresh-token'));

        $response = $this->controller(csrfValid: true)->csrfToken();

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(['token' => 'fresh-token'], json_decode((string) $response->getContent(), true));
        self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
    }

    public function testListRejectsInvalidSinceCursor(): void
    {
        $this->visitor->method('isModerator')->willReturn(false);
        $this->visitor->expects(self::once())->method('applyCookie');

        $controller = $this->controller(csrfValid: true);
        $response = $controller->list('demo', Request::create('/discussion/api/demo?since=nope'));

        self::assertSame(400, $response->getStatusCode());
        self::assertSame(['error' => 'Invalid polling cursor.'], json_decode((string) $response->getContent(), true));
    }

    public function testPollingIncludesVisibilityAndReactionUpdatesForLoadedComments(): void
    {
        $this->visitor->method('isModerator')->willReturn(false);
        $this->visitor->expects(self::once())->method('applyCookie');
        $this->manager->expects(self::once())
            ->method('getThread')
            ->with('demo', 12, false)
            ->willReturn([
                'comments' => [],
                'lastId' => 12,
                'serverTime' => '2026-06-23T10:00:00+00:00',
        ]);
        $this->manager->expects(self::once())
            ->method('getPollUpdates')
            ->with('demo', [3, 7], false)
            ->willReturn([
                'removedCommentIds' => [7],
                'reactionUpdates' => [[
                    'commentId' => 3,
                    'reactions' => [['emoji' => '👍', 'count' => 2, 'mine' => false]],
                ]],
            ]);

        $controller = $this->controller(csrfValid: true);
        $response = $controller->list('demo', Request::create('/discussion/api/demo?since=12&sync_ids=3,7,3'));
        $payload = json_decode((string) $response->getContent(), true);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame([7], $payload['removedCommentIds']);
        self::assertSame(2, $payload['reactionUpdates'][0]['reactions'][0]['count']);
    }

    public function testPollingRejectsInvalidSyncIdsBeforeQuerying(): void
    {
        $this->visitor->method('isModerator')->willReturn(false);
        $this->visitor->expects(self::once())->method('applyCookie');
        $this->manager->expects(self::never())->method('getThread');
        $this->manager->expects(self::never())->method('getPollUpdates');

        $controller = $this->controller(csrfValid: true);
        $response = $controller->list('demo', Request::create('/discussion/api/demo?since=12&sync_ids=3,nope'));

        self::assertSame(400, $response->getStatusCode());
        self::assertSame(
            ['error' => 'Invalid comment polling request.'],
            json_decode((string) $response->getContent(), true)
        );
    }

    public function testCreateRejectsInvalidCsrfToken(): void
    {
        $this->visitor->expects(self::once())->method('applyCookie');
        $this->manager->expects(self::never())->method('createComment');

        $controller = $this->controller(csrfValid: false);
        $request = Request::create(
            '/discussion/api/demo',
            'POST',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['body' => 'Hello', '_token' => 'bad'], JSON_THROW_ON_ERROR)
        );

        $response = $controller->create('demo', $request);

        self::assertSame(400, $response->getStatusCode());
        self::assertSame(
            ['error' => 'Invalid security token. Please reload the page.', 'code' => 'invalid_csrf'],
            json_decode((string) $response->getContent(), true)
        );
    }

    public function testCreateReturnsTranslatedValidationError(): void
    {
        $this->visitor->expects(self::once())->method('applyCookie');
        $this->manager->expects(self::once())
            ->method('createComment')
            ->with('demo', self::isType('array'), self::isInstanceOf(Request::class))
            ->willThrowException(new ValidationException('Comment is too long (max %max% characters).', ['%max%' => 10]));

        $controller = $this->controller(csrfValid: true);
        $request = Request::create(
            '/discussion/api/demo',
            'POST',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['body' => 'too long', '_token' => 'good'], JSON_THROW_ON_ERROR)
        );

        $response = $controller->create('demo', $request);

        self::assertSame(422, $response->getStatusCode());
        self::assertSame(
            ['error' => 'Comment is too long (max 10 characters).'],
            json_decode((string) $response->getContent(), true)
        );
    }

    public function testDeleteRejectsNonModerator(): void
    {
        $comment = (new DiscussionComment())->setReference('demo')->setStatus(CommentStatus::Published);

        $this->visitor->method('isModerator')->willReturn(false);
        $this->visitor->expects(self::once())->method('applyCookie');
        $this->manager->expects(self::never())->method('deleteComment');

        $controller = $this->controller(csrfValid: true);
        $response = $controller->delete($comment, Request::create('/discussion/api/comment/1', 'DELETE'));

        self::assertSame(403, $response->getStatusCode());
        self::assertSame(['error' => 'Not authorised.'], json_decode((string) $response->getContent(), true));
    }

    public function testDeleteRejectsInvalidCsrfHeader(): void
    {
        $comment = (new DiscussionComment())->setReference('demo')->setStatus(CommentStatus::Published);

        $this->visitor->method('isModerator')->willReturn(true);
        $this->visitor->expects(self::once())->method('applyCookie');
        $this->manager->expects(self::never())->method('deleteComment');

        $controller = $this->controller(csrfValid: false);
        $request = Request::create('/discussion/api/comment/1', 'DELETE');
        $request->headers->set('X-CSRF-Token', 'bad');

        $response = $controller->delete($comment, $request);

        self::assertSame(400, $response->getStatusCode());
        self::assertSame(
            ['error' => 'Invalid security token.', 'code' => 'invalid_csrf'],
            json_decode((string) $response->getContent(), true)
        );
    }

    public function testReactionRejectsNonPublishedComment(): void
    {
        $comment = (new DiscussionComment())->setReference('demo')->setStatus(CommentStatus::Pending);

        $this->visitor->expects(self::once())->method('applyCookie');
        $this->manager->expects(self::never())->method('toggleReaction');

        $controller = $this->controller(csrfValid: true);
        $response = $controller->reaction($comment, Request::create('/discussion/api/comment/1/reaction', 'POST'));

        self::assertSame(404, $response->getStatusCode());
        self::assertSame(['error' => 'Comment not available.'], json_decode((string) $response->getContent(), true));
    }

    public function testReactionRejectsInvalidCsrfToken(): void
    {
        $comment = (new DiscussionComment())->setReference('demo')->setStatus(CommentStatus::Published);

        $this->visitor->expects(self::once())->method('applyCookie');
        $this->manager->expects(self::never())->method('toggleReaction');

        $controller = $this->controller(csrfValid: false);
        $request = Request::create(
            '/discussion/api/comment/1/reaction',
            'POST',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['emoji' => '👍', '_token' => 'bad'], JSON_THROW_ON_ERROR)
        );

        $response = $controller->reaction($comment, $request);

        self::assertSame(400, $response->getStatusCode());
        self::assertSame(
            ['error' => 'Invalid security token.', 'code' => 'invalid_csrf'],
            json_decode((string) $response->getContent(), true)
        );
    }

    private function controller(bool $csrfValid): DiscussionApiController
    {
        return new class($this->manager, $this->visitor, $this->csrfTokenManager, $this->translator, $csrfValid) extends DiscussionApiController {
            public function __construct(
                DiscussionManager $manager,
                VisitorTokenProvider $visitor,
                CsrfTokenManagerInterface $csrfTokenManager,
                TranslatorInterface $translator,
                private readonly bool $csrfValid,
            ) {
                parent::__construct($manager, $visitor, $csrfTokenManager, $translator);
            }

            protected function isCsrfTokenValid(string $id, ?string $token): bool
            {
                return $this->csrfValid;
            }
        };
    }
}
