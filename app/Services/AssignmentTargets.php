<?php

namespace App\Services;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Collection;

/** Works out which brokers an assignment request is for (R8). */
class AssignmentTargets
{
    /**
     * @return Collection<int, int>
     */
    public static function userIds(FormRequest $request): Collection
    {
        if ($request->boolean('all_brokers')) {
            return User::query()->role(UserRole::EndUser->value)->pluck('id');
        }

        $ids = collect($request->validated('user_ids') ?? []);
        if ($request->filled('user_id')) {
            $ids->push($request->validated('user_id'));
        }

        return $ids->map(fn ($id) => (int) $id)->unique()->values();
    }
}
