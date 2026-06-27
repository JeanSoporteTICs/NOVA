<?php

namespace App\Providers;

use App\Contracts\ProjectUserProviderInterface;
use Illuminate\Support\Facades\View;
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
        if (class_exists(\RedmineTic\Services\RedmineProjectUserProvider::class)) {
            $this->app->bind(ProjectUserProviderInterface::class, \RedmineTic\Services\RedmineProjectUserProvider::class);
        }
    }

    /**
     * Bootstrap any application services.
     *
     * @return void
     */
    public function boot()
    {
        $this->loadViewsFrom(base_path('RedmineTic/views'), 'redmine_tic');
        View::addLocation(base_path('Nova/views'));
    }
}
