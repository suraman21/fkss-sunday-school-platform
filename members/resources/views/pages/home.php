<?php
/**
 * Home — the signed-in landing page.
 *
 * WHAT THIS PAGE HONESTLY IS
 *   The design is real: header, bottom navigation, verse card, and the
 *   "continue where you left off" shape the prototype had. What it does NOT do
 *   is invent past progress. Until the learning endpoint exists there is no
 *   "continue" to continue from, so the card says so.
 *
 *   This is the one page where a member would be most misled by sample data —
 *   a fabricated "Lesson 3 of 12" looks exactly like a working app and exactly
 *   like a broken one.
 */

declare(strict_types=1);

$lang = $_COOKIE['members_lang'] ?? 'am';
$isAm = $lang !== 'en';

ob_start();
?>
<h1 class="fk-title">መነሻ</h1>
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

<div class="fk-grid two">
    <div class="fk-card">
        <h3><?= $isAm ? 'ትምህርትዎን ይቀጥሉ' : 'Continue learning' ?></h3>
        <p class="fk-help">
            <?= $isAm
                ? 'የትምህርት ክፍሉ ገና በመገንባት ላይ ነው። ሲዘጋጅ የቀጣዩ ትምህርትዎ እዚህ ይታያል።'
                : 'The lessons section is still being built. Your next lesson will appear '
                  . 'here once it is ready.' ?>
        </p>
        <a class="fk-btn ghost" href="/members/lessons">
            <?= $isAm ? 'ትምህርቶችን ይመልከቱ' : 'See lessons' ?>
        </a>
    </div>

    <div class="fk-card">
        <h3><?= $isAm ? 'ማሳወቂያዎች' : 'Announcements' ?></h3>
        <p class="fk-empty" style="padding:0;text-align:left">
            <?= $isAm ? 'እስካሁን ማሳወቂያ የለም።' : 'No announcements yet.' ?>
        </p>
    </div>
</div>

<h2 class="fk-section-title"><?= $isAm ? 'በቅርቡ የሚመጡ' : 'Coming soon' ?></h2>
<div class="fk-chips">
    <?php
    /** Same registry the placeholder pages read, so the two can never disagree. */
    $comingSoon = $isAm
        ? ['ትምህርቶች', 'ፈተናዬ', 'ተግባሮች', 'ውጤት', 'ፈተና ዝግጅት', 'አጋዥ']
        : ['Lessons', 'Quiz me', 'Tasks', 'Progress', 'Exam prep', 'Assistant'];
    $comingHrefs = [
        '/members/lessons', '/members/quiz/1', '/members/tasks',
        '/members/progress', '/members/exam-prep', '/members/assistant',
    ];
    foreach ($comingSoon as $i => $label): ?>
        <a class="fk-chip" href="<?= e($comingHrefs[$i]) ?>" style="text-decoration:none"><?= e($label) ?></a>
    <?php endforeach; ?>
</div>
<?php
$body = (string)ob_get_clean();

echo (new SSMS\Members\Http\Support\View())->render('layouts/base', [
    'content' => $body,
    'title' => $isAm ? 'መነሻ' : 'Home',
    'active' => 'home',
]);
