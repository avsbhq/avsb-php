<?php

declare(strict_types=1);

namespace Avsbhq\Avsb\Middleware\Laravel;

use Avsbhq\Avsb\AvsbServer;
use Avsbhq\Avsb\EvalContext;

/**
 * Middleware — Laravel route middleware that binds an EvalContext to
 * the current request so controllers can call $server->getBoolFlag(...)
 * without manually constructing a context.
 *
 * Register in app/Http/Kernel.php:
 *   protected $routeMiddleware = [
 *       'avsb' => \Avsbhq\Avsb\Middleware\Laravel\Middleware::class,
 *   ];
 *
 * The middleware reads the authenticated user from the request guard;
 * for unauthenticated requests it uses the session ID as the context key.
 * Override $contextResolver to supply custom context-building logic.
 */
final class Middleware
{
    public function __construct(private readonly AvsbServer $server) {}

    /**
     * Handle an incoming request.
     *
     * @param object   $request  Illuminate\Http\Request
     * @param callable $next
     */
    public function handle(object $request, callable $next): mixed
    {
        $context = $this->buildContext($request);

        // Bind the context to the request so controllers can retrieve it.
        $request->attributes->set('avsb_context', $context);

        return $next($request);
    }

    private function buildContext(object $request): EvalContext
    {
        // Prefer authenticated user id; fall back to session id.
        $key = '';
        if (method_exists($request, 'user') && $request->user() !== null) {
            $user = $request->user();
            $key  = (string) ($user->getAuthIdentifier() ?? '');
        }
        if ($key === '' && method_exists($request, 'session')) {
            $key = (string) ($request->session()->getId() ?? '');
        }

        $attrs = [];
        // Promote any avsb_* query/cookie parameters to context attributes.
        if (method_exists($request, 'all')) {
            foreach ($request->all() as $k => $v) {
                if (str_starts_with((string) $k, 'avsb_')) {
                    $attrs[substr((string) $k, 5)] = $v;
                }
            }
        }

        return EvalContext::user($key, $attrs);
    }
}
