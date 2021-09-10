<?php

namespace Tests\Acceptance\Models;

use Jaulz\Hoard\Traits\IsHoardableTrait;
use Illuminate\Database\Eloquent\Model;

class Image extends Model
{
    use IsHoardableTrait;

    public static function hoard()
    {
        return [
            [
                'function' => 'COUNT',
                'foreignModelName' => [Post::class, User::class],
                'relationName' => 'imageable',
                'summaryName' => 'images_count',
            ],

            [
                'function' => 'COUNT',
                'relationName' => 'tags',
                'summaryName' => 'taggables_count',
            ],

            [
                'function' => 'MAX',
                'relationName' => 'tags',
                'summaryName' => 'last_created_at',
                'valueName' => 'created_at',
            ],

            [
                'function' => 'MIN',
                'relationName' => 'tags',
                'summaryName' => 'first_created_at',
                'valueName' => 'created_at',
            ],
        ];
    }

    public function imageable()
    {
        return $this->morphTo();
    }
    
    public function tags()
    {
        return $this->morphToMany(Tag::class, 'taggable')->using(Taggable::class);
    }
}
