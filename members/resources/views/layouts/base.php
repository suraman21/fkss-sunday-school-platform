<?php
/**
 * Members app — base layout.
 *
 * @var string $content  rendered page body
 * @var string $title    page title (already a literal, never user input)
 * @var string $active   nav key for aria-current
 * @var bool   $chromeless  welcome/login screens hide the header and nav
 */

declare(strict_types=1);

use SSMS\Members\Http\Support\View;

$lang = $_COOKIE['members_lang'] ?? 'am';
if (!in_array($lang, View::locales(), true)) {
    $lang = 'am';
}
$isAm = $lang === 'am';
$chrome = $chromeless ?? false;
$active = $active ?? '';
$title = $title ?? '';

/** Navigation labels, in both languages. One table, no scattered conditionals. */
$nav = [
    'home' => ['am' => 'መነሻ', 'en' => 'Home', 'icon' => '⌂'],
    'lessons' => ['am' => 'ትምህርቶች', 'en' => 'Lessons', 'icon' => '📖'],
    'tasks' => ['am' => 'ተግባሮች', 'en' => 'Tasks', 'icon' => '✓'],
    'progress' => ['am' => 'ውጤት', 'en' => 'Progress', 'icon' => '◔'],
    'exam' => ['am' => 'ፈተና', 'en' => 'Exam prep', 'icon' => '✎'],
];

/** Assembly-time version stamp: a new deploy busts the browser cache without
 *  anyone editing a query string by hand. */
$assetVersion = (string)(@filemtime(MEMBERS_ROOT . '/public/assets/js/app.js') ?: 1);
$learningVersion = (string)(@filemtime(MEMBERS_ROOT . '/public/assets/js/learning.js') ?: 1);
?>
<!DOCTYPE html>
<html lang="<?= $isAm ? 'am' : 'en' ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="color-scheme" content="light">
<meta name="theme-color" content="#64211C">
<meta name="robots" content="noindex, nofollow">
<title><?= $title !== '' ? e($title) . ' · ' : '' ?>ፈለገ ቅዱሳን</title>
<link rel="stylesheet" href="/members/public/assets/css/theme.css?v=<?= e($assetVersion) ?>">
</head>
<body<?= !empty($requiresAuth) ? ' data-requires-auth="1"' : '' ?>>

<?php if (!$chrome): ?>
<header class="fk-header">
    <a class="fk-brand" href="/members/home"><span>ፈለገ ቅዱሳን</span></a>
    <span class="fk-spacer"></span>
    <div class="fk-lang-toggle" role="group" aria-label="<?= $isAm ? 'ቋንቋ' : 'Language' ?>">
        <button type="button" data-lang="am" aria-pressed="<?= $isAm ? 'true' : 'false' ?>">አማ</button>
        <button type="button" data-lang="en" aria-pressed="<?= $isAm ? 'false' : 'true' ?>">EN</button>
    </div>
    <button type="button" class="fk-btn ghost" data-action="logout"
            style="min-height:40px;padding:6px 14px;font-size:14px">
        <?= $isAm ? 'ውጣ' : 'Sign out' ?>
    </button>
</header>
<?php endif; ?>

<div class="fk-church" aria-hidden="true"></div>

<main class="fk-page" id="main">
<?= $content ?>
</main>

<?php if (!$chrome): ?>
<nav class="fk-bottom-nav" aria-label="<?= $isAm ? 'ዋና ገጾች' : 'Main pages' ?>">
    <?php foreach ($nav as $key => $item):
        $href = match ($key) {
            'home' => '/members/home',
            'lessons' => '/members/lessons',
            'tasks' => '/members/tasks',
            'progress' => '/members/progress',
            'exam' => '/members/exam-prep',
        };
        ?>
    <a href="<?= e($href) ?>"
       <?= $active === $key ? 'aria-current="page"' : '' ?>>
        <span class="fk-ico" aria-hidden="true"><?= $item['icon'] ?></span>
        <span class="fk-lbl"><?= $isAm ? $item['am'] : $item['en'] ?></span>
    </a>
    <?php endforeach; ?>
</nav>
<?php endif; ?>

<div class="fk-toast" id="toast" role="status" aria-live="polite" hidden></div>

<script src="/members/public/assets/js/api.js?v=<?= e($assetVersion) ?>" defer></script>
<script src="/members/public/assets/js/app.js?v=<?= e($assetVersion) ?>" defer></script>
<?php if (!empty($learningPage)): ?>
<script src="/members/public/assets/js/learning.js?v=<?= e($learningVersion) ?>" defer></script>
<?php endif; ?>
</body>
</html>
