<?php

namespace MlSolutions\NovaLogsView\Tests;

abstract class TestCase extends \Orchestra\Testbench\TestCase
{
    protected function getPackageProviders($app): array
    {
        return [\MlSolutions\NovaLogsView\ToolServiceProvider::class];
    }

    protected function getEnvironmentSetUp($app): void
    {
        $app['config']->set('nova-logs-view', require __DIR__.'/../config/nova-logs-view.php');
    }
}
