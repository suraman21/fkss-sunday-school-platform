<?php
/**
 * My account page.
 */

declare(strict_types=1);

echo (new SSMS\Members\Http\Support\View())->render('partials/placeholder', [
    'heading' => 'መለያዬ · My account',
    'detail' => 'Your sign-in details and how to change your password.',
    'title' => 'መለያዬ · My account',
    'active' => '',
]);
