<?php
/**
 * ACM package loader (S2).
 * ------------------------------------------------------------
 * Single require for every ACM caller. Loads Composer's
 * autoloader (tracked in vendor/, always present), then
 * explicitly requires every package file (manifest below).
 * Every path is a static __DIR__-relative literal: repo
 * constitution forbids SPL autoloader registration outside
 * vendor/ (see test_class_dependency_closure), and the folder
 * safety net statically verifies each link below
 * (test_structure_include_paths). require_once semantics keep
 * this conflict-free after the dev-machine composer update
 * (same files, same classes).
 * Idempotent: safe to require from many entry points.
 */

if (!defined('SSMS_ACM_AUTOLOAD')) {
    define('SSMS_ACM_AUTOLOAD', true);

    require_once dirname(__DIR__) . '/vendor/autoload.php';

    // Contracts FIRST, then services. implements/extends targets
    // must be declared before the classes that name them: the
    // tracked vendor/ predates the SSMS mapping, so composer
    // cannot be relied upon to resolve them on demand (S4 login
    // seam fatal, 2026-11-13). Value types first, then interfaces.
    require_once __DIR__ . '/src/Contracts/AccountContext.php';
    require_once __DIR__ . '/src/Contracts/ResourceRef.php';
    require_once __DIR__ . '/src/Contracts/AuthorizationDecision.php';
    require_once __DIR__ . '/src/Contracts/PersonRef.php';
    require_once __DIR__ . '/src/Contracts/DeviceRef.php';
    require_once __DIR__ . '/src/Contracts/RegistrationChallenge.php';
    require_once __DIR__ . '/src/Contracts/CodePurpose.php';
    require_once __DIR__ . '/src/Contracts/IssuedCode.php';
    require_once __DIR__ . '/src/Contracts/ActivationGrant.php';
    require_once __DIR__ . '/src/Contracts/RequestMeta.php';
    require_once __DIR__ . '/src/Contracts/AccountRef.php';
    require_once __DIR__ . '/src/Contracts/AuditEvent.php';
    require_once __DIR__ . '/src/Contracts/Authorizer.php';
    require_once __DIR__ . '/src/Contracts/PersonDirectory.php';
    require_once __DIR__ . '/src/Contracts/DeviceRegistry.php';
    require_once __DIR__ . '/src/Contracts/ActivationService.php';
    require_once __DIR__ . '/src/Contracts/AuditTrail.php';
    // Services (moved S2, previously admin/backend/services/Acm/).
    require_once __DIR__ . '/src/Policy/AcmPolicy.php';
    require_once __DIR__ . '/src/Policy/PolicyAuthorizer.php';
    require_once __DIR__ . '/src/Identity/AcmPersonService.php';
    // S3 activation ceremony.
    require_once __DIR__ . '/src/Activation/CodeAlphabet.php';
    require_once __DIR__ . '/src/Activation/ActivationException.php';
    require_once __DIR__ . '/src/Activation/IdempotencyStore.php';
    require_once __DIR__ . '/src/Accounts/AccountProvisioner.php';
    require_once __DIR__ . '/src/Accounts/AcmTicketService.php';
    require_once __DIR__ . '/src/Activation/ActivationService.php';
    require_once __DIR__ . '/src/OfficeNetwork/OfficeNetworkSignal.php';
    // S4 device registry.
    require_once __DIR__ . '/src/Device/DeviceCrypto.php';
    require_once __DIR__ . '/src/Device/DeviceService.php';
    // S5 refresh-time proof verifier (mysqli, injected into rotation).
    require_once __DIR__ . '/src/Device/DeviceRefreshProofs.php';
    require_once __DIR__ . '/src/Office/AcmOfficeService.php';
    // S10 staff enrollment request queue.
    require_once __DIR__ . '/src/Enrollment/StaffEnrollmentRequestService.php';
}
