<?php

declare(strict_types=1);

namespace Bolt\Discussion;

use Bolt\Extension\BaseExtension;

class Extension extends BaseExtension
{
    public function getName(): string
    {
        return 'Bolt Discussion';
    }

    public function initialize(): void
    {
        // The @bolt-discussion namespace is registered at container level, in
        // the extension's config/services.yaml (copied to
        // config/packages/extension_bolt-discussion.yaml by
        // `extensions:configure`), so it also resolves on the CLI. This runtime
        // registration is kept as a fallback for projects that have not re-run
        // `extensions:configure` since upgrading; prepending a path that is
        // already registered is a no-op in effect.
        $this->addTwigNamespace('bolt-discussion');
    }

    public function initializeCli(): void
    {
    }
}
