<?php

namespace Efabrica\ComposerPlugin\Capability;

use Composer\Plugin\Capability\CommandProvider;
use Efabrica\ComposerPlugin\Command\ExtendedOutdatedCommand;

class EfabricaCommandProvider implements CommandProvider
{
    public function getCommands(): array
    {
        return [
            new ExtendedOutdatedCommand(),
        ];
    }

}
