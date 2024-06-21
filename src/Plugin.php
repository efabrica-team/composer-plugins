<?php

namespace Efabrica\ComposerPlugin;

use Composer\Composer;
use Composer\DependencyResolver\Operation\InstallOperation;
use Composer\DependencyResolver\Operation\UpdateOperation;
use Composer\EventDispatcher\EventSubscriberInterface;
use Composer\Installer\PackageEvent;
use Composer\IO\IOInterface;
use Composer\Plugin\Capability\CommandProvider;
use Composer\Plugin\Capable;
use Composer\Plugin\PluginInterface;
use Composer\Script\Event;
use Composer\Util\Filesystem;
use Efabrica\ComposerPlugin\Capability\EfabricaCommandProvider;

class Plugin implements PluginInterface, Capable, EventSubscriberInterface
{
    private string $vendorDir = '';

    private bool $useSharedVendors = false;

    private string $sharedVendorDir = '';

    public function activate(Composer $composer, IOInterface $io): void
    {
        $this->vendorDir = $composer->getConfig()->get('vendor-dir');
    }

    public function deactivate(Composer $composer, IOInterface $io): void
    {
    }

    public function uninstall(Composer $composer, IOInterface $io): void
    {
    }

    public function getCapabilities(): array
    {
        return [
            CommandProvider::class => EfabricaCommandProvider::class
        ];
    }

    public static function getSubscribedEvents()
    {
        return [
            'pre-install-cmd' => 'setup',
            'pre-update-cmd' => 'setup',
            'post-package-install' => 'postPackage',
            'post-package-update' => 'postPackage',
        ];
    }

    public function setup(Event $event)
    {
        $io = $event->getIO();
        $this->useSharedVendors = $io->askConfirmation('Do you want to use shared vendors? [<comment>y</comment>/<comment>N</comment>]: ', false);
        if ($this->useSharedVendors) {
            $sharedVendorDefault = realpath(getcwd() . '/../shared-vendor');
            $this->sharedVendorDir = $io->ask('Which folder do you want to use as shared folder? [<comment>' . $sharedVendorDefault . '</comment>]', $sharedVendorDefault);
        }
    }

    public function postPackage(PackageEvent $packageEvent)
    {
        if ($this->useSharedVendors === false) {
            return;
        }

        if ($packageEvent->getName() === 'post-package-install') {
            /** @var InstallOperation $operation */
            $operation = $packageEvent->getOperation();
            $package = $operation->getPackage();
        } elseif ($packageEvent->getName() === 'post-package-update') {
            /** @var UpdateOperation $operation */
            $operation = $packageEvent->getOperation();
            $package = $operation->getTargetPackage();
        } else {
            return;
        }

        $filesystem = new Filesystem();

        $vendorPath = $this->vendorDir . '/' . $package->getName();
        if (is_link($vendorPath)) {
            return;
        }
        if (is_dir($vendorPath)) {
            $shared = $this->sharedVendorDir . '/' . $package->getName() . '/' . $package->getVersion();
            if (!is_dir($shared)) {
                $filesystem->copyThenRemove($vendorPath, $shared);
            } else {
                $filesystem->removeDirectoryPhp($vendorPath);
            }
            $filesystem->relativeSymlink($shared, $vendorPath);
        }
    }
}
