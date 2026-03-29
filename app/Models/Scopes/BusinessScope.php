<?php

namespace App\Models\Scopes;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use Illuminate\Support\Facades\Auth;

/**
 * Automatically scopes queries to the authenticated user's business.
 *
 * Safe in queue workers: Auth::check() returns false outside HTTP, so the
 * scope is a no-op and agents can see all rows across all businesses.
 */
class BusinessScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        if (Auth::check() && Auth::user()->business_id && ! Auth::user()->isSuperAdmin()) {
            $builder->where($model->getTable() . '.business_id', Auth::user()->business_id);
        }
    }
}
