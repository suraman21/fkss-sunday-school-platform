<?php
/**
 * Progress page.
 */

declare(strict_types=1);

echo (new SSMS\Members\Http\Support\View())->render('partials/placeholder', [
    'heading' => 'ውጤት · Progress',
    'detail' => 'How far you have come, measured from your own completed work.',
    'title' => 'ውጤት · Progress',
    'active' => 'progress',
]);
