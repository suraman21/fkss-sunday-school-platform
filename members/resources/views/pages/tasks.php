<?php
/**
 * Tasks page.
 */

declare(strict_types=1);

echo (new SSMS\Members\Http\Support\View())->render('partials/placeholder', [
    'heading' => 'ተግባሮች · Tasks',
    'detail' => 'Work your teachers have set, and what is still outstanding.',
    'title' => 'ተግባሮች · Tasks',
    'active' => 'tasks',
]);
