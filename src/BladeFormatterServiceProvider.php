<?php

namespace JoelStein\BladeFormatter;

use Illuminate\Support\ServiceProvider;
use JoelStein\BladeFormatter\Commands\FormatCommand;

class BladeFormatterServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/blade-formatter.php', 'blade-formatter');
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                FormatCommand::class,
            ]);

            $this->publishes([
                __DIR__.'/../config/blade-formatter.php' => config_path('blade-formatter.php'),
            ], 'blade-formatter-config');
        }
    }
}
