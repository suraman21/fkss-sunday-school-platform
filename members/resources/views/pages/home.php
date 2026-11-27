<?php
/**
 * Home page.
 */

declare(strict_types=1);

echo (new SSMS\Members\Http\Support\View())->render('partials/placeholder', [
    'heading' => 'መነሻ · Home',
    "detail" => "Today's verse, your next lesson and any announcements for you.",
    'title' => 'መነሻ · Home',
    'active' => 'home',
]);
