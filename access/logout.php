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

define("FA_LOGOUT_PHP_FILE","");

$page_security = 'SA_OPEN';
$path_to_root="..";
include($path_to_root . "/includes/session.inc");
// Logout bypasses the usual connection initialization in session.inc.
// Capture the signed-in identity before clearing it, but never prevent logout.
try {
    if (!empty($_SESSION['wa_current_user']->logged)) {
        set_global_connection($_SESSION['wa_current_user']->company);
        system_audit_request_start();
        system_audit_event('logout');
    }
} catch (Throwable $error) { error_log('System audit logout could not be recorded.'); }
// Always clear the session, even if rendering the confirmation fails.
try {
    $auth_result = 'logout';
    include $path_to_root . '/access/auth_result.php';
} finally {
    session_unset();
    @session_destroy();
}
