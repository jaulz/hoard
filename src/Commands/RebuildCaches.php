<?php

namespace Jaulz\Eloquence\Commands;

use Illuminate\Console\Command;
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
    ))->getAllIsCacheableTraitClasses();

    // Iterate through all cacheable classes and rebuild cache
    collect($classNames)->each(function ($className) {
      // Load all instances lazily
      $models = $className::lazy();
      $count = $models->count();

      // Check if we need to skip this class
      if (
        !$count ||
        (!!$this->option('class') && $className !== $this->option('class'))
      ) {
        $this->comment("Rebuild $className caches skipped");
        return;
      }

      // Run through each model and rebuild cache
      $this->comment("Rebuild $className caches");
      $bar = $this->output->createProgressBar($count);
      $bar->setFormat(
        '%current%/%max% [%bar%] %percent:3s%% (%elapsed:6s%/%estimated:-6s%): %message%'
      );
      $bar->setMessage('');
      $bar->start();
      $models->each(function ($model) use ($bar) {
        $keyName = $model->getKeyName();
        $key = $model->getKey();

        // Set information message to bar
        $bar->setMessage("$keyName=$key");

        // Check differences between the model before and after
        $before = collect($model->getAttributes());
        $model->rebuildCache();
        $after = collect($model->refresh()->getAttributes());
        $difference = $before->diffAssoc($after)->toArray();

        // Progress bar
        $bar->advance();
      });

      $bar->setMessage('completed');
      $bar->finish();
      $this->newLine();
    });
  }
}
