<?php
/**
 * Shared "not built yet" body.
 *
 * WHY A PLACEHOLDER IS HONEST HERE, AND SAMPLE DATA WOULD NOT BE
 *   A page showing invented lessons looks identical to a page whose API call
 *   failed, so nobody can tell whether the contract works. This renders the
 *   real layout, the real navigation and a plain statement of what is missing —
 *   which is also what the prototype's handover recommended for unfinished
 *   features ("honest 'not available yet' cards").
 *
 * @var string $heading  page heading
 * @var string $detail   one sentence of what this page will do
 * @var string $active   nav key
 * @var string $title    document title
 */

declare(strict_types=1);

$lang = $_COOKIE['members_lang'] ?? 'am';
$isAm = $lang !== 'en';

ob_start();
?>
<h1 class="fk-title"><?= e($heading) ?></h1>
<div class="fk-card">
    <p><?= e($detail) ?></p>
    <p class="fk-help">
        <?= $isAm
            ? 'ይህ ክፍል ገና በመገንባት ላይ ነው።'
            : 'This section is still being built.' ?>
    </p>
</div>
<?php
$body = (string)ob_get_clean();

echo (new SSMS\Members\Http\Support\View())->render('layouts/base', [
    'content' => $body,
    'title' => $title,
    'active' => $active,
]);
