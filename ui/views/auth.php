<?php
if (!isset($identity, $escape, $auth_view) || !in_array($auth_view, array('login', 'password_reset', 'auth_result'), true)) {
    http_response_code(404);
    exit;
}
// Inline icons keep authentication independent of a third-party JS library.
$icon = static function ($name) {
    $paths = array(
        'chart' => '<path d="M4 4v16h16M8 15v-3m5 3V8m5 7V5"/>',
        'people' => '<circle cx="9" cy="8" r="3"/><path d="M3 21v-2a6 6 0 0 1 12 0v2M16 5a3 3 0 0 1 0 6m2 4a5 5 0 0 1 3 4v2"/>',
        'lock' => '<rect x="5" y="10" width="14" height="11" rx="2"/><path d="M8 10V7a4 4 0 0 1 8 0v3m-4 5v2"/>',
        'globe' => '<circle cx="12" cy="12" r="9"/><ellipse cx="12" cy="12" rx="4" ry="9"/><path d="M3 12h18"/>',
        'arrow' => '<path d="M5 12h14m-5-5 5 5-5 5"/>',
    );
    echo '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $paths[$name] . '</svg>';
};
?>
<!doctype html>
<html lang="<?= $escape(str_replace('_', '-', $_SESSION['language']->code ?? 'en')) ?>" dir="<?= $escape($_SESSION['language']->dir ?? 'ltr') ?>">
<head>
  <meta charset="<?= $escape($encoding) ?>">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= $escape($title) ?></title>
  <link rel="icon" type="image/x-icon" href="<?= $escape($path_to_root) ?>/ui/favicon.ico">
  <link rel="icon" type="image/png" sizes="32x32" href="<?= $escape($path_to_root) ?>/ui/favicon-32x32.png">
  <link rel="icon" type="image/png" sizes="16x16" href="<?= $escape($path_to_root) ?>/ui/favicon-16x16.png">
  <link rel="apple-touch-icon" href="<?= $escape($path_to_root) ?>/ui/apple-touch-icon.png">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&amp;display=swap" rel="stylesheet">
  <?php foreach (array('ui/design/tokens.css', 'ui/components/login.css') as $asset): ?>
  <link rel="stylesheet" href="<?= $escape($path_to_root . '/' . $asset) ?>?v=<?= filemtime($path_to_root . '/' . $asset) ?>">
  <?php endforeach; ?>
  <script src="<?= $escape($path_to_root) ?>/ui/login.js?v=<?= filemtime($path_to_root . '/ui/login.js') ?>" defer></script>
</head>
<body class="ma-auth-page" id="loginscreen">
<?php div_start('_page_body'); ?>
<div id="ma-login" data-timeout="<?= $login_timeout ? 'true' : 'false' ?>"
     data-retry-seconds="<?= $blocked ? max(0, (int)$SysPrefs->login_delay) : 0 ?>"
     data-retry-message="<?= $escape(_('You can try signing in again.')) ?>">
  <aside class="ma-story" aria-label="<?= $escape($identity['name']) ?>">
    <div class="ma-story-inner">
      <div class="ma-badge"><span class="ma-dot" aria-hidden="true"></span><?= $escape($identity['name']) ?></div>
      <h1><?= _('Smarter business,') ?><br><span><?= _('simpler accounting.') ?></span></h1>
      <p class="ma-intro"><?= _('From sales and purchases to your final accounts. Manage your finances, inventory, and operations in one connected workspace.') ?></p>
      <div class="ma-rule" aria-hidden="true"></div>
      <ul class="ma-features">
        <li><span class="ma-feature-icon"><?php $icon('chart'); ?></span><?= _('Financial reports & business insights') ?></li>
        <li><span class="ma-feature-icon"><?php $icon('people'); ?></span><?= _('Sales, purchasing & inventory control') ?></li>
        <li><span class="ma-feature-icon"><?php $icon('lock'); ?></span><?= _('Role-based access for your team') ?></li>
        <li><span class="ma-feature-icon"><?php $icon('globe'); ?></span><?= _('Multi-company & multi-currency support') ?></li>
      </ul>
      <p class="ma-copyright">&copy; <?= date('Y') ?> <?= $escape($identity['name']) ?>. <?= $escape(_($identity['description'])) ?></p>
    </div>
  </aside>
  <main class="ma-auth">
    <div class="ma-form-wrap">
      <div class="ma-logo">
        <img class="ma-logo-mark" src="<?= $escape($path_to_root) ?>/ui/logo.png" alt="" width="40" height="40">
        <span><?= $escape($identity['name']) ?></span>
      </div>
      <h2><?= $escape($auth_heading) ?></h2>
      <p class="ma-subtitle"><?= $escape($auth_subtitle) ?></p>
      <?php if ($auth_message): ?>
      <p id="log_msg" class="ma-notice<?= $auth_error ? ' ma-notice--error' : '' ?>" role="<?= $auth_error ? 'alert' : 'status' ?>"><?= $escape($auth_message) ?></p>
      <?php endif; ?>
      <?php include __DIR__ . '/' . $auth_view . '.php'; ?>
      <p class="ma-help"><strong><?= _('Need access to your workspace?') ?></strong><?= _('Contact your system administrator.') ?></p>
    </div>
  </main>
</div>
<?php div_end(); ?>
</body>
</html>
