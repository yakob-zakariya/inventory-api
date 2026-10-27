<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ChangePasswordRequest;
use App\Http\Requests\Api\V1\LoginRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\AccessControl;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\PersonalAccessToken;
use Laravel\Sanctum\TransientToken;

class AuthController extends Controller
{
    private const DEACTIVATED = 'This account is deactivated. Ask the shop owner to reactivate it.';

    /**
     * Exchange email and password for a new API token.
     */
    public function login(LoginRequest $request): JsonResponse
    {
        $user = User::where('email', $request->validated('email'))->first();

        if (! $user || ! Hash::check($request->validated('password'), $user->password)) {
            throw ValidationException::withMessages([
                'email' => __('auth.failed'),
            ]);
        }

        if (! $user->is_active) {
            throw ValidationException::withMessages(['email' => self::DEACTIVATED]);
        }

        $token = $user->createToken($request->tokenName())->plainTextToken;

        return response()->json([
            'data' => [
                'token' => $token,
                'user' => new UserResource($user->load('roles')),
            ],
        ]);
    }

    /**
     * Log the web app in with a session cookie. Call GET /sanctum/csrf-cookie first.
     */
    public function startSession(LoginRequest $request): UserResource
    {
        if (! $request->hasSession()) {
            throw new BusinessRuleException('Cookie login is only for the web app. Use /auth/login to get a token.');
        }

        if (! Auth::guard('web')->attempt($request->safe()->only(['email', 'password']))) {
            throw ValidationException::withMessages([
                'email' => __('auth.failed'),
            ]);
        }

        if (! Auth::guard('web')->user()->is_active) {
            Auth::guard('web')->logout();

            throw ValidationException::withMessages(['email' => self::DEACTIVATED]);
        }

        $request->session()->regenerate();

        return $this->me($request);
    }

    /**
     * Log the web app out and end its session.
     */
    public function endSession(Request $request): Response
    {
        if ($request->hasSession()) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        return response()->noContent();
    }

    /**
     * Revoke the token used for this request (or end the session, for the web app).
     */
    public function logout(Request $request): Response
    {
        $token = $request->user()->currentAccessToken();

        if ($token instanceof TransientToken) {
            return $this->endSession($request);
        }

        $token->delete();

        return response()->noContent();
    }

    /**
     * Change the logged-in user's own password. Their other logins end; this one stays.
     */
    public function changePassword(ChangePasswordRequest $request, AccessControl $access): Response
    {
        $user = $request->user();
        $user->update(['password' => $request->validated('password')]);

        $token = $user->currentAccessToken();
        $access->endLogins(
            $user,
            exceptTokenId: $token instanceof PersonalAccessToken ? $token->id : null,
            exceptSessionId: $request->hasSession() ? $request->session()->getId() : null,
        );

        return response()->noContent();
    }

    /**
     * Show the logged-in user with their roles and permission names.
     */
    public function me(Request $request): UserResource
    {
        $user = $request->user()->load('roles.permissions', 'permissions');

        return (new UserResource($user))->withPermissions();
    }
}
