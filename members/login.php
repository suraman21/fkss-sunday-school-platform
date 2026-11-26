<?php
/**
 * /members/login — sign-in.
 *
 * Draws the form shell only. No credential check, no session creation, no
 * password handling, no redirect.
 *
 * WHY IT IS NOT IMPLEMENTED
 * A member or parent has users.is_active = 0 until the ACM device ceremony
 * completes (acm/src/Device/DeviceService.php::completeFull). A password form
 * would therefore accept a correct password and then be refused by the API
 * for a reason it could not explain to the person typing it. Implementing
 * sign-in means first choosing the browser activation path — a product
 * decision recorded in README §2, not a coding step.
 */

declare(strict_types=1);

require_once __DIR__ . '/src/Support/bootstrap.php';

\MembersPortal\Support\Bootstrap::create()->run('/login');
