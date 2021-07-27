<?php

namespace Jaulz\Eloquence\Commands;

use Illuminate\Console\Command;
use Jaulz\Eloquence\Behaviours\Cacheable\Cache;
use Jaulz\Eloquence\Support\FindCacheableClasses;

class RebuildCaches extends Command
{
  /**
   * The name and signature of the console command.
   *
   * @var string
   */
  protected $signature = 'caches:rebuild {--class= : Optional classes to update} {--dir= : Directory in which to look for classes}';

  /**
   * The console command description.
   *
   * @var string
   */
  protected $description = 'Rebuild the caches for one or more Eloquent models';

  /**
   * Create a new command instance.
   */
  public function __construct()
  {
    parent::__construct();
  }

  /**
   * Execute the console command.
   *
   * @return mixed
   */
  public function handle()
  {
    $directory = $this->option('dir') ?: app_path();
    $classNames = (new FindCacheableClasses(
      $directory
    ))->getAllCacheableClasses();

    // Iterate through all cacheable classes and rebuild cache
    collect($classNames)->each(function ($className) {
      return !!$this->option('class') ? $className === $this->option('class') : true;
    })->each(function ($className) {
      // Load all instances lazily
      $models = $className::lazy();
      $count = $models->count();
      $bar = $this->output->createProgressBar($count);
      $bar->setFormat('%current%/%max% [%bar%] %percent:3s%% (%elapsed:6s%/%estimated:-6s%): %message%');

      // Run through each model and rebuild cache
      $this->comment(
        "Rebuild $className caches"
      );
      $bar->start();
      $models->each(function ($model) use (
        $bar,
      ) {
        $keyName = $model->getKeyName();
        $key = $model->getKey();

        $bar->setMessage("$keyName=$key");

        $before = collect($model->getAttributes());
        $model->cache();
        $after = collect($model->refresh()->getAttributes());
        $difference = $before->diffAssoc($after)->toArray();

        $bar->advance();
      });

      $endTime = microtime(true);
      $bar->finish();
      $this->newLine();
    });
  }
}
