<?php
/**
 * Members app — HTML escaping helper.
 *
 * A SEPARATE FILE, not a function at the bottom of View.php, because PHP
 * declares namespace-level functions at COMPILE time, not at require time
 * (unlike classes, which are autoloaded). A function defined in View.php only
 * exists once View.php has been required — so any file that used e() before
 * loading View would die with "undefined function", and a test caught exactly
 * that. Owning one file, required early, removes the ordering trap.
 *
 * DELIBERATELY IN THE GLOBAL NAMESPACE.
 *   Templates are ordinary PHP files with no `namespace` declaration, so they
 *   are resolved in the GLOBAL namespace. An `e()` nested inside
 *   SSMS\Members\Http\Support would be invisible to them: `e($title)` in a
 *   template would fatal with "call to undefined function", and every page
 *   would 500. The escaping helper has to live where its callers are.
 *
 * Named `e()` rather than `View::escape()` so a missed escape is visually
 * obvious in review: `<?= $name ?>` next to `<?= e($name) ?>`.
 */

declare(strict_types=1);


/**
 * Escape for HTML text and attribute contexts.
 *
 * ENT_QUOTES covers both quote styles; ENT_SUBSTITUTE turns invalid UTF-8 into
 * U+FFFD instead of returning an EMPTY string. That distinction matters: an
 * empty escape is how a "sanitised" value silently becomes an attribute
 * breakout. The explicit charset is required — htmlspecialchars defaults to
 * ISO-8859-1 on older builds, which mangles Amharic.
 */
function e(?string $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
