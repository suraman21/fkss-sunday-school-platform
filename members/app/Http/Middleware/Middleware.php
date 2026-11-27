<?php
/**
 * Members app — middleware contract.
 */

declare(strict_types=1);

namespace SSMS\Members\Http\Middleware;

use SSMS\Members\Http\Request;
use SSMS\Members\Http\Response;

/**
 * Middleware decorates an OUTGOING response.
 *
 * That direction is deliberate: rendering happens in the controller, so the
 * headers a security policy requires are applied after the body exists and
 * cannot be skipped by an early return. Adding an auth middleware later is
 * therefore a routing concern, not a header concern.
 */
interface Middleware
{
    public function process(Request $request, Response $response): Response;
}
