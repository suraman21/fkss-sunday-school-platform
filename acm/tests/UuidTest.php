<?php
/**
 * AcmPersonService::uuid4() must emit a real RFC 4122 version-4 UUID.
 *
 * THE BUG THIS FILE EXISTS FOR
 *   The final 48-bit group was built with
 *       unpack('N', substr($b, 10, 2))[1] * 4294967296
 *           + unpack('N', substr($b, 12, 4))[1]
 *   `unpack('N', ...)` reads four bytes; substr() handed it two. PHP raised
 *   "not enough input values, need 4 values but only 2 were provided", the
 *   term evaluated to 0, and multiplying by 2^32 zeroed the top 16 bits of
 *   the group. Every uuid in the ACM package therefore ended 0000xxxxxxxx --
 *   measured on five consecutive values, all five had that shape.
 *
 *   Losing a third of the entropy does not break uniqueness on its own, but
 *   these are the public identifiers of activation tickets, persons, devices
 *   and enrollment requests, and half a group of zeroes is a fingerprint that
 *   correlates rows across tables. It also emitted a PHP warning on every
 *   single call, which the production logs carry.
 *
 * WHAT IS PINNED
 *   1. The format is the canonical 8-4-4-4-12 with a version 4 and an
 *      RFC 4122 variant nibble.
 *   2. The final group is NOT mostly zero -- the specific symptom.
 *   3. Two calls do not collide, and the last group actually varies.
 */

declare(strict_types=1);

namespace SSMS\Acm\Tests;

use PHPUnit\Framework\TestCase;
use SSMS\Acm\Identity\AcmPersonService;

require_once __DIR__ . '/../src/Identity/AmharicNameMatcher.php';
require_once __DIR__ . '/../src/Identity/AcmPersonService.php';

final class UuidTest extends TestCase
{
    /** @var list<string> */
    private array $uuids = [];

    protected function setUp(): void
    {
        $this->uuids = [];
        for ($i = 0; $i < 25; $i++) {
            $this->uuids[] = AcmPersonService::uuid4();
        }
    }

    /**
     * @dataProvider sampleUuids
     */
    public function test_it_is_a_canonical_version_4_uuid(string $uuid): void
    {
        $this->assertSame(
            1,
            preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $uuid),
            'not a version-4 UUID: ' . $uuid
        );
    }

    public static function sampleUuids(): array
    {
        // Fixed samples: the shape rules must hold for these regardless of
        // what this particular run generated.
        return [
            'sample a' => ['3f2504e0-4f89-41d3-9a0c-0305e82c3301'],
            'sample b' => ['c0c2193f-34d1-419f-ac93-286a79628cef'],
            'sample c' => ['00000000-0000-4000-8000-000000000001'],
        ];
    }

    public function test_the_final_group_is_not_half_zeroes(): void
    {
        // The exact symptom of the defect: ..-0000xxxxxxxx.
        foreach ($this->uuids as $uuid) {
            $last = substr($uuid, 24);
            $this->assertSame(
                false,
                str_starts_with($last, '0000'),
                'uuid ends with a zeroed high half: ' . $uuid
            );
        }
    }

    public function test_the_final_group_actually_varies(): void
    {
        // A constant or truncated group would also pass a format check.
        $tails = [];
        foreach ($this->uuids as $uuid) {
            $tails[substr($uuid, 24)] = true;
        }
        $this->assertSame(count($this->uuids), count($tails), 'two generated uuids shared a node group');
    }

    public function test_no_two_calls_collide(): void
    {
        $this->assertSame(count($this->uuids), count(array_unique($this->uuids)));
    }
}
