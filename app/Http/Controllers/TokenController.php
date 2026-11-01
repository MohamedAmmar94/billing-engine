<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\PersonalAccessToken;

final class TokenController
{
    public function store(Request $request): JsonResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
            'device_name' => ['required', 'string', 'max:100'],
        ]);

        // validate() بتفحص الباسورد من غير ما تعمل session
        if (! Auth::validate(['email' => $credentials['email'], 'password' => $credentials['password']])) {
            throw ValidationException::withMessages(['email' => ['The provided credentials are incorrect.']]);
        }

        $user = User::query()->where('email', $credentials['email'])->firstOrFail();

        return response()->json([
            'token' => $user->createToken($credentials['device_name'])->plainTextToken,
        ], 201);
    }

    public function destroy(Request $request): Response
    {
        PersonalAccessToken::findToken((string) $request->bearerToken())?->delete();

        return response()->noContent();
    }
}
