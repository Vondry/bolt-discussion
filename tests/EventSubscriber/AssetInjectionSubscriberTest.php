<?php

declare(strict_types=1);

namespace Bolt\Discussion\Tests\EventSubscriber;

use Bolt\Discussion\EventSubscriber\AssetInjectionSubscriber;
use Bolt\Discussion\Service\VisitorTokenProvider;
use Bolt\Widget\Injector\RequestZone;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

class AssetInjectionSubscriberTest extends TestCase
{
    public function testSubscribedEventsRegisterResponseHandler(): void
    {
        self::assertSame([KernelEvents::RESPONSE => 'onResponse'], AssetInjectionSubscriber::getSubscribedEvents());
    }

    public function testIgnoresNonFrontendOrPagesWithoutDiscussionMount(): void
    {
        $routes = $this->createMock(UrlGeneratorInterface::class);
        $routes->expects(self::never())->method('generate');
        $visitor = $this->createMock(VisitorTokenProvider::class);
        $visitor->expects(self::never())->method('applyCookie');

        $subscriber = new AssetInjectionSubscriber($routes, $visitor);

        $backend = Request::create('/bolt');
        RequestZone::setToRequest($backend, RequestZone::BACKEND);
        $response = new Response('<html><body><div class="bolt-discussion"></div></body></html>');
        $subscriber->onResponse($this->event($backend, $response));
        self::assertSame('<html><body><div class="bolt-discussion"></div></body></html>', $response->getContent());

        $frontend = Request::create('/');
        RequestZone::setToRequest($frontend, RequestZone::FRONTEND);
        $response = new Response('<html><body>No comments here</body></html>');
        $subscriber->onResponse($this->event($frontend, $response));
        self::assertSame('<html><body>No comments here</body></html>', $response->getContent());
    }

    public function testInjectsAssetsIntoHeadAndBodyAndAppliesVisitorCookie(): void
    {
        $routes = $this->createMock(UrlGeneratorInterface::class);
        $routes->method('generate')->willReturnCallback(static fn (string $route, array $params): string => match ($params['file']) {
            'discussion.css' => '/discussion/asset/discussion.css?x=<1>',
            'discussion.js' => '/discussion/asset/discussion.js?x=<2>',
        });
        $visitor = $this->createMock(VisitorTokenProvider::class);
        $visitor->expects(self::once())->method('applyCookie');

        $request = Request::create('/');
        RequestZone::setToRequest($request, RequestZone::FRONTEND);
        $response = new Response('<html><head></head><body><div class="bolt-discussion"></div></body></html>');

        (new AssetInjectionSubscriber($routes, $visitor))->onResponse($this->event($request, $response));

        $content = (string) $response->getContent();
        self::assertStringContainsString('<link rel="stylesheet" href="/discussion/asset/discussion.css?x=&lt;1&gt;">', $content);
        self::assertStringContainsString('<script type="module" src="/discussion/asset/discussion.js?x=&lt;2&gt;"></script>', $content);
        self::assertStringContainsString("</head>", $content);
        self::assertStringContainsString("</body>", $content);
    }

    public function testInjectsAssetsWhenPageHasNoHeadOrBodyTags(): void
    {
        $routes = $this->createMock(UrlGeneratorInterface::class);
        $routes->method('generate')->willReturnCallback(static fn (string $route, array $params): string => '/assets/' . $params['file']);
        $visitor = $this->createMock(VisitorTokenProvider::class);
        $visitor->expects(self::once())->method('applyCookie');

        $request = Request::create('/');
        RequestZone::setToRequest($request, RequestZone::FRONTEND);
        $response = new Response('<div class="bolt-discussion"></div>');

        (new AssetInjectionSubscriber($routes, $visitor))->onResponse($this->event($request, $response));

        self::assertSame(
            '<link rel="stylesheet" href="/assets/discussion.css"><div class="bolt-discussion"></div><script type="module" src="/assets/discussion.js"></script>',
            $response->getContent()
        );
    }

    private function event(Request $request, Response $response): ResponseEvent
    {
        return new ResponseEvent(
            $this->createMock(HttpKernelInterface::class),
            $request,
            HttpKernelInterface::MAIN_REQUEST,
            $response
        );
    }
}
