<?php
/**
 * Lessons page.
 */

declare(strict_types=1);

echo (new SSMS\Members\Http\Support\View())->render('partials/placeholder', [
    'heading' => 'ትምህርቶች · Lessons',
    'detail' => 'Your subjects, units and lessons in the order the school sets them.',
    'title' => 'ትምህርቶች · Lessons',
    'active' => 'lessons',
]);
