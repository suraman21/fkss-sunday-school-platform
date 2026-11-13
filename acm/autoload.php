<?php
/**
 * ACM package loader (S2).
 * ------------------------------------------------------------
 * Single require for every ACM caller. Loads Composer's
 * autoloader when present, then explicitly requires every
 * package file (manifest below). Explicit requires only: repo
 * constitution forbids SPL autoloader registration outside
 * vendor/ (see test_class_dependency_closure), and every entry
 * point must load what it calls.
 * require_once semantics keep this conflict-free after the
 * dev-machine `composer update` lands (same files, same classes).
 * Idempotent: safe to require from many entry points.
 */

if (!defined('SSMS_ACM_AUTOLOAD')) {
    define('SSMS_ACM_AUTOLOAD', true);

    $ssmsVendorAutoload = dirname(__DIR__) . '/vendor/autoload.php';
    if (is_file($ssmsVendorAutoload)) {
        require_once $ssmsVendorAutoload;
    }

    $ssmsAcmSrc = __DIR__ . '/src';
    // Services (moved S2, previously admin/backend/services/Acm/).
    require_once $ssmsAcmSrc . '/Policy/AcmPolicy.php';
    require_once $ssmsAcmSrc . '/Policy/PolicyAuthorizer.php';
    require_once $ssmsAcmSrc . '/Identity/AcmPersonService.php';
    require_once $ssmsAcmSrc . '/Accounts/AcmTicketService.php';
    require_once $ssmsAcmSrc . '/Office/AcmOfficeService.php';
    // Contracts: value types first, then interfaces.
    require_once $ssmsAcmSrc . '/Contracts/AccountContext.php';
    require_once $ssmsAcmSrc . '/Contracts/ResourceRef.php';
    require_once $ssmsAcmSrc . '/Contracts/AuthorizationDecision.php';
    require_once $ssmsAcmSrc . '/Contracts/PersonRef.php';
    require_once $ssmsAcmSrc . '/Contracts/DeviceRef.php';
    require_once $ssmsAcmSrc . '/Contracts/RegistrationChallenge.php';
    require_once $ssmsAcmSrc . '/Contracts/CodePurpose.php';
    require_once $ssmsAcmSrc . '/Contracts/IssuedCode.php';
    require_once $ssmsAcmSrc . '/Contracts/ActivationGrant.php';
    require_once $ssmsAcmSrc . '/Contracts/RequestMeta.php';
    require_once $ssmsAcmSrc . '/Contracts/AccountRef.php';
    require_once $ssmsAcmSrc . '/Contracts/AuditEvent.php';
    require_once $ssmsAcmSrc . '/Contracts/Authorizer.php';
    require_once $ssmsAcmSrc . '/Contracts/PersonDirectory.php';
    require_once $ssmsAcmSrc . '/Contracts/DeviceRegistry.php';
    require_once $ssmsAcmSrc . '/Contracts/ActivationService.php';
    require_once $ssmsAcmSrc . '/Contracts/AuditTrail.php';
}
