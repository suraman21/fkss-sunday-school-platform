<?php
/**
 * S2 compatibility shim. The implementation lives at
 * acm/src/Accounts/AcmTicketService.php
 * (SSMS\Acm\Accounts\AcmTicketService); this file keeps the
 * historical class name loadable. No logic here by design.
 */
require_once dirname(__DIR__, 4) . '/acm/autoload.php';
class_alias(\SSMS\Acm\Accounts\AcmTicketService::class, 'App\\Services\\Acm\\AcmTicketService');
