<?php

namespace Efabrica\ComposerPlugin\Command;

use Composer\Command\BaseCommand;
use Composer\Composer;
use Composer\Filter\PlatformRequirementFilter\PlatformRequirementFilterFactory;
use Composer\Json\JsonFile;
use Composer\Package\BasePackage;
use Composer\Package\CompletePackageInterface;
use Composer\Package\AliasPackage;
use Composer\Package\PackageInterface;
use Composer\Package\Version\VersionSelector;
use Composer\Pcre\Preg;
use Composer\Repository\InstalledArrayRepository;
use Composer\Repository\ComposerRepository;
use Composer\Repository\CompositeRepository;
use Composer\Repository\PlatformRepository;
use Composer\Repository\InstalledRepository;
use Composer\Repository\RepositoryInterface;
use Composer\Repository\RepositorySet;
use Composer\Semver\Semver;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Helper\TableSeparator;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Finder\Finder;
use UnexpectedValueException;

/**
 * @author Michal Lulco <michal.lulco@gmail.com>
 *
 * and original authors of ShowCommand:
 * @author Robert Schönthal <seroscho@googlemail.com>
 * @author Jordi Boggiano <j.boggiano@seld.be>
 * @author Jérémy Romey <jeremyFreeAgent>
 * @author Mihai Plasoianu <mihai@plasoianu.de>
 */
class ExtendedOutdatedCommand extends BaseCommand
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

    /** @var array<string, string> */
    private array $hostToTypeMap = [
        'github.com' => self::TYPE_GITHUB,
        'gitlab.com' => self::TYPE_GITLAB,
    ];

    protected function configure(): void
    {
        $this
            ->setName('extended-outdated')
            ->setDescription('Shows a list of installed packages that have updates available, including their latest version. If possible, it also shows URL to diff and changelog.')
            ->setDefinition(array(
                new InputOption('filter', null, InputOption::VALUE_REQUIRED, 'Name of package(s) including a wildcard (*) to filter lists of packages.'),
                new InputOption('locked', null, InputOption::VALUE_NONE, 'List all locked packages'),
                new InputOption('ignore', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Ignore specified package(s). Use it with the --outdated option if you don\'t want to be informed about new versions of some packages.'),
                new InputOption('minor-only', 'm', InputOption::VALUE_NONE, 'Show only packages that have minor SemVer-compatible updates. Use with the --outdated option.'),
                new InputOption('direct', 'D', InputOption::VALUE_NONE, 'Shows only packages that are directly required by the root package'),
                new InputOption('strict', null, InputOption::VALUE_NONE, 'Return a non-zero exit code when there are outdated packages'),
                new InputOption('format', 'f', InputOption::VALUE_REQUIRED, 'Format of the output: text or json', 'text'),
                new InputOption('no-dev', null, InputOption::VALUE_NONE, 'Disables search in require-dev packages.'),
                new InputOption('ignore-platform-req', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Ignore a specific platform requirement (php & ext- packages). Use with the --outdated option'),
                new InputOption('ignore-platform-reqs', null, InputOption::VALUE_NONE, 'Ignore all platform requirements (php & ext- packages). Use with the --outdated option'),
                new InputOption('host-to-type', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Add hosts to type map. Use pipe to separate host and type, e.g. --host-to-type="my.gitlab.org|gitlab"'),
            ))
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        /** @var Composer $composer */
        $composer = $this->getComposer();
        /** @var string $vendorDir */
        $vendorDir = $composer->getConfig()->get('vendor-dir');

        /** @var string[] $hostsToTypes */
        $hostsToTypes = $input->getOption('host-to-type');
        foreach ($hostsToTypes as $hostToType) {
            [$host, $type] = explode('|', $hostToType, 2);
            $this->hostToTypeMap[$host] = $type;
        }

        $io = $this->getIO();

        /** @var string $format */
        $format = $input->getOption('format');
        if (!in_array($format, ['text', 'json'])) {
            $io->writeError(sprintf('Unsupported format "%s". See help for supported formats.', $format));
            return 1;
        }

        /** @var bool|string $ignorePlatformReqs */
        $ignorePlatformReqs = $input->getOption('ignore-platform-reqs') ?: ($input->getOption('ignore-platform-req') ?: false);

        // init repos
        /** @var array<string, string|false> $platformOverrides */
        $platformOverrides = $composer->getConfig()->get('platform') ?: [];
        $platformRepo = new PlatformRepository([], $platformOverrides);
        $lockedRepo = null;

        if ($input->getOption('locked')) {
            $locker = $composer->getLocker();
            if (!$locker || !$locker->isLocked()) {
                throw new UnexpectedValueException('A valid composer.json and composer.lock files is required to run this command with --locked');
            }
            $lockedRepo = $locker->getLockedRepository(!$input->getOption('no-dev'));
            $repos = $installedRepo = new InstalledRepository(array($lockedRepo));
        } else {
            $rootPkg = $composer->getPackage();
            $repos = $installedRepo = new InstalledRepository(array($composer->getRepositoryManager()->getLocalRepository()));

            if ($input->getOption('no-dev')) {
                $packages = $this->filterRequiredPackages($installedRepo, $rootPkg);
                $repos = $installedRepo = new InstalledRepository(array(new InstalledArrayRepository(array_map(function ($pkg) {
                    return clone $pkg;
                }, $packages))));
            }

            if (!$installedRepo->getPackages() && ($rootPkg->getRequires() || $rootPkg->getDevRequires())) {
                $io->writeError('<warning>No dependencies installed. Try running composer install or update.</warning>');
            }
        }

        /** @var string|null $packageFilter */
        $packageFilter = $input->getOption('filter');

        // list packages
        $packages = [];
        $packageFilterRegex = null;
        if (null !== $packageFilter) {
            $packageFilterRegex = '{^'.str_replace('\\*', '.*?', preg_quote($packageFilter)).'$}i';
        }

        $packageListFilter = [];
        if ($input->getOption('direct')) {
            $packageListFilter = $this->getRootRequires($composer);
        }

        foreach ($repos->getRepositories() as $repo) {
            if ($repo === $platformRepo) {
                $type = 'platform';
            } elseif ($lockedRepo !== null && $repo === $lockedRepo) {
                $type = 'locked';
            } elseif ($repo === $installedRepo || in_array($repo, $installedRepo->getRepositories(), true)) {
                $type = 'installed';
            } else {
                $type = 'available';
            }
            if ($repo instanceof ComposerRepository) {
                foreach ($repo->getPackageNames($packageFilter) as $name) {
                    $packages[$type][$name] = $name;
                }
            } else {
                foreach ($repo->getPackages() as $package) {
                    if (!isset($packages[$type][$package->getName()])
                        || !is_object($packages[$type][$package->getName()])
                        || version_compare($packages[$type][$package->getName()]->getVersion(), $package->getVersion(), '<')
                    ) {
                        while ($package instanceof AliasPackage) {
                            $package = $package->getAliasOf();
                        }
                        if (!$packageFilterRegex || Preg::isMatch($packageFilterRegex, $package->getName())) {
                            if (!$packageListFilter || in_array($package->getName(), $packageListFilter, true)) {
                                $packages[$type][$package->getName()] = $package;
                            }
                        }
                    }
                }
                if ($repo === $platformRepo) {
                    foreach ($platformRepo->getDisabledPackages() as $name => $package) {
                        $packages[$type][$name] = $package;
                    }
                }
            }
        }

        /** @var bool $showMinorOnly */
        $showMinorOnly = $input->getOption('minor-only');

        /** @var string[] $ignore */
        $ignore = $input->getOption('ignore');
        $ignoredPackages = array_map('strtolower', $ignore);
        /** @var PackageInterface[] $latestPackages */
        $latestPackages = [];
        $exitCode = 0;
        $viewData = [];
        foreach (['platform' => true, 'locked' => true, 'available' => false, 'installed' => true] as $type => $showVersion) {
            if (isset($packages[$type])) {
                ksort($packages[$type]);

                if ($showVersion) {
                    foreach ($packages[$type] as $package) {
                        if (is_object($package)) {
                            $latestPackage = $this->findLatestPackage($package, $composer, $platformRepo, $showMinorOnly, $ignorePlatformReqs);
                            if ($latestPackage === null) {
                                continue;
                            }

                            $latestPackages[$package->getPrettyName()] = $latestPackage;
                        }
                    }
                }

                $hasOutdatedPackages = false;

                $viewData[$type] = [];
                foreach ($packages[$type] as $package) {
                    $packageViewData = [];
                    if (is_object($package)) {
                        $latestPackage = null;
                        if (isset($latestPackages[$package->getPrettyName()])) {
                            $latestPackage = $latestPackages[$package->getPrettyName()];
                        }

                        // Determine if Composer is checking outdated dependencies and if current package should trigger non-default exit code
                        $packageIsUpToDate = $latestPackage && $latestPackage->getFullPrettyVersion() === $package->getFullPrettyVersion() && (!$latestPackage instanceof CompletePackageInterface || !$latestPackage->isAbandoned());
                        $packageIsIgnored = \in_array($package->getPrettyName(), $ignoredPackages, true);
                        if ($packageIsUpToDate || $packageIsIgnored) {
                            continue;
                        }

                        if ($input->getOption('strict')) {
                            $hasOutdatedPackages = true;
                        }

                        $packageViewData['name'] = $package->getPrettyName();
                        $packageViewData['version'] = $package->getFullPrettyVersion();
                        $packageViewData['latest'] = $latestPackage ? $latestPackage->getFullPrettyVersion() : '';
                        $packageViewData['latest-status'] = $latestPackage ? $this->getUpdateStatus($latestPackage, $package) : '';

                        $urls = [
                            self::URL_DIFF => $latestPackage ? $this->createDiffUrl($package, $latestPackage) : null,
                            self::URL_CHANGELOG => $latestPackage ? $this->createChangelogUrl($package, $latestPackage, $vendorDir) : null,
                        ];

                        $packageViewData['urls'] = array_filter($urls);

                        if ($latestPackage instanceof CompletePackageInterface && $latestPackage->isAbandoned()) {
                            $replacement = is_string($latestPackage->getReplacementPackage())
                                ? 'Use ' . $latestPackage->getReplacementPackage() . ' instead'
                                : 'No replacement was suggested';
                            $packageWarning = sprintf(
                                'Package %s is abandoned, you should avoid using it. %s.',
                                $package->getPrettyName(),
                                $replacement
                            );
                            $packageViewData['warning'] = $packageWarning;
                        }
                        $viewData[$type][] = $packageViewData;
                    }
                }

                if ($input->getOption('strict') && $hasOutdatedPackages) {
                    $exitCode = 1;
                    break;
                }
            }
        }

        if ('json' === $format) {
            $io->write(JsonFile::encode($viewData));
        } else {
            if (array_filter($viewData)) {
                if (!$io->isDecorated()) {
                    $io->writeError('Legend:');
                    $io->writeError('! patch or minor release available - update recommended');
                    $io->writeError('~ major release available - update possible');
                } else {
                    $io->writeError('<info>Color legend:</info>');
                    $io->writeError('- <highlight>patch or minor</highlight> release available - update recommended');
                    $io->writeError('- <comment>major</comment> release available - update possible');
                }
            }

            $outdatedPackages = array_merge(...array_values($viewData));

            $outdatedPackagesCount = count($outdatedPackages);
            if ($outdatedPackagesCount === 0) {
                return 0;
            }

            $table = new Table($output);
            $table->setheaders(['Package', 'Actual version', 'Latest version', 'Notes']);

            $i = 0;
            foreach ($outdatedPackages as $package) {
                $notes = $package['urls'];
                if (isset($package['warning'])) {
                    $notes[] = '<warning>' . $package['warning'] . '</warning>';
                }
                $package['notes'] = implode("\n", $notes);

                $latestVersion = $package['latest'];
                $style = $this->updateStatusToVersionStyle($package['latest-status']);
                if (!$io->isDecorated()) {
                    $latestVersion = str_replace(['up-to-date', 'semver-safe-update', 'update-possible'], ['=', '!', '~'], $package['latest-status']) . ' ' . $latestVersion;
                }
                $package['latest'] = ($style ? '<' . $style . '>' : '') . $latestVersion . ($style ? '</' . $style . '>' : '');

                unset($package['latest-status']);
                unset($package['urls']);
                unset($package['warning']);

                $table->addRow($package);
                if ($i + 1 < $outdatedPackagesCount) {
                    $table->addRow(new TableSeparator());
                }
                $i++;
            }

            $table->render();
        }

        return $exitCode;
    }

    /**
     * @return string[]
     */
    private function getRootRequires(Composer $composer): array
    {
        $rootPackage = $composer->getPackage();

        return array_map(
            'strtolower',
            array_keys(array_merge($rootPackage->getRequires(), $rootPackage->getDevRequires()))
        );
    }

    private function updateStatusToVersionStyle(string $updateStatus): string
    {
        // 'up-to-date' is printed green
        // 'semver-safe-update' is printed red
        // 'update-possible' is printed yellow
        return str_replace(['up-to-date', 'semver-safe-update', 'update-possible'], ['info', 'highlight', 'comment'], $updateStatus);
    }

    private function getUpdateStatus(PackageInterface $latestPackage, PackageInterface $package): string
    {
        if ($latestPackage->getFullPrettyVersion() === $package->getFullPrettyVersion()) {
            return 'up-to-date';
        }

        $constraint = $package->getVersion();
        if (0 !== strpos($constraint, 'dev-')) {
            $constraint = '^'.$constraint;
        }
        if ($latestPackage->getVersion() && Semver::satisfies($latestPackage->getVersion(), $constraint)) {
            // it needs an immediate semver-compliant upgrade
            return 'semver-safe-update';
        }

        // it needs an upgrade but has potential BC breaks so is not urgent
        return 'update-possible';
    }

    /**
     * Given a package, this finds the latest package matching it
     *
     * @param bool|string $ignorePlatformReqs
     */
    private function findLatestPackage(PackageInterface $package, Composer $composer, PlatformRepository $platformRepo, bool $minorOnly = false, $ignorePlatformReqs = false): ?PackageInterface
    {
        // find the latest version allowed in this repo set
        $name = $package->getName();
        $versionSelector = new VersionSelector($this->getRepositorySet($composer), $platformRepo);
        /** @var string $stability */
        $stability = $composer->getPackage()->getMinimumStability();
        $flags = $composer->getPackage()->getStabilityFlags();
        if (isset($flags[$name])) {
            $stability = array_search($flags[$name], BasePackage::$stabilities, true);
        }

        /** @var string $bestStability */
        $bestStability = $stability;
        if ($composer->getPackage()->getPreferStable()) {
            $bestStability = $package->getStability();
        }

        $targetVersion = null;
        if (0 === strpos($package->getVersion(), 'dev-')) {
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

    private function getRepositorySet(Composer $composer): RepositorySet
    {
        if (!$this->repositorySet) {
            $this->repositorySet = new RepositorySet($composer->getPackage()->getMinimumStability(), $composer->getPackage()->getStabilityFlags());
            $this->repositorySet->addRepository(new CompositeRepository($composer->getRepositoryManager()->getRepositories()));
        }

        return $this->repositorySet;
    }

    /**
     * Find package requires and child requires
     *
     * @param  PackageInterface[] $bucket
     * @return PackageInterface[]
     */
    private function filterRequiredPackages(RepositoryInterface $repo, PackageInterface $package, array $bucket = []): array
    {
        $requires = $package->getRequires();

        foreach ($repo->getPackages() as $candidate) {
            foreach ($candidate->getNames() as $name) {
                if (isset($requires[$name])) {
                    if (!in_array($candidate, $bucket, true)) {
                        $bucket[] = $candidate;
                        $bucket = $this->filterRequiredPackages($repo, $candidate, $bucket);
                    }
                    break;
                }
            }
        }

        return $bucket;
    }

    private function createDiffUrl(PackageInterface $package, PackageInterface $latestPackage): ?string
    {
        $sourceUrl = $package->getSourceUrl();
        $baseUrl = $this->getBaseUrl($sourceUrl);

        if ($baseUrl === null) {
            return null;
        }

        $type = $this->getType($baseUrl);
        $diffPattern = self::URL_MAP[$type][self::URL_DIFF] ?? '';

        [$actualVersion, $actualCommitHash] = explode(' ', $package->getFullPrettyVersion(true, PackageInterface::DISPLAY_SOURCE_REF), 2);
        [$latestVersion, $latestCommitHash] = explode(' ', $latestPackage->getFullPrettyVersion(true, PackageInterface::DISPLAY_SOURCE_REF), 2);

        if ($actualVersion === $latestVersion) {
            $actualVersion = $actualCommitHash;
            $latestVersion = $latestCommitHash;
        }

        if ($actualVersion === $latestVersion) {
            return null;
        }

        return str_replace(
            ['%baseUrl%', '%actualVersion%', '%latestVersion%'],
            [$baseUrl, $actualVersion, $latestVersion],
            $diffPattern
        );
    }

    private function createChangelogUrl(PackageInterface $package, PackageInterface $latestPackage, string $vendorDir): ?string
    {
        if ($package->getType() === 'metapackage') {
            return null;
        }

        $packagePath = $vendorDir . '/' . $package->getName();

        $changelog = $this->findChangelogFile($packagePath);
        if ($changelog === null) {
            return null;
        }

        $sourceUrl = $package->getSourceUrl();
        $baseUrl = $this->getBaseUrl($sourceUrl);

        if ($baseUrl === null) {
            return null;
        }

        $type = $this->getType($baseUrl);
        $changelogPattern = self::URL_MAP[$type][self::URL_CHANGELOG] ?? '';

        return str_replace(
            ['%baseUrl%', '%latestVersion%', '%changelogFile%'],
            [$baseUrl, preg_replace('/^dev-/', '', $latestPackage->getPrettyVersion()), $changelog],
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

    private function getBaseUrl(?string $sourceUrl): ?string
    {
        if (!$sourceUrl) {
            return null;
        }
        return str_replace([':', 'git@', '.git', '///'], ['/', 'https://', '', '://'], $sourceUrl);
    }

    private function getType(string $baseUrl): ?string
    {
        $host = parse_url($baseUrl, PHP_URL_HOST);
        return $this->hostToTypeMap[$host] ?? null;
    }
}
