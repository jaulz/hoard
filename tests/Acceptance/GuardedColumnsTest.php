<?php

namespace Tests\Acceptance;

use Tests\Acceptance\Models\GuardedUser;

class GuardedColumnsTest extends AcceptanceTestCase
{
    public function testGuardedUser()
    {
        $user = GuardedUser::create([
            'first_name' => 'Stuart',
            'last_name' => 'Jones',
        ]);

        $this->assertEquals('Stuart', $user->first_name);
        $this->assertEquals('Jones', $user->last_name);
    }
}
