<?php
namespace Tests\Unit\Stubs;

use Jaulz\Eloquence\Behaviours\CamelCasing;

class ModelStub extends ParentModelStub
{
    use \Jaulz\Eloquence\Behaviours\CamelCasing;

    protected $attributes = [
        'first_name' => 'Kirk',
        'last_name' => 'Bushell',
        'address' => 'Home',
        'country_of_origin' => 'Australia'
    ];
}
