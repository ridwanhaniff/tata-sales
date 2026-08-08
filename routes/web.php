<?php

use App\Http\Controllers\Web\LandingRenderController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::middleware(['resolve.tenant', 'set.tenant'])->group(function () {
    Route::get('/l/{slug}', LandingRenderController::class)->name('landing.show');
});
