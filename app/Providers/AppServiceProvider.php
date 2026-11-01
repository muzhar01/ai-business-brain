<?php

namespace App\Providers;

use App\Support\LabelPairing;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Outside production, fail loudly on N+1 lazy loading, silently dropped
        // mass-assignment attributes and reads of columns that weren't selected.
        Model::shouldBeStrict(! $this->app->isProduction());

        Blade::prepareStringsForCompilationUsing(LabelPairing::apply(...));
    }
}
