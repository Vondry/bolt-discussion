<?php

declare(strict_types=1);

namespace BoltDiscussion\Controller\Backend;

use Bolt\Controller\Backend\BackendZoneInterface;
use BoltDiscussion\Entity\DiscussionComment;
use BoltDiscussion\Enum\CommentStatus;
use BoltDiscussion\Repository\DiscussionCommentRepository;
use BoltDiscussion\Repository\DiscussionReactionRepository;
use BoltDiscussion\Service\DiscussionManager;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

#[IsGranted('ROLE_EDITOR')]
#[Route('/extension/discussion')]
class DiscussionAdminController extends AbstractController implements BackendZoneInterface
{
    private const CSRF_ID = 'bolt_discussion_admin';
    private const DOMAIN = 'bolt_discussion';

    public function __construct(
        private readonly DiscussionManager $manager,
        private readonly DiscussionCommentRepository $comments,
        private readonly DiscussionReactionRepository $reactions,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route('', name: 'bolt_discussion_admin', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('@bolt-discussion/backend/index.html.twig', [
            'discussions' => $this->comments->findReferencesOverview(),
        ]);
    }

    #[Route('/view/{reference}', name: 'bolt_discussion_admin_thread', methods: ['GET'], requirements: ['reference' => '[A-Za-z0-9_.:-]{1,191}'])]
    public function thread(string $reference): Response
    {
        $comments = $this->comments->findForAdmin($reference);
        $ids = array_map(static fn (DiscussionComment $c): int => (int) $c->getId(), $comments);
        $reactions = $this->reactions->summaryFor($ids, '');

        return $this->render('@bolt-discussion/backend/thread.html.twig', [
            'reference' => $reference,
            'comments' => $comments,
            'reactions' => $reactions,
        ]);
    }

    #[Route('/comment/{id}/{action}', name: 'bolt_discussion_admin_action', methods: ['POST'], requirements: ['id' => '\d+', 'action' => 'delete|restore|spam'])]
    public function action(DiscussionComment $comment, string $action, Request $request): RedirectResponse
    {
        if (! $this->isCsrfTokenValid(self::CSRF_ID, (string) $request->request->get('_token'))) {
            $this->addFlash('danger', $this->translator->trans('Invalid security token.', [], self::DOMAIN));

            return $this->redirectToThread($comment, $request);
        }

        $flashKey = match ($action) {
            'delete' => 'Comment #%id% deleted.',
            'restore' => 'Comment #%id% approved.',
            'spam' => 'Comment #%id% marked as spam.',
            default => throw new BadRequestHttpException('Unknown moderation action.'),
        };

        match ($action) {
            'delete' => $this->manager->deleteComment($comment),
            'restore' => $this->manager->setStatus($comment, CommentStatus::Published),
            'spam' => $this->manager->setStatus($comment, CommentStatus::Spam),
            default => throw new BadRequestHttpException('Unknown moderation action.'),
        };

        $this->addFlash('success', $this->translator->trans($flashKey, ['%id%' => $comment->getId()], self::DOMAIN));

        return $this->redirectToThread($comment, $request);
    }

    private function redirectToThread(DiscussionComment $comment, Request $request): RedirectResponse
    {
        $reference = (string) $request->request->get('reference', $comment->getReference());

        return $this->redirectToRoute('bolt_discussion_admin_thread', ['reference' => $reference]);
    }
}
