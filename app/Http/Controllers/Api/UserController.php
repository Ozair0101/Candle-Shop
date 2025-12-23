<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;

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
}
