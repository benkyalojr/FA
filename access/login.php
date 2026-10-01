<?php
/**********************************************************************
    Copyright (C) FrontAccounting, LLC.
	Released under the terms of the GNU General Public License, GPL, 
	as published by the Free Software Foundation, either version 3 
	of the License, or (at your option) any later version.
    This program is distributed in the hope that it will be useful,
    but WITHOUT ANY WARRANTY; without even the implied warranty of
    MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  
    See the License here <http://www.gnu.org/licenses/gpl-3.0.html>.
***********************************************************************/
// Authentication stays in includes/session.inc. This page only prepares the view.
if (!isset($path_to_root) || isset($_GET['path_to_root']) || isset($_POST['path_to_root']))
    die(_("Restricted access"));
include $path_to_root . '/ui/auth.inc';
$login_timeout = !empty($_SESSION['wa_current_user']->last_act);
$blocked = check_faillog();
$auth_message = '';
$auth_error = false;
if (!$allow) {
    $auth_message = _('A secure connection is required. Open this site using HTTPS to sign in.');
    $auth_error = true;
} elseif ($blocked) {
    $auth_message = _('Too many failed login attempts. Please wait a while before trying again.');
    $auth_error = true;
} elseif (isset($_POST['SubmitUser']) && $_SESSION['wa_current_user']->login_attempt > 1) {
    $auth_message = _('Invalid username or password. Please try again.');
    $auth_error = true;
} elseif ($SysPrefs->allow_demo_mode) {
    $auth_message = _('Login as user: demouser and password: password');
}

$value = $login_timeout ? $_SESSION['wa_current_user']->loginname
    : (is_string($_POST['user_name_entry_field'] ?? null) ? $_POST['user_name_entry_field']
        : ($SysPrefs->allow_demo_mode ? 'demouser' : ''));
$password = $SysPrefs->allow_demo_mode ? 'password' : '';
$title = ($login_timeout ? _('Session expired') : _('Sign in')) . ' | ' . $identity['name'];
$reset_url = $path_to_root . '/index.php?reset=1';
$show_reset = !empty($SysPrefs->allow_password_reset) && !$SysPrefs->allow_demo_mode;

// Keep the existing application script-cache refresh when starting a session.
flush_dir(user_js_cache());

// Preserve pending transactions after reauthentication, with escaped field names
// and values. Login controls and CSRF metadata are rendered afresh by this form.
$render_saved_field = static function ($name, $value) use (&$render_saved_field, $escape) {
    if (is_array($value)) {
        foreach ($value as $key => $item)
            $render_saved_field($name . '[' . $key . ']', $item);
    } else {
        echo '<input type="hidden" name="' . $escape($name) . '" value="' . $escape($value) . '">';
    }
};
$login_reserved_fields = array('ui_mode', 'user_name_entry_field', 'password', 'SubmitUser',
    'company_login_name', 'company_login_nickname', '_token', '_focus', '_modified', '_confirmed');

$auth_view = 'login';
$auth_heading = _('Welcome back');
$auth_subtitle = $login_timeout ? _('Your session has expired. Sign in to continue where you left off.')
    : _('Sign in to your workspace to continue.');
include $path_to_root . '/ui/views/auth.php';
