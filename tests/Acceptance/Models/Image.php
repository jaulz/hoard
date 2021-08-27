<?php

namespace Tests\Acceptance\Models;

use Jaulz\Hoard\Traits\IsCacheableTrait;
use Illuminate\Database\Eloquent\Model;

class Image extends Model
{
    use IsCacheableTrait;

    public static function hoard()
    {
        return [
            [
                'function' => 'COUNT',
                'foreignModelName' => [Post::class],
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
