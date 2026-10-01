<?php if (!isset($report, $form)) { http_response_code(404); exit; } ?>
<section class="ma-report-filters" data-report="<?= ma_ui_escape($report->id) ?>" aria-labelledby="ma-selected-report-title">
  <div class="ma-report-filter-heading"><h2 id="ma-selected-report-title" tabindex="-1"><?= ma_ui_escape($title) ?></h2><a class="ma-report-close" href="<?= ma_ui_escape($_SERVER['PHP_SELF'].'?Class='.rawurlencode($class)) ?>" aria-label="<?= _('Close report filters') ?>"><?= ma_ui_icon('close') ?></a></div>
  <p class="ma-report-description"><?= ma_ui_escape($description) ?></p>
  <?= $form ?>
</section>
