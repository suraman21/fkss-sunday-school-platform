<?php
/**
 * Home — the signed-in landing page.
 *
 * UI/UX CLONED FROM THE PROTOTYPE'S DASHBOARD
 *   docs/prototypes/member-app-2026-11-26/frontend/student-dashboard.html is a
 *   card grid with an emoji section heading per card and one action button. The
 *   same shapes are used here (fk-grid two, fk-card, fk-stat, fk-bar, fk-btn).
 *   The prototype's markup was not reused because it restyled HTML that no
 *   longer exists, but its LAYOUT is: verse at the top, then a grid of what a
 *   student came to check.
 *
 * WHAT IS LIVE NOW
 *   Department status and attendance are real, read from /api/v1/my/*. The
 *   cards paint a skeleton first and fill from the API, so a slow connection
 *   shows structure instead of blank space.
 */

declare(strict_types=1);

$isAm = ($_COOKIE['members_lang'] ?? 'am') !== 'en';

ob_start();
?>
<h1 class="fk-title"><?= $isAm ? 'መነሻ' : 'Home' ?></h1>
<p class="fk-sub"><?= $isAm ? 'እንኳን በደህና መጡ።' : 'Welcome back.' ?></p>

<div class="fk-verse" style="margin-bottom:18px">
    <p class="lbl"><?= $isAm ? 'የዕለቱ ቃል' : 'Verse of the day' ?></p>
    <p style="margin:0;font-size:18px;line-height:1.7">
        <?= $isAm
            ? '“ጌታ እረኛዬ ነው፥ የሚያጎድልብኝ የለም።”'
            : '“The Lord is my shepherd; I shall not want.”' ?>
    </p>
    <span class="src"><?= $isAm ? 'መዝሙር 23፥1' : 'Psalm 23:1' ?></span>
</div>

<!-- Painted by my.js from /api/v1/my/profile and /api/v1/my/attendance. -->
<div id="home-summary">
    <div class="fk-grid two">
        <div class="fk-skel"></div>
        <div class="fk-skel"></div>
    </div>
</div>

<h2 class="fk-section-title"><?= $isAm ? 'የእኔ' : 'Mine' ?></h2>

<div class="fk-grid two">
    <div class="fk-card">
        <h3><?= $isAm ? '📋 የእኔ መረጃ' : '📋 My information' ?></h3>
        <p class="fk-help"><?= $isAm
            ? 'ስምዎ፣ ክፍልዎና የመገኛ አድራሻዎ።'
            : 'Your name, section and contact details.' ?></p>
        <a class="fk-btn ghost" href="/members/profile">
            <?= $isAm ? 'ይመልከቱ' : 'View' ?>
        </a>
    </div>

    <div class="fk-card">
        <h3><?= $isAm ? '📊 የመገኘት ሁኔታ' : '📊 Attendance' ?></h3>
        <p class="fk-help"><?= $isAm
            ? 'የተገኙባቸው ቀናትና መቶኛ።'
            : 'The days you attended, and the percentage.' ?></p>
        <a class="fk-btn ghost" href="/members/attendance">
            <?= $isAm ? 'ይመልከቱ' : 'View' ?>
        </a>
    </div>

    <div class="fk-card">
        <h3><?= $isAm ? '📈 ውጤቶቼ' : '📈 My grades' ?></h3>
        <p class="fk-help"><?= $isAm
            ? 'በትምህርት ቤቱ የተመዘገቡ ውጤቶች።'
            : 'Results the school has recorded.' ?></p>
        <a class="fk-btn ghost" href="/members/grades">
            <?= $isAm ? 'ይመልከቱ' : 'View' ?>
        </a>
    </div>

    <div class="fk-card">
        <h3><?= $isAm ? '📚 ትምህርቶቼ' : '📚 Lessons' ?></h3>
        <p class="fk-help"><?= $isAm
            ? 'የተመደቡልዎትን ትምህርቶች ይመልከቱ።'
            : 'The lessons assigned to you.' ?></p>
        <a class="fk-btn ghost" href="/members/lessons">
            <?= $isAm ? 'ይመልከቱ' : 'View' ?>
        </a>
    </div>
</div>

<h2 class="fk-section-title"><?= $isAm ? 'በቅርቡ የሚመጡ' : 'Coming soon' ?></h2>
<div class="fk-chips">
    <?php
    // Same set as the feature registry in partials/placeholder.php, so the two
    // cannot disagree about what is built.
    $comingSoon = $isAm
        ? ['ፈተናዬ' => '/members/quiz/1', 'ተግባሮች' => '/members/tasks',
           'ውጤት' => '/members/progress', 'ፈተና ዝግጅት' => '/members/exam-prep',
           'አጋዥ' => '/members/assistant']
        : ['Quiz me' => '/members/quiz/1', 'Tasks' => '/members/tasks',
           'Progress' => '/members/progress', 'Exam prep' => '/members/exam-prep',
           'Assistant' => '/members/assistant'];
    foreach ($comingSoon as $label => $href): ?>
        <a class="fk-chip" href="<?= e($href) ?>" style="text-decoration:none"><?= e($label) ?></a>
    <?php endforeach; ?>
</div>
<?php
$body = (string)ob_get_clean();

echo (new SSMS\Members\Http\Support\View())->render('layouts/base', [
    'content' => $body,
    'title' => $isAm ? 'መነሻ' : 'Home',
    'active' => 'home',
    'requiresAuth' => true,
    'myPage' => true,
]);
