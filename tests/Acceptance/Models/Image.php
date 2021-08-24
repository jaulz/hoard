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
                'relation' => 'imageable',
                'summary' => 'images_count',
            ],
        ];
    }

    /**
     * Get the parent imageable model (user or post).
     */
    public function imageable()
    {
        return $this->morphTo();
    }
}
