<?php
/**
 * Amharic-aware name matching (S12).
 * ------------------------------------------------------------
 * THE PROBLEM
 *   Amharic is written in the Ge'ez abugida. Several consonant families are
 *   pronounced identically in modern Amharic but are different Unicode
 *   characters, and people write them interchangeably in names. A member
 *   registered as "ሠላም" who types "ሰላም" has written the same name; `===`
 *   says otherwise, and every byte-comparing search box silently returns
 *   nothing. The office then concludes the person is not in the register.
 *
 *   Example families (all pronounced the same):
 *     ሀ ሐ ኀ   -> h
 *     ሰ ሠ     -> s
 *     አ ዐ     -> ʾ (glottal)
 *     ጸ ፀ     -> ṣ
 *     ቀ ከ ቐ ቈ ኰ ዀ   -> k
 *     ጠ       -> ṭ, which is NOT the same as ተ (t); they stay apart
 *
 * HOW IT WORKS — AND WHY NOT A HAND-WRITTEN GLYPH TABLE
 *   The Ethiopic block is laid out in groups of EIGHT: for every consonant,
 *   eight consecutive code points give the vowel orders
 *
 *        +0 ä   +1 u   +2 i   +3 a   +4 e   +5 ə   +6 o   +7 ʷa
 *
 *   so `base = cp & ~7` identifies the consonant and `cp - base` the vowel.
 *   A first version of this class listed every glyph by hand and, by
 *   mistake, put all seven vowel orders of the glottal series into one
 *   group — which folded አ onto ኡ, i.e. declared two different names equal.
 *   A unit test caught it. This version derives the vowel order
 *   arithmetically, so vowels cannot be folded by construction: only the
 *   CONSONANT base is rewritten, and the offset is carried across.
 *
 * WHAT IT DOES NOT DO
 *   Folding is a SEARCH convenience, never an identity claim. Two names
 *   that fold together are "probably the same name", not proof of the same
 *   person. `compare()` returns a score and an explicit `kind`, and only a
 *   human officer looking at the record promotes that to an approval.
 *
 * Deterministic, dependency-free, unit-testable without a database.
 */

namespace SSMS\Acm\Identity;

final class AmharicNameMatcher
{
    /**
     * Consonant bases that Amharic pronounces identically, mapped to the
     * base that names are usually written with. Values are code points,
     * NOT glyphs, so the vowel offset can be added arithmetically.
     *
     * Deliberately absent: ተ (t) vs ጠ (ṭ), ደ (d) vs ዸ, ፐ (p) vs ጰ (p̣).
     * Those are different phonemes in Amharic, not homophones, and folding
     * them would merge genuinely different names.
     */
    private const CONSONANT_FAMILIES = [
        0x1210 => 0x1200, // ሐ -> ሀ   (ḥ  -> h)
        0x1280 => 0x1200, // ኀ -> ሀ   (ḫ  -> h)
        0x1220 => 0x1230, // ሠ -> ሰ   (ś  -> s)
        0x12D0 => 0x12A0, // ዐ -> አ   (ʿ  -> ʾ)
        0x1340 => 0x1338, // ፀ -> ጸ   (ṣ́ -> ṣ)
        0x12E0 => 0x12D8, // ዠ -> ዘ   (ž  -> z)
        0x1278 => 0x1328, // ቸ -> ጨ   (č  -> č̣)
        0x1298 => 0x1290, // ኘ -> ነ   (ñ  -> n)
        0x1268 => 0x1260, // ቨ -> በ   (v  -> b)
        0x1358 => 0x1348, // ፘ -> ፈ
        // k: the largest family — q, k, ḫʷ and the labialised series
        0x1240 => 0x12A8, // ቀ -> ከ
        0x1250 => 0x12A8, // ቐ -> ከ
        0x12B8 => 0x12A8, // ኸ -> ከ
        0x1248 => 0x12A8, // ቈ -> ከ
        0x12B0 => 0x12A8, // ኰ -> ከ
        0x12C0 => 0x12A8, // ዀ -> ከ
        // g
        0x1318 => 0x1308, // ጘ -> ገ
        0x130C => 0x1308, // ጓ -> ገ
    ];

    /**
     * Vowel orders that are written interchangeably for ONE consonant: the
     * glottal series. አ (ʾä) and ዓ (ʿa) are both a glottal stop, and names
     * are written with either -- "አለሙ" and "ዓለሙ" are the same name in
     * practice. Orders 0 (ä), 3 (a) and 5 (ə) are neutralised onto order 0.
     *
     * This exception is scoped to the glottal consonant on purpose. Applying
     * it generally would fold ሰ (sä) onto ሳ (sa) and merge genuine
     * differences -- which is exactly the bug the first version had.
     */
    private const VOWEL_NEUTRAL_BASES = [0x12A0];

    private const VOWEL_NEUTRAL_ORDERS = [0, 3, 5];

    /**
     * Characters to drop: Ethiopic punctuation, ASCII punctuation and any
     * separator that is not part of a name.
     */
    private const PUNCT = '/[\x{1360}-\x{1368}\x{1380}-\x{139F},.;:!?\/\\\\|()\[\]"\'`´’“”«»\-_]+/u';

    /**
     * Fold a name to its comparison key.
     *
     * Homophone consonants are rewritten to one canonical consonant; the
     * vowel order is carried across untouched. Latin text is lowercased.
     * Punctuation and spacing are removed, because they are not part of a
     * name and are typed inconsistently.
     */
    public static function fold(string $name): string
    {
        $name = self::trimAndCollapse($name);
        if ($name === '') {
            return '';
        }
        $chars = self::chars($name);
        $out = '';
        foreach ($chars as $ch) {
            $cp = self::codepoint($ch);
            if ($cp === null) {
                $out .= strtolower($ch);
                continue;
            }
            if ($cp < 0x1200 || $cp > 0x137F) {
                // Latin (and everything else) participates in comparison
                // case-insensitively: a member typing "nahom" must match the
                // registered "Nahom". Without this the fold keys differ by
                // case and the whole comparison falls through to distance.
                $out .= function_exists('mb_strtolower')
                    ? mb_strtolower($ch, 'UTF-8')
                    : strtolower($ch);
                continue;
            }
            $base = $cp & ~7;
            $offset = $cp - $base;
            $canonical = self::CONSONANT_FAMILIES[$base] ?? $base;
            if (in_array($canonical, self::VOWEL_NEUTRAL_BASES, true)
                && in_array($offset, self::VOWEL_NEUTRAL_ORDERS, true)) {
                $offset = 0;
            }
            $out .= self::utf8($canonical + $offset);
        }
        return $out;
    }

    /**
     * Collapse whitespace and drop punctuation, keeping the glyphs the
     * member actually used. Used for display and before folding.
     */
    public static function trimAndCollapse(string $name): string
    {
        $name = str_replace(["\u{00A0}", "\u{200B}", "\r", "\n", "\t"], ' ', $name);
        $name = (string)preg_replace(self::PUNCT, ' ', $name);
        $name = (string)preg_replace('/\s+/u', ' ', $name);
        return trim($name);
    }

    /**
     * The first word of a display name. Amharic names are given-name first,
     * so this is the part the member is asked for and the part the register
     * is matched on.
     */
    public static function firstName(string $name): string
    {
        $name = self::trimAndCollapse($name);
        if ($name === '') {
            return '';
        }
        $parts = explode(' ', $name);
        return $parts[0];
    }

    /**
     * Distance in GRAPHEMES between the folded forms.
     *
     * A byte-level Levenshtein on Ge'ez is meaningless: every glyph is three
     * bytes, so a one-letter difference reads as three, and a threshold
     * tuned on Latin text does the wrong thing. Comparing folded graphemes
     * means a difference of 1 is "one letter", which is what a typo is.
     */
    public static function distance(string $a, string $b): int
    {
        return self::graphemeDistance(self::fold($a), self::fold($b));
    }

    private static function graphemeDistance(string $a, string $b): int
    {
        if ($a === $b) {
            return 0;
        }
        $ga = self::chars($a);
        $gb = self::chars($b);
        $la = count($ga);
        $lb = count($gb);
        if ($la === 0) {
            return $lb;
        }
        if ($lb === 0) {
            return $la;
        }
        $prev = range(0, $lb);
        for ($i = 1; $i <= $la; $i++) {
            $cur = [$i];
            for ($j = 1; $j <= $lb; $j++) {
                $cost = ($ga[$i - 1] === $gb[$j - 1]) ? 0 : 1;
                $cur[$j] = min($prev[$j] + 1, $cur[$j - 1] + 1, $prev[$j - 1] + $cost);
            }
            $prev = $cur;
        }
        return $prev[$lb];
    }

    /**
     * Compare a typed name against a registered name.
     *
     * @return array{score:float,kind:string,detail:string}
     *   kind: 'exact'     identical as written
     *         'homophone' identical after folding — same sounds, other letters
     *         'close'     near miss (typo or a partial entry)
     *         'different' does not match
     */
    public static function compare(string $typed, string $registered): array
    {
        $typed = self::trimAndCollapse($typed);
        $registered = self::trimAndCollapse($registered);
        if ($typed === '' || $registered === '') {
            return ['score' => 0.0, 'kind' => 'different', 'detail' => 'One of the names is empty.'];
        }

        if (self::eqi($typed, $registered)) {
            return ['score' => 1.0, 'kind' => 'exact', 'detail' => 'Spelled exactly as registered.'];
        }
        if (self::eqi(self::fold($typed), self::fold($registered))) {
            return [
                'score' => 0.97,
                'kind' => 'homophone',
                'detail' => 'Same sounds, different letters (for example ሰ/ሠ).',
            ];
        }

        // From here the comparison is on FIRST NAMES. The flow asks for the
        // first name only, so a typed first name has to score well against a
        // register row that stores the full name.
        $typedFirst = self::fold(self::firstName($typed));
        $regFirst = self::fold(self::firstName($registered));
        $len = count(self::chars($regFirst));

        // The first names agree outright. Because the flow asks for the first
        // name only, this IS a match against a register row that stores the
        // full name -- "ሰላም" against "ሰላም ተስፋዬ". The officer still sees the
        // full record on the verification card before approving.
        if ($typedFirst !== '' && $typedFirst === $regFirst) {
            // The fold made them equal, but that does NOT mean they are
            // written the same. If the letters differ this is a homophone
            // match, and saying "exact" here would be a small lie shown to
            // the officer at the moment they decide to approve somebody.
            // Report what is actually true; `agrees()` accepts both kinds,
            // so the gate behaves the same either way.
            if (self::eqi(self::firstName($typed), self::firstName($registered))) {
                return [
                    'score' => 1.0,
                    'kind' => 'exact',
                    'detail' => 'First name matches the registered name.',
                ];
            }
            return [
                'score' => 0.97,
                'kind' => 'homophone',
                'detail' => 'Same sounds, different letters (for example ሰ/ሠ).',
            ];
        }
        $dist = self::graphemeDistance($typedFirst, $regFirst);
        $similarity = $len > 0 ? max(0.0, 1.0 - ($dist / $len)) : 0.0;

        // A genuine prefix is a partial entry, not a wrong name. Only when
        // the typed name is SHORTER: "ናሆ" is a prefix of "ናሆም", but "ናሆም" is
        // not a prefix of "ናሆ".
        if ($typedFirst !== '' && $typedFirst !== $regFirst
            && count(self::chars($typedFirst)) < $len
            && str_starts_with($regFirst, $typedFirst)) {
            return ['score' => max($similarity, 0.9), 'kind' => 'close',
                    'detail' => 'Matches the start of the registered name.'];
        }

        if ($dist <= 1 && $len >= 4) {
            return ['score' => max($similarity, 0.85), 'kind' => 'close',
                    'detail' => 'One letter different (possible typo).'];
        }
        if ($dist <= 2 && $len >= 7) {
            return ['score' => max($similarity, 0.8), 'kind' => 'close',
                    'detail' => 'Two letters different (possible typo).'];
        }

        return ['score' => round($similarity, 2), 'kind' => 'different',
                'detail' => 'Does not match the registered spelling.'];
    }

    /**
     * Does this comparison count as "the name agrees" for the purpose of
     * keeping a claim out of the review queue?
     *
     * Only exact and homophone matches qualify. `close` deliberately does
     * NOT: a one-letter difference is also what a WRONG name looks like,
     * and the rule is that an unverified claim must not create office work.
     */
    public static function agrees(array $comparison): bool
    {
        return in_array($comparison['kind'], ['exact', 'homophone'], true);
    }

    // ---------------------------------------------------------------- utf8

    /** @return list<string> single graphemes */
    private static function chars(string $s): array
    {
        $out = @preg_split('//u', $s, -1, PREG_SPLIT_NO_EMPTY);
        return $out === false ? str_split($s) : $out;
    }

    private static function codepoint(string $ch): ?int
    {
        static $cache = [];
        if (isset($cache[$ch])) {
            return $cache[$ch];
        }
        $b = array_values(unpack('C*', $ch) ?: []);
        $n = count($b);
        if ($n === 0 || $n > 4) {
            return $cache[$ch] = null;
        }
        if ($n === 1) {
            $cp = $b[0];
        } elseif ($n === 2) {
            $cp = (($b[0] & 0x1F) << 6) | ($b[1] & 0x3F);
        } elseif ($n === 3) {
            $cp = (($b[0] & 0x0F) << 12) | (($b[1] & 0x3F) << 6) | ($b[2] & 0x3F);
        } else {
            $cp = (($b[0] & 0x07) << 18) | (($b[1] & 0x3F) << 12) | (($b[2] & 0x3F) << 6) | ($b[3] & 0x3F);
        }
        return $cache[$ch] = $cp;
    }

    private static function utf8(int $cp): string
    {
        if ($cp < 0x80) {
            return chr($cp);
        }
        if ($cp < 0x800) {
            return chr(0xC0 | ($cp >> 6)) . chr(0x80 | ($cp & 0x3F));
        }
        if ($cp < 0x10000) {
            return chr(0xE0 | ($cp >> 12)) . chr(0x80 | (($cp >> 6) & 0x3F)) . chr(0x80 | ($cp & 0x3F));
        }
        return chr(0xF0 | ($cp >> 18)) . chr(0x80 | (($cp >> 12) & 0x3F))
            . chr(0x80 | (($cp >> 6) & 0x3F)) . chr(0x80 | ($cp & 0x3F));
    }

    private static function eqi(string $a, string $b): bool
    {
        if (function_exists('mb_strtolower')) {
            return mb_strtolower($a, 'UTF-8') === mb_strtolower($b, 'UTF-8');
        }
        return strcasecmp($a, $b) === 0;
    }
}
