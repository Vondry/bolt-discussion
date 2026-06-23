<?php

declare(strict_types=1);

namespace BoltDiscussion;

use Bolt\Extension\BaseExtension;

class Extension extends BaseExtension
{
    public function getName(): string
    {
        return 'Bolt Discussion';
    }

    /**
     * @return array<string, string>
     */
    public function getConfigFilenames(): array
    {
        $path = $this->getBoltConfig()->getPath('extensions_config');

        return [
            'main' => sprintf('%s%sbolt-discussion.yaml', $path, DIRECTORY_SEPARATOR),
            'local' => sprintf('%s%sbolt-discussion_local.yaml', $path, DIRECTORY_SEPARATOR),
        ];
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
