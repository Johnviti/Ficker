<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;

class AuthenticatedSessionController extends Controller
{
    /**
     * Handle an incoming authentication request.
     */
    public function store(LoginRequest $request): JsonResponse
    {
        $credentials = $request->only('email', 'password');

        if (Auth::attempt($credentials)) {
            $user = Auth::user();

            if (Hash::needsRehash($user->password)) {
                $user->password = Hash::make($credentials['password']);
                $user->save();
            }

            $token = $user->createToken('login_token')->plainTextToken;

            return response()->json([
                "message" => "Login Success",
                "data" => [
                    "token" => $token,
                    "token_type" => "Bearer"
                ]
            ], 200);
        }

        return response()->json([
            "message" => "Error",
        ], 404);
    }

    /**
     * Destroy an authenticated session.
     */
    public function destroy(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user && $user->currentAccessToken()) {
            $user->currentAccessToken()->delete();
        }

        Auth::guard('web')->logout();

        return response()->json([
            "message" => "Logout Success"
        ], 200);
    }
}
