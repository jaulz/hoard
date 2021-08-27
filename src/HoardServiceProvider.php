<?php

namespace Jaulz\Hoard;

use Illuminate\Support\ServiceProvider;
use Jaulz\Hoard\Commands\ConfigCacheCommand;
use Jaulz\Hoard\Commands\RebuildCachesCommand;

class HoardServiceProvider extends ServiceProvider
{
    /**
     * Initialises the service provider, and here we attach our own blueprint
     * resolver to the schema, so as to provide the enhanced functionality.
     */
    public function boot()
    {
        $this->commands([
            ConfigCacheCommand::class,
            RebuildCachesCommand::class,
        ]);
    }
}
