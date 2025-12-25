<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class UserController extends Controller
{
    /**
     * List users (admin only).
     */
    public function index(Request $request)
    {
        $query = User::query();

        if ($request->filled('role')) {
            $query->where('role', $request->get('role'));
        }

        $users = $query->orderByDesc('id')->get();

        return response()->json([
            'data' => $users,
        ]);
    }

    /**
     * Create a new user (admin only).
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:6'],
            'role' => ['required', 'in:admin,customer'],
        ]);

        $user = new User();
        $user->name = $validated['name'];
        $user->email = $validated['email'];
        $user->password = Hash::make($validated['password']);
        $user->role = $validated['role'];
        $user->save();

        return response()->json([
            'message' => 'User created successfully.',
            'data' => $user,
        ], 201);
    }
}
