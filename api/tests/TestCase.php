<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    public function createApplication()
    {
        $app = parent::createApplication();
        // Legacy route fixtures use rollback transactions. Make their explicit
        // phpunit mode effective even when Composer/Artisan booted config before
        // PHPUnit's environment setup. Durable receipt fixtures opt in after
        // parent setup and use independent connections with real commits.
        $app['config']->set('native_traffic_receipts.enabled', false);
        return $app;
    }
}
