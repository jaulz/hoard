<?php

namespace Tests\Acceptance\Models;

use Illuminate\Database\Eloquent\Model;

use Illuminate\Database\Eloquent\Relations\MorphPivot;
use Jaulz\Hoard\Traits\IsHoardableTrait;

class Taggable extends MorphPivot
{
    use IsHoardableTrait;
    
  /**
   * The table associated with the model.
   *
   * @var string
   */
  protected $table = 'taggables';
}
