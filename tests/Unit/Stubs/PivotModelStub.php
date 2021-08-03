<?php
namespace Tests\Unit\Stubs;


class PivotModelStub extends ParentModelStub
{
    protected $attributes = [
        'first_name' => 'Kirk',
        'pivot_field' => 'whatever'
    ];
}
