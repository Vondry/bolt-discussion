<?php

declare(strict_types=1);

namespace Bolt\Discussion\Tests\Twig;

use Bolt\Discussion\Repository\DiscussionCommentRepository;
use Bolt\Discussion\Service\DiscussionConfig;
use Bolt\Discussion\Service\DiscussionManager;
use Bolt\Discussion\Service\VisitorTokenProvider;
use Bolt\Discussion\Twig\DiscussionExtension;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Translation\MessageCatalogue;
use Symfony\Component\Translation\MessageCatalogueInterface;
use Symfony\Component\Translation\TranslatorBagInterface;
use Symfony\Contracts\Translation\LocaleAwareInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\Markup;
use Twig\TwigFilter;

class DiscussionExtensionTest extends TestCase
{
    public function testTwigFunctionsAreRegistered(): void
    {
        $extension = new DiscussionExtension(
            $this->createMock(DiscussionConfig::class),
            $this->createMock(DiscussionManager::class),
            $this->createMock(VisitorTokenProvider::class),
            $this->createMock(DiscussionCommentRepository::class),
            $this->createMock(UrlGeneratorInterface::class),
            $this->createMock(CsrfTokenManagerInterface::class),
            $this->createMock(TranslatorInterface::class),
        );

        $functions = $extension->getFunctions();

        self::assertSame('discussion', $functions[0]->getName());
        self::assertSame('discussion_count', $functions[1]->getName());
    }

    public function testInitialPageIsRenderedAndSafelyEmbeddedForHydration(): void
    {
        $config = $this->createMock(DiscussionConfig::class);
        $config->method('pollInterval')->willReturn(10000);
        $config->method('reactionsEnabled')->willReturn(true);
        $config->method('reactions')->willReturn(['👍']);
        $config->method('repliesEnabled')->willReturn(true);
        $config->method('requireName')->willReturn(true);
        $config->method('maxLength')->willReturn(2000);

        $initialPage = [
            'comments' => [[
                'id' => 7,
                'parentId' => null,
                'author' => 'Alice Example',
                'authenticated' => false,
                'body' => 'Hello </script><script>alert(1)</script>',
                'status' => 'published',
                'createdAt' => '2026-06-23T10:00:00+00:00',
                'reactions' => [['emoji' => '👍', 'count' => 2, 'mine' => false]],
            ]],
            'lastId' => 7,
            'hasMore' => false,
            'nextBefore' => 7,
            'serverTime' => '2026-06-23T10:01:00+00:00',
        ];

        $manager = $this->createMock(DiscussionManager::class);
        $manager->expects(self::once())
            ->method('getPage')
            ->with('article-1', null, null, false)
            ->willReturn($initialPage);

        $visitor = $this->createMock(VisitorTokenProvider::class);
        $visitor->method('isModerator')->willReturn(false);

        $router = $this->createMock(UrlGeneratorInterface::class);
        $router->method('generate')->willReturnCallback(
            static fn (string $route, array $parameters = []): string => match ($route) {
                'bolt_discussion_api_csrf_token' => '/discussion/api/csrf/token',
                'bolt_discussion_api_list' => '/discussion/api/' . $parameters['reference'],
                'bolt_discussion_api_reaction' => '/discussion/api/comment/0/reaction',
                'bolt_discussion_api_delete' => '/discussion/api/comment/0',
                default => throw new \RuntimeException('Unexpected route'),
            }
        );

        $csrf = $this->createMock(CsrfTokenManagerInterface::class);
        $csrf->method('getToken')->willReturn(new CsrfToken('bolt_discussion', 'csrf-value'));

        $translator = $this->fakeTranslator();

        $loader = new FilesystemLoader();
        $loader->addPath(\dirname(__DIR__, 2) . '/templates', 'bolt-discussion');
        $twig = new Environment($loader);
        $twig->addFilter(new TwigFilter(
            'trans',
            static fn (string $message): string => $message
        ));

        $extension = new DiscussionExtension(
            $config,
            $manager,
            $visitor,
            $this->createMock(DiscussionCommentRepository::class),
            $router,
            $csrf,
            $translator,
        );

        $html = $extension->render($twig, 'article-1', ['title' => 'Comments']);

        self::assertStringContainsString('data-bd-composer', $html);
        self::assertStringContainsString('data-csrf-token-url="/discussion/api/csrf/token"', $html);
        self::assertStringContainsString('data-bd-list', $html);
        self::assertStringContainsString('Alice Example', $html);
        self::assertStringContainsString('Hello &lt;/script&gt;&lt;script&gt;alert(1)&lt;/script&gt;', $html);
        self::assertStringContainsString('data-bd-initial', $html);
        self::assertStringContainsString('\u003C\/script\u003E\u003Cscript\u003E', $html);
        self::assertStringNotContainsString('</script><script>alert(1)</script>', $html);
        self::assertStringNotContainsString('Loading discussion', $html);

        // Only the categories English defines are emitted, and absent ones are
        // never looked up via trans() (which would record them as missing).
        self::assertStringContainsString(
            '&quot;replyCount&quot;:{&quot;one&quot;:&quot;%count% reply&quot;,&quot;other&quot;:&quot;%count% replies&quot;}',
            $html
        );
        self::assertNotContains('reply_count.many', $translator->transKeys);
        self::assertNotContains('reply_count.few', $translator->transKeys);
    }

    public function testStringableReferenceCanBeRenderedFromTextFieldValue(): void
    {
        $html = $this->renderWith([], new Markup('rockfest-2027-lineup', 'UTF-8'));

        self::assertStringContainsString('data-reference="rockfest-2027-lineup"', $html);
        self::assertStringContainsString('data-list-url="/discussion/api/rockfest-2027-lineup"', $html);
    }

    public function testStringableReferenceCanBeCountedFromTextFieldValue(): void
    {
        $comments = $this->createMock(DiscussionCommentRepository::class);
        $comments->expects(self::once())
            ->method('countPublished')
            ->with('rockfest-2027-lineup')
            ->willReturn(12);

        $extension = new DiscussionExtension(
            $this->createMock(DiscussionConfig::class),
            $this->createMock(DiscussionManager::class),
            $this->createMock(VisitorTokenProvider::class),
            $comments,
            $this->createMock(UrlGeneratorInterface::class),
            $this->createMock(CsrfTokenManagerInterface::class),
            $this->createMock(TranslatorInterface::class),
        );

        self::assertSame(12, $extension->count(new Markup('rockfest-2027-lineup', 'UTF-8')));
    }

    public function testRenderingWithoutTranslatorCatalogueUsesFallbackReplyLabelsAndInitials(): void
    {
        $config = $this->createMock(DiscussionConfig::class);
        $config->method('pollInterval')->willReturn(10000);
        $config->method('reactionsEnabled')->willReturn(false);
        $config->method('reactions')->willReturn([]);
        $config->method('repliesEnabled')->willReturn(true);
        $config->method('requireName')->willReturn(false);
        $config->method('maxLength')->willReturn(2000);

        $manager = $this->createMock(DiscussionManager::class);
        $manager->expects(self::once())
            ->method('getPage')
            ->with('article-1', null, null, false)
            ->willReturn([
                'comments' => [
                    [
                        'id' => 1,
                        'parentId' => null,
                        'author' => '',
                        'authenticated' => false,
                        'body' => 'Root',
                        'status' => 'published',
                        'createdAt' => '2026-06-23T10:00:00+00:00',
                        'reactions' => [],
                    ],
                    [
                        'id' => 2,
                        'parentId' => 1,
                        'author' => 'Solo',
                        'authenticated' => false,
                        'body' => 'Reply',
                        'status' => 'published',
                        'createdAt' => '2026-06-23T10:05:00+00:00',
                        'reactions' => [],
                    ],
                ],
                'lastId' => 2,
                'hasMore' => false,
                'nextBefore' => 1,
                'serverTime' => '2026-06-23T10:06:00+00:00',
            ]);

        $visitor = $this->createMock(VisitorTokenProvider::class);
        $visitor->method('isModerator')->willReturn(false);

        $router = $this->createMock(UrlGeneratorInterface::class);
        $router->method('generate')->willReturnCallback(
            static fn (string $route, array $parameters = []): string => match ($route) {
                'bolt_discussion_api_csrf_token' => '/discussion/api/csrf/token',
                'bolt_discussion_api_list' => '/discussion/api/' . $parameters['reference'],
                'bolt_discussion_api_reaction' => '/discussion/api/comment/0/reaction',
                'bolt_discussion_api_delete' => '/discussion/api/comment/0',
                default => '/',
            }
        );

        $csrf = $this->createMock(CsrfTokenManagerInterface::class);
        $csrf->method('getToken')->willReturn(new CsrfToken('bolt_discussion', 'csrf-value'));

        $translator = new class() implements TranslatorInterface {
            public function trans(?string $id, array $parameters = [], ?string $domain = null, ?string $locale = null): string
            {
                return strtr((string) $id, $parameters);
            }

            public function getLocale(): string
            {
                return 'en';
            }
        };

        $loader = new FilesystemLoader();
        $loader->addPath(\dirname(__DIR__, 2) . '/templates', 'bolt-discussion');
        $twig = new Environment($loader);
        $twig->addFilter(new TwigFilter('trans', static fn (string $message): string => $message));

        $html = (new DiscussionExtension(
            $config,
            $manager,
            $visitor,
            $this->createMock(DiscussionCommentRepository::class),
            $router,
            $csrf,
            $translator,
        ))->render($twig, 'article-1');

        self::assertStringContainsString('1 replies', $html);
        self::assertMatchesRegularExpression('/>\s*\?\s*<\/span>/', $html);
        self::assertMatchesRegularExpression('/>\s*SO\s*<\/span>/', $html);
        self::assertStringContainsString('Reply', $html);
    }

    public function testComposerTextCanBeOverriddenPerInstance(): void
    {
        $html = $this->renderWith([
            'namePlaceholder' => 'Your nickname',
            'commentPlaceholder' => 'What should we book?',
            'replyPlaceholder' => 'Reply with your pick',
            'submitLabel' => 'Send it',
        ]);

        // Server-rendered composer uses the overridden copy.
        self::assertStringContainsString('placeholder="Your nickname"', $html);
        self::assertStringContainsString('placeholder="What should we book?"', $html);
        self::assertStringContainsString('>Send it</button>', $html);

        // JS-created forms use the same overridden labels from data-i18n.
        self::assertStringContainsString('&quot;name&quot;:&quot;Your nickname&quot;', $html);
        self::assertStringContainsString('&quot;commentPlaceholder&quot;:&quot;What should we book?&quot;', $html);
        self::assertStringContainsString('&quot;replyPlaceholder&quot;:&quot;Reply with your pick&quot;', $html);
        self::assertStringContainsString('&quot;post&quot;:&quot;Send it&quot;', $html);
    }

    public function testComposerPlaceholdersFallBackToTranslatedDefaults(): void
    {
        $html = $this->renderWith([]);

        self::assertStringContainsString('placeholder="Your name"', $html);
        self::assertStringContainsString('placeholder="Join the discussion…"', $html);
    }

    public function testBlankOverridesAreIgnored(): void
    {
        $html = $this->renderWith(['namePlaceholder' => '   ', 'commentPlaceholder' => '']);

        self::assertStringContainsString('placeholder="Your name"', $html);
        self::assertStringContainsString('placeholder="Join the discussion…"', $html);
    }

    public function testStringableComposerPlaceholdersCanBeOverriddenPerInstance(): void
    {
        $html = $this->renderWith([
            'namePlaceholder' => new Markup('Festival name', 'UTF-8'),
            'commentPlaceholder' => new Markup('Ask the band', 'UTF-8'),
            'replyPlaceholder' => new Markup('Reply with a reason', 'UTF-8'),
            'submitLabel' => new Markup('Send pick', 'UTF-8'),
        ]);

        self::assertStringContainsString('placeholder="Festival name"', $html);
        self::assertStringContainsString('placeholder="Ask the band"', $html);
        self::assertStringContainsString('>Send pick</button>', $html);
        self::assertStringContainsString('&quot;replyPlaceholder&quot;:&quot;Reply with a reason&quot;', $html);
        self::assertStringContainsString('&quot;post&quot;:&quot;Send pick&quot;', $html);
    }

    public function testReplyPlaceholderFallsBackToOverriddenCommentPlaceholder(): void
    {
        $html = $this->renderWith(['commentPlaceholder' => 'Ask the band']);

        self::assertStringContainsString('&quot;replyPlaceholder&quot;:&quot;Ask the band&quot;', $html);
    }

    public function testExplicitReplyPlaceholderWinsOverCommentPlaceholderFallback(): void
    {
        $html = $this->renderWith([
            'commentPlaceholder' => 'Ask the band',
            'replyPlaceholder' => 'Reply to this comment',
        ]);

        self::assertStringContainsString('&quot;replyPlaceholder&quot;:&quot;Reply to this comment&quot;', $html);
    }

    /**
     * @param array<string, mixed> $options
     */
    private function renderWith(array $options, string|\Stringable $reference = 'article-1'): string
    {
        $expectedReference = (string) $reference;
        $config = $this->createMock(DiscussionConfig::class);
        $config->method('pollInterval')->willReturn(10000);
        $config->method('reactionsEnabled')->willReturn(true);
        $config->method('reactions')->willReturn(['👍']);
        $config->method('repliesEnabled')->willReturn(true);
        $config->method('requireName')->willReturn(true);
        $config->method('maxLength')->willReturn(2000);

        $manager = $this->createMock(DiscussionManager::class);
        $manager->expects(self::once())
            ->method('getPage')
            ->with($expectedReference, null, null, false)
            ->willReturn([
                'comments' => [],
                'lastId' => 0,
                'hasMore' => false,
                'nextBefore' => null,
                'serverTime' => '2026-06-23T10:00:00+00:00',
            ]);

        $visitor = $this->createMock(VisitorTokenProvider::class);
        $visitor->method('isModerator')->willReturn(false);

        $router = $this->createMock(UrlGeneratorInterface::class);
        $router->method('generate')->willReturnCallback(
            static fn (string $route, array $parameters = []): string => match ($route) {
                'bolt_discussion_api_csrf_token' => '/discussion/api/csrf/token',
                'bolt_discussion_api_list' => '/discussion/api/' . $parameters['reference'],
                'bolt_discussion_api_reaction' => '/discussion/api/comment/0/reaction',
                'bolt_discussion_api_delete' => '/discussion/api/comment/0',
                default => '/',
            }
        );

        $csrf = $this->createMock(CsrfTokenManagerInterface::class);
        $csrf->method('getToken')->willReturn(new CsrfToken('bolt_discussion', 'csrf-value'));

        // Default translator: identity trans() so untouched labels render as
        // their English source strings, plus a real catalogue for plural forms.
        $translator = $this->fakeTranslator();

        $loader = new FilesystemLoader();
        $loader->addPath(\dirname(__DIR__, 2) . '/templates', 'bolt-discussion');
        $twig = new Environment($loader);
        $twig->addFilter(new TwigFilter('trans', static fn (string $message): string => $message));

        $extension = new DiscussionExtension(
            $config,
            $manager,
            $visitor,
            $this->createMock(DiscussionCommentRepository::class),
            $router,
            $csrf,
            $translator,
        );

        return $extension->render($twig, $reference, $options);
    }

    /**
     * Translator double that mirrors the real one: identity trans() plus a
     * message catalogue (TranslatorBagInterface) exposing only the plural
     * categories a locale actually defines — English has one/other, no "many".
     * Records the keys passed to trans() so tests can assert that the absent
     * categories are never looked up (which would log them as missing).
     */
    private function fakeTranslator(string $locale = 'en'): TranslatorInterface&LocaleAwareInterface&TranslatorBagInterface
    {
        return new class($locale) implements TranslatorInterface, LocaleAwareInterface, TranslatorBagInterface {
            /** @var list<string> */
            public array $transKeys = [];

            public function __construct(
                private string $locale
            ) {
            }

            public function trans(?string $id, array $parameters = [], ?string $domain = null, ?string $locale = null): string
            {
                $this->transKeys[] = (string) $id;

                return strtr((string) $id, $parameters);
            }

            public function getLocale(): string
            {
                return $this->locale;
            }

            public function setLocale(string $locale): void
            {
                $this->locale = $locale;
            }

            public function getCatalogue(?string $locale = null): MessageCatalogueInterface
            {
                return new MessageCatalogue($locale ?? $this->locale, [
                    'bolt_discussion' => [
                        'reply_count.one' => '%count% reply',
                        'reply_count.other' => '%count% replies',
                    ],
                ]);
            }

            public function getCatalogues(): array
            {
                return [$this->getCatalogue()];
            }
        };
    }
}
