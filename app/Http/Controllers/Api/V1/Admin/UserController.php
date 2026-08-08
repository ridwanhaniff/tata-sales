<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\User\StoreUserRequest;
use App\Http\Requests\User\UpdateUserRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class UserController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $users = User::query()
            ->when($request->filled('role'), fn ($q) => $q->where('role', $request->string('role')))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->when($request->filled('search'), function ($q) use ($request) {
                $q->where(function ($q) use ($request) {
                    $q->where('name', 'ilike', '%'.$request->string('search').'%')
                        ->orWhere('email', 'ilike', '%'.$request->string('search').'%');
                });
            })
            ->orderBy('created_at', 'desc')
            ->paginate(min($request->integer('per_page', 20), 100));

        return ApiResponse::paginated($users, UserResource::class);
    }

    public function store(StoreUserRequest $request): JsonResponse
    {
        $tenantId = $this->tenantIdFor($request);

        $exists = User::query()
            ->where('tenant_id', $tenantId)
            ->where('email', $request->validated('email'))
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages([
                'email' => ['Email sudah terdaftar di tenant ini.'],
            ]);
        }

        $user = User::create([
            ...$request->validated(),
            'tenant_id' => $tenantId,
            'password_hash' => Hash::make($request->validated('password')),
        ]);

        return ApiResponse::created(new UserResource($user));
    }

    public function show(User $user): JsonResponse
    {
        return ApiResponse::success(new UserResource($user));
    }

    public function update(UpdateUserRequest $request, User $user): JsonResponse
    {
        $data = $request->validated();

        if (isset($data['password'])) {
            $data['password_hash'] = Hash::make($data['password']);
            unset($data['password']);
        }

        $user->update($data);

        return ApiResponse::success(new UserResource($user->fresh()));
    }

    public function destroy(User $user): JsonResponse
    {
        $user->delete();

        return ApiResponse::noContent();
    }

    private function tenantIdFor(Request $request): ?string
    {
        $tenant = $request->attributes->get('tenant');

        if ($tenant) {
            return $tenant->id;
        }

        if ($request->user()?->isSuperAdmin() && $request->filled('tenant_id')) {
            return $request->string('tenant_id');
        }

        return null;
    }
}
