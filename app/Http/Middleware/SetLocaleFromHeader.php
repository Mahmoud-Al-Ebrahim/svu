<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Validation and error messages follow the portal language sent in Accept-Language (ar | en). */
class SetLocaleFromHeader
{
    public function handle(Request $request, Closure $next): Response
    {
        $lang = $request->getPreferredLanguage(['ar', 'en']);
        app()->setLocale($lang ?? config('app.locale'));

        return $next($request);
    }
}
