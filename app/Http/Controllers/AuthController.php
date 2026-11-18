<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\UserService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function login(Request $request)
    {
        $data = $request->validate([
            'email' => 'required|email',
            'password' => 'required|string',
            'device' => 'nullable|string|max:60',
        ]);

        $user = User::where('email', $data['email'])->first();

        if (! $user || ! $user->active || ! Hash::check($data['password'], $user->password)) {
            throw ValidationException::withMessages(['email' => ['Identifiants invalides.']]);
        }

        return [
            'token' => $user->createToken($data['device'] ?? 'web')->plainTextToken,
            'user' => $user->only('id', 'name', 'email', 'role', 'must_change_password'),
        ];
    }

    public function me(Request $request)
    {
        return $request->user()->only('id', 'name', 'email', 'role', 'must_change_password');
    }

    public function changePassword(Request $request, UserService $users)
    {
        $data = $request->validate([
            'current_password' => 'required|string',
            'password'         => 'required|string|min:8|max:100|confirmed',
        ]);

        $users->changeOwnPassword(
            $request->user(),
            $data['current_password'],
            $data['password'],
            $request->user()->currentAccessToken()?->id,
        );

        return response()->noContent();
    }

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();

        return response()->noContent();
    }
}
