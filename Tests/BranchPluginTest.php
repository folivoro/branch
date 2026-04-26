<?php

declare(strict_types=1);

namespace Folivoro\Branch\Tests;

use Composer\Semver\Constraint\Constraint;
use Composer\Semver\Intervals;
use Folivoro\Branch\BranchPlugin;
use PHPUnit\Framework\TestCase;

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

        $this->assertArrayHasKey('pre-install', $events);
        $this->assertArrayHasKey('pre-update', $events);
        $this->assertEquals('onPreInstallOrUpdate', $events['pre-install']);
        $this->assertEquals('onPreInstallOrUpdate', $events['pre-update']);
    }
}