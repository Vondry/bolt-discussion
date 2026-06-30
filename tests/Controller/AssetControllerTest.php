<?php

declare(strict_types=1);

namespace Bolt\Discussion\Tests\Controller;

use Bolt\Discussion\Controller\AssetController;
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

    public function testRejectsWhenBuildDirectoryIsMissing(): void
    {
        $buildDir = \dirname(__DIR__, 2) . '/public/build';
        $backup = \dirname(__DIR__, 2) . '/public/build-coverage-backup-' . bin2hex(random_bytes(4));

        if (! @rename($buildDir, $backup)) {
            self::markTestSkipped('Build directory cannot be moved on this platform.');
        }

        try {
            $this->expectException(NotFoundHttpException::class);
            (new AssetController())->serve('discussion.js');
        } finally {
            @rename($backup, $buildDir);
        }
    }

    public function testRejectsAllowedAssetNameThatResolvesOutsideBuildDirectory(): void
    {
        $buildDir = \dirname(__DIR__, 2) . '/public/build';
        $asset = $buildDir . '/discussion.css';
        $backup = $buildDir . '/discussion.css.coverage-backup-' . bin2hex(random_bytes(4));
        $target = sys_get_temp_dir() . '/bolt-discussion-secret-' . bin2hex(random_bytes(6)) . '.css';
        file_put_contents($target, 'secret');

        if (! @rename($asset, $backup)) {
            @unlink($target);
            self::markTestSkipped('Asset file cannot be moved on this platform.');
        }

        if (! @symlink($target, $asset)) {
            @rename($backup, $asset);
            @unlink($target);
            self::markTestSkipped('Symlinks are not available on this platform.');
        }

        try {
            $this->expectException(NotFoundHttpException::class);
            (new AssetController())->serve('discussion.css');
        } finally {
            @unlink($asset);
            @rename($backup, $asset);
            @unlink($target);
        }
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
