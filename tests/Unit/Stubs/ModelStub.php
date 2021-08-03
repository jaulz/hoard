<?php
namespace Tests\Unit\Stubs;


class ModelStub extends ParentModelStub
{
    protected $attributes = [
        'first_name' => 'Kirk',
        'last_name' => 'Bushell',
        'address' => 'Home',
        'country_of_origin' => 'Australia'
    ];
}
