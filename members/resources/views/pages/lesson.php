<?php
/**
 * Lesson reader — placeholder.
 *
 * Takes a route parameter ({id}) and ignores it on purpose: rendering "Lesson
 * 42" from a URL would imply the lesson exists. The endpoint that can answer
 * "does this lesson exist and is it open to this member?" is not built yet.
 */

declare(strict_types=1);

echo (new SSMS\Members\Http\Support\View())->render('partials/placeholder', [
    'feature' => 'lessons',
    'active' => 'lessons',
]);
