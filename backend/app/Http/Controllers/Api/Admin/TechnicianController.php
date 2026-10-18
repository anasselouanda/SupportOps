<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ListUsersRequest;
use App\Http\Resources\TechnicianResource;
use App\Models\User;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class TechnicianController extends Controller
{
    public function index(ListUsersRequest $request): AnonymousResourceCollection
    {
        return TechnicianResource::collection(User::query()
            ->where('role', User::ROLE_TECHNICIAN)
            ->where('is_active', true)
            ->orderBy('name')->orderBy('id')
            ->paginate((int) $request->validated('per_page', 15), ['id', 'name'])
            ->withQueryString());
    }
}
