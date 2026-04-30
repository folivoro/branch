<?php

declare(strict_types=1);

namespace Folivoro\Branch;

use Composer\Composer;
use Composer\EventDispatcher\EventSubscriberInterface;
use Composer\IO\IOInterface;
use Composer\Plugin\PluginInterface;
use Composer\Repository\PathRepository;
use Composer\Util\HttpDownloader;
use Composer\Util\Loop;
use Composer\Util\ProcessExecutor;
use Composer\EventDispatcher\EventDispatcher;
use Composer\Package\Version\VersionParser;
use Composer\Semver\Constraint\Constraint;
use Composer\Script\ScriptEvents;

class BranchPlugin implements PluginInterface, EventSubscriberInterface
{
    private Composer $composer;
    private IOInterface $io;

    public function activate(Composer $composer, IOInterface $io): void
    {
        $this->composer = $composer;
        $this->io = $io;
    }

    public function deactivate(Composer $composer, IOInterface $io): void
    {
    }

    public function uninstall(Composer $composer, IOInterface $io): void
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            ScriptEvents::PRE_INSTALL_CMD => 'onPreInstallOrUpdate',
            ScriptEvents::PRE_UPDATE_CMD => 'onPreInstallOrUpdate',
        ];
    }

    public function onPreInstallOrUpdate(): void
    {
        $localConfig = $this->loadLocalConfig();

        if ($localConfig === null || !isset($localConfig)) {
            return;
        }

        $repoManager = $this->composer->getRepositoryManager();
        $config = $this->composer->getConfig();
        $requires = $this->composer->getPackage()->getRequires();
        $loop = $this->composer->getLoop();
        $versionParser = new VersionParser();


        foreach ($localConfig as $packageName => $path) {
            if (!is_string($path)) {
                continue;
            }

            $fullPath = $this->resolvePath($path);
            if (!file_exists($fullPath)) {
                $this->io->write(sprintf('<warning>folivoro/branch: Path "%s" not found for package "%s"</warning>', $fullPath, $packageName));
                continue;
            }

            $version = $this->resolveVersion($packageName, $requires, $versionParser);

            $repository = new PathRepository(
                [
                    'url' => $fullPath,
                    'options' => $version ? ['versions' => [$packageName => $version]] : [],
                ],
                $this->io,
                $config,
                $loop->getHttpDownloader(),
                $this->composer->getEventDispatcher(),
                $loop->getProcessExecutor()
            );

            $repoManager->prependRepository($repository);
        }
    }

    private function loadLocalConfig(): ?array
    {
        $projectRoot = $this->findProjectRoot();
        if ($projectRoot === null) {
            return null;
        }

        $localJsonPath = $projectRoot . '/branch-local.json';
        if (!file_exists($localJsonPath)) {
            return null;
        }

        $content = file_get_contents($localJsonPath);
        if ($content === false) {
            return null;
        }

        $config = json_decode($content, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->io->write('<warning>folivoro/branch: Invalid branch-local.json format</warning>');
            return null;
        }

        return $config;
    }

    private function findProjectRoot(): ?string
    {
        $cwd = getcwd();
        if ($cwd === false) {
            return null;
        }

        $dir = $cwd;
        $home = dirname(__DIR__, 2);
        $globalConfig = $home . '/config.json';

        while ($dir !== '' && $dir !== '.' && $dir !== '/') {
            $composerJsonPath = $dir . '/composer.json';
            if (file_exists($composerJsonPath)) {
                return $dir;
            }
            $dir = dirname($dir);
        }

        if (file_exists($globalConfig)) {
            $content = file_get_contents($globalConfig);
            if ($content !== false) {
                $config = json_decode($content, true);
                if (isset($config['home']) && is_dir($config['home'])) {
                    return $config['home'];
                }
            }
        }

        return $cwd;
    }

    private function resolvePath(string $path): string
    {
        if (str_starts_with($path, '/')) {
            return $path;
        }

        $cwd = getcwd();
        if ($cwd !== false) {
            return $cwd . '/' . $path;
        }

        return $path;
    }

    private function resolveVersion(string $packageName, array $requires, VersionParser $versionParser): ?string
    {
        if (!isset($requires[$packageName])) {
            return null;
        }

        $constraint = $requires[$packageName]->getConstraint();
        if ($constraint === null) {
            return null;
        }

        return $constraint->getLowerBound()->getVersion();
    }
}
