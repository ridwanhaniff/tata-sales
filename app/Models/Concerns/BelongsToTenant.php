<?php

namespace App\Models\Concerns;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\App;

trait BelongsToTenant
{
    protected static function bootBelongsToTenant(): void
    {
        static::addGlobalScope('tenant', function (Builder $builder) {
            $tenant = static::currentTenant();

            return $tenant ? $builder->where($builder->qualifyColumn('tenant_id'), $tenant->id) : $builder;
        });

        static::creating(function (Model $model) {
            $tenant = static::currentTenant();

            if ($tenant && ! $model->getAttribute('tenant_id')) {
                $model->setAttribute('tenant_id', $tenant->id);
            }
        });
    }

    protected static function currentTenant(): ?Tenant
    {
        return App::bound('currentTenant') ? App::make('currentTenant') : null;
    }
}
