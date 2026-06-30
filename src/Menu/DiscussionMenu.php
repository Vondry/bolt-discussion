<?php

declare(strict_types=1);

namespace Bolt\Discussion\Menu;

use Bolt\Menu\ExtensionBackendMenuInterface;
use Knp\Menu\MenuItem;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Adds a "Discussion" entry to the Bolt admin sidebar.
 *
 * Tagged `bolt.extension_backend_menu` in the extension's config/services.yaml.
 */
class DiscussionMenu implements ExtensionBackendMenuInterface
{
    public function __construct(
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function addItems(MenuItem $menu): void
    {
        $menu->addChild('Discussion', [
            'uri' => $this->urlGenerator->generate('bolt_discussion_admin'),
            'extras' => [
                'name' => $this->translator->trans('Discussion', [], 'bolt_discussion'),
                'icon' => 'fa-comments',
            ],
        ]);
    }
}
