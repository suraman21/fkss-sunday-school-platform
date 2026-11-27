<?php
/**
 * Unit quiz page.
 */

declare(strict_types=1);

echo (new SSMS\Members\Http\Support\View())->render('partials/placeholder', [
    'heading' => 'ፈተና · Unit quiz',
    'detail' => 'The quiz that unlocks the next unit in this subject.',
    'title' => 'ፈተና · Unit quiz',
    'active' => 'lessons',
]);
