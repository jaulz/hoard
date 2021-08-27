<?php

namespace Jaulz\Hoard;

use Illuminate\Support\ServiceProvider;
use Jaulz\Hoard\Commands\CacheCommand;
use Jaulz\Hoard\Commands\ClearCommand;

class HoardServiceProvider extends ServiceProvider
{
    /**
     * Initialises the service provider, and here we attach our own blueprint
     * resolver to the schema, so as to provide the enhanced functionality.
     */
    public function boot()
    {
        $this->commands([
            ClearCommand::class,
            CacheCommand::class,
            RecalculateCachesCommand::class,
        ]);
    }
}
