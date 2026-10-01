<?php
/**********************************************************************
    eTIMS - KRA's own reference code lists (Country, Packing Unit,
    Quantity Unit, Item Type, Taxation Type), seeded into etims_code_list
    by sql/etims.php from real KRA data (see sql/etims_code_list_data.php
    - sourced from wakulima's own production data, not guessed; the
    Stanbest API has no endpoint for these).

    Select2-enhanced dropdowns, same pattern as bank_directory_banks_list()
    (includes/ui/ui_lists.inc): the full list is rendered once and select2
    filters it client-side - no server round trip, no search_box (which
    would narrow the SQL to just the currently-selected value and leave
    nothing for select2 to search locally - see that function's own
    comment for the full reasoning).
***********************************************************************/

// spec_option always included, not just for the ones that start blank
// (item_cls_cd) - without it, combo_input() falls back to
// auto-selecting the alphabetically-first option whenever $selected_id
// matches nothing (array_selector()'s own documented fallback
// behaviour), which would silently pick a real code the user never
// chose rather than leaving the field visibly unset.
function etims_code_list($cl_name, $name, $selected_id = null, $submit_on_change = false)
{
	$sql = "SELECT code, code_name FROM " . TB_PREF . "etims_code_list WHERE cl_name=" . db_escape($cl_name);
	$slug = 'etims_' . preg_replace('/[^a-z0-9]+/', '_', strtolower($cl_name));
	return combo_input($name, $selected_id, $sql, 'code', 'code_name',
		array('order' => 'code_name', 'select_submit' => $submit_on_change,
			'spec_option' => _('-- Select --'), 'spec_id' => '', 'select2_local' => $slug));
}

function etims_code_list_row($cl_name, $label, $name, $selected_id = null, $submit_on_change = false)
{
	echo "<tr><td class='label'>$label</td><td>";
	echo etims_code_list($cl_name, $name, $selected_id, $submit_on_change);
	echo "</td></tr>\n";
}

function etims_country_list_row($label, $name, $selected_id = null, $submit_on_change = false)
{
	etims_code_list_row('Country', $label, $name, $selected_id, $submit_on_change);
}

function etims_packing_unit_list_row($label, $name, $selected_id = null, $submit_on_change = false)
{
	etims_code_list_row('Packing Unit', $label, $name, $selected_id, $submit_on_change);
}

function etims_quantity_unit_list_row($label, $name, $selected_id = null, $submit_on_change = false)
{
	etims_code_list_row('Quantity Unit', $label, $name, $selected_id, $submit_on_change);
}

function etims_item_type_list_row($label, $name, $selected_id = null, $submit_on_change = false)
{
	etims_code_list_row('Item Type', $label, $name, $selected_id, $submit_on_change);
}

function etims_tax_type_list_row($label, $name, $selected_id = null, $submit_on_change = false)
{
	etims_code_list_row('Taxation Type', $label, $name, $selected_id, $submit_on_change);
}

function etims_item_classification_list_row($label, $name, $selected_id = null, $submit_on_change = false)
{
	etims_code_list_row('Item Classification', $label, $name, $selected_id, $submit_on_change);
}

// Item Category is synced from Stanbest (etims_sync_categories(),
// etims_categories_db.inc), not static KRA data like the others -
// spec_option's label reflects that so an empty list reads as
// "not synced yet", not "no categories exist".
function etims_category_list_row($label, $name, $selected_id = null, $submit_on_change = false)
{
	$sql = "SELECT code, code_name FROM " . TB_PREF . "etims_code_list WHERE cl_name='Item Category'";
	echo "<tr><td class='label'>$label</td><td>";
	echo combo_input($name, $selected_id, $sql, 'code', 'code_name',
		array('order' => 'code_name', 'select_submit' => $submit_on_change,
			'spec_option' => _('-- None / not synced --'), 'spec_id' => '', 'select2_local' => 'etims_item_category'));
	echo "</td></tr>\n";
}
