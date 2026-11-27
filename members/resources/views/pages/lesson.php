<?php
/**
 * Lesson reader.
 *
 * The route parameter is validated as a positive integer HERE, in the template,
 * before it is emitted into an attribute. A non-numeric id is a 404 rather than
 * a request to the API with junk in the path.
 *
 * @var array{id:string} $params
 */

declare(strict_types=1);

$isAm = ($_COOKIE['members_lang'] ?? 'am') !== 'en';
$lessonId = (string)($params['id'] ?? '');
if (preg_match('/^[1-9][0-9]{0,9}$/', $lessonId) !== 1) {
    http_response_code(404);
    echo (new SSMS\Members\Http\Support\View())->render('pages/not_found');
    return;
}

ob_start();
?>
<div id="lesson-root" data-lesson-id="<?= e($lessonId) ?>">
    <div class="fk-skel"></div>
</div>
<?php
$body = (string)ob_get_clean();

echo (new SSMS\Members\Http\Support\View())->render('layouts/base', [
    'content' => $body,
    'title' => $isAm ? 'ትምህርት' : 'Lesson',
    'active' => 'lessons',
    'requiresAuth' => true,
    'learningPage' => true,
]);
