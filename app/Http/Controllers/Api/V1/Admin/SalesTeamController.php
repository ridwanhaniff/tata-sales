<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Sales\StoreSalesTeamRequest;
use App\Http\Requests\Sales\UpdateSalesTeamRequest;
use App\Http\Resources\SalesTeamResource;
use App\Models\SalesTeam;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class SalesTeamController extends Controller
{
    public function index(): JsonResponse
    {
        $teams = SalesTeam::query()
            ->with(['members', 'productCategory'])
            ->orderBy('name')
            ->get();

        return ApiResponse::success(SalesTeamResource::collection($teams));
    }

    public function store(StoreSalesTeamRequest $request): JsonResponse
    {
        $data = $request->validated();
        $memberIds = $data['member_ids'] ?? [];
        unset($data['member_ids']);

        $team = DB::transaction(function () use ($data, $memberIds, $request) {
            $team = SalesTeam::create([
                ...$data,
                'tenant_id' => $request->attributes->get('tenant')?->id,
            ]);

            $this->syncMembers($team, $memberIds);

            return $team;
        });

        return ApiResponse::created(new SalesTeamResource($team->load(['members', 'productCategory'])));
    }

    public function update(UpdateSalesTeamRequest $request, SalesTeam $team): JsonResponse
    {
        $data = $request->validated();
        $memberIds = array_key_exists('member_ids', $data) ? ($data['member_ids'] ?? []) : null;
        unset($data['member_ids']);

        $team->forceFill($data)->save();

        if ($memberIds !== null) {
            $this->syncMembers($team, $memberIds);
        }

        return ApiResponse::success(new SalesTeamResource($team->load(['members', 'productCategory'])));
    }

    public function destroy(SalesTeam $team): JsonResponse
    {
        $team->delete();

        return ApiResponse::noContent();
    }

    /**
     * @param  list<string>  $memberIds
     */
    private function syncMembers(SalesTeam $team, array $memberIds): void
    {
        $team->members()->detach();

        foreach ($memberIds as $userId) {
            $team->members()->attach($userId, ['tenant_id' => $team->tenant_id]);
        }
    }
}
