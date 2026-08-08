<?php

namespace App\Exceptions;

use App\Support\ApiResponse;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Exceptions\Handler as ExceptionHandler;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

class Handler extends ExceptionHandler
{
    protected $dontFlash = [
        'password_hash',
        'password',
        'password_confirmation',
    ];

    public function register(): void
    {
        $this->renderable(function (ValidationException $e, $request) {
            if ($request->is('api/*')) {
                return ApiResponse::error(
                    'VALIDATION_ERROR',
                    'Validasi gagal.',
                    422,
                    $e->errors()
                );
            }
        });

        $this->renderable(function (AuthenticationException $e, $request) {
            if ($request->is('api/*')) {
                return ApiResponse::error(
                    'UNAUTHENTICATED',
                    'Token tidak valid atau tidak disertakan.',
                    401
                );
            }
        });

        $this->renderable(function (ModelNotFoundException $e, $request) {
            if ($request->is('api/*')) {
                return ApiResponse::error(
                    'NOT_FOUND',
                    'Resource tidak ditemukan.',
                    404
                );
            }
        });

        $this->renderable(function (NotFoundHttpException $e, $request) {
            if ($request->is('api/*')) {
                return ApiResponse::error(
                    'NOT_FOUND',
                    'Endpoint tidak ditemukan.',
                    404
                );
            }
        });

        $this->renderable(function (ThrottleRequestsException $e, $request) {
            if ($request->is('api/*')) {
                return ApiResponse::error(
                    'RATE_LIMITED',
                    'Terlalu banyak permintaan. Coba lagi nanti.',
                    429
                );
            }
        });

        $this->renderable(function (HttpException $e, $request) {
            if ($request->is('api/*') && $e->getStatusCode() === 403) {
                return ApiResponse::error(
                    'FORBIDDEN',
                    'Anda tidak memiliki akses ke resource ini.',
                    403
                );
            }

            if ($request->is('api/*') && $e->getStatusCode() === 405) {
                return ApiResponse::error(
                    'METHOD_NOT_ALLOWED',
                    'Method tidak diizinkan untuk endpoint ini.',
                    405
                );
            }
        });

        $this->renderable(function (Throwable $e, $request) {
            if ($request->is('api/*')) {
                $status = $e instanceof HttpException ? $e->getStatusCode() : 500;

                $message = $status >= 500 && config('app.debug') && $e->getMessage() !== ''
                    ? $e->getMessage()
                    : 'Terjadi kesalahan internal.';

                return ApiResponse::error('INTERNAL_ERROR', $message, $status);
            }
        });
    }
}
