<?php

namespace App\Providers;

use App\Contracts\ProjectUserProviderInterface;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     *
     * @return void
     */
    public function register()
    {
        if (class_exists(\RedmineTic\Services\Redmine\RedmineProjectUserProvider::class)) {
            $this->app->bind(ProjectUserProviderInterface::class, \RedmineTic\Services\Redmine\RedmineProjectUserProvider::class);
        }
    }

    /**
     * Bootstrap any application services.
     *
     * @return void
     */
    public function boot()
    {
        $redmineTicPath = rtrim((string) data_get(config('modules.redmine_tic', []), 'path', base_path('redmine_tic')), DIRECTORY_SEPARATOR);
        $this->loadViewsFrom($redmineTicPath . DIRECTORY_SEPARATOR . 'nova' . DIRECTORY_SEPARATOR . 'resources' . DIRECTORY_SEPARATOR . 'views', 'redmine_tic');
    }
}
