<?php

declare(strict_types=1);

namespace BoltDiscussion\Tests\Twig;

use BoltDiscussion\Repository\DiscussionCommentRepository;
use BoltDiscussion\Service\DiscussionConfig;
use BoltDiscussion\Service\DiscussionManager;
use BoltDiscussion\Service\VisitorTokenProvider;
use BoltDiscussion\Twig\DiscussionExtension;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFilter;

class DiscussionExtensionTest extends TestCase
{
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

        $translator = $this->createMock(TranslatorInterface::class);
        $translator->method('getLocale')->willReturn('en');
        $translator->method('trans')->willReturnCallback(
            static fn (string $key): string => match ($key) {
                'reply_count.one' => '%count% reply',
                'reply_count.other' => '%count% replies',
                default => $key,
            }
        );

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
    }

    public function testComposerPlaceholdersCanBeOverriddenPerInstance(): void
    {
        $html = $this->renderWith([
            'namePlaceholder' => 'Your nickname',
            'commentPlaceholder' => 'What should we book?',
            'submitLabel' => 'Send it',
        ]);

        // Server-rendered composer uses the overridden copy …
        self::assertStringContainsString('placeholder="Your nickname"', $html);
        self::assertStringContainsString('placeholder="What should we book?"', $html);
        self::assertStringContainsString('>Send it</button>', $html);

        // … and so do the labels handed to the JS for reply forms / hydration.
        self::assertStringContainsString('What should we book?', $html);
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

    /**
     * @param array<string, mixed> $options
     */
    private function renderWith(array $options): string
    {
        $config = $this->createMock(DiscussionConfig::class);
        $config->method('pollInterval')->willReturn(10000);
        $config->method('reactionsEnabled')->willReturn(true);
        $config->method('reactions')->willReturn(['👍']);
        $config->method('repliesEnabled')->willReturn(true);
        $config->method('requireName')->willReturn(true);
        $config->method('maxLength')->willReturn(2000);

        $manager = $this->createMock(DiscussionManager::class);
        $manager->method('getPage')->willReturn([
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

        // Default translator: every key maps to itself, so the untouched labels
        // render as their English source strings.
        $translator = $this->createMock(TranslatorInterface::class);
        $translator->method('getLocale')->willReturn('en');
        $translator->method('trans')->willReturnCallback(static fn (string $key): string => $key);

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

        return $extension->render($twig, 'article-1', $options);
    }
}
