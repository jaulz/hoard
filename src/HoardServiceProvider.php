<?php

namespace Jaulz\Hoard;

use Illuminate\Support\ServiceProvider;
use Jaulz\Hoard\Commands\CacheCommand;
use Jaulz\Hoard\Commands\ClearCommand;
use Jaulz\Hoard\Commands\RefreshCommand;

class HoardServiceProvider extends ServiceProvider
{
    /**
     * Boots the service provider.
     */
    public function boot()
    {
        $this->publishes([
            __DIR__ . '/../database/migrations/' => database_path('migrations')
        ], 'hoard-migrations');

        $this->commands([
            ClearCommand::class,
            CacheCommand::class,
            RefreshCommand::class,
        ]);
    }
}
