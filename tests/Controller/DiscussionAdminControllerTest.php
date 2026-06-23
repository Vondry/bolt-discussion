<?php

declare(strict_types=1);

namespace BoltDiscussion\Tests\Controller;

use BoltDiscussion\Controller\Backend\DiscussionAdminController;
use BoltDiscussion\Entity\DiscussionComment;
use BoltDiscussion\Enum\CommentStatus;
use BoltDiscussion\Repository\DiscussionCommentRepository;
use BoltDiscussion\Repository\DiscussionReactionRepository;
use BoltDiscussion\Service\DiscussionManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
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
            static fn (string $key, array $params = []): string => strtr($key, $params)
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

    private function controller(bool $csrfValid): DiscussionAdminController
    {
        return new class(
            $this->manager,
            $this->comments,
            $this->reactions,
            $this->translator,
            $this->urlGenerator,
            $this->requestStack,
            $csrfValid,
        ) extends DiscussionAdminController {
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
}