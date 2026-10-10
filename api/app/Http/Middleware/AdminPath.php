<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class AdminPath
{
    public function handle(Request $request, Closure $next)
    {
        $provided = trim((string) $request->route('admin_path', ''));
        $expected = trim((string) admin_setting(
            'secure_path',
            admin_setting('frontend_admin_path', hash('crc32b', config('app.key')))
        ));

        // Behave exactly like an unregistered route for an invalid admin path:
        // do not reveal the current secure path or authentication state.
        if ($provided === '' || $expected === '' || !hash_equals($expected, $provided)) {
            abort(404);
        }

        return $next($request);
    }
}
