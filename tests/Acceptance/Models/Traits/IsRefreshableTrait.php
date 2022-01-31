<?php

namespace Tests\Acceptance\Models\Traits;

trait IsRefreshableTrait
{
  /**
   * Reload the current model instance with fresh attributes from the database.
   *
   * @param  array|string  $with
   * @param  array|string  $scopes
   * @return $this
   */
  public function forceRefresh($with = [], $scopes = [])
  {
    if (!$this->exists) {
      return $this;
    }

    $query = $this->newQuery();

    foreach ($scopes as $scope) {
      $query->$scope();
    }

    $this->setRawAttributes(
      $this->setKeysForSelectQuery(
        $query
      )->firstOrFail()->attributes
    );

    $this->load(
      $with ?? collect($this->relations)
        ->keys()
        ->all()
    );

    $this->syncOriginal();

    return $this;
  }
}
