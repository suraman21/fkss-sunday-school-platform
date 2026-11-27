<?php
/**
 * The member code the register stores, and the code the detector looks for.
 *
 * THE BUG THIS FILE EXISTS FOR
 *   `normalizeMemberCode()` stripped every non-alphanumeric character, and the
 *   detector then compared the result against `members.member_code` with plain
 *   equality. But the register does not store a bare alphanumeric string: codes
 *   are allocated as `$prefix . '-' . $tail` (IdentityCodeService::
 *   allocateWithPrefix) and written verbatim -- "C-27456", "B-24659".
 *
 *   So "C-27456" became "C27456", matched no row, and the detector answered
 *   NO_MEMBER. Every member code in the register's own format was refused as
 *   "could not verify these details", whichever way the member typed it. The
 *   whole activation flow was dead and nothing caught it, because there was no
 *   test of the detector at all.
 *
 * WHAT IS PINNED HERE
 *   1. The normalised form IS the form the register stores, for the shapes the
 *      register actually allocates.
 *   2. Typing the code with or without the separator, in any case, with stray
 *      spaces, all resolve to the same value.
 *   3. The detector RESOLVES against a register holding the real format.
 *   4. Shapes that are not the register's format are not mangled into it.
 *
 * These are cheap, database-free and deterministic, which is why there is no
 * excuse for their absence.
 */

declare(strict_types=1);

namespace SSMS\Acm\Tests;

use PHPUnit\Framework\TestCase;
use SSMS\Acm\Requests\AcmActivationDetector;
use SSMS\Acm\Requests\AcmActivationRequestService;

require_once __DIR__ . '/../src/Identity/AmharicNameMatcher.php';
require_once __DIR__ . '/../src/Requests/AcmActivationRequestService.php';
require_once __DIR__ . '/../src/Requests/AcmActivationDetector.php';
require_once __DIR__ . '/../src/Requests/AcmClaimRefused.php';

final class ActivationCodeFormatTest extends TestCase
{
    // -----------------------------------------------------------------
    // 1. Normalisation produces what the register stores
    // -----------------------------------------------------------------

    /**
     * @dataProvider allocatedCodes
     */
    public function test_normalised_form_matches_the_register_format(string $typed, string $stored): void
    {
        $this->assertSame($stored, AcmActivationRequestService::normalizeMemberCode($typed));
    }

    public static function allocatedCodes(): array
    {
        // IdentityCodeService: letter A/B/C + '-' + a 5-digit tail.
        return [
            'hyphenated, canonical'  => ['C-27456', 'C-27456'],
            'lower case'             => ['c-27456', 'C-27456'],
            'upper case, no hyphen'  => ['C27456', 'C-27456'],
            'lower case, no hyphen'  => ['c27456', 'C-27456'],
            'surrounding spaces'     => ['  C-27456  ', 'C-27456'],
            'children category'      => ['A-10001', 'A-10001'],
            'intermediate category'  => ['B-24659', 'B-24659'],
            'youth category'         => ['C-64518', 'C-64518'],
            'copy-pasted with spaces' => ['C - 27456', 'C-27456'],
        ];
    }

    public function test_a_code_that_is_not_the_register_shape_is_left_alone(): void
    {
        // A staff code has a longer alpha prefix; a legacy import may hold
        // anything. Neither is re-separated, because guessing a separator
        // would be inventing a code rather than reading one.
        $this->assertSame('TEACHER12345', AcmActivationRequestService::normalizeMemberCode('TEACHER-12345'));
        $this->assertSame('ABC', AcmActivationRequestService::normalizeMemberCode('abc'));
    }

    public function test_empty_input_stays_empty(): void
    {
        $this->assertSame('', AcmActivationRequestService::normalizeMemberCode(''));
        $this->assertSame('', AcmActivationRequestService::normalizeMemberCode('   '));
        $this->assertSame('', AcmActivationRequestService::normalizeMemberCode('---'));
    }

    // -----------------------------------------------------------------
    // 2. The detector resolves against the register's real format
    // -----------------------------------------------------------------

    /**
     * @dataProvider typedSpellings
     */
    public function test_detector_resolves_however_the_member_types_it(string $typed): void
    {
        $detector = new AcmActivationDetector(new RegisterFakePdo());
        $result = $detector->detect($typed, 'መኩራ');

        $this->assertSame(
            AcmActivationDetector::RESOLVED,
            $result['kind'],
            "typing '{$typed}' must resolve against a register holding 'C-27456'"
        );
        $this->assertSame(1, $result['member_id']);
    }

    public static function typedSpellings(): array
    {
        return [
            'as printed on the card' => ['C-27456'],
            'no hyphen'              => ['C27456'],
            'lower case'             => ['c-27456'],
            'lower case no hyphen'   => ['c27456'],
            'with spaces'            => [' C-27456 '],
        ];
    }

    public function test_latin_name_resolves_too(): void
    {
        $detector = new AcmActivationDetector(new RegisterFakePdo());
        $result = $detector->detect('B-24659', 'Bekele');
        $this->assertSame(AcmActivationDetector::RESOLVED, $result['kind']);
        $this->assertSame(2, $result['member_id']);
    }

    public function test_a_code_that_belongs_to_nobody_is_still_refused(): void
    {
        // Fixing the format must not turn the endpoint into an open door.
        $detector = new AcmActivationDetector(new RegisterFakePdo());
        $this->assertSame(AcmActivationDetector::NO_MEMBER, $detector->detect('Z-99999', 'Nobody')['kind']);
    }

    public function test_a_wrong_name_is_still_refused(): void
    {
        $detector = new AcmActivationDetector(new RegisterFakePdo());
        $this->assertSame(AcmActivationDetector::NAME_MISMATCH, $detector->detect('C-27456', 'Wrong')['kind']);
    }

    public function test_an_inactive_member_is_still_refused(): void
    {
        $pdo = new RegisterFakePdo();
        $pdo->register[0]['status'] = 'archived';
        $detector = new AcmActivationDetector($pdo);
        $this->assertSame(AcmActivationDetector::NOT_ACTIVE, $detector->detect('C-27456', 'መኩራ')['kind']);
    }

    // -----------------------------------------------------------------
    // 3. The refusal stays reason-free
    // -----------------------------------------------------------------

    /**
     * "That ID exists but the name is wrong" would be an oracle. Every refusal
     * kind must produce the identical message, carrying no cause.
     *
     * (An earlier version of this test asserted the message contained no
     * "name" at all. It does -- "first name" is guidance for the honest user,
     * not a disclosure. What must never appear is WHICH part failed, so the
     * test now compares kinds to each other instead of grepping words.)
     */
    public function test_every_refusal_kind_produces_the_identical_message(): void
    {
        $kinds = [
            AcmActivationDetector::NO_MEMBER,
            AcmActivationDetector::NAME_MISMATCH,
            AcmActivationDetector::AMBIGUOUS,
            AcmActivationDetector::NOT_ACTIVE,
            AcmActivationDetector::NO_ACCOUNT,
        ];

        $messages = [];
        foreach ($kinds as $kind) {
            $messages[$kind] = (new \SSMS\Acm\Requests\AcmClaimRefused($kind))->getMessage();
        }

        $distinct = array_values(array_unique(array_values($messages)));
        $this->assertSame(
            1,
            count($distinct),
            'a differing message per kind would tell the caller WHICH detail was wrong'
        );
        $this->assertSame(\SSMS\Acm\Requests\AcmClaimRefused::PUBLIC_MESSAGE, $messages[AcmActivationDetector::NAME_MISMATCH]);
    }

    public function test_the_message_carries_no_reason_and_no_identifier(): void
    {
        $message = (new \SSMS\Acm\Requests\AcmClaimRefused(AcmActivationDetector::NO_MEMBER))->getMessage();
        foreach (['C-27456', 'no such member', 'not registered', 'does not exist',
                  'wrong name', 'unknown'] as $leak) {
            $this->assertStringNotContainsString($leak, strtolower($message));
        }
    }
    // -----------------------------------------------------------------
    // 4. The bare five-digit tail the member actually types
    // -----------------------------------------------------------------

    /**
     * A SECOND measured refusal: against a register holding "B-24659", typing
     * "24659" (the digits only, which is what people read off a card and
     * write on a form) answered NO_MEMBER even after the hyphen fix.
     */
    public function test_a_bare_five_digit_tail_resolves_to_its_member(): void
    {
        $detector = new AcmActivationDetector(new RegisterFakePdo());
        $result = $detector->detect('24659', 'Bekele');
        $this->assertSame(AcmActivationDetector::RESOLVED, $result['kind']);
        $this->assertSame(2, $result['member_id'], 'the tail belongs to B-24659');
    }

    /**
     * The tail is a probe, not a key. Two members can share a tail under
     * different category letters, so when the name agrees with both the
     * detector must refuse rather than pick one.
     */
    public function test_a_tail_two_members_share_is_refused_not_guessed(): void
    {
        $pdo = new RegisterFakePdo();
        $clone = $pdo->register[1];
        $clone['id'] = 3;
        $clone['member_code'] = 'C-24659';
        $pdo->register[] = $clone;

        $result = (new AcmActivationDetector($pdo))->detect('24659', 'Bekele');
        $this->assertSame(AcmActivationDetector::AMBIGUOUS, $result['kind']);
        $this->assertSame('ambiguous', $result['match']);
    }

    /**
     * @dataProvider codesTheTailProbeMustNotTouch
     */
    public function test_the_tail_probe_only_answers_for_a_bare_tail(string $typed): void
    {
        $result = (new AcmActivationDetector(new RegisterFakePdo()))->detect($typed, 'Bekele');
        $this->assertSame(
            AcmActivationDetector::NO_MEMBER,
            $result['kind'],
            '"' . $typed . '" is not the register format and must not be shortened into it'
        );
    }

    public static function codesTheTailProbeMustNotTouch(): array
    {
        return [
            'four digits' => ['4659'],
            'six digits' => ['124659'],
            'tail of nobody' => ['00001'],
            'letters and digits, no separator' => ['B24659X'],
        ];
    }

}

/**
 * A PDO that answers the detector's member lookup the way the real database
 * does: EXACT equality on `member_code`, with codes stored in the register's
 * own hyphenated format.
 *
 * The point of this fake is that it does NOT normalise. A fake that helpfully
 * stripped dashes would hide the very defect these tests exist to catch.
 */

final class RegisterFakePdo extends \PDO
{
    /** @var list<array<string,mixed>> */
    public array $register = [
        [
            'id' => 1, 'member_code' => 'C-27456',
            'full_name_am' => 'መኩራ መኩራ መኩራ', 'full_name_en' => null,
            'student_name' => 'መኩራ', 'christian_name' => 'መኩራ', 'baptismal_name' => null,
            'father_name' => 'መኩራ', 'grandfather_name' => 'መኩራ',
            'gender' => 'Male', 'date_of_birth' => null, 'age' => null,
            'current_section' => 'ህጻናት', 'education_level' => null,
            'status' => 'active', 'phone_primary' => '+251912345678', 'phone_number' => null,
        ],
        [
            'id' => 2, 'member_code' => 'B-24659',
            'full_name_am' => 'በቀለ ታደሰ ደስታ', 'full_name_en' => 'Bekele Tadesse Desta',
            'student_name' => 'በቀለ', 'christian_name' => null, 'baptismal_name' => null,
            'father_name' => 'ታደሰ', 'grandfather_name' => 'ደስታ',
            'gender' => 'Female', 'date_of_birth' => null, 'age' => null,
            'current_section' => 'ማዕከላዊያን', 'education_level' => null,
            'status' => 'active', 'phone_primary' => null, 'phone_number' => null,
        ],
    ];

    public function __construct()
    {
    }

    #[\ReturnTypeWillChange]
    public function prepare($query, $options = [])
    {
        return new RegisterFakeStmt((string)$query, $this);
    }
}

final class RegisterFakeStmt
{
    /** @var list<array<string,mixed>> */
    private array $rows = [];

    public function __construct(private string $sql, private RegisterFakePdo $db)
    {
    }

    public function execute(array $params = []): bool
    {
        if (str_contains($this->sql, 'member_code = :code')) {
            $code = (string)($params[':code'] ?? '');
            // EXACT, like the real query. No normalisation, no forgiveness.
            $this->rows = array_values(array_filter(
                $this->db->register,
                static fn(array $row): bool => $row['member_code'] === $code
            ));
        } elseif (str_contains($this->sql, 'RIGHT(m.member_code, 5) = :tail')) {
            // The real statement's WHERE, answered literally: the stored
            // code's last five characters. No member_code LIKE forgiveness.
            $tail = (string)($params[':tail'] ?? '');
            $this->rows = array_values(array_filter(
                $this->db->register,
                static fn(array $row): bool => substr((string)$row['member_code'], -5) === $tail
            ));
        } else {
            $this->rows = [];
        }
        return true;
    }

    public function fetchAll(int $mode = 0): array
    {
        return $this->rows;
    }

    public function fetch(int $mode = 0)
    {
        return $this->rows[0] ?? false;
    }

    public function fetchColumn()
    {
        return false;
    }

    public function rowCount(): int
    {
        return count($this->rows);
    }

    public function close(): void
    {
    }
}
