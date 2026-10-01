<?php if (!isset($data, $actions)) { http_response_code(404); exit; } ?>
<section class="ma-hero">
  <div class="ma-hero-main"><h1><?= ma_ui_escape($greeting) ?> <span><?= ma_ui_escape($user_name) ?></span></h1>
    <p><?= ma_ui_escape(sprintf(_("Here's your business overview for %s"), date('l, F j, Y', strtotime($today)))) ?></p>
    <?php if ($actions): ?><div class="ma-actions"><?php foreach ($actions as $action): ?>
      <a class="ma-action<?= $action['icon']==='receipt' && strpos($action['link'],'sales/')===0 ? ' featured' : '' ?>" href="<?= ma_ui_escape(ma_ui_href($action['link'])) ?>"><?= ma_ui_icon($action['icon']) ?><?= ma_ui_escape($action['label']) ?></a>
    <?php endforeach; ?></div><?php endif; ?>
  </div>
  <?php if ($data['fiscal']): $year = $data['fiscal']; ?>
  <aside class="ma-year"><strong><?= _('FINANCIAL YEAR') ?> <?= ma_ui_escape(substr($year['begin'],0,4).(substr($year['begin'],0,4)!==substr($year['end'],0,4) ? '-'.substr($year['end'],0,4) : '')) ?></strong>
    <small><?= ma_ui_escape(sql2date($year['begin']).' - '.sql2date($year['end'])) ?></small>
    <progress value="<?= $year['percent'] ?>" max="100" aria-label="<?= ma_ui_escape(_('Financial year progress')) ?>"></progress>
    <div class="ma-year-bottom"><span><?= ma_ui_escape(sprintf(_('%d days in'), $year['elapsed'])) ?></span><span><?= $year['percent'] ?>%</span><span><?= ma_ui_escape(sprintf(_('%d remaining'), $year['remaining'])) ?></span></div>
  </aside>
  <?php endif; ?>
</section>
<?php if ($data['snapshot']): ?>
<section aria-labelledby="ma-snapshot-title"><div class="ma-section-title"><h2 id="ma-snapshot-title"><?= _("Today's snapshot") ?></h2><span class="ma-section-note"><?= ma_ui_escape($data['currency']) ?></span></div>
  <div class="ma-snapshot"><?php foreach ($data['snapshot'] as $stat): ?>
    <a class="ma-stat ma-<?= $stat['tone'] ?>" href="<?= ma_ui_escape(ma_ui_href($stat['link'])) ?>" title="<?= ma_ui_escape($stat['hint']) ?>"><span class="ma-stat-icon"><?= ma_ui_icon($stat['icon']) ?></span><div><div class="ma-value"><?= ma_dashboard_number($stat['value'], $stat['money']) ?></div><div class="ma-stat-label"><?= ma_ui_escape($stat['label']) ?></div></div></a>
  <?php endforeach; ?></div>
</section>
<?php endif; ?>
<?php if ($data['overview']): ?>
<section aria-labelledby="ma-overview-title"><div class="ma-section-title"><h2 id="ma-overview-title"><?= _('Overview') ?></h2><span class="ma-section-note"><?= _('Active records') ?></span></div>
  <div class="ma-overview"><?php foreach ($data['overview'] as $stat): ?>
    <a class="ma-overview-item ma-<?= $stat['tone'] ?>" href="<?= ma_ui_escape(ma_ui_href($stat['link'])) ?>"><div><div class="ma-overview-label"><?= ma_ui_escape($stat['label']) ?></div><div class="ma-value"><?= ma_dashboard_number($stat['value'], $stat['money']) ?></div></div><span class="ma-stat-icon"><?= ma_ui_icon($stat['icon']) ?></span></a>
  <?php endforeach; ?></div>
</section>
<?php endif; ?>
<?php if ($data['analytics']): ?>
<section aria-labelledby="ma-analytics-title"><div class="ma-section-title"><h2 id="ma-analytics-title"><?= _('Financial analytics') ?></h2>
  <form class="ma-period-form" method="get" action="<?= ma_ui_escape(ma_ui_href('index.php')) ?>"><label for="ma-period"><?= _('Period') ?></label><select class="ma-period" id="ma-period" name="period">
    <?php foreach (array('year'=>_('This financial year'), 'month'=>_('This month'), 'all'=>_('All time')) as $value=>$label): ?><option value="<?= $value ?>"<?= $data['period']===$value ? ' selected' : '' ?>><?= ma_ui_escape($label) ?></option><?php endforeach; ?>
    </select><button type="submit"><?= _('Apply') ?></button></form>
  </div>
  <div class="ma-analytics">
    <article class="ma-panel"><div class="ma-panel-head"><h3><?= _('Income vs costs') ?></h3><span class="ma-section-note"><?= ma_ui_escape($data['currency']) ?></span></div>
      <?php ma_dashboard_chart($data['series'], $data['currency']); ?>
      <p class="ma-chart-note"><?= _('Posted income compared with cost of sales and operating expenses.') ?></p>
      <?php if ($data['series']): ?><details class="ma-data-table"><summary><?= _('View figures') ?></summary><table><thead><tr><th><?= _('Period') ?></th><th><?= _('Income') ?></th><th><?= _('Costs') ?></th></tr></thead><tbody>
        <?php foreach ($data['series'] as $row): ?><tr><td><?= ma_ui_escape($row['period']) ?></td><td><?= ma_dashboard_number($row['sales']) ?></td><td><?= ma_dashboard_number($row['costs']) ?></td></tr><?php endforeach; ?>
      </tbody></table></details><?php endif; ?>
    </article>
    <article class="ma-panel"><div class="ma-panel-head"><h3><?= _('Class balances') ?></h3><span class="ma-section-note"><?= ma_ui_escape($data['currency']) ?></span></div>
      <?php if (!$data['classes']): ?><div class="ma-empty"><?= _('No posted balances in this period.') ?></div><?php else:
        $max_balance = max(1, ...array_map(static function ($row) { return abs($row['balance']); }, $data['classes']));
        $tones = array('blue','green','orange','purple','pink'); ?>
      <div class="ma-balances"><?php foreach ($data['classes'] as $i=>$class): ?>
        <div class="ma-balance ma-<?= $tones[$i%count($tones)] ?>"><div class="ma-balance-label"><span><?= ma_ui_escape($class['class_name']) ?></span><strong><?= ma_dashboard_number($class['balance']) ?></strong></div><div class="ma-balance-track" aria-hidden="true"><div class="ma-balance-fill" style="width:<?= round(abs($class['balance'])/$max_balance*100,2) ?>%"></div></div></div>
      <?php endforeach; ?></div><?php endif; ?>
      <p class="ma-chart-note"><?= ma_ui_escape(sprintf(_('Balances as of %s. Income and expenses cover the selected period.'), sql2date($data['to']))) ?></p>
    </article>
  </div>
</section>
<?php endif; ?>
<?php if (!$data['snapshot'] && !$data['overview'] && !$data['analytics']): ?>
<div class="ma-section-title"><h2><?= _('Your workspace') ?></h2></div>
<div class="ma-panel"><div class="ma-empty"><?= _('Use the navigation menu to open the tasks available to your role.') ?></div></div>
<?php endif; ?>
