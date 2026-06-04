<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class UserController extends Controller
{
    public function index(): JsonResponse
    {
        $this->authorize('viewAny', User::class);

        return response()->json(
            User::query()
                ->select('id', 'name', 'email', 'role', 'is_active', 'created_at', 'updated_at')
                ->orderByDesc('is_active')
                ->orderBy('name')
                ->get()
        );
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', User::class);

        $request->merge([
            'name' => trim((string) $request->input('name')),
            'email' => mb_strtolower(trim((string) $request->input('email'))),
        ]);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'role' => ['required', 'in:admin,editor,viewer'],
            'is_active' => ['sometimes', 'boolean'],
            'password' => ['required', 'confirmed', Password::min(8)->letters()->numbers()],
        ]);

        $user = User::create([
            'name' => trim($data['name']),
            'email' => mb_strtolower(trim($data['email'])),
            'role' => $data['role'],
            'is_active' => $request->boolean('is_active', true),
            'password' => Hash::make($data['password']),
        ]);

        return response()->json($this->serializeUser($user), 201);
    }

    public function update(Request $request, User $user): JsonResponse
    {
        $this->authorize('update', $user);

        $request->merge([
            'name' => trim((string) $request->input('name')),
            'email' => mb_strtolower(trim((string) $request->input('email'))),
        ]);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => [
                'required',
                'email',
                'max:255',
                Rule::unique('users', 'email')->ignore($user->id),
            ],
            'role' => ['required', 'in:admin,editor,viewer'],
            'is_active' => ['required', 'boolean'],
            'password' => ['nullable', 'confirmed', Password::min(8)->letters()->numbers()],
        ]);

        $authUser = $request->user('api');
        $targetRole = $data['role'];
        $targetIsActive = (bool) $data['is_active'];

        if ((int) $authUser?->id === (int) $user->id && $targetRole !== $user->role) {
            return response()->json([
                'message' => 'Seu proprio nivel de acesso nao pode ser alterado por esta tela.',
            ], 422);
        }

        if ((int) $authUser?->id === (int) $user->id && ! $targetIsActive) {
            return response()->json([
                'message' => 'Seu proprio acesso nao pode ser desativado por esta tela.',
            ], 422);
        }

        if ($this->isRemovingLastActiveAdmin($user, $targetRole, $targetIsActive)) {
            return response()->json([
                'message' => 'O ultimo administrador ativo do sistema nao pode ser desativado nem perder o perfil admin.',
            ], 422);
        }

        $payload = [
            'name' => trim($data['name']),
            'email' => mb_strtolower(trim($data['email'])),
            'role' => $targetRole,
            'is_active' => $targetIsActive,
        ];

        if (! empty($data['password'])) {
            $payload['password'] = Hash::make($data['password']);
        }

        $user->update($payload);

        return response()->json($this->serializeUser($user->fresh()));
    }

    private function isRemovingLastActiveAdmin(User $user, string $targetRole, bool $targetIsActive): bool
    {
        if ($user->role !== 'admin' || ! $user->is_active) {
            return false;
        }

        if ($targetRole === 'admin' && $targetIsActive) {
            return false;
        }

        return User::query()
            ->where('role', 'admin')
            ->where('is_active', true)
            ->count() <= 1;
    }

    private function serializeUser(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->role,
            'is_active' => (bool) $user->is_active,
            'created_at' => $user->created_at,
            'updated_at' => $user->updated_at,
        ];
    }
}
