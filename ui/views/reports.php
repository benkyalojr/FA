<?php if (!isset($categories, $active)) { http_response_code(404); exit; } ?>
<section id="ma-reports" aria-label="<?= _('Reports') ?>" data-category="<?= ma_ui_escape($active) ?>" data-count="<?= ma_ui_escape(_('%d reports')) ?>" data-matches="<?= ma_ui_escape(_('%d matching reports')) ?>">
  <div class="ma-reports-intro"><p><?= _('Find the reports you need, organised by business area. Choose a report, set your filters, and export to PDF or Excel.') ?></p></div>
  <div class="ma-report-search"><label class="ma-report-sr" for="ma-report-search"><?= _('Search reports by name or description') ?></label><?= ma_ui_icon('search') ?><input type="search" id="ma-report-search" placeholder="<?= ma_ui_escape(_('Search reports by name or description...')) ?>" autocomplete="off"><button type="button" id="ma-report-clear" aria-label="<?= _('Clear search') ?>" hidden><?= ma_ui_icon('close') ?></button></div>
  <?php if ($categories): ?>
  <div class="ma-report-layout">
    <nav class="ma-report-categories" aria-label="<?= _('Report categories') ?>">
      <?php foreach ($categories as $category): ?>
      <a class="ma-report-category" data-category="<?= ma_ui_escape($category['id']) ?>" data-label="<?= ma_ui_escape($category['label']) ?>" href="<?= ma_ui_escape($action.'?Class='.rawurlencode($category['id'])) ?>"<?= $category['id']===$active ? ' aria-current="page"' : '' ?>><?= ma_ui_icon($category['icon']) ?><span><?= ma_ui_escape($category['label']) ?></span></a>
      <?php endforeach; ?>
    </nav>
    <div class="ma-report-list"><p class="ma-report-count" id="ma-report-count" role="status" aria-live="polite"><?= ma_ui_escape($categories[$active]['label'].' / '.sprintf(_('%d reports'),count($categories[$active]['reports']))) ?></p>
      <div class="ma-report-grid">
        <?php foreach ($categories as $category): foreach ($category['reports'] as $report): ?>
        <a class="ma-report-card" id="ma-report-<?= ma_ui_escape($report->id) ?>" data-category="<?= ma_ui_escape($category['id']) ?>" data-report="<?= ma_ui_escape($report->id) ?>" href="<?= ma_ui_escape($action.'?Class='.rawurlencode($category['id']).'&REP_ID='.rawurlencode($report->id)) ?>" aria-controls="rep_form"<?= $selected === $report ? ' aria-current="true"' : '' ?><?= $category['id']!==$active ? ' hidden' : '' ?>>
          <strong><?= ma_ui_escape(ma_ui_label($report->name)) ?></strong><span><?= ma_ui_escape(ma_report_description($report)) ?></span><small><?= ma_ui_escape($category['label']) ?></small>
        </a>
        <?php endforeach; endforeach; ?>
        <div class="ma-report-empty" id="ma-report-empty" hidden><?= _('No reports match your search. Try a different name or keyword.') ?></div>
      </div>
    </div>
    <div class="ma-report-detail">
      <div id="rep_form"><?= $panel ?></div>
      <div class="ma-report-placeholder"><?= ma_ui_icon('file') ?><h2><?= _('Report filters') ?></h2><p><?= _('Select a report to set your filters and print or export it.') ?></p></div>
    </div>
  </div>
  <?php else: ?><div class="ma-report-empty"><?= _('There are no report categories available to your role.') ?></div><?php endif; ?>
</section>
