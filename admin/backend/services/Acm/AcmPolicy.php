<?php
/**
 * S2 compatibility shim. The implementation lives at
 * acm/src/Policy/AcmPolicy.php (SSMS\Acm\Policy\AcmPolicy);
 * this file keeps the historical class name loadable.
 * No logic here by design.
 */
require_once dirname(__DIR__, 4) . '/acm/autoload.php';
class_alias(\SSMS\Acm\Policy\AcmPolicy::class, 'App\\Services\\Acm\\AcmPolicy');
