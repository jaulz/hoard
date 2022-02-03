<?php

namespace Tests\Acceptance;

use Jaulz\Hoard\HoardSchema;
use Jaulz\Hoard\HoardServiceProvider;
use Orchestra\Testbench\TestCase;

class PrepareConditionsTestCase extends TestCase
{
    public function setUp(): void
    {
        parent::setUp();

        $serviceProvider = new HoardServiceProvider($this->app);
        $serviceProvider->boot();
    }

    protected function getEnvironmentSetUp($app)
    {
        $app['config']->set('database.default', 'test');
        $app['config']->set('database.connections.test', array(
            'driver'   => 'pgsql',
            'database' => 'hoard'
        ));
    }

    public function testNumbers()
    {
        $this->assertEquals(HoardSchema::prepareConditions([
            'parent_id' => 1,
          ]), '"parent_id" = 1');
    }

    public function testStrings()
    {
        $this->assertEquals(HoardSchema::prepareConditions([
            'parent_id' => 'test',
          ]), '"parent_id" = \'test\'');
    }

    public function testNull()
    {
        $this->assertEquals(HoardSchema::prepareConditions([
            'parent_id' => null,
          ]), '"parent_id" IS NULL');
          $this->assertEquals(HoardSchema::prepareConditions([
            ['parent_id', 'IS NOT', null]
            ]), '"parent_id" IS NOT NULL');
    }
  }