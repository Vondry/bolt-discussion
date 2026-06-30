<?php

declare(strict_types=1);

namespace Bolt\Discussion\Tests\Menu;

use Bolt\Discussion\Menu\DiscussionMenu;
use Knp\Menu\FactoryInterface;
use Knp\Menu\ItemInterface;
use Knp\Menu\MenuItem;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

class DiscussionMenuTest extends TestCase
{
    public function testAddsDiscussionItemToBackendMenu(): void
    {
        $routes = $this->createMock(UrlGeneratorInterface::class);
        $routes->expects(self::once())
            ->method('generate')
            ->with('bolt_discussion_admin')
            ->willReturn('/bolt/extension/discussion');

        $translator = $this->createMock(TranslatorInterface::class);
        $translator->expects(self::once())
            ->method('trans')
            ->with('Discussion', [], 'bolt_discussion')
            ->willReturn('Diskuze');

        $factory = new class() implements FactoryInterface {
            public function createItem(string $name, array $options = []): ItemInterface
            {
                $item = new MenuItem($name, $this);
                $item->setUri($options['uri'] ?? null);
                foreach ($options['extras'] ?? [] as $key => $value) {
                    $item->setExtra((string) $key, $value);
                }

                return $item;
            }
        };
        $menu = new MenuItem('root', $factory);

        (new DiscussionMenu($routes, $translator))->addItems($menu);

        $item = $menu->getChild('Discussion');
        self::assertNotNull($item);
        self::assertSame('/bolt/extension/discussion', $item->getUri());
        self::assertSame('Diskuze', $item->getExtra('name'));
        self::assertSame('fa-comments', $item->getExtra('icon'));
    }
}
