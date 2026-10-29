<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function register(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:users',
            'password' => 'required|string|min:8|confirmed',
        ]);

        $user = User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => Hash::make($data['password']),
        ]);

        Auth::login($user);
        $request->session()->regenerate();

        return response()->json(['user' => $user], 201);
    }

    public function login(Request $request)
    {
        $data = $request->validate([
            'email' => 'required|email',
            'password' => 'required|string',
        ]);

        if (! Auth::attempt($data, $request->boolean('remember')) || Auth::user()?->disabled_at) {
            Auth::logout();
            throw ValidationException::withMessages(['email' => 'Invalid credentials.']);
        }

        $request->session()->regenerate();

        return response()->json(['user' => Auth::user()]);
    }

    public function logout(Request $request)
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json(['message' => 'Logged out']);
    }

    public function me(Request $request)
    {
        $user = $request->user();

        return response()->json(['user' => $user->load('clients:id,name,slug'), 'permissions' => [
            'manage_clients' => $user->isAdmin(),
            'manage_connections' => $user->isAdmin(),
            'manage_ai_models' => $user->isAdmin(),
            'manage_alerts' => $user->canManage(),
            'manage_ad_library' => $user->canManage(),
            'run_performance_audits' => $user->canRunPerformanceAudits(),
            'run_content_audits' => $user->canRunContentAudits(),
            'use_data_inspector' => $user->canUseDataInspector(),
        ], 'features' => [
            'ad_library' => (bool) config('services.ad_library.enabled'),
            'ad_library_audits' => (bool) config('services.ad_library.enabled') && (bool) config('ad_library_audit.enabled'),
        ]]);
    }
}
