<?php

namespace Jaulz\Hoard\Commands;

use Illuminate\Console\Command;
use Jaulz\Hoard\Support\FindCacheableClasses;
use Jaulz\Hoard\Support\FindHoardableClasses;

class RefreshCommand extends Command
{
  /**
   * The name and signature of the console command.
   *
   * @var string
   */
  protected $signature = 'hoard:refresh {--filter= : Optional filter that will be applied to the class name}';

  /**
   * The console command description.
   *
   * @var string
   */
  protected $description = 'Rebuild the cache for one or more Eloquent models';

  /**
   * Execute the console command.
   *
   * @return mixed
   */
  public function handle()
  {
    $classNames = (new FindHoardableClasses(
      app_path()
    ))->getClassNames();

    // Iterate through all hoardable classes and rebuild cache
    collect($classNames)->each(function ($className) {
      // Load all models lazily
      $models = $className::lazy();
      $count = $models->count();

      // Check if we need to skip this class
      if (
        !$count ||
        (!!$this->option('filter') && !str_contains($className, $this->option('filter')))
      ) {
        return;
      }

      // Run through each model and rebuild cache
      $this->comment('Refresh "' . $className . '" caches');
      $bar = $this->output->createProgressBar($count);
      $bar->setFormat(
        '%current%/%max% [%bar%] %percent:3s%% (%elapsed:6s%/%estimated:-6s%): %message% <fg=white;bg=red>%warning%</>'
      );
      $bar->setMessage('');
      $bar->setMessage('', 'warning');
      $bar->start();
      $fixed = 0;
      $models->each(function ($model) use ($bar, &$fixed) {
        $keyName = $model->getKeyName();
        $key = $model->getKey();

        // Set information message to bar
        $message = $keyName . '=' . $key;
        $bar->setMessage($message);

        // Check differences between the model before and after
        $before = collect($model->getAttributes());
        $model->refreshHoard();
        $after = collect($model->refresh()->getAttributes());
        $difference = $before->diffAssoc($after)->toArray();

        // Increase number of fixed caches
        if (count($difference) > 0) {
          $fixed++;
        }

        // Progress bar
        $bar->advance();
      });

      // Finalize class
      if ($fixed > 0) {
        $bar->setMessage('(fixed ' . $fixed . ' models)', 'warning');
      }
      $bar->setMessage('completed');
      $bar->finish();
      $this->newLine();
    });
  }
}
