<?php if (!isset($module)) { http_response_code(404); exit; } ?>
<div class="ma-module-heading"><span class="ma-module-symbol"><?= ma_ui_icon($module['icon']) ?></span><div><h1><?= ma_ui_escape($module['label']) ?></h1><p><?= _('Choose an action to get started.') ?></p></div></div>
<div class="ma-module-grid">
<?php foreach ($module['groups'] as $group): ?>
  <section class="ma-panel"><div class="ma-panel-head"><h2><?= ma_ui_escape($group['label']) ?></h2><span class="ma-count"><?= count($group['links']) ?></span></div>
    <div class="ma-module-links"><?php foreach ($group['links'] as $item): ?>
      <a href="<?= ma_ui_escape(ma_ui_href($item['link'])) ?>"><?= ma_ui_icon('file') ?><span><?= ma_ui_escape($item['label']) ?></span><span aria-hidden="true">&rarr;</span></a>
    <?php endforeach; ?></div>
  </section>
<?php endforeach; ?>
</div>
