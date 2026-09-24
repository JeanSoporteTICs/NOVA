<?php

namespace App\Providers;

use App\Contracts\ProjectUserProviderInterface;
use App\Services\Database\UpgradeSafety;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Database\Events\MigrationStarted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;
use RedmineTic\Services\RedmineProjectUserProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     *
     * @return void
     */
    public function register()
    {
        if (class_exists(RedmineProjectUserProvider::class)) {
            $this->app->bind(ProjectUserProviderInterface::class, RedmineProjectUserProvider::class);
        }
    }

    /**
     * Bootstrap any application services.
     *
     * @return void
     */
    public function boot()
    {
        if ($this->app->runningInConsole()) {
            $this->app['events']->listen(CommandStarting::class, function ($event): void {
                if ($event->command === 'migrate') {
                    $connection = $event->input->hasParameterOption('--database') ? $event->input->getParameterOption('--database') : null;
                    app(UpgradeSafety::class)->assertMayUpgrade(DB::connection($connection ?: null));
                }
            });
            $this->app['events']->listen(MigrationStarted::class, function ($event): void {
                $file = (new \ReflectionClass($event->migration))->getFileName();
                if ($event->method === 'up' && basename($file, '.php') === UpgradeSafety::CLEANUP) {
                    app(UpgradeSafety::class)->assertCleanupIsEmpty(DB::connection($event->migration->getConnection()));
                }
            });
        }
        $this->loadViewsFrom(base_path('RedmineTic/views'), 'redmine_tic');
        View::addLocation(base_path('Nova/views'));
    }
}
