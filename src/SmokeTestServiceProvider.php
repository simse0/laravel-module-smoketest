<?php

namespace Trafficdesign\SmokeTest;

use Illuminate\Support\ServiceProvider;

class SmokeTestServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../stubs/SmokeTest.stub' => base_path('tests/Feature/SmokeTest.php'),
            ], 'smoke-test');
        }
    }
}
