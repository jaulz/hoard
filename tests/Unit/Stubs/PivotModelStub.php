<?php
namespace Tests\Unit\Stubs;

use Jaulz\Eloquence\Behaviours\CamelCasing;

class PivotModelStub extends ParentModelStub
{
    use \Jaulz\Eloquence\Behaviours\CamelCasing;

    protected $attributes = [
        'first_name' => 'Kirk',
        'pivot_field' => 'whatever'
    ];
}
