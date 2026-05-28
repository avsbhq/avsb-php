<?php

declare(strict_types=1);

namespace Avsbhq\Avsb\Middleware\Symfony;

use Avsbhq\Avsb\AvsbServer;
use Avsbhq\Avsb\EvalContext;

/**
 * EventListener — Symfony kernel.request listener that binds an EvalContext
 * to the current request attributes.
 *
 * Once bound, controllers can retrieve the context via:
 *   $context = $request->attributes->get('avsb_context');
 *
 * Registered as a kernel.event_listener tag by AvsbExtension. Requires
 * symfony/http-kernel (listed as a suggested dependency).
 */
final class EventListener
{
    public function __construct(private readonly AvsbServer $server) {}

    /**
     * Build an EvalContext from the Symfony request and attach it to the
     * request attributes for downstream use.
     *
     * @param object $event  Symfony\Component\HttpKernel\Event\RequestEvent
     */
    public function onKernelRequest(object $event): void
    {
        if (method_exists($event, 'isMainRequest') && !$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        $context = $this->buildContext($request);
        $request->attributes->set('avsb_context', $context);
    }

    private function buildContext(object $request): EvalContext
    {
        $key = '';

        // Try session-based identity.
        if (method_exists($request, 'getSession')) {
            try {
                $key = (string) $request->getSession()->getId();
            } catch (\Throwable) {
                // Session may not be started.
            }
        }

        $attrs = [];
        // Promote any X-Avsb-* headers to context attributes.
        if (method_exists($request, 'headers')) {
            foreach ($request->headers->all() as $name => $values) {
                if (str_starts_with((string) $name, 'x-avsb-')) {
                    $attrName        = str_replace('-', '_', substr((string) $name, 7));
                    $attrs[$attrName] = implode(',', (array) $values);
                }
            }
        }

        return EvalContext::user($key, $attrs);
    }
}
