<?php
/**
 * S2 compatibility shim. The implementation lives at
 * acm/src/Identity/AcmPersonService.php
 * (SSMS\Acm\Identity\AcmPersonService); this file keeps the
 * historical class name loadable. No logic here by design.
 */
require_once dirname(__DIR__, 4) . '/acm/autoload.php';
class_alias(\SSMS\Acm\Identity\AcmPersonService::class, 'App\\Services\\Acm\\AcmPersonService');
