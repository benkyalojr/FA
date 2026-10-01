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
// Keep saved dashboard links working with the shared, permission-aware overview.
$path_to_root = '..';
$page_security = 'SA_OPEN';
include_once $path_to_root . '/includes/session.inc';
include_once $path_to_root . '/includes/ui.inc';
include_once $path_to_root . '/ui/renderer.php';
include_once $path_to_root . '/ui/dashboard.inc';
page(_('Dashboard'), false, true);
ma_dashboard(ma_ui_navigation($_SESSION['App']->applications));
end_page(false, true);
