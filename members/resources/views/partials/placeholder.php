<?php
/**
 * "Coming soon" placeholder.
 *
 * WHY THIS EXISTS RATHER THAN SAMPLE DATA
 *   A page filled with invented lessons is indistinguishable from a page whose
 *   API call failed, so nobody can tell whether the contract works. And a page
 *   that *looks* finished invites someone to report it as broken. This states
 *   plainly which of the two situations applies.
 *
 * WHY THE FEATURE LIST IS DATA, NOT MARKUP
 *   Every feature the prototype had is registered once in $features below with
 *   its status. That makes "what is left" answerable from one place, and stops
 *   a page quietly disappearing from the navigation without anyone deciding it
 *   should.
 *
 * @var string $feature  key into the registry
 */

declare(strict_types=1);

$lang = $_COOKIE['members_lang'] ?? 'am';
$isAm = $lang !== 'en';

/**
 * Feature registry.
 *
 *   status: 'live'   shipping now, this page is real
 *           'soon'   designed, planned, not built
 *
 * `owner` records whose work it is: 'api' means it is waiting on an endpoint,
 * 'content' means it is waiting on data a human must write (lesson text, quiz
 * questions, past papers). That distinction is the difference between "blocked
 * on me" and "blocked on the school", and it belongs on the screen.
 */
$features = [
    'lessons' => [
        'am' => 'ትምህርቶች', 'en' => 'Lessons',
        'status' => 'soon', 'owner' => 'api',
        'detail_am' => 'የእያንዳንዱ ትምህርት ጽሑፍ፣ በትምህርት ቤቱ በሚወስነው ቅደም ተከተል።',
        'detail_en' => 'Each lesson text, in the order the school sets.',
        'blocked_en' => 'Waiting on the learning endpoint.',
    ],
    'quiz' => [
        'am' => 'ፈተናዬ', 'en' => 'Quiz me',
        'status' => 'soon', 'owner' => 'content',
        'detail_am' => 'የክፍል ፈተና፣ ከመልስ ማብራሪያ ጋር።',
        'detail_en' => 'The unit quiz, with explanations for each answer.',
        'blocked_en' => 'Waiting on question content, which a teacher must write.',
    ],
    'tasks' => [
        'am' => 'ተግባሮች', 'en' => 'Tasks',
        'status' => 'soon', 'owner' => 'api',
        'detail_am' => 'በአስተማሪዎችዎ የተሰጡ ሥራዎች።',
        'detail_en' => 'Work your teachers have set, and what is outstanding.',
        'blocked_en' => 'Waiting on a tasks endpoint.',
    ],
    'progress' => [
        'am' => 'ውጤት', 'en' => 'Progress',
        'status' => 'soon', 'owner' => 'api',
        'detail_am' => 'የተጠናቀቁ ትምህርቶችና የፈተና ውጤቶች።',
        'detail_en' => 'Completed lessons and quiz results, from your own work.',
        'blocked_en' => 'Needs lessons and quizzes before it can show anything.',
    ],
    'exam_prep' => [
        'am' => 'ፈተና ዝግጅት', 'en' => 'Exam prep',
        'status' => 'soon', 'owner' => 'content',
        'detail_am' => 'ያለፉ ፈተናዎችና የጊዜ ገደብ ልምምድ።',
        'detail_en' => 'Past papers and timed practice for your grade.',
        'blocked_en' => 'The prototype also left this unfinished: past papers exist, but the '
            . 'study-plan scheduler and timed mocks were never built.',
    ],
    'assistant' => [
        'am' => 'አጋዥ', 'en' => 'Assistant',
        'status' => 'soon', 'owner' => 'api',
        'detail_am' => 'በተከፈቱ ትምህርቶችዎ ውስጥ ፍለጋ።',
        'detail_en' => 'Search inside the lessons that are open to you.',
        'blocked_en' => 'The prototype shipped keyword search only; a real assistant needs a '
            . 'server-side key and a decision about cost.',
    ],
    'notifications' => [
        'am' => 'ማሳወቂያዎች', 'en' => 'Announcements',
        'status' => 'soon', 'owner' => 'api',
        'detail_am' => 'ከትምህርት ቤቱ የሚላኩ ማሳወቂያዎች።',
        'detail_en' => 'Notices sent by the school.',
        'blocked_en' => 'The notifications table exists; a member-facing read endpoint does not.',
    ],
];

$feature = $feature ?? 'lessons';
$spec = $features[$feature] ?? $features['lessons'];
$heading = $isAm ? $spec['am'] : $spec['en'];
$detail = $isAm ? $spec['detail_am'] : $spec['detail_en'];

ob_start();
?>
<h1 class="fk-title"><?= e($heading) ?></h1>

<div class="fk-card">
    <p class="fk-note" style="display:inline-block;margin-bottom:.8rem">
        <?= $isAm ? 'በቅርቡ ይመጣል' : 'Coming soon' ?>
    </p>
    <p><?= e($detail) ?></p>
    <p class="fk-help">
        <?= $isAm
            ? 'ይህ ክፍል ገና በመገንባት ላይ ነው።'
            : 'This section is still being built.' ?>
    </p>
    <?php if (!$isAm && !empty($spec['blocked_en'])): ?>
    <p class="fk-help" style="margin-top:.6rem">
        <strong>Status:</strong> <?= e($spec['blocked_en']) ?>
    </p>
    <?php endif; ?>
</div>

<p class="fk-help fk-center" style="margin-top:1.2rem">
    <a href="/members/home"><?= $isAm ? 'ወደ መነሻ ተመለስ' : 'Back to home' ?></a>
</p>
<?php
$body = (string)ob_get_clean();

echo (new SSMS\Members\Http\Support\View())->render('layouts/base', [
    'content' => $body,
    'title' => $heading,
    'active' => $active ?? '',
]);
