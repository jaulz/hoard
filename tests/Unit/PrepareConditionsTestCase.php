<?php

namespace Tests\Unit;

use Illuminate\Support\Facades\DB;
use Jaulz\Hoard\HoardSchema;
use PHPUnit\Framework\TestCase;
use Mockery as m;

class PrepareConditionsTestCase extends TestCase
{
    public function setUp(): void
    {
        parent::setUp();

        $this->init();
    }

    public function tearDown(): void
    {
        m::close();
    }

    public function init()
    {
    }

    public function testNumbers()
    {
        $this->assertEquals(1, 1);
    }
}
