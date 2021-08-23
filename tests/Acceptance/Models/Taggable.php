<?php

namespace Tests\Acceptance\Models;

use Illuminate\Database\Eloquent\Model;

use Illuminate\Database\Eloquent\Relations\MorphPivot;
use Jaulz\Eloquence\Traits\IsCacheableTrait;

class Taggable extends MorphPivot
{
    use IsCacheableTrait;
    
  /**
   * The table associated with the model.
   *
   * @var string
   */
  protected $table = 'taggables';
}
