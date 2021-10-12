<?php

namespace Tests\Acceptance;

class NativeAcceptanceTest extends AcceptanceTestCase
{
    protected $native = true;

    protected function getEnvironmentSetUp($app)
    {
        $app['config']->set('database.default', 'test');
        $app['config']->set('database.connections.test', array(
            'driver'   => 'pgsql',
            'database' => 'hoard'
        ));
    }
}
