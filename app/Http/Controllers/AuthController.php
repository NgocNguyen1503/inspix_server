<?php

namespace App\Http\Controllers;

use App\Helpers\ApiResponse;
use App\Models\User;
use Illuminate\Http\Request;

class AuthController extends Controller
{
    public function signIn(Request $request)
    {
        $params = $request->validate([
            'email' => 'required|email',
            'password' => 'required|string',
        ]);

        $user = User::createOrFirst([
            'email' => $params['email'],
        ], [
            'password' => $params['password'],
            'name' => explode('@', $params['email'])[0],
        ]);

        $token = $user->createToken('auth_token')->plainTextToken;

        return ApiResponse::success([
            'token' => $token,
            'user' => $user,
        ]);
    }
}
