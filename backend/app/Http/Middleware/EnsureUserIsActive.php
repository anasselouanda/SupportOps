<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserIsActive
{
    /** @param Closure(Request): Response $next */
    public function handle(Request $request, Closure $next): Response
    {
        $authenticatedUser = $request->user();

        if ($authenticatedUser === null) {
            abort(401);
        }

        $user = User::query()->find($authenticatedUser->getAuthIdentifier());

        if ($user === null || ! $user->is_active) {
            Auth::guard('web')->logout();
            Auth::guard('sanctum')->forgetUser();

            if ($request->hasSession()) {
                $request->session()->invalidate();
                $request->session()->regenerateToken();
            }

            return response()->json([
                'message' => 'Votre compte a été désactivé. Contactez un administrateur.',
                'code' => 'account_disabled',
            ], 403);
        }

        Auth::guard('web')->setUser($user);
        Auth::guard('sanctum')->setUser($user);
        $request->setUserResolver(fn (?string $guard = null): User => $user);

        return $next($request);
    }
}
