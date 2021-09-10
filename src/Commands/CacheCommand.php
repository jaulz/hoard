<?php

namespace Jaulz\Hoard\Commands;

use Illuminate\Console\Command;
use Illuminate\Contracts\Console\Kernel as ConsoleKernelContract;
use Illuminate\Filesystem\Filesystem;
use Jaulz\Hoard\Support\FindCacheableClasses;
use Jaulz\Hoard\Support\FindHoardableClasses;
use LogicException;
use Throwable;

class CacheCommand extends Command
{
    /**
     * The console command name.
     *
     * @var string
     */
    protected $name = 'hoard:cache';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Create a cache file for faster configuration loading';

    /**
     * The filesystem instance.
     *
     * @var \Illuminate\Filesystem\Filesystem
     */
    protected $files;

    /**
     * Create a new config cache command instance.
     *
     * @param  \Illuminate\Filesystem\Filesystem  $files
     * @return void
     */
    public function __construct(Filesystem $files)
    {
        parent::__construct();

        $this->files = $files;
    }

    /**
     * Execute the console command.
     *
     * @return void
     *
     * @throws \LogicException
     */
    public function handle()
    {
        $this->call('hoard:clear');

        $config = $this->getFreshConfiguration();

        $path = app()->bootstrapPath('cache/hoard.php');

        $this->files->put(
            $path, '<?php return '.var_export($config, true).';'.PHP_EOL
        );

        try {
            require $path;
        } catch (Throwable $exception) {
            $this->files->delete($path);

            throw new LogicException('Your configuration files are not serializable.', 0, $exception);
        }

        $this->info('Configuration cached successfully!');
    }

    /**
     * Boot a fresh copy of the hoard configuration.
     *
     * @return array
     */
    protected function getFreshConfiguration()
    {
        $classNames = (new FindHoardableClasses(
          app_path()
        ))->getClassNames();
    
        // Iterate through all cacheable classes and gather their configurations
        $configurations = [];
        collect($classNames)->each(function ($className) use (&$configurations) {
            $configurations[$className] = $className::getForeignHoardConfigurations(true);
        });

        return $configurations;
    }
}