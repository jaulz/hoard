<?php

namespace Jaulz\Hoard\Traits;

use Illuminate\Support\Facades\DB;

trait IsHoardableTrait
{
  /**
   * Boot the trait.
   */
  public static function bootIsHoardableTrait()
  {

  }

  /**
   * Refresh cache for the model.
   *
   * @param ?bool $native
   * @return array
   */
  public function refreshHoard()
  {
    return DB::select('SELECT hoard_refresh_all(?, ?, ?)', [$this->getTable(), $this->getKeyName(), $this->getKeyName() . ' = ' . $this->getKey()]);
  }
}
