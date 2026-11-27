<?php
/**
 * Members app — security headers middleware.
 *
 * WHY THE POLICY IS A CONSTANT HERE AND NOT IN config/app.php
 *   It was in config, and a test caught the failure mode: if the config had not
 *   been loaded, the middleware fell back to `default-src 'self'` — a DIFFERENT
 *   policy, chosen by whatever happened to run first. A security posture that
 *   depends on boot order is not a posture, it is a coincidence.
 *
 *   The CSP is an invariant of this app, so it lives with the code that sends
 *   it. There is nothing to tune: `script-src 'self'` with no 'unsafe-inline'
 *   is what makes template injection unexploitable, and that is the point.
 *
 * WHY A CLASS AND NOT THE .htaccess
 *   The root .htaccess sets a baseline for the whole site. This app needs a
 *   STRICTER policy because it holds a bearer token in the page — exactly what
 *   an injected script would hunt for. Scoping the tighter policy here keeps
 *   the admin console unaffected, and PHP-set headers survive on hosts without
 *   mod_headers.
 */

declare(strict_types=1);

namespace SSMS\Members\Http\Middleware;

use SSMS\Members\Http\Request;
use SSMS\Members\Http\Response;

final class SecurityHeaders implements Middleware
{
    /**
     * No 'unsafe-inline', no 'unsafe-eval', no third-party origins.
     *
     * `connect-src 'self'` matters as much as script-src: it means a script
     * that somehow ran could not POST a member's data to another host.
     */
    private const CSP = "default-src 'self'; "
        . "img-src 'self' data:; "
        . "style-src 'self'; "
        . "script-src 'self'; "
        . "connect-src 'self'; "
        . "font-src 'self'; "
        . "object-src 'none'; "
        . "base-uri 'self'; "
        . "form-action 'self'; "
        . "frame-ancestors 'self'";

    public function process(Request $request, Response $response): Response
    {
        $headers = [
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'SAMEORIGIN',
            'Referrer-Policy' => 'strict-origin-when-cross-origin',
            'Cross-Origin-Opener-Policy' => 'same-origin',
            // The app needs no device APIs. Denying them means a compromised
            // page cannot reach the camera or location on a member's phone.
            'Permissions-Policy' => 'camera=(), microphone=(), geolocation=(), payment=(), usb=()',
            'Content-Security-Policy' => self::CSP,
        ];

        // HSTS only when the request really arrived over TLS. Sending it over
        // plain HTTP is ignored by browsers, and sending it from a non-TLS
        // deployment can lock users out of a working HTTP host.
        if ($request->isSecure()) {
            $headers['Strict-Transport-Security'] = 'max-age=31536000; includeSubDomains';
        }

        foreach ($headers as $name => $value) {
            $response = $response->withHeader($name, $value);
        }

        return $response;
    }

    /** Exposed for the contract test, so the policy cannot drift silently. */
    public static function policy(): string
    {
        return self::CSP;
    }
}
