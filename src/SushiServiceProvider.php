<?php

namespace Sushi;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class SushiServiceProvider extends ServiceProvider
{
    public function register()
    {
        // Unimplented
    }

    public function boot()
    {
        $this->loadRoutesFrom(__DIR__.'/routes.php');
    }
}