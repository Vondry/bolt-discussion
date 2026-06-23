<?php

declare(strict_types=1);

namespace BoltDiscussion\Tests\Controller;

use BoltDiscussion\Controller\AssetController;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class AssetControllerTest extends TestCase
{
    public function testServesKnownAssetWithNoSniffHeader(): void
    {
        $response = (new AssetController())->serve('discussion.js');

        self::assertSame('application/javascript', $response->headers->get('Content-Type'));
        self::assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
    }

    public function testRejectsUnsupportedBuildFileType(): void
    {
        $this->expectException(NotFoundHttpException::class);

        (new AssetController())->serve('discussion.js.LICENSE.txt');
    }

    public function testRejectsSymlinkThatResolvesOutsideBuildDirectory(): void
    {
        $buildDir = \dirname(__DIR__, 2) . '/public/build';
        $target = sys_get_temp_dir() . '/bolt-discussion-secret-' . bin2hex(random_bytes(6)) . '.js';
        $link = $buildDir . '/external-' . bin2hex(random_bytes(6)) . '.js';
        file_put_contents($target, 'secret');

        if (! @symlink($target, $link)) {
            @unlink($target);
            self::markTestSkipped('Symlinks are not available on this platform.');
        }

        try {
            $this->expectException(NotFoundHttpException::class);
            (new AssetController())->serve(basename($link));
        } finally {
            @unlink($link);
            @unlink($target);
        }
    }
}
