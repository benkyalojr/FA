<?php if (!isset($navigation, $identity)) { http_response_code(404); exit; } ?>
<a class="ma-skip" href="#main-content"><?= ma_ui_escape(_('Skip to content')) ?></a>
<div class="ma-app<?= $no_menu ? ' ma-app--minimal' : '' ?>" id="ma-workspace">
<?php if (!$no_menu): ?>
  <button type="button" class="ma-nav-scrim" aria-label="<?= ma_ui_escape(_('Close navigation')) ?>" hidden></button>
  <aside class="ma-sidebar" id="ma-sidebar" aria-label="<?= ma_ui_escape(_('Main navigation')) ?>">
    <a class="ma-brand" href="<?= ma_ui_escape(ma_ui_href('index.php')) ?>"><img class="ma-brand-mark" src="<?= ma_ui_escape($path_to_root) ?>/ui/logo.png" alt="" width="29" height="29"><?= ma_ui_escape($identity['name']) ?></a>
    <button type="button" class="ma-mobile-close ma-icon-button" aria-label="<?= ma_ui_escape(_('Close navigation')) ?>"><?= ma_ui_icon('close') ?></button>
    <div class="ma-company" title="<?= ma_ui_escape(_('Current company')) ?>"><?= ma_ui_icon('bank') ?><span><?= ma_ui_escape($company_name) ?></span></div>
    <nav class="ma-navigation">
      <a class="ma-nav-item<?= !$active_app ? ' active' : '' ?>" href="<?= ma_ui_escape(ma_ui_href('index.php')) ?>"<?= !$active_app ? ' aria-current="page"' : '' ?>><?= ma_ui_icon('gauge') ?><span><?= _('Dashboard') ?></span></a>
      <?php foreach ($navigation as $id => $entry): ?>
      <?php if ($id === MA_SALES_APP || $id === MA_CUSTOMERS_APP): ?>
      <a class="ma-nav-item<?= $id === $active_app ? ' active' : '' ?>" href="<?= ma_ui_escape(ma_ui_href($id === MA_CUSTOMERS_APP ? 'sales/manage/customer_list.php' : 'index.php?application='.rawurlencode($id))) ?>"<?= $id === $active_app ? ' aria-current="page"' : '' ?>><?= ma_ui_icon($entry['icon']) ?><span><?= ma_ui_escape($entry['label']) ?></span></a>
      <?php else: ?>
      <details class="ma-nav-group"<?= $id === $active_app ? ' open' : '' ?>>
        <summary class="ma-nav-item<?= $id === $active_app ? ' active' : '' ?>"><?= ma_ui_icon($entry['icon']) ?><span><?= ma_ui_escape($entry['label']) ?></span><?= ma_ui_icon('chevron') ?></summary>
        <div class="ma-submenu">
          <a class="ma-module-overview" href="<?= ma_ui_escape(ma_ui_href('index.php?application='.rawurlencode($id))) ?>"><?= ma_ui_escape(sprintf(_('%s overview'), $entry['label'])) ?></a>
          <?php foreach ($entry['groups'] as $group): ?>
          <div class="ma-submenu-label"><?= ma_ui_escape($group['label']) ?></div>
          <?php foreach ($group['links'] as $item): ?>
          <a href="<?= ma_ui_escape(ma_ui_href($item['link'])) ?>"><?= ma_ui_escape($item['label']) ?></a>
          <?php endforeach; endforeach; ?>
        </div>
      </details>
      <?php endif; ?>
      <?php endforeach; ?>
    </nav>
    <div class="ma-sidebar-foot"><?= ma_ui_icon('lock') ?><span><?= _('Your business workspace') ?></span></div>
  </aside>
<?php endif; ?>
  <div class="ma-workspace">
    <header class="ma-topbar">
      <?php if (!$no_menu): ?>
      <button type="button" class="ma-icon-button" id="ma-menu-toggle" aria-label="<?= ma_ui_escape(_('Toggle navigation')) ?>" aria-controls="ma-sidebar" aria-expanded="true"><?= ma_ui_icon('grid') ?></button>
      <?php if ($quick_actions): ?>
      <details class="ma-dropdown ma-quick"><summary>+ <?= _('Quick') ?> <?= ma_ui_icon('chevron') ?></summary><div class="ma-dropdown-panel">
        <?php foreach ($quick_actions as $action): ?><a href="<?= ma_ui_escape(ma_ui_href($action['link'])) ?>"><?= ma_ui_icon($action['icon']) ?><?= ma_ui_escape($action['label']) ?></a><?php endforeach; ?>
      </div></details>
      <?php endif; endif; ?>
      <strong class="ma-top-title"><?= ma_ui_escape(ma_ui_label($title)) ?></strong>
      <?php if (!empty($sales_toolbar)) ma_sales_topbar_new(); elseif (!empty($customers_toolbar)) ma_customers_topbar_new(); ?>
      <img id="ajaxmark" class="ma-ajaxmark" src="<?= ma_ui_escape($path_to_root) ?>/themes/default/images/ajax-loader.gif" alt="<?= ma_ui_escape(_('Loading')) ?>" style="visibility:hidden">
      <?php if (!$no_menu): ?>
      <details class="ma-dropdown ma-user"><summary><span class="ma-user-name"><?= ma_ui_escape($user_name) ?></span><span class="ma-avatar"><?= ma_ui_escape($initial) ?></span><?= ma_ui_icon('chevron') ?></summary>
        <div class="ma-dropdown-panel">
          <div class="ma-account-name"><?= ma_ui_escape($user_name) ?><small><?= ma_ui_escape($company_name) ?></small></div>
          <?php if ($_SESSION['wa_current_user']->can_access('SA_SETUPDISPLAY')): ?><a href="<?= ma_ui_escape(ma_ui_href('admin/display_prefs.php')) ?>"><?= ma_ui_icon('settings') ?><?= _('Preferences') ?></a><?php endif; ?>
          <?php if ($_SESSION['wa_current_user']->can_access('SA_CHGPASSWD')): ?><a href="<?= ma_ui_escape(ma_ui_href('admin/change_current_user_password.php')) ?>"><?= ma_ui_icon('lock') ?><?= _('Change password') ?></a><?php endif; ?>
          <?php if ($SysPrefs->help_base_url): ?><a href="<?= help_url() ?>" target="_blank" rel="noopener noreferrer"><?= ma_ui_icon('help') ?><?= _('Help & support') ?></a><?php endif; ?>
          <a href="<?= ma_ui_escape(ma_ui_href('access/logout.php')) ?>"><?= ma_ui_icon('logout') ?><?= _('Sign out') ?></a>
        </div>
      </details>
      <button type="button" class="ma-icon-button" id="ma-theme-toggle" aria-label="<?= ma_ui_escape(_('Toggle dark appearance')) ?>" aria-pressed="false"><?= ma_ui_icon('sun') ?></button>
      <?php endif; ?>
    </header>
    <main class="ma-content<?= !empty($list_screen) ? ' ma-sales-list' : '' ?>" id="main-content" tabindex="-1">
    <?php if (!empty($sales_toolbar)) ma_sales_toolbar(); elseif (!empty($customers_toolbar)) ma_customers_toolbar(); ?>
    <?php if (!empty($sales_list)) ma_sales_stats(ma_sales_active_tab()); ?>
    <?php if (!$is_index): ?>
      <div class="ma-page-heading"><h1><?= ma_ui_escape(ma_ui_label($title)) ?></h1><span id="hints"></span></div>
      <section class="ma-page-panel">
    <?php endif; ?>
