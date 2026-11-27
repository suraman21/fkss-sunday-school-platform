<?php
/**
 * Exam preparation page.
 */

declare(strict_types=1);

echo (new SSMS\Members\Http\Support\View())->render('partials/placeholder', [
    'heading' => 'ፈተና ዝግጅት · Exam prep',
    'detail' => 'Past papers and timed practice for your grade.',
    'title' => 'ፈተና ዝግጅት · Exam prep',
    'active' => 'exam',
]);
