<?php
namespace Tests\Acceptance\Models;

use Illuminate\Contracts\Database\Eloquent\Builder;
use Jaulz\Hoard\Traits\IsHoardableTrait;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Jaulz\Hoard\Scopes\HoardScope;

class Comment extends Model
{
    use IsHoardableTrait;
    use SoftDeletes;

    public static function boot()
    {
        parent::boot();

        static::addGlobalScope(new HoardScope(function (Builder $builder) {
            $builder->select('*');
        }));
    }
}
