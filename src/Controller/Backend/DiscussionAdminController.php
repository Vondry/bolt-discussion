<?php

declare(strict_types=1);

namespace BoltDiscussion\Controller\Backend;

use Bolt\Controller\Backend\BackendZoneInterface;
use BoltDiscussion\Entity\DiscussionComment;
use BoltDiscussion\Enum\CommentStatus;
use BoltDiscussion\Repository\DiscussionCommentRepository;
use BoltDiscussion\Repository\DiscussionReactionRepository;
use BoltDiscussion\Service\DiscussionManager;
use MessageFormatter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Translation\TranslatorBagInterface;
use Symfony\Contracts\Translation\LocaleAwareInterface;
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
        $locale = $this->translator instanceof LocaleAwareInterface ? $this->translator->getLocale() : 'en';
        $replyCountForms = $this->replyCountForms($locale);

        return $this->render('@bolt-discussion/backend/thread.html.twig', [
            'reference' => $reference,
            'comments' => $comments,
            'commentCountLabel' => $this->commentCountLabel(count($comments), $locale),
            'threads' => $this->buildThreads($comments, $replyCountForms),
            'reactions' => $reactions,
            'replyCountForms' => $replyCountForms,
        ]);
    }

    /**
     * @param DiscussionComment[] $comments newest-first, as returned by the admin repository
     * @param array<string, string> $replyCountForms
     * @return list<array{comment: DiscussionComment, replies: list<DiscussionComment>, replyLabel: string, orphan: bool}>
     */
    private function buildThreads(array $comments, array $replyCountForms): array
    {
        $threads = [];
        $rootIndex = [];
        $locale = $this->translator instanceof LocaleAwareInterface ? $this->translator->getLocale() : 'en';

        foreach ($comments as $comment) {
            if ($comment->isReply()) {
                continue;
            }

            $rootIndex[(int) $comment->getId()] = count($threads);
            $threads[] = [
                'comment' => $comment,
                'replies' => [],
                'replyLabel' => '',
                'orphan' => false,
            ];
        }

        foreach ($comments as $comment) {
            if (! $comment->isReply()) {
                continue;
            }

            $parentId = (int) $comment->getParent()?->getId();
            if (isset($rootIndex[$parentId])) {
                $threads[$rootIndex[$parentId]]['replies'][] = $comment;
                continue;
            }

            $threads[] = [
                'comment' => $comment,
                'replies' => [],
                'replyLabel' => '',
                'orphan' => true,
            ];
        }

        foreach ($threads as &$thread) {
            usort(
                $thread['replies'],
                static fn (DiscussionComment $a, DiscussionComment $b): int => $a->getCreatedAt() <=> $b->getCreatedAt()
            );
            $thread['replyLabel'] = $this->replyCountLabel(count($thread['replies']), $locale, $replyCountForms);
        }
        unset($thread);

        return $threads;
    }

    /**
     * @return array<string, string>
     */
    private function replyCountForms(string $locale): array
    {
        return $this->pluralForms('reply_count', $locale);
    }

    /**
     * @param array<string, string> $forms
     */
    private function replyCountLabel(int $count, string $locale, array $forms): string
    {
        return $this->pluralLabel($count, $locale, $forms, '%count% replies');
    }

    private function commentCountLabel(int $count, string $locale): string
    {
        return $this->pluralLabel($count, $locale, $this->pluralForms('comment_count', $locale), '%count% comments');
    }

    /**
     * Reads the CLDR plural forms (one/few/many/other) for a translation key
     * straight from the message catalogue, taking only the categories a locale
     * actually defines (Czech has no "many", etc.). Reading the catalogue
     * instead of probing each key with trans() avoids flagging the absent
     * categories as missing translations.
     *
     * @return array<string, string>
     */
    private function pluralForms(string $key, string $locale): array
    {
        if (! $this->translator instanceof TranslatorBagInterface) {
            return [];
        }

        $messages = $this->translator->getCatalogue($locale)->all(self::DOMAIN);

        $forms = [];
        foreach (['one', 'few', 'many', 'other'] as $category) {
            $formKey = $key . '.' . $category;
            if (isset($messages[$formKey])) {
                $forms[$category] = $messages[$formKey];
            }
        }

        return $forms;
    }

    /**
     * Picks the correct plural form for $count using the locale's CLDR rules
     * (e.g. Czech: 1 → one, 2-4 → few, 5+ → other) and substitutes %count%.
     *
     * @param array<string, string> $forms
     */
    private function pluralLabel(int $count, string $locale, array $forms, string $fallback): string
    {
        $category = $count === 1 ? 'one' : 'other';
        if (class_exists(MessageFormatter::class)) {
            $selected = MessageFormatter::formatMessage(
                $locale,
                '{count, plural, one {one} few {few} many {many} other {other}}',
                ['count' => $count]
            );
            if (is_string($selected)) {
                $category = $selected;
            }
        }

        $template = $forms[$category] ?? $forms['other'] ?? $fallback;

        return str_replace('%count%', (string) $count, $template);
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
