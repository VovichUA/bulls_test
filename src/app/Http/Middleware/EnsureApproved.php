<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnsureApproved
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user()?->fresh();
        if (! $user || $user->approved_at === null) {
            if ($request->expectsJson()) {
                abort(403, 'Доступ очікує схвалення адміністратора.');
            }

            return redirect()->route('approval.pending');
        }
        Auth::setUser($user);

        return $next($request);
    }
}
