<?php

namespace CristhCasco\SgDnit;

use CristhCasco\SgDnit\Console\ImportDnitRuc;
use CristhCasco\SgDnit\Services\DnitRucService;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\ServiceProvider;

class SgDnitServiceProvider extends ServiceProvider
{
    public function register()
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/sg-dnit.php', 'sg-dnit');
        $this->app->singleton(DnitRucService::class);
    }

    public function boot()
    {
        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');

        if ($this->app->runningInConsole()) {
            $this->commands([ImportDnitRuc::class]);

            $this->publishes([
                __DIR__ . '/../config/sg-dnit.php' => config_path('sg-dnit.php'),
            ], 'sg-dnit-config');

            // Actualización mensual del listado (requiere "php artisan schedule:run" en el servidor)
            $this->callAfterResolving(Schedule::class, function (Schedule $schedule) {
                if (config('sg-dnit.schedule.enabled')) {
                    $schedule->command('dnit:importar')
                        ->monthlyOn((int) config('sg-dnit.schedule.day', 5), config('sg-dnit.schedule.time', '03:00'));
                }
            });
        }
    }
}
