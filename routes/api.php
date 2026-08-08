<?php

use Illuminate\Support\Facades\Route;

Route::get('/health', function () {
    return response()->json([
        'data' => [
            'service' => 'tata-sales',
            'status' => 'ok',
            'tenant' => app()->bound('currentTenant') ? app('currentTenant')->slug : null,
        ],
    ]);
});
