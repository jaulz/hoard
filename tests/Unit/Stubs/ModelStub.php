<?php
namespace Tests\Unit\Stubs;

use Eloquence\Behaviours\CamelCasing;

class ModelStub extends ParentModelStub
{

    protected $attributes = [
        'firstName' => 'Kirk',
        'lastName' => 'Bushell',
        'address' => 'Home',
        'countryOfOrigin' => 'Australia'
    ];
}
