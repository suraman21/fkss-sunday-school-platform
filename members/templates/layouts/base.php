<?php
/**
 * Base layout. Shell only — no page logic, no queries.
 *
 * @var callable $e escaping helper
 * @var array $data title, content, session, current_slug
 */
$title   = (string)($data['title'] ?? 'Members');
$content = (string)($data['content'] ?? '');
$who     = $data['session']['full_name'] ?? ($data['session']['username'] ?? '');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?= $e($title) ?> — Members</title>
<link rel="stylesheet" href="/members/assets/css/base.css">
</head>
<body class="mp">
  <?php if ($who !== ''): ?>
  <header class="mp-bar">
    <a class="mp-bar__brand" href="/members">Members</a>
    <span class="mp-bar__who"><?= $e($who) ?></span>
  </header>
  <?php endif; ?>
  <main class="mp-main">
<?= $content ?>
  </main>
</body>
</html>
