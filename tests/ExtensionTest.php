<?php

declare(strict_types=1);

namespace Bolt\Discussion\Tests;

use Bolt\Configuration\Config;
use Bolt\Discussion\Extension;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Guards the config-filename contract so a single, correctly named config file
 * is generated on install.
 *
 * Two independent Bolt code paths derive the extension's config filename and
 * must agree, otherwise two duplicate files are created:
 *
 *  - At runtime, {@see \Bolt\Extension\ConfigTrait::getConfigFilenames()}
 *    slugifies the entrypoint's namespace ("Bolt\Discussion" => "bolt-discussion").
 *  - On `extensions:configure --with-config`,
 *    {@see \Bolt\Command\ExtensionsConfigureCommand} derives the destination
 *    from the entrypoint's namespace ("Bolt\Discussion" => "bolt-discussion").
 *
 * A single-segment namespace such as "BoltDiscussion" slugifies to
 * "boltdiscussion" and previously had to be patched with a getConfigFilenames()
 * override, which the install command ignored — producing both
 * boltdiscussion.yaml and bolt-discussion.yaml.
 */
class ExtensionTest extends TestCase
{
    public function testRuntimeConfigFilenamesUseTheHyphenatedSlug(): void
    {
        $dir = '/var/www/config/extensions';

        $config = $this->createMock(Config::class);
        $config->method('getPath')->with('extensions_config')->willReturn($dir);

        $extension = new Extension();
        $reflection = new ReflectionClass($extension);
        $property = $reflection->getProperty('boltConfig');
        $property->setAccessible(true);
        $property->setValue($extension, $config);

        $filenames = $extension->getConfigFilenames();

        self::assertSame('bolt-discussion.yaml', basename($filenames['main']));
        self::assertSame('bolt-discussion_local.yaml', basename($filenames['local']));
    }

    public function testInstallCommandDerivesTheSameFilenameFromTheNamespace(): void
    {
        // Mirrors ExtensionsConfigureCommand::copyConfig()/getExtensionConfigPath().
        $namespace = (new ReflectionClass(Extension::class))->getNamespaceName();

        [$vendor, $name] = explode('\\', mb_strtolower($namespace . '\\'));
        $filename = sprintf('%s%s%s.yaml', $vendor, ($name !== '' ? '-' : ''), $name);

        self::assertSame('bolt-discussion.yaml', $filename);
    }
}
