<?php
/**
 * TNTSearch tokenizer with the SSMS Amharic pipeline baked in.
 *
 * TNTSearch persists the tokenizer class name inside the index and restores
 * it at query time, so index-time and query-time normalization are the same
 * code path by construction. Original spellings are never altered here —
 * this only shapes the searchable tokens.
 *
 * Pipeline: AmharicIdentityNormalizer (NFC + verified homophone folding +
 * Ethiopic punctuation + lowercase) → Latin light normalization (strip
 * transliteration quotes, collapse doubled ASCII letters: "Tadesse"→"Tadese")
 * → default Unicode-word split (Ethiopic letters are \p{L}, kept as tokens).
 *
 * NOTE: this file extends a vendor class. Load it only after
 * vendor/autoload.php (MemberSearchService::available() guards this).
 */
namespace App\Services;

use TeamTNT\TNTSearch\Tokenizer\Tokenizer;
use TeamTNT\TNTSearch\Tokenizer\TokenizerInterface;

require_once __DIR__ . '/AmharicIdentityNormalizer.php';

final class EthiopicSearchTokenizer extends Tokenizer implements TokenizerInterface
{
    public function tokenize($text, $stopwords = [])
    {
        $text = AmharicIdentityNormalizer::normalize($text);
        if ($text === '') {
            return [];
        }
        $text = self::latinLightNorm($text);
        return parent::tokenize($text, $stopwords);
    }

    /**
     * Latin-side light normalization. ASCII-only by design: it must never
     * alter Ethiopic characters (repeated Fidel syllables can be legitimate).
     */
    public static function latinLightNorm($text): string
    {
        if (!is_scalar($text)) {
            return '';
        }
        $text = (string)$text;
        // Transliteration quote marks: ASCII apostrophe/backtick, modifier
        // letters ʻʼʾʿ (U+02BB–U+02BF range, commonly U+02BE/U+02BF) and
        // curly quotes. Removed (not spaced): "tse'hay" → "tsehay".
        $text = str_replace(
            ["'", '`', 'ʻ', 'ʼ', 'ʾ', 'ʿ', '‘', '’', '‚', '‛', '"', '“', '”', '„'],
            '',
            $text
        );
        // Doubled Latin letters are spelling noise in Amharic names
        // ("Tadesse"/"Tadese", "Birr"/"Bir"). Collapse runs to one.
        $collapsed = preg_replace('/([a-z])\1+/u', '$1', $text);
        if (is_string($collapsed)) {
            $text = $collapsed;
        }
        return $text;
    }
}
