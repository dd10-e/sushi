<?php

use Illuminate\Support\Facades\Request;
use Illuminate\Support\Facades\Route;

Route::get('sushi', function () {
    dd(1);

    return response()->noContent();
})->name('sushi.pgsql');