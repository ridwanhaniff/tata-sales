<?php

namespace App\Services\Pipeline;

use App\Models\PipelineStage;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PipelineStageService
{
    public function create(array $data, string $tenantId): PipelineStage
    {
        $this->assertTerminalUniqueness($tenantId, $data, null);

        return DB::transaction(function () use ($data, $tenantId) {
            return PipelineStage::create([
                'tenant_id' => $tenantId,
                'key' => $data['key'],
                'label' => $data['label'],
                'sort_order' => $data['sort_order'] ?? 0,
                'is_won' => (bool) ($data['is_won'] ?? false),
                'is_lost' => (bool) ($data['is_lost'] ?? false),
            ]);
        });
    }

    public function update(PipelineStage $stage, array $data): PipelineStage
    {
        $payload = array_merge([
            'key' => $stage->key,
            'label' => $stage->label,
            'sort_order' => $stage->sort_order,
            'is_won' => $stage->is_won,
            'is_lost' => $stage->is_lost,
        ], $data);

        $this->assertTerminalUniqueness($stage->tenant_id, $payload, $stage->id);

        $stage->forceFill($payload)->save();

        return $stage->fresh();
    }

    public function delete(PipelineStage $stage): void
    {
        $used = DB::table('leads')
            ->where('tenant_id', $stage->tenant_id)
            ->where('status', $stage->key)
            ->exists();

        if ($used) {
            throw ValidationException::withMessages([
                'stage' => ['Pipeline stage sudah dipakai oleh lead — tidak bisa dihapus.'],
            ]);
        }

        $stage->delete();
    }

    private function assertTerminalUniqueness(string $tenantId, array $data, ?string $ignoreId): void
    {
        if ((bool) ($data['is_won'] ?? false)) {
            $this->assertNoOther('is_won', $tenantId, $ignoreId, 'Hanya satu stage is_won yang boleh ada per tenant.');
        }

        if ((bool) ($data['is_lost'] ?? false)) {
            $this->assertNoOther('is_lost', $tenantId, $ignoreId, 'Hanya satu stage is_lost yang boleh ada per tenant.');
        }

        if ((bool) ($data['is_won'] ?? false) && (bool) ($data['is_lost'] ?? false)) {
            throw ValidationException::withMessages([
                'is_won' => ['Stage tidak bisa berstatus is_won sekaligus is_lost.'],
                'is_lost' => ['Stage tidak bisa berstatus is_won sekaligus is_lost.'],
            ]);
        }
    }

    private function assertNoOther(string $column, string $tenantId, ?string $ignoreId, string $message): void
    {
        $exists = PipelineStage::query()
            ->where('tenant_id', $tenantId)
            ->where($column, true)
            ->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages(['stage' => [$message]]);
        }
    }
}
