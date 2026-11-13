<?php
/**
 * ACM S2: package loading proof. New SSMS\Acm names resolve, and the
 * historical App\Services\Acm names resolve to the SAME
 * implementation via the shims (single implementation, two names —
 * never a duplicated auth implementation).
 */

namespace SSMS\Acm\Tests;

use PHPUnit\Framework\TestCase;
use SSMS\Acm\Accounts\AcmTicketService;
use SSMS\Acm\Identity\AcmPersonService;
use SSMS\Acm\Office\AcmOfficeService;
use SSMS\Acm\Policy\AcmPolicy;

final class ClassLoadingTest extends TestCase
{
    public function testNewNamesResolve(): void
    {
        $this->assertTrue(class_exists(AcmPolicy::class));
        $this->assertTrue(class_exists(AcmPersonService::class));
        $this->assertTrue(class_exists(AcmTicketService::class));
        $this->assertTrue(class_exists(AcmOfficeService::class));
        // S3 ceremony classes travel with the same manifest.
        $this->assertTrue(class_exists(\SSMS\Acm\Activation\ActivationService::class));
        $this->assertTrue(class_exists(\SSMS\Acm\Activation\CodeAlphabet::class));
        $this->assertTrue(class_exists(\SSMS\Acm\Activation\ActivationException::class));
        $this->assertTrue(class_exists(\SSMS\Acm\Activation\IdempotencyStore::class));
        $this->assertTrue(class_exists(\SSMS\Acm\Accounts\AccountProvisioner::class));
        $this->assertTrue(class_exists(\SSMS\Acm\OfficeNetwork\OfficeNetworkSignal::class));
    }

    public function testContractsResolve(): void
    {
        foreach ([
            \SSMS\Acm\Contracts\Authorizer::class,
            \SSMS\Acm\Contracts\PersonDirectory::class,
            \SSMS\Acm\Contracts\DeviceRegistry::class,
            \SSMS\Acm\Contracts\ActivationService::class,
            \SSMS\Acm\Contracts\AuditTrail::class,
        ] as $contract) {
            $this->assertTrue(interface_exists($contract), $contract);
        }
        foreach ([
            \SSMS\Acm\Contracts\AccountContext::class,
            \SSMS\Acm\Contracts\ResourceRef::class,
            \SSMS\Acm\Contracts\AuthorizationDecision::class,
            \SSMS\Acm\Contracts\CodePurpose::class,
        ] as $type) {
            $this->assertTrue(
                class_exists($type) || interface_exists($type) || enum_exists($type),
                $type
            );
        }
    }

    public function testLegacyShimsAliasTheSameImplementation(): void
    {
        // Static requires (folder safety net forbids variable links).
        require_once dirname(__DIR__, 2) . '/admin/backend/services/Acm/AcmPolicy.php';
        require_once dirname(__DIR__, 2) . '/admin/backend/services/Acm/AcmPersonService.php';
        require_once dirname(__DIR__, 2) . '/admin/backend/services/Acm/AcmTicketService.php';
        require_once dirname(__DIR__, 2) . '/admin/backend/services/Acm/AcmOfficeService.php';
        $pairs = [
            ['SSMS\Acm\Policy\AcmPolicy', 'App\Services\Acm\AcmPolicy'],
            ['SSMS\Acm\Identity\AcmPersonService', 'App\Services\Acm\AcmPersonService'],
            ['SSMS\Acm\Accounts\AcmTicketService', 'App\Services\Acm\AcmTicketService'],
            ['SSMS\Acm\Office\AcmOfficeService', 'App\Services\Acm\AcmOfficeService'],
        ];
        foreach ($pairs as [$new, $old]) {
            $this->assertTrue(class_exists($old), $old);
            // Alias, not a copy: same file, same methods, same behavior.
            $this->assertSame(
                (new \ReflectionClass($new))->getFileName(),
                (new \ReflectionClass($old))->getFileName(),
                $old
            );
        }
        // Behavior through the old name equals behavior through the new one.
        $this->assertSame(AcmPolicy::consoleRoles(), \App\Services\Acm\AcmPolicy::consoleRoles());
    }
}
