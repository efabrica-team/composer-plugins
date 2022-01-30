<?php

namespace Efabrica\ComposerPlugin\Command;

use Composer\Command\ShowCommand;
use Composer\Composer;
use Composer\Filter\PlatformRequirementFilter\PlatformRequirementFilterFactory;
use Composer\Package\AliasPackage;
use Composer\Package\BasePackage;
use Composer\Package\PackageInterface;
use Composer\Package\Version\VersionSelector;
use Composer\Repository\CompositeRepository;
use Composer\Repository\InstalledRepository;
use Composer\Repository\PlatformRepository;
use Composer\Repository\RepositorySet;
use Composer\Semver\Semver;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Helper\TableSeparator;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Finder\Finder;

class ExtendedOutdatedCommand extends ShowCommand
{
    private ?RepositorySet $repositorySet = null;

    private const TYPE_GITHUB = 'github';

    private const TYPE_GITLAB = 'gitlab';

    private const URL_CHANGELOG = 'changelog';

    private const URL_DIFF = 'diff';

    private const URL_MAP = [
        self::TYPE_GITHUB => [
            self::URL_CHANGELOG => '%baseUrl%/blob/%latestVersion%/%changelogFile%',
            self::URL_DIFF => '%baseUrl%/compare/%actualVersion%...%latestVersion%',
        ],
        self::TYPE_GITLAB => [
            self::URL_CHANGELOG => '%baseUrl%/-/blob/%latestVersion%/%changelogFile%',
            self::URL_DIFF => '%baseUrl%/-/compare/%actualVersion%...%latestVersion%',
        ],
    ];

    private array $hostToTypeMap = [
        'github.com' => self::TYPE_GITHUB,
        'gitlab.com' => self::TYPE_GITLAB,
    ];

    protected function configure(): void
    {
        $this->setName('extended-outdated')
            ->setDescription('Shows a list of installed packages that have updates available, including their latest version. If possible, it also shows URL to diff and changelog.')
            ->addOption('host-to-type', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Add hosts to type map. Use pipe to separate host and type, e.g. --host-to-type="my.gitlab.org|gitlab"')
            ->addOption('ignore', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Ignore specified package(s). Use it if you don\'t want to be informed about new versions of some packages.')
        ;

        // TODO add other options from outdated
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        foreach ($input->getOption('host-to-type') as $hostToType) {
            [$host, $type] = explode('|', $hostToType, 2);
            $this->hostToTypeMap[$host] = $type;
        }

        $composer = $this->getComposer();

        $platformOverrides = $composer->getConfig()->get('platform') ?: [];
        $platformRepo = new PlatformRepository([], $platformOverrides);

        // TODO check if this is correct
        $installedRepository = new InstalledRepository([$composer->getRepositoryManager()->getLocalRepository()]);

        $packages = $installedRepository->getPackages();
        $rootRequires = $this->getRootRequires();
        $ignoredPackages = array_map('strtolower', $input->getOption('ignore'));
        $vendorDir = $composer->getConfig()->get('vendor-dir');
        $outdatedPackagesRows = [];
        foreach ($packages as $package) {
            if (!in_array($package->getName(), $rootRequires, true)) {
                continue;
            }
            if (in_array($package->getName(), $ignoredPackages, true)) {
                continue;
            }

            $urls = [];

            $latestPackage = $this->findLatestPackage($package, $composer, $platformRepo);
            if ($latestPackage === null) {
                continue;
            }
            if ($package->getFullPrettyVersion() === $latestPackage->getFullPrettyVersion()) {
                continue;
            }

            $urls[] = $this->createDiffUrl($package, $latestPackage);
            $urls[] = $this->createChangelogUrl($package, $latestPackage, $vendorDir);

            $outdatedPackagesRows[] = [
                $package->getName(),
                $package->getPrettyVersion(),
                $this->getUpdateStatus($latestPackage, $package),
                implode("\n", array_filter($urls))
            ];
        }

        $table = new Table($output);
        $table->setheaders(['Package', 'Actual version', 'Latest version', 'Diff + Changelog']);
        $outdatedPackagesCount = count($outdatedPackagesRows);
        foreach ($outdatedPackagesRows as $i => $outdatedPackagesRow) {
            $table->addRow($outdatedPackagesRow);
            if ($i + 1 < $outdatedPackagesCount) {
                $table->addRow(new TableSeparator());
            }
        }

        $table->render();
        return $outdatedPackagesCount;
    }


    private function createDiffUrl(PackageInterface $package, PackageInterface $latestPackage): string
    {
        $sourceUrl = $package->getSourceUrl();
        $baseUrl = $this->getBaseUrl($sourceUrl);

        $type = $this->getType($baseUrl);
        $diffPattern = self::URL_MAP[$type][self::URL_DIFF] ?? '';

        return str_replace(
            ['%baseUrl%', '%actualVersion%', '%latestVersion%'],
            [$baseUrl, $package->getPrettyVersion(), $latestPackage->getPrettyVersion()],
            $diffPattern
        );
    }

    private function createChangelogUrl(PackageInterface $package, PackageInterface $latestPackage, string $vendorDir): ?string
    {
        $packagePath = $vendorDir . '/' . $package->getName();

        $changelog = $this->findChangelogFile($packagePath);
        if ($changelog === null) {
            return null;
        }

        $sourceUrl = $package->getSourceUrl();
        $baseUrl = $this->getBaseUrl($sourceUrl);

        $type = $this->getType($baseUrl);
        $changelogPattern = self::URL_MAP[$type][self::URL_CHANGELOG] ?? '';

        return str_replace(
            ['%baseUrl%', '%latestVersion%', '%changelogFile%'],
            [$baseUrl, $latestPackage->getPrettyVersion(), $changelog],
            $changelogPattern
        );
    }

    private function findChangelogFile(string $dir): ?string
    {
        $files = Finder::create()
            ->files()
            ->name('CHANGELOG.md')
            ->name('changelog.md')
            ->name('Changelog.md')
            ->in($dir);

        foreach ($files as $file) {
            return pathinfo((string)$file, PATHINFO_BASENAME);
        }

        return null;
    }

    private function getBaseUrl(string $sourceUrl): string
    {
        return str_replace([':', 'git@', '.git', '///'], ['/', 'https://', '', '://'], $sourceUrl);
    }

    private function getType(string $baseUrl): ?string
    {
        $host = parse_url($baseUrl, PHP_URL_HOST);
        return $this->hostToTypeMap[$host] ?? null;
    }

    /**
     * copy from ShowCommand
     */
    private function findLatestPackage(PackageInterface $package, Composer $composer, PlatformRepository $platformRepo, $minorOnly = false, $ignorePlatformReqs = false): ?PackageInterface
    {
        // find the latest version allowed in this repo set
        $name = $package->getName();
        $versionSelector = new VersionSelector($this->getRepositorySet($composer), $platformRepo);
        $stability = $composer->getPackage()->getMinimumStability();
        $flags = $composer->getPackage()->getStabilityFlags();
        if (isset($flags[$name])) {
            $stability = array_search($flags[$name], BasePackage::$stabilities, true);
        }

        $bestStability = $stability;
        if ($composer->getPackage()->getPreferStable()) {
            $bestStability = $package->getStability();
        }

        $targetVersion = null;
        if (strpos($package->getVersion(), 'dev-') === null) {
            $targetVersion = $package->getVersion();
        }

        if ($targetVersion === null && $minorOnly) {
            $targetVersion = '^' . $package->getVersion();
        }

        $candidate = $versionSelector->findBestCandidate($name, $targetVersion, $bestStability, PlatformRequirementFilterFactory::fromBoolOrList($ignorePlatformReqs));
        while ($candidate instanceof AliasPackage) {
            $candidate = $candidate->getAliasOf();
        }

        return $candidate ?: null;
    }

    /**
     * copy from ShowCommand
     */
    private function getRepositorySet(Composer $composer): RepositorySet
    {
        if (!$this->repositorySet) {
            $this->repositorySet = new RepositorySet($composer->getPackage()->getMinimumStability(), $composer->getPackage()->getStabilityFlags());
            $this->repositorySet->addRepository(new CompositeRepository($composer->getRepositoryManager()->getRepositories()));
        }

        return $this->repositorySet;
    }

    /**
     * inspired by ShowCommand
     */
    private function getUpdateStatus(PackageInterface $latestPackage, PackageInterface $package): string
    {
        if ($latestPackage->getFullPrettyVersion() === $package->getFullPrettyVersion()) {
            return '<info>' . $latestPackage->getPrettyVersion() . '</info>';
        }

        $constraint = $package->getVersion();
        if (strpos($constraint, 'dev-') !== 0) {
            $constraint = '^'.$constraint;
        }
        if ($latestPackage->getVersion() && Semver::satisfies($latestPackage->getVersion(), $constraint)) {
            // it needs an immediate semver-compliant upgrade
            return '<highlight>' . $latestPackage->getPrettyVersion() . '</highlight>';
        }

        // it needs an upgrade but has potential BC breaks so is not urgent
        return '<comment>' . $latestPackage->getPrettyVersion() . '</comment>';
    }
}
