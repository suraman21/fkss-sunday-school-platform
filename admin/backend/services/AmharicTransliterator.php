<?php
/**
 * Amharic (Fidel) → Latin transliteration for SEARCH ONLY.
 *
 * Two deterministic forms per name (no per-character variant explosion):
 * - PRIMARY: 6th-order (sadis) bare consonants, as Ethiopians usually write
 *   names ("Selam" ሰላም, "Hakim" ሐኪም, "Tsion" ጽዮን). Order 1 (ä) renders
 *   "e" in consonant families (Bekele በቀለ, Meseret መሰረት) but አ→"a"
 *   (Abebe) and ሀ→"ha" (Habtamu ሀብታሙ, Hanna ሐና).
 * - SECONDARY: 6th orders vocalized with "i" plus structural alternates
 *   (q→k, y→i, w→u, ts→s), covering spellings like "Girma" ግርማ or "Birr".
 *   Residual "e/i" wobble ("Leul" vs "Liul") is within fuzzy distance 1.
 *
 * Remaining variance (typos, doubled letters, q/k initials) is covered by
 * the tokenizer's Latin light-normalization plus TNTSearch fuzzy matching.
 *
 * Input is folded with AmharicIdentityNormalizer FIRST, so the table only
 * needs folded families (ሐ→ሀ, ሠ→ሰ, ዐ/ኣ→አ, ፀ→ጸ are handled upstream).
 * Unmapped characters (Latin, digits, punctuation, rare/extended Fidel
 * such as labiovelars) pass through UNCHANGED — an incomplete table must
 * never destroy a searchable token. Table gaps are a P1 extension: bump
 * VERSION and re-backfill the index.
 *
 * Version the output so mapping changes can be backfilled deliberately.
 */
namespace App\Services;

require_once __DIR__ . '/AmharicIdentityNormalizer.php';

final class AmharicTransliterator
{
    public const VERSION = 1;

    /**
     * Order vowels 2–7. Order 6 is bare in the primary form.
     * Order 1 (ä) is per-family (see orderOneVowel).
     */
    private const VOWELS = ['a', 'u', 'i', 'a', 'e', '', 'o'];

    /**
     * [latin base, 7 Fidel orders as one literal string].
     * A LIST (not assoc): several families share a base (ተ/ጠ→t, ቸ/ጨ→ch,
     * ፐ/ጰ→p) because ejective vs plain is not distinguished in search.
     * NOTE: ወ-family order 2 is ዉ (U+1291); order 6 is ው (U+1295).
     * Folding maps ዉ→ው upstream, so both surface as bare "w" here.
     *
     * @var array<int, array{0:string, 1:string}>
     */
    private const FAMILIES = [
        ['h',  'ሀሁሂሃሄህሆ'],
        ['l',  'ለሉሊላሌልሎ'],
        ['m',  'መሙሚማሜምሞ'],
        ['r',  'ረሩሪራሬርሮ'],
        ['s',  'ሰሱሲሳሴስሶ'],
        ['sh', 'ሸሹሺሻሼሽሾ'],
        ['q',  'ቀቁቂቃቄቅቆ'],
        ['b',  'በቡቢባቤብቦ'],
        ['t',  'ተቱቲታቴትቶ'],
        ['ch', 'ቸቹቺቻቼችቾ'],
        ['n',  'ነኑኒናኔንኖ'],
        ['ny', 'ኘኙኚኛኜኝኞ'],
        ['',   'አኡኢኣኤእኦ'],
        ['k',  'ከኩኪካኬክኮ'],
        ['w',  'ወዉዊዋዌውዎ'],
        ['z',  'ዘዙዚዛዜዝዞ'],
        ['zh', 'ዠዡዢዣዤዥዦ'],
        ['y',  'የዩዪያዬይዮ'],
        ['d',  'ደዱዲዳዴድዶ'],
        ['g',  'ገጉጊጋጌግጎ'],
        ['j',  'ጀጁጂጃጄጅጆ'],
        ['t',  'ጠጡጢጣጤጥጦ'],
        ['ch', 'ጨጩጪጫጬጭጮ'],
        ['p',  'ጰጱጲጳጴጵጶ'],
        ['ts', 'ጸጹጺጻጼጽጾ'],
        ['f',  'ፈፉፊፋፌፍፎ'],
        ['p',  'ፐፑፒፓፔፕፖ'],
    ];

    /** Stragglers that folding usually removes, kept for robustness. */
    private const EXTRA = [
        'ዉ' => 'w', // folds to ው upstream; bare "w" either way (እውነት→ewnet)
    ];

    /** @var array<string,string>|null */
    private static $map = null;
    /** @var array<string,string>|null */
    private static $vocalMap = null;

    private static function orderOneVowel(string $base): string
    {
        // ä renders "e" in consonant families (be/ke/le/me/ne/se/te…),
        // but the glottal stop and h-families keep "a" (Abebe, Habtamu).
        return ($base === '' || $base === 'h') ? 'a' : 'e';
    }

    /** @return array{0:array<string,string>,1:array<string,string>} */
    private static function maps(): array
    {
        if (self::$map !== null && self::$vocalMap !== null) {
            return [self::$map, self::$vocalMap];
        }
        $map = self::EXTRA;
        $vocal = self::EXTRA;
        foreach (self::FAMILIES as $family) {
            [$base, $chars] = $family;
            $list = preg_split('//u', $chars, -1, PREG_SPLIT_NO_EMPTY);
            if (!is_array($list) || count($list) !== 7) {
                continue; // a corrupt row must not poison the table
            }
            foreach ($list as $order => $fidel) {
                if ($order === 0) {
                    $latin = $base . self::orderOneVowel($base);
                    $map[$fidel] = $latin;
                    $vocal[$fidel] = $latin;
                } elseif ($order === 5) {
                    // Primary: bare sadis. Secondary: vocalized with "i".
                    $bare = $base === '' ? 'e' : $base; // እ can never be bare
                    $map[$fidel] = $bare;
                    $vocal[$fidel] = $base . 'i';
                    if ($vocal[$fidel] === 'i') {
                        $vocal[$fidel] = 'e'; // እ stays "e" in both forms
                    }
                } else {
                    $latin = $base . self::VOWELS[$order];
                    $map[$fidel] = $latin;
                    $vocal[$fidel] = $latin;
                }
            }
        }
        self::$map = $map;
        self::$vocalMap = $vocal;
        return [$map, $vocal];
    }

    private static function folded($value): string
    {
        if (!is_scalar($value)) {
            return '';
        }
        return AmharicIdentityNormalizer::normalize($value);
    }

    private static function render(string $folded, bool $vocalizeSixth): string
    {
        [$map, $vocal] = self::maps();
        $table = $vocalizeSixth ? $vocal : $map;
        $chars = preg_split('//u', $folded, -1, PREG_SPLIT_NO_EMPTY);
        if (!is_array($chars)) {
            return $folded;
        }
        $out = '';
        foreach ($chars as $ch) {
            $out .= $table[$ch] ?? $ch;
        }
        return $out;
    }

    /** PRIMARY Latin form (bare 6th orders). */
    public static function transliterate($value): string
    {
        $folded = self::folded($value);
        return $folded === '' ? '' : self::render($folded, false);
    }

    /** SECONDARY Latin form (vocalized 6ths + structural alternates). */
    public static function vocalized($value): string
    {
        $folded = self::folded($value);
        return $folded === '' ? '' : self::loose(self::render($folded, true));
    }

    /**
     * Structural alternates fuzzy matching cannot bridge cheaply
     * (q/k, y/i, w/u glides, ts/s). Input: lowercase Latin.
     */
    public static function loose(string $latin): string
    {
        if ($latin === '') {
            return '';
        }
        return strtr($latin, ['ts' => 's', 'q' => 'k', 'y' => 'i', 'w' => 'u']);
    }

    /**
     * Both searchable Latin forms for one input (duplicates removed).
     * @return string[]
     */
    public static function variants($value): array
    {
        $primary = self::transliterate($value);
        if ($primary === '') {
            return [];
        }
        $secondary = self::vocalized($value);
        if ($secondary === '' || $secondary === $primary) {
            return [$primary];
        }
        return [$primary, $secondary];
    }
}
