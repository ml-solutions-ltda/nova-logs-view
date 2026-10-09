<?php

namespace MlSolutions\NovaLogsView;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Laravel\Nova\Http\Middleware\Authenticate;
use Laravel\Nova\Nova;
use MlSolutions\NovaLogsView\Http\Middleware\Authorize;
use MlSolutions\NovaLogsView\Support\NovaCompatibility;

class ToolServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'nova-logs-view');

        $this->app->booted(function () {
            $this->routes();
        });

        $this->publishes([
            __DIR__.'/../config/nova-logs-view.php' => config_path('nova-logs-view.php'),
        ], 'nova-logs-view-config');
    }

    /**
     * Register the tool's routes.
     */
    protected function routes(): void
    {
        if ($this->app->routesAreCached()) {
            return;
        }

        if (NovaCompatibility::usesInertia()) {
            Nova::router(['nova', Authenticate::class, Authorize::class], 'nova-logs-view')
                ->group(__DIR__.'/../routes/inertia.php');
        }

        Route::middleware(['nova', Authenticate::class, Authorize::class])
            ->prefix('nova-vendor/nova-logs-view')
            ->group(__DIR__.'/../routes/api.php');
    }

    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/nova-logs-view.php', 'nova-logs-view');
    }
}
