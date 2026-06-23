<?php

declare(strict_types=1);

namespace BoltDiscussion\EventSubscriber;

use Bolt\Widget\Injector\RequestZone;
use BoltDiscussion\Service\VisitorTokenProvider;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Injects the extension's built CSS/JS into frontend pages, but only when a
 * discussion mount is actually present on the page.
 */
class AssetInjectionSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly VisitorTokenProvider $visitor,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::RESPONSE => 'onResponse'];
    }

    public function onResponse(ResponseEvent $event): void
    {
        if (! $event->isMainRequest() || ! RequestZone::isForFrontend($event->getRequest())) {
            return;
        }

        $response = $event->getResponse();
        $content = $response->getContent();
        if (! is_string($content) || ! str_contains($content, 'class="bolt-discussion"')) {
            return;
        }

        $cssUrl = $this->urlGenerator->generate('bolt_discussion_asset', ['file' => 'discussion.css']);
        $jsUrl = $this->urlGenerator->generate('bolt_discussion_asset', ['file' => 'discussion.js']);

        $css = sprintf('<link rel="stylesheet" href="%s">', htmlspecialchars($cssUrl, ENT_QUOTES));
        $js = sprintf('<script type="module" src="%s"></script>', htmlspecialchars($jsUrl, ENT_QUOTES));

        if (str_contains($content, '</head>')) {
            $content = str_replace('</head>', $css . "\n</head>", $content);
        } else {
            $content = $css . $content;
        }

        if (str_contains($content, '</body>')) {
            $content = str_replace('</body>', $js . "\n</body>", $content);
        } else {
            $content .= $js;
        }

        $response->setContent($content);
        $this->visitor->applyCookie($response);
    }
}
