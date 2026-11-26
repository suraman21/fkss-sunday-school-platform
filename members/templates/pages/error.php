<?php
/**
 * Error page. Shows only a status and a message already judged safe — never
 * an exception message, a path or a query.
 *
 * @var callable $e
 * @var array $data status, message, session
 */
$status = (int)($data['status'] ?? 500);
?>
    <p class="mp-error__code"><?= $e($status) ?></p>
    <h1 class="mp-title"><?= $e($data['message'] ?? 'Something went wrong.') ?></h1>
    <p class="mp-hint"><a href="/members">Back to Members</a></p>
