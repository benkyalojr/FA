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
if (!isset($path_to_root) || isset($_GET['path_to_root']) || isset($_POST['path_to_root']))
    die(_("Restricted access"));
include $path_to_root . '/ui/auth.inc';
$auth_view = 'password_reset';
$auth_heading = _('Forgot your password?');
$auth_subtitle = _('Enter your email, username, or phone number. We will send a temporary password to your registered phone and email.');
$title = _('Reset password') . ' | ' . $identity['name'];
$reset_identifier = is_string($_POST['reset_identifier'] ?? null) ? $_POST['reset_identifier'] : '';
if (!$allow) {
    $auth_message = _('A secure connection is required. Open this site using HTTPS to reset your password.');
    $auth_error = true;
}
include $path_to_root . '/ui/views/auth.php';
