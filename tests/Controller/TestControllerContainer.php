<?php

declare(strict_types=1);

namespace Bolt\Discussion\Tests\Controller;

use Psr\Container\ContainerInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

class TestControllerContainer implements ContainerInterface
{
    public function __construct(
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly RequestStack $requestStack = new RequestStack(),
    ) {
    }

    public function get(string $id): mixed
    {
        return match ($id) {
            'router' => $this->urlGenerator,
            UrlGeneratorInterface::class => $this->urlGenerator,
            'request_stack' => $this->requestStack,
            RequestStack::class => $this->requestStack,
            default => throw new \RuntimeException('Unsupported service: ' . $id),
        };
    }

    public function has(string $id): bool
    {
        return in_array($id, ['router', UrlGeneratorInterface::class, 'request_stack', RequestStack::class], true);
    }
}
