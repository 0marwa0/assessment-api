<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreUserRequest;
use App\Http\Requests\UpdateUserRoleRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class UserController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $users = User::query()
            ->with('roles')
            ->when($request->filled('role'), fn ($query) => $query->role($request->string('role')->toString()))
            ->latest()
            ->paginate($request->integer('per_page', 15));

        return UserResource::collection($users);
    }

    public function show(User $user): UserResource
    {
        return new UserResource($user->load('roles'));
    }

    public function store(StoreUserRequest $request): UserResource
    {
        $user = User::create(collect($request->validated())->except('role')->all());
        $user->assignRole($request->validated('role'));

        return (new UserResource($user))
            ->additional(['message' => 'User created successfully.']);
    }

    public function updateRole(UpdateUserRoleRequest $request, User $user): UserResource
    {
        $user->syncRoles([$request->validated('role')]);

        return (new UserResource($user))
            ->additional(['message' => 'User role updated successfully.']);
    }
}
