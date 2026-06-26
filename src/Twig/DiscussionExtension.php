<?php

declare(strict_types=1);

namespace BoltDiscussion\Twig;

use BoltDiscussion\Repository\DiscussionCommentRepository;
use BoltDiscussion\Service\DiscussionConfig;
use BoltDiscussion\Service\DiscussionManager;
use BoltDiscussion\Service\VisitorTokenProvider;
use DateTimeImmutable;
use DateTimeInterface;
use MessageFormatter;
use Stringable;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Translation\TranslatorBagInterface;
use Symfony\Contracts\Translation\LocaleAwareInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

class DiscussionExtension extends AbstractExtension
{
    private const CSRF_ID = 'bolt_discussion';
    private const ID_PLACEHOLDER = '__ID__';
    private const DOMAIN = 'bolt_discussion';

    /**
     * discussion() option => label id it overrides, so a single discussion can
     * carry its own composer copy.
     */
    private const LABEL_OPTIONS = [
        'namePlaceholder' => 'name',
        'commentPlaceholder' => 'commentPlaceholder',
        'replyPlaceholder' => 'replyPlaceholder',
        'submitLabel' => 'post',
    ];

    public function __construct(
        private readonly DiscussionConfig $config,
        private readonly DiscussionManager $manager,
        private readonly VisitorTokenProvider $visitor,
        private readonly DiscussionCommentRepository $comments,
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly CsrfTokenManagerInterface $csrfTokenManager,
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('discussion', $this->render(...), ['is_safe' => ['html'], 'needs_environment' => true]),
            new TwigFunction('discussion_count', $this->count(...)),
        ];
    }

    /**
     * Render the discussion mount point. The frontend JS hydrates it.
     *
     * @param array<string, mixed> $options
     */
    public function render(Environment $twig, string|Stringable $reference, array $options = []): string
    {
        $reference = (string) $reference;
        $canModerate = $this->visitor->isModerator();
        $initialPage = $this->manager->getPage($reference, null, null, $canModerate);
        $initialPage['canModerate'] = $canModerate;
        $locale = $this->translator instanceof LocaleAwareInterface ? $this->translator->getLocale() : 'en';
        $replyCountForms = $this->replyCountForms($locale);

        $context = [
            'reference' => $reference,
            'title' => $options['title'] ?? null,
            'pollInterval' => $this->config->pollInterval(),
            'reactionsEnabled' => $this->config->reactionsEnabled(),
            'reactions' => $this->config->reactions(),
            'repliesEnabled' => $this->config->repliesEnabled(),
            'requireName' => $this->config->requireName(),
            'maxLength' => $this->config->maxLength(),
            'canModerate' => $canModerate,
            'csrfToken' => $this->csrfTokenManager->getToken(self::CSRF_ID)->getValue(),
            'csrfTokenUrl' => $this->urlGenerator->generate('bolt_discussion_api_csrf_token'),
            'listUrl' => $this->urlGenerator->generate('bolt_discussion_api_list', ['reference' => $reference]),
            // Routes require a numeric id, so generate with 0 and swap in the
            // client-side placeholder. The "/comment/0" segment is unambiguous.
            'reactionUrlTemplate' => str_replace(
                '/comment/0/',
                '/comment/' . self::ID_PLACEHOLDER . '/',
                $this->urlGenerator->generate('bolt_discussion_api_reaction', ['id' => 0])
            ),
            'deleteUrlTemplate' => str_replace(
                '/comment/0',
                '/comment/' . self::ID_PLACEHOLDER,
                $this->urlGenerator->generate('bolt_discussion_api_delete', ['id' => 0])
            ),
            'locale' => $locale,
            'labels' => $this->jsLabels($options, $locale),
            'initialPage' => $initialPage,
            'initialJson' => json_encode(
                $initialPage,
                JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR
            ),
            'initialThreads' => $this->initialThreads($initialPage['comments'], $locale, $replyCountForms),
        ];

        return $twig->render('@bolt-discussion/mount.html.twig', $context);
    }

    public function count(string|Stringable $reference): int
    {
        return $this->comments->countPublished((string) $reference);
    }

    /**
     * Translated labels handed to the frontend JS via a data attribute, keyed by
     * short ids the script understands.
     *
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    private function jsLabels(array $options, string $locale): array
    {
        $sources = [
            'empty' => 'Be the first to comment.',
            'loadMore' => 'Load more comments',
            'name' => 'Your name',
            'replyPlaceholder' => 'Write a reply…',
            'commentPlaceholder' => 'Join the discussion…',
            'post' => 'Post comment',
            'reply' => 'Reply',
            'delete' => 'Delete',
            'staff' => 'staff',
            'pending' => 'awaiting review',
            'awaitingModeration' => 'Thanks! Your comment is awaiting moderation.',
            'genericError' => 'Something went wrong.',
            'networkError' => 'Network error. Please try again.',
            'confirmDelete' => 'Delete this comment?',
            'hideReplies' => 'Hide replies',
            'addReaction' => 'Add reaction',
        ];

        $labels = array_map(fn (string $source): string => $this->translator->trans($source, [], self::DOMAIN), $sources);

        // Per-instance overrides, so each discussion() call can set its own
        // composer copy (already translated by the caller if needed). Falls back
        // to the translated defaults above.
        $overrides = [];
        foreach (self::LABEL_OPTIONS as $option => $label) {
            $value = $this->stringOption($options[$option] ?? null);
            if ($value !== null) {
                $labels[$label] = $value;
                $overrides[$option] = true;
            }
        }

        if (isset($overrides['commentPlaceholder']) && ! isset($overrides['replyPlaceholder'])) {
            $labels['replyPlaceholder'] = $labels['commentPlaceholder'];
        }

        // CLDR plural forms for the collapsed reply count; the JS picks one with
        // Intl.PluralRules for the current locale and substitutes %count%.
        $labels['replyCount'] = $this->replyCountForms($locale);

        return $labels;
    }

    private function stringOption(mixed $value): ?string
    {
        if (! is_string($value) && ! $value instanceof Stringable) {
            return null;
        }

        $value = (string) $value;

        return trim($value) === '' ? null : $value;
    }

    /**
     * CLDR plural forms (one/few/many/other) for the reply count, taken only
     * for the categories a locale actually defines (Czech has no "many", etc.).
     * Reading the catalogue rather than probing each key with trans() avoids
     * flagging the absent categories as missing translations.
     *
     * @return array<string, string>
     */
    private function replyCountForms(string $locale): array
    {
        if (! $this->translator instanceof TranslatorBagInterface) {
            return [];
        }

        $messages = $this->translator->getCatalogue($locale)->all(self::DOMAIN);

        $forms = [];
        foreach (['one', 'few', 'many', 'other'] as $category) {
            $key = 'reply_count.' . $category;
            if (isset($messages[$key])) {
                $forms[$category] = $messages[$key];
            }
        }

        return $forms;
    }

    /**
     * Shape the flat API payload into roots with replies for the initial
     * server-rendered HTML. JavaScript consumes the original flat payload.
     *
     * @param array<int, array<string, mixed>> $comments
     * @param array<string, string> $replyCountForms
     * @return array<int, array{
     *     comment: array<string, mixed>,
     *     replies: array<int, array<string, mixed>>,
     *     replyLabel: string
     * }>
     */
    private function initialThreads(array $comments, string $locale, array $replyCountForms): array
    {
        $roots = [];
        $replies = [];

        foreach ($comments as $comment) {
            $comment = $this->withPresentation($comment);
            $parentId = $comment['parentId'] ?? null;
            if ($parentId === null) {
                $roots[(int) $comment['id']] = [
                    'comment' => $comment,
                    'replies' => [],
                    'replyLabel' => '',
                ];
            } else {
                $replies[(int) $parentId][] = $comment;
            }
        }

        foreach ($roots as $id => &$thread) {
            $thread['replies'] = $replies[$id] ?? [];
            $thread['replyLabel'] = $this->replyCountLabel(count($thread['replies']), $locale, $replyCountForms);
        }
        unset($thread);

        return array_values($roots);
    }

    /**
     * @param array<string, mixed> $comment
     * @return array<string, mixed>
     */
    private function withPresentation(array $comment): array
    {
        $author = (string) ($comment['author'] ?? '');
        $parts = preg_split('/\s+/u', trim($author), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if ($parts === []) {
            $initials = '?';
        } elseif (count($parts) === 1) {
            $initials = mb_strtoupper(mb_substr($parts[0], 0, 2));
        } else {
            $initials = mb_strtoupper(
                mb_substr($parts[0], 0, 1) . mb_substr($parts[array_key_last($parts)], 0, 1)
            );
        }

        $hash = 0;
        $utf16 = mb_convert_encoding($author, 'UTF-16BE', 'UTF-8');
        $codeUnits = unpack('n*', $utf16) ?: [];
        foreach ($codeUnits as $codeUnit) {
            $hash = (($hash * 31) + $codeUnit) & 0xFFFFFFFF;
        }

        $createdAt = new DateTimeImmutable((string) ($comment['createdAt'] ?? 'now'));
        $comment['initials'] = $initials;
        $comment['avatarHue'] = $hash % 360;
        $comment['createdLabel'] = $createdAt->format('Y-m-d H:i');
        $comment['createdTitle'] = $createdAt->format(DateTimeInterface::ATOM);

        // avatarUrl, when present, is passed through from the DiscussionManager serialization.

        return $comment;
    }

    /**
     * @param array<string, string> $forms
     */
    private function replyCountLabel(int $count, string $locale, array $forms): string
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

        $template = $forms[$category] ?? $forms['other'] ?? '%count% replies';

        return str_replace('%count%', (string) $count, $template);
    }
}
