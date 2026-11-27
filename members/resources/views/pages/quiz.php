<?php
/**
 * quiz page — placeholder until its feature lands.
 * The feature registry in partials/placeholder.php decides what is said.
 */

declare(strict_types=1);

echo (new SSMS\Members\Http\Support\View())->render('partials/placeholder', [
    'feature' => 'quiz',
    'active' => 'lessons',
]);
