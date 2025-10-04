<?php
namespace PicoInno\SimpleLog;

use Illuminate\Support\ServiceProvider;

class SimpleLogServiceProvider extends ServiceProvider
{
    public function boot()
    {
         // Load package migrations
        $this->loadMigrationsFrom(__DIR__.'/database/migrations');

        // Publish package configuration
        $this->publishes([
            __DIR__.'/config/activity_log.php' => config_path('activity_log.php'),
        ], 'config');

        // Merge package configuration
        $this->mergeConfigFrom(
            __DIR__.'/config/activity_log.php', 'simplelog'
        );

        $this->commands([
            \PicoInno\SimpleLog\Console\PurgeOldLogs::class,
        ]);


        

    }

    public function register()
    {

    }
}
