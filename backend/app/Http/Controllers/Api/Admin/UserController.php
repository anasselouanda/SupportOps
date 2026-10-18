<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ListUsersRequest;
use App\Http\Requests\Admin\StoreUserRequest;
use App\Http\Requests\Admin\UpdateUserRequest;
use App\Models\User;
use App\Services\AdminUserService;
use Illuminate\Http\JsonResponse;

class UserController extends Controller
{
    public function index(ListUsersRequest $request): JsonResponse
    {
        $users = User::query()
            ->orderBy('id')
            ->paginate((int) $request->validated('per_page', 15), ['id', 'name', 'email', 'role', 'is_active'], 'page', (int) $request->validated('page', 1));
        $users->setCollection($users->getCollection()->map(fn (User $user): array => $user->publicData()));

        return response()->json($users);
    }

    public function store(StoreUserRequest $request, AdminUserService $service): JsonResponse
    {
        $user = $service->create($request->user(), $request->safe()->only([
            'name', 'email', 'password', 'role', 'is_active',
        ]));

        return response()->json([
            'message' => 'Utilisateur créé avec succès.',
            'user' => $user->publicData(),
        ], 201);
    }

    public function update(UpdateUserRequest $request, User $user, AdminUserService $service): JsonResponse
    {
        $updatedUser = $service->update($request->user(), $user, $request->safe()->only([
            'name', 'email', 'role', 'is_active',
        ]));

        return response()->json([
            'message' => 'Utilisateur modifié avec succès.',
            'user' => $updatedUser->publicData(),
        ]);
    }
}
