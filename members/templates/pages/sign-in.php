<?php
/**
 * Sign-in shell. The form is inert on purpose (see public/login.php).
 *
 * @var callable $e
 * @var array $data
 */
$notice = $data['notice'] ?? null;
?>
    <h1 class="mp-title">Sign in</h1>

    <?php if ($notice !== null && $notice !== ''): ?>
      <p class="mp-notice" role="status"><?= $e($notice) ?></p>
    <?php endif; ?>

    <form class="mp-form" method="post" action="/members/login" novalidate>
      <label class="mp-field">
        <span class="mp-field__label">Username or email</span>
        <input class="mp-field__input" type="text" name="username"
               autocomplete="username" required disabled>
      </label>

      <label class="mp-field">
        <span class="mp-field__label">Password</span>
        <input class="mp-field__input" type="password" name="password"
               autocomplete="current-password" required disabled>
      </label>

      <button class="mp-button" type="submit" disabled>Sign in</button>
    </form>

    <p class="mp-hint">Sign-in is not available yet.</p>
