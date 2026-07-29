<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Task 31: deactivating an academic term hides everything filed under it. Route-model binding still
 * resolves those rows, so a stale link or bookmark would otherwise open a record that is supposed
 * to be invisible — and the policies are inconsistent about it (some route through visibleTo and
 * 403, others compare educator_id directly and happily render). This closes both cases in one
 * place, before the controller runs, independently of how each policy is written.
 *
 * Only the educator's OWN hidden records are redirected. Someone else's record is left alone so it
 * still produces the normal 403/404 — turning that into a friendly notice would leak whether the
 * record exists.
 */
class RedirectInactiveTermRecords
{
    public const MESSAGE = 'That record belongs to an inactive term and is currently hidden.';

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user || ! $user->hasRole('educator')) {
            return $next($request);
        }

        foreach ($request->route()?->parameters() ?? [] as $model) {
            if (! $model instanceof Model || ! method_exists($model, 'termIsActive')) {
                continue;
            }
            if ((int) $model->getAttribute('educator_id') !== $user->id || $model->termIsActive()) {
                continue;
            }

            return $request->expectsJson()
                ? response()->json(['status' => 'error', 'message' => self::MESSAGE], 409)
                : redirect()->route('educator.dashboard')->with('status', self::MESSAGE);
        }

        return $next($request);
    }
}
