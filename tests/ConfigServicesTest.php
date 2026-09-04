<?php

declare(strict_types=1);

namespace Bolt\Discussion\Tests;

use Bolt\Discussion\Entity\DiscussionComment;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Symfony\Component\Yaml\Yaml;

/**
 * Guards the registrations that only exist in config/services.yaml, which
 * `extensions:configure` copies to config/packages/extension_bolt-discussion.yaml.
 *
 * These have no runtime substitute: `BaseExtension::addTwigNamespace()` only
 * touches the Twig environment of the running web request, so without the
 * container-level path the namespace is missing on the CLI, and without the
 * mapping the entities are invisible to Doctrine.
 */
class ConfigServicesTest extends TestCase
{
    private const VENDOR_PATH = '%kernel.project_dir%/vendor/tomvondracek/bolt-discussion';

    /** @return array<string, mixed> */
    private function config(): array
    {
        return Yaml::parseFile(\dirname(__DIR__) . '/config/services.yaml');
    }

    public function testTwigNamespaceIsRegisteredForTheShippedTemplates(): void
    {
        $paths = $this->config()['twig']['paths'] ?? [];

        self::assertSame(
            ['bolt-discussion'],
            array_values($paths),
            'The templates directory must be registered under the @bolt-discussion namespace.'
        );
        self::assertSame([self::VENDOR_PATH . '/templates'], array_keys($paths));
        self::assertFileExists(\dirname(__DIR__) . '/templates/mount.html.twig');
    }

    public function testEntityMappingIsRegisteredForTheEntityNamespace(): void
    {
        $mapping = $this->config()['doctrine']['orm']['mappings']['BoltDiscussion'] ?? [];

        self::assertFalse($mapping['is_bundle'], 'Extensions are not bundles.');
        self::assertSame('attribute', $mapping['type']);
        self::assertSame(self::VENDOR_PATH . '/src/Entity', $mapping['dir']);
        self::assertSame(
            (new ReflectionClass(DiscussionComment::class))->getNamespaceName(),
            $mapping['prefix'],
            'The mapping prefix must match the namespace the entities actually live in.'
        );
    }

    public function testTranslationCatalogsAreRegistered(): void
    {
        self::assertSame(
            [self::VENDOR_PATH . '/translations'],
            $this->config()['framework']['translator']['paths'] ?? []
        );
    }
}
