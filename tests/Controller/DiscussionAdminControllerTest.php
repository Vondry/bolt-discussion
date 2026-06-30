<?php

declare(strict_types=1);

namespace Bolt\Discussion\Tests\Controller;

use Bolt\Discussion\Controller\Backend\DiscussionAdminController;
use Bolt\Discussion\Entity\DiscussionComment;
use Bolt\Discussion\Enum\CommentStatus;
use Bolt\Discussion\Repository\DiscussionCommentRepository;
use Bolt\Discussion\Repository\DiscussionReactionRepository;
use Bolt\Discussion\Service\DiscussionManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Translation\MessageCatalogue;
use Symfony\Component\Translation\MessageCatalogueInterface;
use Symfony\Component\Translation\TranslatorBagInterface;
use Symfony\Contracts\Translation\LocaleAwareInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

class DiscussionAdminControllerTest extends TestCase
{
    private DiscussionManager&MockObject $manager;
    private DiscussionCommentRepository&MockObject $comments;
    private DiscussionReactionRepository&MockObject $reactions;
    private TranslatorInterface&MockObject $translator;
    private UrlGeneratorInterface&MockObject $urlGenerator;
    private Session $session;
    private RequestStack $requestStack;

    protected function setUp(): void
    {
        $this->manager = $this->createMock(DiscussionManager::class);
        $this->comments = $this->createMock(DiscussionCommentRepository::class);
        $this->reactions = $this->createMock(DiscussionReactionRepository::class);
        $this->translator = $this->createMock(TranslatorInterface::class);
        $this->urlGenerator = $this->createMock(UrlGeneratorInterface::class);
        $this->session = new Session(new MockArraySessionStorage());
        $this->requestStack = new RequestStack();

        $this->translator->method('trans')->willReturnCallback(
            static fn (string $key, array $params = []): string => match ($key) {
                'reply_count.one' => '%count% reply',
                'reply_count.other' => '%count% replies',
                default => strtr($key, $params),
            }
        );
    }

    public function testInvalidCsrfTokenRedirectsWithDangerFlash(): void
    {
        $comment = (new DiscussionComment())->setReference('demo')->setStatus(CommentStatus::Published);

        $this->manager->expects(self::never())->method('deleteComment');
        $this->urlGenerator->expects(self::once())
            ->method('generate')
            ->with('bolt_discussion_admin_thread', ['reference' => 'demo'], UrlGeneratorInterface::ABSOLUTE_PATH)
            ->willReturn('/extension/discussion/view/demo');

        $controller = $this->controller(csrfValid: false);
        $request = Request::create('/extension/discussion/comment/1/delete', 'POST', ['_token' => 'bad']);
        $request->setSession($this->session);
        $this->requestStack->push($request);

        try {
            $response = $controller->action($comment, 'delete', $request);
        } finally {
            $this->requestStack->pop();
        }

        self::assertSame('/extension/discussion/view/demo', $response->getTargetUrl());
        self::assertSame(['Invalid security token.'], $this->session->getFlashBag()->peek('danger'));
    }

    public function testDeleteActionCallsManagerAndRedirects(): void
    {
        $comment = (new DiscussionComment())->setReference('demo')->setStatus(CommentStatus::Published);
        $this->setCommentId($comment, 7);

        $this->manager->expects(self::once())->method('deleteComment')->with($comment);
        $this->urlGenerator->expects(self::once())
            ->method('generate')
            ->with('bolt_discussion_admin_thread', ['reference' => 'demo'], UrlGeneratorInterface::ABSOLUTE_PATH)
            ->willReturn('/extension/discussion/view/demo');

        $controller = $this->controller(csrfValid: true);
        $request = Request::create('/extension/discussion/comment/7/delete', 'POST', ['_token' => 'good']);
        $request->setSession($this->session);
        $this->requestStack->push($request);

        try {
            $response = $controller->action($comment, 'delete', $request);
        } finally {
            $this->requestStack->pop();
        }

        self::assertSame('/extension/discussion/view/demo', $response->getTargetUrl());
        self::assertSame(['Comment #7 deleted.'], $this->session->getFlashBag()->peek('success'));
    }

    public function testRestoreActionPublishesComment(): void
    {
        $comment = (new DiscussionComment())->setReference('demo')->setStatus(CommentStatus::Pending);
        $this->setCommentId($comment, 8);

        $this->manager->expects(self::once())->method('setStatus')->with($comment, CommentStatus::Published);
        $this->urlGenerator->expects(self::once())
            ->method('generate')
            ->with('bolt_discussion_admin_thread', ['reference' => 'demo'], UrlGeneratorInterface::ABSOLUTE_PATH)
            ->willReturn('/extension/discussion/view/demo');

        $controller = $this->controller(csrfValid: true);
        $request = Request::create('/extension/discussion/comment/8/restore', 'POST', ['_token' => 'good']);
        $request->setSession($this->session);
        $this->requestStack->push($request);

        try {
            $response = $controller->action($comment, 'restore', $request);
        } finally {
            $this->requestStack->pop();
        }

        self::assertSame('/extension/discussion/view/demo', $response->getTargetUrl());
        self::assertSame(['Comment #8 approved.'], $this->session->getFlashBag()->peek('success'));
    }

    public function testSpamActionMarksCommentAsSpam(): void
    {
        $comment = (new DiscussionComment())->setReference('demo')->setStatus(CommentStatus::Published);
        $this->setCommentId($comment, 9);

        $this->manager->expects(self::once())->method('setStatus')->with($comment, CommentStatus::Spam);
        $this->urlGenerator->expects(self::once())
            ->method('generate')
            ->with('bolt_discussion_admin_thread', ['reference' => 'demo'], UrlGeneratorInterface::ABSOLUTE_PATH)
            ->willReturn('/extension/discussion/view/demo');

        $controller = $this->controller(csrfValid: true);
        $request = Request::create('/extension/discussion/comment/9/spam', 'POST', ['_token' => 'good']);
        $request->setSession($this->session);
        $this->requestStack->push($request);

        try {
            $response = $controller->action($comment, 'spam', $request);
        } finally {
            $this->requestStack->pop();
        }

        self::assertSame('/extension/discussion/view/demo', $response->getTargetUrl());
        self::assertSame(['Comment #9 marked as spam.'], $this->session->getFlashBag()->peek('success'));
    }

    public function testRedirectUsesPostedReferenceWhenProvided(): void
    {
        $comment = (new DiscussionComment())->setReference('demo')->setStatus(CommentStatus::Published);
        $this->setCommentId($comment, 10);

        $this->manager->expects(self::once())->method('deleteComment')->with($comment);
        $this->urlGenerator->expects(self::once())
            ->method('generate')
            ->with('bolt_discussion_admin_thread', ['reference' => 'demo.thread-2'], UrlGeneratorInterface::ABSOLUTE_PATH)
            ->willReturn('/extension/discussion/view/demo.thread-2');

        $controller = $this->controller(csrfValid: true);
        $request = Request::create('/extension/discussion/comment/10/delete', 'POST', [
            '_token' => 'good',
            'reference' => 'demo.thread-2',
        ]);
        $request->setSession($this->session);
        $this->requestStack->push($request);

        try {
            $response = $controller->action($comment, 'delete', $request);
        } finally {
            $this->requestStack->pop();
        }

        self::assertSame('/extension/discussion/view/demo.thread-2', $response->getTargetUrl());
        self::assertSame(['Comment #10 deleted.'], $this->session->getFlashBag()->peek('success'));
    }

    public function testThreadViewReceivesGroupedThreadsWithReactions(): void
    {
        $root = $this->comment(1, 'Root', '2026-06-23 10:00:00');
        $replyNewer = $this->comment(3, 'Reply newer', '2026-06-23 10:20:00', $root);
        $replyOlder = $this->comment(2, 'Reply older', '2026-06-23 10:10:00', $root);
        $deletedParent = $this->comment(99, 'Deleted parent', '2026-06-23 09:00:00');
        $orphanReply = $this->comment(4, 'Orphan reply', '2026-06-23 10:30:00', $deletedParent);

        $comments = [$orphanReply, $replyNewer, $root, $replyOlder];
        $reactionSummary = [
            1 => ['👍' => ['count' => 2, 'mine' => false]],
            3 => ['❤️' => ['count' => 1, 'mine' => false]],
        ];
        $this->comments->expects(self::once())
            ->method('findForAdmin')
            ->with('demo')
            ->willReturn($comments);
        $this->reactions->expects(self::once())
            ->method('summaryFor')
            ->with([4, 3, 1, 2], '')
            ->willReturn($reactionSummary);

        $translator = new class() implements TranslatorInterface, LocaleAwareInterface, TranslatorBagInterface {
            public function trans(?string $id, array $parameters = [], ?string $domain = null, ?string $locale = null): string
            {
                return strtr((string) $id, $parameters);
            }

            public function getLocale(): string
            {
                return 'cs';
            }

            public function setLocale(string $locale): void
            {
            }

            public function getCatalogue(?string $locale = null): MessageCatalogueInterface
            {
                // Czech defines one/few/other (no "many") — mirrors the real catalogue.
                return new MessageCatalogue($locale ?? 'cs', [
                    'bolt_discussion' => [
                        'reply_count.one' => '%count% odpověď',
                        'reply_count.few' => '%count% odpovědi',
                        'reply_count.other' => '%count% odpovědí',
                        'comment_count.one' => '%count% komentář',
                        'comment_count.few' => '%count% komentáře',
                        'comment_count.other' => '%count% komentářů',
                    ],
                ]);
            }

            public function getCatalogues(): array
            {
                return [$this->getCatalogue()];
            }
        };

        $controller = new class($this->manager, $this->comments, $this->reactions, $translator) extends DiscussionAdminController {
            /** @var array<string, mixed> */
            public array $renderedParameters = [];

            public string $renderedView = '';

            public function __construct(
                DiscussionManager $manager,
                DiscussionCommentRepository $comments,
                DiscussionReactionRepository $reactions,
                TranslatorInterface $translator,
            ) {
                parent::__construct($manager, $comments, $reactions, $translator);
            }

            protected function render(string $view, array $parameters = [], ?Response $response = null): Response
            {
                $this->renderedView = $view;
                $this->renderedParameters = $parameters;

                return new Response();
            }
        };

        $controller->thread('demo');

        self::assertSame('@bolt-discussion/backend/thread.html.twig', $controller->renderedView);
        self::assertSame('demo', $controller->renderedParameters['reference']);
        self::assertSame($comments, $controller->renderedParameters['comments']);
        self::assertSame($reactionSummary, $controller->renderedParameters['reactions']);
        self::assertSame([
            'one' => '%count% odpověď',
            'few' => '%count% odpovědi',
            'other' => '%count% odpovědí',
        ], $controller->renderedParameters['replyCountForms']);

        // 4 comments → Czech "few" form (komentáře), not the bare plural.
        self::assertSame('4 komentáře', $controller->renderedParameters['commentCountLabel']);

        $threads = $controller->renderedParameters['threads'];
        self::assertCount(2, $threads);
        self::assertSame($root, $threads[0]['comment']);
        self::assertFalse($threads[0]['orphan']);
        self::assertSame([$replyOlder, $replyNewer], $threads[0]['replies']);
        self::assertSame('2 odpovědi', $threads[0]['replyLabel']);
        self::assertSame($orphanReply, $threads[1]['comment']);
        self::assertTrue($threads[1]['orphan']);
        self::assertSame([], $threads[1]['replies']);
    }

    private function controller(bool $csrfValid): DiscussionAdminController
    {
        return new class($this->manager, $this->comments, $this->reactions, $this->translator, $this->urlGenerator, $this->requestStack, $csrfValid) extends DiscussionAdminController {
            public function __construct(
                DiscussionManager $manager,
                DiscussionCommentRepository $comments,
                DiscussionReactionRepository $reactions,
                TranslatorInterface $translator,
                UrlGeneratorInterface $urlGenerator,
                RequestStack $requestStack,
                private readonly bool $csrfValid,
            ) {
                parent::__construct($manager, $comments, $reactions, $translator);
                $this->setContainer(new TestControllerContainer($urlGenerator, $requestStack));
            }

            protected function isCsrfTokenValid(string $id, ?string $token): bool
            {
                return $this->csrfValid;
            }
        };
    }

    private function setCommentId(DiscussionComment $comment, int $id): void
    {
        $property = new \ReflectionProperty(DiscussionComment::class, 'id');
        $property->setValue($comment, $id);
    }

    private function comment(int $id, string $body, string $createdAt, ?DiscussionComment $parent = null): DiscussionComment
    {
        $comment = (new DiscussionComment())
            ->setReference('demo')
            ->setAuthorName('Admin user')
            ->setBody($body)
            ->setStatus(CommentStatus::Published)
            ->setParent($parent);

        $this->setCommentId($comment, $id);

        $property = new \ReflectionProperty(DiscussionComment::class, 'createdAt');
        $property->setValue($comment, new \DateTimeImmutable($createdAt));

        return $comment;
    }
}
