<?php

use App\Http\Controllers\Api\V1\Admin\LandingPageController as AdminLandingPageController;
use App\Http\Controllers\Api\V1\Admin\ProductCategoryController as AdminProductCategoryController;
use App\Http\Controllers\Api\V1\Admin\ProductController as AdminProductController;
use App\Http\Controllers\Api\V1\Admin\TenantController;
use App\Http\Controllers\Api\V1\Admin\UserController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\Public\EventController;
use App\Http\Controllers\Api\V1\Public\LandingPageController;
use App\Http\Controllers\Api\V1\Public\ProductController;
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

Route::post('/auth/login', [AuthController::class, 'login']);
Route::middleware('auth:sanctum')->group(function () {
    Route::get('/auth/me', [AuthController::class, 'me']);
    Route::post('/auth/logout', [AuthController::class, 'logout']);
});

Route::middleware(['auth:sanctum', 'role:super_admin'])->group(function () {
    Route::apiResource('/admin/tenants', TenantController::class)->except(['edit', 'create']);
});

Route::middleware(['auth:sanctum', 'role:super_admin,owner'])->group(function () {
    Route::apiResource('/admin/users', UserController::class)->except(['edit', 'create']);
});

Route::middleware(['auth:sanctum', 'role:super_admin,owner,manager,content_manager'])->group(function () {
    Route::get('/admin/products', [AdminProductController::class, 'index']);
    Route::post('/admin/products', [AdminProductController::class, 'store']);
    Route::get('/admin/products/{product}', [AdminProductController::class, 'show']);
    Route::put('/admin/products/{product}', [AdminProductController::class, 'update']);
    Route::delete('/admin/products/{product}', [AdminProductController::class, 'destroy']);
    Route::post('/admin/products/{product}/publish', [AdminProductController::class, 'publish']);
    Route::post('/admin/products/{product}/unpublish', [AdminProductController::class, 'unpublish']);
    Route::post('/admin/products/{product}/images', [AdminProductController::class, 'uploadImages']);
    Route::delete('/admin/products/{product}/images/{image}', [AdminProductController::class, 'deleteImage']);

    Route::get('/admin/product-categories', [AdminProductCategoryController::class, 'index']);
    Route::post('/admin/product-categories', [AdminProductCategoryController::class, 'store']);
    Route::put('/admin/product-categories/{category}', [AdminProductCategoryController::class, 'update']);
    Route::delete('/admin/product-categories/{category}', [AdminProductCategoryController::class, 'destroy']);

    Route::get('/admin/landing-pages', [AdminLandingPageController::class, 'index']);
    Route::post('/admin/landing-pages', [AdminLandingPageController::class, 'store']);
    Route::get('/admin/landing-pages/{landingPage}', [AdminLandingPageController::class, 'show']);
    Route::put('/admin/landing-pages/{landingPage}', [AdminLandingPageController::class, 'update']);
    Route::delete('/admin/landing-pages/{landingPage}', [AdminLandingPageController::class, 'destroy']);
    Route::post('/admin/landing-pages/{landingPage}/publish', [AdminLandingPageController::class, 'publish']);
    Route::post('/admin/landing-pages/{landingPage}/unpublish', [AdminLandingPageController::class, 'unpublish']);
    Route::get('/admin/landing-pages/{landingPage}/sections', [AdminLandingPageController::class, 'sections']);
    Route::post('/admin/landing-pages/{landingPage}/sections', [AdminLandingPageController::class, 'storeSection']);
    Route::put('/admin/landing-pages/{landingPage}/sections/reorder', [AdminLandingPageController::class, 'reorder']);
    Route::put('/admin/landing-pages/{landingPage}/sections/{section}', [AdminLandingPageController::class, 'updateSection']);
    Route::delete('/admin/landing-pages/{landingPage}/sections/{section}', [AdminLandingPageController::class, 'destroySection']);
});

Route::get('/products', [ProductController::class, 'index']);
Route::get('/products/{slug}', [ProductController::class, 'show']);
Route::get('/landing-pages/{slug}', [LandingPageController::class, 'show']);
Route::post('/events', [EventController::class, 'track']);
