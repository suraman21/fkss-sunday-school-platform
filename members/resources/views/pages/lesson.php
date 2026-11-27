<?php
/**
 * Lesson reader page.
 */

declare(strict_types=1);

echo (new SSMS\Members\Http\Support\View())->render('partials/placeholder', [
    'heading' => 'ትምህርት · Lesson',
    'detail' => 'A single lesson, with its reading text and a way to mark it complete.',
    'title' => 'ትምህርት · Lesson',
    'active' => 'lessons',
]);
