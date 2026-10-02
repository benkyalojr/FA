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
$page_security = 'SA_SECROLES';
$path_to_root = '..';
include_once($path_to_root.'/includes/session.inc');
add_access_extensions();
add_css_file($path_to_root.'/ui/components/roles.css');
$js = file_get_contents($path_to_root.'/js/security_roles.js');
page(_($help_context = 'Access Setup'), false, false, '', $js);
include_once($path_to_root.'/includes/ui.inc');
include_once($path_to_root.'/includes/access_levels.inc');
include_once($path_to_root.'/admin/db/security_db.inc');

function rights_html($text) { return htmlspecialchars(html_entity_decode((string)$text, ENT_QUOTES, 'UTF-8'), ENT_QUOTES, 'UTF-8'); }
function rights_version($role) { return hash('sha256', serialize($role)); }

// Business area a security section belongs to; sections are numbered in
// families (11-13 Sales, 21-23 Purchasing, ...). Extension sections that
// fit no family are listed together.
function rights_module($section_code)
{
	$n = ($section_code >> 8) & 0xff;
	foreach (array(
		array(1, 3, _('Administration and setup')),
		array(11, 13, _('Sales')),
		array(21, 23, _('Purchasing')),
		array(31, 33, _('Inventory')),
		array(36, 38, _('Fixed assets')),
		array(41, 43, _('Manufacturing')),
		array(51, 53, _('Dimensions')),
		array(61, 66, _('Banking and general ledger')),   // 64-66: M-Pesa, shown as groups inside the Banking card
		array(90, 92, _('Communications')),
	) as $family)
		if ($n >= $family[0] && $n <= $family[1]) return $family[2];
	return _('Extensions');
}

// Every right this page can show for the current company.
function rights_available()
{
	global $security_areas, $security_sections;
	$result = array();
	foreach ($security_areas as $key => $r) {
		// System administration rights belong to the first (site) company only.
		if (user_company() && ($r[0] & 0xff00) == SS_SADMIN) continue;
		$section = $r[0] & ~0xff;
		$result[$r[0]] = array(rights_module($section), $section,
			isset($security_sections[$section]) ? $security_sections[$section] : '', $r[1]);
	}
	return $result;
}

$available = rights_available();
$id = (int)get_post('role', 0);
$load = !isset($_POST['role']) || list_updated('role') || get_post('clone') || get_post('cancel');
$mutation = get_post('addupdate') || get_post('delete');

if ($mutation && check_csrf_token()) {
	$row = $id ? get_security_role($id) : false;
	$error = '';
	if ($id && (!$row || !hash_equals(rights_version($row), (string)get_post('role_version', ''))))
		$error = _('This role changed since you opened it. Select the role again to load the latest permissions.');
	elseif (get_post('delete')) {
		if (!$id)
			$error = _('Select a role to delete.');
		elseif (check_role_used($id))
			$error = _('This role is assigned to users and cannot be deleted.');
		else {
			delete_security_role($id);
			display_notification(_('Security role deleted.'));
			$_POST = array(); $id = 0; $load = true;
		}
	} else {
		$name = trim(get_post('name', '')); $description = trim(get_post('description', ''));
		if ($name === '' || strlen($name) > 22 || $description === '' || strlen($description) > 52)
			$error = _('Enter a role name (up to 22 characters) and description (up to 52 characters).');
		$areas = array();
		foreach ($available as $code => $r)
			if (get_post('Area'.$code) == 1) $areas[] = (int)$code;
		// Keep rights this page does not show (uninstalled extensions, and site
		// administration rights when editing from a non-site company).
		if ($row)
			foreach ($row['areas'] as $code)
				if (!isset($available[$code])) $areas[] = (int)$code;
		$self_right = $security_areas['SA_SECROLES'][0];
		if ($id && $id == $_SESSION['wa_current_user']->access && !in_array($self_right, $areas, true))
			$error = _('Keep Access Setup enabled for your own role so you can manage permissions.');
		if (!$error) {
			$areas = array_values(array_unique($areas));
			$sections = array_values(array_unique(array_map(function($code) { return $code & ~255; }, $areas)));
			if ($id) {
				update_security_role($id, $name, $description, $sections, $areas);
				update_record_status($id, get_post('inactive'), 'security_roles', 'id');
			} else
				add_security_role($name, $description, $sections, $areas);
			display_notification($id ? _('Security role updated.') : _('New security role added.'));
			$_POST = array(); $id = 0; $load = true;
		}
	}
	if ($error) display_error($error);
	$Ajax->activate('_page_body');
}

if ($load) {
	$clone = get_post('clone');
	if (get_post('cancel')) $id = 0;
	$row = $id ? get_security_role($id) : false;
	$show = get_post('show_inactive');
	$_POST = array('role' => $clone ? '' : ($id ? $id : ''), 'show_inactive' => $show,
		'name' => $row ? $row['role'] : '', 'description' => $row ? $row['description'] : '',
		'inactive' => $row ? $row['inactive'] : 0, 'role_version' => $row ? rights_version($row) : '');
	if ($row)
		foreach ($row['areas'] as $code)
			if (in_array($code & ~255, $row['sections'])) $_POST['Area'.$code] = 1;
	if ($clone) set_focus('name');
	$Ajax->activate('_page_body');
}
$new_role = !get_post('role');
if (get_post('_show_inactive_update')) $Ajax->activate('role');

start_form(); echo '<div class="rights-workspace">';
start_table(TABLESTYLE2);
security_roles_list_row(_('Role:'), 'role', null, true, true, check_value('show_inactive'));
check_row(_('Show inactive roles:'), 'show_inactive', null, true);
text_row(_('Role name:'), 'name', rights_html(get_post('name')), 30, 22);
text_row(_('Description:'), 'description', rights_html(get_post('description')), 40, 52);
record_status_list_row(_('Status:'), 'inactive');
end_table(1); hidden('role_version');

echo '<div class="rights-toolbar"><label>'._('Find a module or right:')
	.' <input type="search" size="30" aria-label="'._('Find a module or right').'" oninput="securityRightsFilter(this.value)"></label></div>';
echo '<p class="rights-note">'._('Select the rights available to this role. Rights are grouped by business area.').'</p>';

$modules = array();
foreach ($available as $code => $r) $modules[$r[0]][$r[1]][$code] = $r;
foreach ($modules as $module => $sections) {
	ksort($sections);
	$selected = 0; $total = 0;
	foreach ($sections as $rights) foreach ($rights as $code => $r) { $total++; if (get_post('Area'.$code)) $selected++; }
	echo '<section class="rights-module" data-module="'.rights_html($module).'"><header><h3>'.rights_html($module).'</h3>'
		.'<span class="rights-count">'.$selected.' / '.$total.' '._('selected').'</span></header><div class="rights-groups">';
	foreach ($sections as $section => $rights) {
		uksort($rights, function($a, $b) { return ($a & 0xff) - ($b & 0xff); });
		$first = reset($rights);
		echo '<div class="rights-group"><h4>'.rights_html($first[2]).'</h4><div class="rights-bulk">'
			.'<button type="button" data-rights-select="all">'._('Select all').'</button>'
			.'<button type="button" data-rights-select="none">'._('Clear').'</button></div>';
		foreach ($rights as $code => $r) {
			echo '<label class="rights-item"><input type="checkbox" name="Area'.(int)$code.'" value="1"'
				.(get_post('Area'.$code) ? ' checked' : '').' onchange="securityRightsCount(this)">'
				.'<span>'.rights_html($r[3]).'</span></label>';
		}
		echo '</div>';
	}
	echo '</div></section>';
}

echo '<div class="rights-actions">';
if ($new_role)
	submit('addupdate', _('Create Role'), true, '', 'default');
else {
	submit('addupdate', _('Save Role'), true, '', 'default');
	submit('clone', _('Clone Role'), true, '', true);
	submit('delete', _('Delete Role'), true, '', true);
	submit('cancel', _('Cancel'), true, _('Cancel editing'), 'cancel');
}
echo '</div></div>';
end_form(); end_page();
