<?php

namespace Tests\Acceptance\Models;

use Jaulz\Eloquence\Traits\IsCacheableTrait;
use Illuminate\Database\Eloquent\Model;

class Image extends Model
{
    use IsCacheableTrait;

    public static function caches()
    {
        return [
            [
                'function' => 'COUNT',
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
