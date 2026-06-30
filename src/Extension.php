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
        // Templates are resolvable via the @bolt-discussion namespace, and can
        // be overridden by dropping a same-named template in the active theme.
        $this->addTwigNamespace('bolt-discussion');
    }

    public function initializeCli(): void
    {
    }
}
