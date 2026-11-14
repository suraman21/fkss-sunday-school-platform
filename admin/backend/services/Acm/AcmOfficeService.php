<?php
/**
 * S2 compatibility shim. The implementation lives at
 * acm/src/Office/AcmOfficeService.php
 * (SSMS\Acm\Office\AcmOfficeService); this file keeps the
 * historical class name loadable. No logic here by design.
 */
require_once dirname(__DIR__, 4) . '/acm/autoload.php';
class_alias(\SSMS\Acm\Office\AcmOfficeService::class, 'App\\Services\\Acm\\AcmOfficeService');
