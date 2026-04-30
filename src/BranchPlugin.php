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
use Composer\Semver\Constraint\Constraint;
use Composer\Semver\Constraint\MultiConstraint;
use Composer\Script\ScriptEvents;

/**
 * Composer plugin that enables local package development without modifying composer.json.
 *
 * This plugin reads a `branch-local.json` file from the project root and registers
 * each specified package path as a Composer PathRepository. It automatically extracts
 * version constraints from the project's composer.json requirements.
 *
 * Usage:
 * Create a `branch-local.json` next to your `composer.json`:
 * ```json
 * {
 *     "vendor/package": "../relative/or/absolute/path"
 * }
 * ```
 *
 * @author folivoro
 * @license MIT
 */
class BranchPlugin implements PluginInterface, EventSubscriberInterface
{
    /**
     * The Composer instance.
     *
     * @var Composer
     */
    private Composer $composer;

    /**
     * The IO interface for output messages.
     *
     * @var IOInterface|null
     */
    private ?IOInterface $io = null;

    /**
     * Activates the plugin and stores references to Composer and IO.
     *
     * Called by Composer when the plugin is loaded. Displays a startup message.
     *
     * @param Composer $composer The Composer instance
     * @param IOInterface $io The IO interface for console output
     *
     * @return void
     */
    public function activate(Composer $composer, IOInterface $io): void
    {
        $this->composer = $composer;
        $this->io = $io;
        $this->io->write('<fg=cyan>🦥 folivoro/branch plugin activated</>');
    }

    /**
     * Deactivates the plugin.
     *
     * Called when Composer deactivates the plugin. Currently no cleanup is required.
     *
     * @param Composer $composer The Composer instance
     * @param IOInterface $io The IO interface for console output
     *
     * @return void
     */
    public function deactivate(Composer $composer, IOInterface $io): void
    {
    }

    /**
     * Uninstalls the plugin.
     *
     * Called when Composer uninstalls the plugin. Currently no cleanup is required.
     *
     * @param Composer $composer The Composer instance
     * @param IOInterface $io The IO interface for console output
     *
     * @return void
     */
    public function uninstall(Composer $composer, IOInterface $io): void
    {
    }

    /**
     * Returns the list of events this plugin subscribes to.
     *
     * The plugin listens to both `pre-install-cmd` and `pre-update-cmd` events
     * to register local path repositories before Composer resolves dependencies.
     *
     * @return array<string, string> Associative array mapping event names to handler methods
     */
    public static function getSubscribedEvents(): array
    {
        return [
            ScriptEvents::PRE_INSTALL_CMD => 'onPreInstallOrUpdate',
            ScriptEvents::PRE_UPDATE_CMD => 'onPreInstallOrUpdate',
        ];
    }

    /**
     * Handles the pre-install and pre-update events.
     *
     * Loads the `branch-local.json` configuration, resolves paths and versions for
     * each package, and registers them as PathRepositories with Composer's repository manager.
     *
     * @return void
     */
    public function onPreInstallOrUpdate(): void
    {
        $localConfig = $this->loadLocalConfig();

        if ($localConfig === null) {
            $this->io->write('<fg=yellow>📭 folivoro/branch: No branch-local.json found, skipping</>');
            return;
        }

        $this->io->write(sprintf('<fg=cyan>🔧 folivoro/branch: Resolving %d package(s) from branch-local.json</>', count($localConfig)));

        $repoManager = $this->composer->getRepositoryManager();
        $config = $this->composer->getConfig();
        $requires = $this->composer->getPackage()->getRequires();
        $loop = $this->composer->getLoop();


        foreach ($localConfig as $packageName => $path) {
            if (!is_string($path)) {
                continue;
            }

            $this->io->write(sprintf('<fg=gray>  → %s</>', $packageName));

            $fullPath = $this->resolvePath($path);
            if (!file_exists($fullPath)) {
                $this->io->write(sprintf('<warning>⚠️ folivoro/branch: Path "%s" not found for package "%s"</warning>', $fullPath, $packageName));
                continue;
            }

            $version = $this->readLocalVersion($fullPath, $packageName)
                ?? $this->resolveVersion($packageName, $requires);

            $this->io->write(sprintf('<fg=green>  ✓ %s → %s (version: %s)</>', $packageName, $fullPath, $version ?? '<fg=yellow>auto</>'));

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

        $this->io->write('<fg=cyan>📦 folivoro/branch: All local packages registered</>');
    }

    /**
     * Loads and parses the `branch-local.json` configuration file.
     *
     * Searches for the file in the project root directory. If found, reads and
     * decodes the JSON content into an associative array.
     *
     * @return array<string, mixed>|null The parsed configuration array, or null if not found or invalid
     */
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

        $this->io->write(sprintf('<fg=cyan>📄 folivoro/branch: Found branch-local.json at %s</>', $localJsonPath));

        $content = file_get_contents($localJsonPath);
        if ($content === false) {
            return null;
        }

        $config = json_decode($content, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->io->write('<warning>❌ folivoro/branch: Invalid branch-local.json format</warning>');
            return null;
        }

        return $config;
    }

    /**
     * Finds the project root directory.
     *
     * Walks up the directory tree from the current working directory looking for
     * a `composer.json` file. Falls back to the Composer global config home directory
     * or the current working directory if no composer.json is found.
     *
     * @return string|null The absolute path to the project root, or null if determination fails
     */
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

    /**
     * Resolves a path from the configuration to an absolute filesystem path.
     *
     * If the path is already absolute (starts with `/`), it is returned as-is.
     * Relative paths are resolved against the current working directory.
     *
     * @param string $path The path to resolve (relative or absolute)
     *
     * @return string The resolved absolute path
     */
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

    /**
     * Resolves the version constraint for a package from the project's requirements.
     *
     * Extracts the appropriate version string from the composer.json require constraint:
     * - For dev constraints (e.g., `dev-main`), returns the branch name directly
     * - For multi-constraints, checks for any dev-branch constraints first
     * - For numeric constraints, returns the lower bound version
     *
     * @param string $packageName The package name to resolve
     * @param array<string, \Composer\Package\Link> $requires The project's require constraints
     *
     * @return string|null The resolved version string, or null if not found
     */
    private function resolveVersion(string $packageName, array $requires): ?string
    {
        if (!isset($requires[$packageName])) {
            return null;
        }

        $constraint = $requires[$packageName]->getConstraint();
        if ($constraint === null) {
            return null;
        }

        if ($constraint instanceof Constraint) {
            $version = $constraint->getVersion();
            if (str_starts_with($version, 'dev-')) {
                $this->log(sprintf('<fg=yellow>🌿 Using dev-branch "%s" for %s</>', $version, $packageName));
                return $version;
            }
        }

        if ($constraint instanceof MultiConstraint) {
            foreach ($constraint->getConstraints() as $sub) {
                if ($sub instanceof Constraint && str_starts_with($sub->getVersion(), 'dev-')) {
                    $version = $sub->getVersion();
                    $this->log(sprintf('<fg=yellow>🌿 Using dev-branch "%s" for %s (multi-constraint)</>', $version, $packageName));
                    return $version;
                }
            }
        }

        $version = $constraint->getLowerBound()->getVersion();
        $this->log(sprintf('<fg=gray>🔢 Using lower bound "%s" for %s</>', $version, $packageName));
        return $version;
    }

    /**
     * Reads the version from a local package's composer.json file.
     *
     * Looks for an explicit `version` field in the composer.json at the given path.
     * This is useful for local packages that need to override automatic version detection.
     *
     * @param string $path The path to the local package directory
     * @param string $packageName The package name (used for logging)
     *
     * @return string|null The version string if found, or null
     */
    private function readLocalVersion(string $path, string $packageName): ?string
    {
        $composerJsonPath = rtrim($path, '/') . '/composer.json';
        if (!file_exists($composerJsonPath)) {
            return null;
        }

        $content = file_get_contents($composerJsonPath);
        if ($content === false) {
            return null;
        }

        $data = json_decode($content, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return null;
        }

        if (isset($data['version'])) {
            $this->log(sprintf('<fg=yellow>📖 Read version "%s" from local composer.json for %s</>', $data['version'], $packageName));
            return $data['version'];
        }

        return null;
    }

    /**
     * Writes a message to the IO output if the IO interface is available.
     *
     * @param string $message The message to write (may include Composer formatting tags)
     *
     * @return void
     */
    private function log(string $message): void
    {
        if ($this->io !== null) {
            $this->io->write($message);
        }
    }
}
