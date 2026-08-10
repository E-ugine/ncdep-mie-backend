<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class AuthController extends Controller
{
    public function login(Request $request): JsonResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
        ]);

        if (! Auth::attempt($credentials)) {
            return response()->json([
                'message' => 'invalid_credentials',
            ], 422);
        }

        $request->session()->regenerate();

        return response()->json([
            'user' => $this->userPayload($request->user()),
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json([
            'message' => 'Logged out.',
        ]);
    }

    public function user(Request $request): JsonResponse
    {
        return response()->json([
            'user' => $this->userPayload($request->user()),
        ]);
    }

    private function userPayload($user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'supplier_id' => $user->supplier_id,
            // The sidebar identity card needs the company name, not just the id — null when
            // unlinked, same as supplier_id, rather than fabricating a placeholder company.
            'supplier_name' => $user->supplier?->name,
            // Dashboard subtitle ("Company · Country") needs a real location — the supplier's
            // country, not a fabricated sub-region (the mockup's "Nyeri & Kirinyaga" county detail
            // has no equivalent column anywhere in the schema).
            'supplier_country' => $user->supplier?->country?->name,
        ];
    }
}
