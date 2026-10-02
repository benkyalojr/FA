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
	// Public invoice share links (/share/invoice/<token>) never need a login.
	if (preg_match('~/share/invoice/([A-Za-z0-9_-]{20,64})/?$~', (string)parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH), $share_m)) {
		$_GET['t'] = $share_m[1];
		require __DIR__.'/share/index.php';
		exit;
	}
	// M-Pesa callbacks (/pay/hook/<secret>/<kind>) are called by Safaricom, never by a logged-in user.
	if (preg_match('~/pay/hook/(?:(\d+)/)?([A-Za-z0-9]{16,64})/([a-z0-9]+)/?$~', (string)parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH), $mpesa_m)) {
		if ($mpesa_m[1] !== '') $mpesa_company = (int)$mpesa_m[1];
		$mpesa_hook_secret = $mpesa_m[2];
		$mpesa_hook_kind = $mpesa_m[3];
		require __DIR__.'/mpesa/hook.php';
		exit;
	}
	$path_to_root=".";
	if (!file_exists($path_to_root.'/config_db.php'))
		header("Location: ".$path_to_root."/install/index.php");

	$page_security = 'SA_OPEN';
	ini_set('xdebug.auto_trace',1);
	include_once("includes/session.inc");

	add_access_extensions();
	$app = &$_SESSION["App"];
	if (isset($_GET['application']))
		$app->selected_application = $_GET['application'];

	$app->display();
