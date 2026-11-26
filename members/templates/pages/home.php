<?php
/**
 * Landing page. Receives $data only.
 *
 * @var callable $e
 * @var array $data
 */
$name = (string)($data['session']['full_name'] ?? '');
if ($name === '') {
    $name = (string)($data['session']['username'] ?? '');
}
?>
    <h1 class="mp-title">Welcome<?= $name !== '' ? ', ' . $e($name) : '' ?></h1>
    <p class="mp-lead">Your lessons, attendance and progress will appear here.</p>

    <section class="mp-cards" aria-label="Sections">
      <p class="mp-empty">Nothing to show yet.</p>
    </section>
