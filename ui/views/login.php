<?php if (!isset($login_reserved_fields, $escape)) { http_response_code(404); exit; } ?>
      <?php if ($allow): ?>
      <?php start_form(false, false, $escape($_SESSION['timeout']['uri'] ?? $_SERVER['PHP_SELF']), 'loginform'); ?>
        <div class="ma-field">
          <div class="ma-label-row"><label for="ma-username"><?= _('Username') ?></label></div>
          <input id="ma-username" name="user_name_entry_field" type="text" value="<?= $escape($value) ?>"
                 placeholder="<?= $escape(_('Enter your username')) ?>" autocomplete="username" autocapitalize="none" spellcheck="false" maxlength="30" required<?= $auth_error ? ' aria-describedby="log_msg"' : '' ?>>
        </div>
        <div class="ma-field">
          <div class="ma-label-row">
            <label for="ma-password"><?= _('Password') ?></label>
            <?php if ($show_reset): ?><a class="ma-link" href="<?= $escape($reset_url) ?>"><?= _('Forgot password?') ?></a><?php endif; ?>
          </div>
          <div class="ma-password-wrap">
            <input id="ma-password" name="password" type="password" value="<?= $escape($password) ?>"
                   placeholder="<?= $escape(_('Enter your password')) ?>" autocomplete="current-password" required<?= $auth_error ? ' aria-describedby="log_msg"' : '' ?>>
            <button class="ma-eye" type="button" data-password-toggle hidden aria-label="<?= $escape(_('Show password')) ?>"
                    data-show-label="<?= $escape(_('Show password')) ?>" data-hide-label="<?= $escape(_('Hide password')) ?>"
                    aria-pressed="false" aria-controls="ma-password">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12Z"/><circle cx="12" cy="12" r="3"/><path class="ma-eye-slash" d="m3 3 18 18"/></svg>
            </button>
          </div>
        </div>
        <?php if ($login_timeout): ?>
          <input type="hidden" name="company_login_name" value="<?= $escape(user_company()) ?>">
        <?php elseif (isset($db_connections)): ?>
        <div class="ma-field">
          <div class="ma-label-row"><label for="ma-company"><?= _('Company') ?></label></div>
          <?php if (empty($SysPrefs->text_company_selection)): ?>
          <select id="ma-company" name="company_login_name" required>
            <?php foreach ($db_connections as $company_id => $connection): ?>
            <option value="<?= $escape($company_id) ?>"<?= (string)$company_id === (string)$coy ? ' selected' : '' ?>><?= $escape($connection['name']) ?></option>
            <?php endforeach; ?>
          </select>
          <?php else: ?>
          <input id="ma-company" name="company_login_nickname" type="text" value="<?= $escape($company_nickname) ?>" placeholder="<?= $escape(_('Enter your company name')) ?>" maxlength="50" required>
          <?php endif; ?>
        </div>
        <?php endif; ?>
        <input type="hidden" id="ui_mode" name="ui_mode" value="0">
        <button class="ma-submit" type="submit" name="SubmitUser" value="1"<?= $blocked ? ' disabled' : '' ?>>
          <?= $login_timeout ? _('Sign in & continue') : _('Sign in') ?> <?php $icon('arrow'); ?>
        </button>
        <?php
        foreach (($_SESSION['timeout']['post'] ?? array()) as $name => $saved_value)
            if (!in_array($name, $login_reserved_fields, true))
                $render_saved_field($name, $saved_value);
        end_form();
        ?>
      <?php endif; ?>
