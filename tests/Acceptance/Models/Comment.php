<?php
namespace Tests\Acceptance\Models;

use Jaulz\Eloquence\Behaviours\CountCache\Countable;
use Jaulz\Eloquence\Behaviours\CamelCasing;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Comment extends Model
{
    use CamelCasing;
    use Countable;
    use SoftDeletes;

    public function countCaches()
    {
        return [
            'Tests\Acceptance\Models\Post',
            'Tests\Acceptance\Models\User',
        ];
    }
}
