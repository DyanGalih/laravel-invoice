<?php

namespace Crater\Http\Middleware;

use Closure;

class CorsMiddleware
{
    /**
     * Handle an incoming request across Laravel versions.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure  $next
     * @return mixed
     */
    public function handle($request, Closure $next)
    {
        if (class_exists(\Illuminate\Http\Middleware\HandleCors::class)) {
            return app(\Illuminate\Http\Middleware\HandleCors::class)->handle($request, $next);
        }

        if (class_exists(\Fruitcake\Cors\HandleCors::class)) {
            return app(\Fruitcake\Cors\HandleCors::class)->handle($request, $next);
        }

        return $next($request);
    }
}
