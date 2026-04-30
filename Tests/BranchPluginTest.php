<?php

declare(strict_types=1);

namespace Folivoro\Branch\Tests;

use Composer\Package\Link;
use Composer\Semver\Constraint\Constraint;
use Composer\Semver\Constraint\MultiConstraint;
use Composer\Semver\Intervals;
use Folivoro\Branch\BranchPlugin;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

class BranchPluginTest extends TestCase
{
    public function test_plugin_can_be_instantiated(): void
    {
        $plugin = new BranchPlugin();
        $this->assertInstanceOf(BranchPlugin::class, $plugin);
    }

    public function test_get_subscribed_events_returns_pre_events(): void
    {
        $events = BranchPlugin::getSubscribedEvents();

        $this->assertArrayHasKey('pre-install-cmd', $events);
        $this->assertArrayHasKey('pre-update-cmd', $events);
        $this->assertEquals('onPreInstallOrUpdate', $events['pre-install-cmd']);
        $this->assertEquals('onPreInstallOrUpdate', $events['pre-update-cmd']);
    }

    public function test_resolve_version_returns_dev_branch_name_for_dev_constraint(): void
    {
        $plugin = new BranchPlugin();
        $method = new ReflectionMethod($plugin, 'resolveVersion');
        $method->setAccessible(true);

        $constraint = new Constraint('==', 'dev-main');
        $link = new Link('root', 'sixmonkey/sloth', $constraint, 'requires', 'dev-main');
        $requires = ['sixmonkey/sloth' => $link];

        $result = $method->invoke($plugin, 'sixmonkey/sloth', $requires);

        $this->assertEquals('dev-main', $result);
    }

    public function test_resolve_version_returns_lower_bound_for_numeric_constraint(): void
    {
        $plugin = new BranchPlugin();
        $method = new ReflectionMethod($plugin, 'resolveVersion');
        $method->setAccessible(true);

        $constraint = new Constraint('>=', '1.0.0');
        $link = new Link('root', 'acme/widget', $constraint, 'requires', '^1.0.0');
        $requires = ['acme/widget' => $link];

        $result = $method->invoke($plugin, 'acme/widget', $requires);

        $this->assertEquals('1.0.0', $result);
    }

    public function test_resolve_version_handles_multiconstraint_with_dev_branch(): void
    {
        $plugin = new BranchPlugin();
        $method = new ReflectionMethod($plugin, 'resolveVersion');
        $method->setAccessible(true);

        $constraint1 = new Constraint('>=', '1.0.0');
        $constraint2 = new Constraint('==', 'dev-develop');
        $multi = new MultiConstraint([$constraint1, $constraint2], false);
        $link = new Link('root', 'acme/gizmo', $multi, 'requires', '>=1.0.0 || dev-develop');
        $requires = ['acme/gizmo' => $link];

        $result = $method->invoke($plugin, 'acme/gizmo', $requires);

        $this->assertEquals('dev-develop', $result);
    }

    public function test_resolve_version_returns_null_for_missing_package(): void
    {
        $plugin = new BranchPlugin();
        $method = new ReflectionMethod($plugin, 'resolveVersion');
        $method->setAccessible(true);

        $result = $method->invoke($plugin, 'missing/package', []);

        $this->assertNull($result);
    }

    public function test_read_local_version_returns_version_from_composer_json(): void
    {
        $plugin = new BranchPlugin();
        $method = new ReflectionMethod($plugin, 'readLocalVersion');
        $method->setAccessible(true);

        $tmpDir = sys_get_temp_dir() . '/branch-test-' . uniqid();
        mkdir($tmpDir);
        file_put_contents($tmpDir . '/composer.json', json_encode([
            'name' => 'test/package',
            'version' => '2.5.0',
        ]));

        $result = $method->invoke($plugin, $tmpDir, 'test/package');

        $this->assertEquals('2.5.0', $result);

        unlink($tmpDir . '/composer.json');
        rmdir($tmpDir);
    }

    public function test_read_local_version_returns_null_when_no_version_set(): void
    {
        $plugin = new BranchPlugin();
        $method = new ReflectionMethod($plugin, 'readLocalVersion');
        $method->setAccessible(true);

        $tmpDir = sys_get_temp_dir() . '/branch-test-' . uniqid();
        mkdir($tmpDir);
        file_put_contents($tmpDir . '/composer.json', json_encode([
            'name' => 'test/package',
        ]));

        $result = $method->invoke($plugin, $tmpDir, 'test/package');

        $this->assertNull($result);

        unlink($tmpDir . '/composer.json');
        rmdir($tmpDir);
    }

    public function test_read_local_version_returns_null_for_nonexistent_path(): void
    {
        $plugin = new BranchPlugin();
        $method = new ReflectionMethod($plugin, 'readLocalVersion');
        $method->setAccessible(true);

        $result = $method->invoke($plugin, '/nonexistent/path', 'missing/package');

        $this->assertNull($result);
    }
}
