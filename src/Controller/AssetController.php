<?php

declare(strict_types=1);

namespace BoltDiscussion\Controller;

use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Streams the extension's Encore-built assets straight from the package's
 * public/build directory. This lets the extension work as a symlinked Composer
 * "path" repository without copying anything into the project's web root.
 */
class AssetController
{
    private const ALLOWED_ASSETS = [
        'discussion.js' => 'application/javascript',
        'discussion.css' => 'text/css',
    ];

    #[Route('/discussion/asset/{file}', name: 'bolt_discussion_asset', methods: ['GET'], requirements: ['file' => '[A-Za-z0-9_\-\.]+'])]
    public function serve(string $file): Response
    {
        if (! isset(self::ALLOWED_ASSETS[$file])) {
            throw new NotFoundHttpException('Asset not found.');
        }

        $buildDir = realpath(\dirname(__DIR__, 2) . '/public/build');
        if ($buildDir === false) {
            throw new NotFoundHttpException('Asset not found.');
        }

        // Require the resolved file to be a direct child of the build directory.
        // This rejects traversal as well as symlinks pointing outside that directory.
        $real = realpath($buildDir . DIRECTORY_SEPARATOR . $file);
        if ($real === false || \dirname($real) !== $buildDir || ! is_file($real)) {
            throw new NotFoundHttpException('Asset not found.');
        }

        $response = new BinaryFileResponse($real);
        $response->setPublic();
        $response->setMaxAge(3600);
        $response->headers->set('Content-Type', self::ALLOWED_ASSETS[$file]);
        $response->headers->set('X-Content-Type-Options', 'nosniff');

        return $response;
    }
}
