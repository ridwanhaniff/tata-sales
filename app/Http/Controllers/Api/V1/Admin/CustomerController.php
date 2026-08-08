<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\CustomerResource;
use App\Models\Customer;
use App\Services\Customer\Customer360Service;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CustomerController extends Controller
{
    public function __construct(private readonly Customer360Service $service) {}

    public function index(Request $request): JsonResponse
    {
        $customers = Customer::query()
            ->withCount('leads')
            ->when($request->filled('search'), function ($q) use ($request) {
                $search = $request->string('search')->toString();

                $q->where(function ($q) use ($search) {
                    $q->where('name', 'ilike', '%'.$search.'%')
                        ->orWhere('phone', 'ilike', '%'.$search.'%')
                        ->orWhere('email', 'ilike', '%'.$search.'%');
                });
            })
            ->when($request->filled('source'), fn ($q) => $q->where('source', $request->string('source')))
            ->orderByDesc('created_at')
            ->paginate(min($request->integer('per_page', 20), 100));

        return ApiResponse::paginated($customers, CustomerResource::class);
    }

    public function show(Customer $customer): JsonResponse
    {
        $view = $this->service->view($customer);

        $customer->setRelation('leads', $view['leads']);
        $customer->timeline = $view['timeline'];

        $resource = new CustomerResource($customer);
        $resource->showTimeline = true;

        return ApiResponse::success($resource);
    }
}
