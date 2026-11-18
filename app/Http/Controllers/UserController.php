<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\UserService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class UserController extends Controller
{
    public function __construct(private UserService $users)
    {
    }

    public function index(Request $request)
    {
        $request->validate([
            'role'   => ['nullable', Rule::in(User::ROLES)],
            'active' => 'nullable|boolean',
        ]);

        $query = User::query()->orderBy('name');

        if ($role = $request->query('role')) {
            $query->where('role', $role);
        }
        if ($request->query('active') !== null) {
            $query->where('active', $request->boolean('active'));
        }
        if ($s = $request->query('q')) {
            $query->where(fn ($w) => $w->where('name', 'like', "%{$s}%")->orWhere('email', 'like', "%{$s}%"));
        }

        $page = $query->paginate(100);
        $page->setCollection($page->getCollection()->map->toApi());

        return $page;
    }

    public function show(User $user)
    {
        return $user->toApi();
    }

    public function store(Request $request)
    {
        $this->normalizeEmail($request);

        $data = $request->validate([
            'name'     => 'required|string|max:120',
            'email'    => 'required|email|max:190|unique:users,email',
            'role'     => ['required', Rule::in(User::ROLES)],
            'password' => 'nullable|string|min:8|max:100',
        ]);

        [$user, $generated] = $this->users->create($data);

        return response()->json(array_filter([
            'user'               => $user->toApi(),
            'temporary_password' => $generated,   // affiché une seule fois, seulement s'il a été généré
        ], fn ($v) => $v !== null), 201);
    }

    public function update(Request $request, User $user)
    {
        $this->normalizeEmail($request);

        $data = $request->validate([
            'name'  => 'sometimes|required|string|max:120',
            'email' => ['sometimes', 'required', 'email', 'max:190', Rule::unique('users', 'email')->ignore($user->id)],
            'role'  => ['sometimes', 'required', Rule::in(User::ROLES)],
        ]);

        return $this->users->update($request->user(), $user->id, $data)->toApi();
    }

    public function activate(Request $request, User $user)
    {
        return $this->users->setActive($request->user(), $user->id, true)->toApi();
    }

    public function deactivate(Request $request, User $user)
    {
        return $this->users->setActive($request->user(), $user->id, false)->toApi();
    }

    public function resetPassword(Request $request, User $user)
    {
        [$fresh, $plain] = $this->users->resetPassword($request->user(), $user->id);

        return ['user' => $fresh->toApi(), 'temporary_password' => $plain];
    }

    private function normalizeEmail(Request $request): void
    {
        if ($request->has('email')) {
            $request->merge(['email' => mb_strtolower(trim((string) $request->input('email')))]);
        }
    }
}
