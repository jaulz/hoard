<?php

namespace Tests\Acceptance;

class LaravelCacheTest extends AcceptanceTestCase
{
    protected function getEnvironmentSetUp($app)
    {
        $app['config']->set('database.default', 'test');
        $app['config']->set('database.connections.test', array(
            'driver'   => 'sqlite',
            'database' => ':memory:'
        ));
    }
}
