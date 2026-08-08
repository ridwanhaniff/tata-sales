<?php

use App\Http\Middleware\EnsureRole;
use App\Http\Middleware\ResolveTenant;
use App\Http\Middleware\SetTenantContext;
use App\Support\ApiResponse;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        apiPrefix: 'api/v1',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->prependToGroup('api', [
            ResolveTenant::class,
            SetTenantContext::class,
        ]);

        $middleware->alias([
            'resolve.tenant' => ResolveTenant::class,
            'set.tenant' => SetTenantContext::class,
            'role' => EnsureRole::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );

        $exceptions->render(function (ValidationException $e, Request $request) {
            if ($request->is('api/*')) {
                return ApiResponse::error('VALIDATION_ERROR', 'Validasi gagal.', 422, $e->errors());
            }
        });

        $exceptions->render(function (AuthenticationException $e, Request $request) {
            if ($request->is('api/*')) {
                return ApiResponse::error('UNAUTHENTICATED', 'Token tidak valid atau tidak disertakan.', 401);
            }
        });

        $exceptions->render(function (ModelNotFoundException $e, Request $request) {
            if ($request->is('api/*')) {
                return ApiResponse::error('NOT_FOUND', 'Resource tidak ditemukan.', 404);
            }
        });

        $exceptions->render(function (NotFoundHttpException $e, Request $request) {
            if ($request->is('api/*')) {
                return ApiResponse::error('NOT_FOUND', 'Endpoint tidak ditemukan.', 404);
            }
        });

        $exceptions->render(function (ThrottleRequestsException $e, Request $request) {
            if ($request->is('api/*')) {
                return ApiResponse::error('RATE_LIMITED', 'Terlalu banyak permintaan. Coba lagi nanti.', 429);
            }
        });

        $exceptions->render(function (HttpException $e, Request $request) {
            if ($request->is('api/*') && $e->getStatusCode() === 403) {
                return ApiResponse::error('FORBIDDEN', 'Anda tidak memiliki akses ke resource ini.', 403);
            }

            if ($request->is('api/*') && $e->getStatusCode() === 405) {
                return ApiResponse::error('METHOD_NOT_ALLOWED', 'Method tidak diizinkan untuk endpoint ini.', 405);
            }
        });

        $exceptions->render(function (Throwable $e, Request $request) {
            if ($request->is('api/*')) {
                $status = $e instanceof HttpException ? $e->getStatusCode() : 500;

                $message = $status >= 500 && config('app.debug') && $e->getMessage() !== ''
                    ? $e->getMessage()
                    : 'Terjadi kesalahan internal.';

                return ApiResponse::error('INTERNAL_ERROR', $message, $status);
            }
        });
    })->create();
