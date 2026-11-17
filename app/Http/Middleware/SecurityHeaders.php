<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Vite;
use Symfony\Component\HttpFoundation\Response;

/**
 * Browser-side hardening for every page: a strict Content-Security-Policy (inline
 * scripts only with a per-request nonce, images only from this site and
 * Pixelforge), no framing, no MIME sniffing, and HSTS in production.
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        // The Vite dev server serves scripts and styles from another origin.
        $policy = Vite::isRunningHot() ? null : $this->contentSecurityPolicy(Vite::useCspNonce());

        $response = $next($request);

        if ($policy !== null) {
            $response->headers->set('Content-Security-Policy', $policy);
        }

        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=(), payment=()');
        $response->headers->set('Cross-Origin-Opener-Policy', 'same-origin');

        if ($request->isSecure() && app()->isProduction()) {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        return $response;
    }

    private function contentSecurityPolicy(string $nonce): string
    {
        $pixelforge = parse_url((string) config('pixelforge.base_url'), PHP_URL_HOST);
        $images = "'self' data:".(is_string($pixelforge) ? " https://{$pixelforge}" : '');

        return implode('; ', [
            "default-src 'self'",
            "script-src 'self' 'nonce-{$nonce}'",
            "style-src-elem 'self' 'nonce-{$nonce}'",
            "style-src-attr 'unsafe-inline'",
            "img-src {$images}",
            "font-src 'self' data:",
            "connect-src 'self'",
            "object-src 'none'",
            "base-uri 'self'",
            "form-action 'self'",
            "frame-ancestors 'none'",
        ]);
    }
}
