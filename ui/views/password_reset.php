<?php if (!isset($reset_identifier, $escape)) { http_response_code(404); exit; } ?>
<?php if ($allow): ?>
<?php start_form(false, false, $escape($path_to_root . '/index.php?reset=1'), 'resetform'); ?>
  <div class="ma-field">
    <div class="ma-label-row"><label for="ma-reset-identifier"><?= _('Email, username, or phone number') ?></label></div>
    <input id="ma-reset-identifier" name="reset_identifier" type="text" value="<?= $escape($reset_identifier) ?>"
           placeholder="<?= $escape(_('Enter your account details')) ?>" autocomplete="username" autocapitalize="none" spellcheck="false" maxlength="80" required>
  </div>
  <div class="ma-field">
    <div class="ma-label-row"><label for="ma-company"><?= _('Company') ?></label></div>
    <?php if (empty($SysPrefs->text_company_selection)): ?>
    <select id="ma-company" name="company_login_name" required>
      <?php foreach (($db_connections ?? array()) as $company_id => $connection): ?>
      <option value="<?= $escape($company_id) ?>"<?= (string)$company_id === (string)$coy ? ' selected' : '' ?>><?= $escape($connection['name']) ?></option>
      <?php endforeach; ?>
    </select>
    <?php else: ?>
    <input id="ma-company" name="company_login_nickname" type="text" value="<?= $escape($company_nickname) ?>"
           placeholder="<?= $escape(_('Enter your company name')) ?>" maxlength="50" required>
    <?php endif; ?>
  </div>
  <input type="hidden" id="ui_mode" name="ui_mode" value="0">
  <button class="ma-submit" type="submit" name="SubmitReset" value="1"><?= _('Reset Password') ?> <?php $icon('arrow'); ?></button>
<?php end_form(); ?>
<?php endif; ?>
<p class="ma-back"><a class="ma-link" href="<?= $escape($path_to_root . '/index.php') ?>"><?= _('Back to sign in') ?></a></p>
