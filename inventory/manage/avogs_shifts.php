<?php
/**********************************************************************
    AVO'Gs — Shift Definitions Maintenance
    Define and edit the shift types (e.g. Morning / Evening) used by
    the mobile app's opening & closing checklists. Stored in
    0_avogs_shift_defs and consumed by the API.
***********************************************************************/
$page_security = 'SA_ITEM';
$path_to_root = "../..";
include($path_to_root . "/includes/session.inc");

page(_($help_context = "Shift Definitions"));

include_once($path_to_root . "/includes/ui.inc");

//----------------------------------------------------------------------------------
// Inline data access (custom AVO'Gs table, no native FA db include).

function avogs_shift_tbl()
{
    return TB_PREF . "avogs_shift_defs";
}

function get_all_shift_defs($show_inactive)
{
    $sql = "SELECT * FROM " . avogs_shift_tbl();
    if (!$show_inactive)
        $sql .= " WHERE inactive = 0";
    $sql .= " ORDER BY sort_order, name";
    return db_query($sql, "could not retrieve shift definitions");
}

function get_shift_def($id)
{
    $sql = "SELECT * FROM " . avogs_shift_tbl() . " WHERE id = " . db_escape($id);
    return db_fetch(db_query($sql, "could not retrieve shift definition"));
}

function shift_key_exists($key, $exclude_id = 0)
{
    $sql = "SELECT id FROM " . avogs_shift_tbl() . " WHERE shift_key = " . db_escape($key)
        . " AND id <> " . db_escape((int) $exclude_id);
    return db_num_rows(db_query($sql, "could not check shift key")) > 0;
}

function add_shift_def($key, $name, $start, $end, $order)
{
    $sql = "INSERT INTO " . avogs_shift_tbl() . " (shift_key, name, start_time, end_time, sort_order)
        VALUES (" . db_escape($key) . ", " . db_escape($name) . ", "
        . ($start === '' ? "NULL" : db_escape($start)) . ", "
        . ($end === '' ? "NULL" : db_escape($end)) . ", " . db_escape((int) $order) . ")";
    db_query($sql, "could not add shift definition");
}

function update_shift_def($id, $key, $name, $start, $end, $order)
{
    $sql = "UPDATE " . avogs_shift_tbl() . " SET
        shift_key = " . db_escape($key) . ",
        name = " . db_escape($name) . ",
        start_time = " . ($start === '' ? "NULL" : db_escape($start)) . ",
        end_time = " . ($end === '' ? "NULL" : db_escape($end)) . ",
        sort_order = " . db_escape((int) $order) . "
        WHERE id = " . db_escape($id);
    db_query($sql, "could not update shift definition");
}

function shift_def_used($id)
{
    $row = get_shift_def($id);
    if (!$row)
        return false;
    $sql = "SELECT COUNT(*) AS c FROM " . TB_PREF . "avogs_shifts WHERE shift_key = " . db_escape($row['shift_key']);
    $r = db_fetch(db_query($sql, "could not check shift usage"));
    return $r['c'] > 0;
}

function delete_shift_def($id)
{
    db_query("DELETE FROM " . avogs_shift_tbl() . " WHERE id = " . db_escape($id), "could not delete shift definition");
}

// Normalise a HH:MM[:SS] string to a TIME literal, or '' if blank/invalid.
function avogs_clean_time($v)
{
    $v = trim($v);
    if ($v === '')
        return '';
    if (preg_match('/^([01]?\d|2[0-3]):([0-5]\d)(:[0-5]\d)?$/', $v, $m))
        return $m[1] . ":" . $m[2] . (isset($m[3]) ? $m[3] : ":00");
    return false; // signals invalid
}

//----------------------------------------------------------------------------------

simple_page_mode(true);

if ($Mode == 'ADD_ITEM' || $Mode == 'UPDATE_ITEM') {
    $input_error = 0;

    if (strlen(trim($_POST['shift_key'])) == 0) {
        $input_error = 1;
        display_error(_("The shift key cannot be empty."));
        set_focus('shift_key');
    } elseif (!preg_match('/^[a-z0-9_-]{1,10}$/', $_POST['shift_key'])) {
        $input_error = 1;
        display_error(_("The shift key must be 1-10 chars: lowercase letters, digits, '-' or '_'."));
        set_focus('shift_key');
    } elseif (shift_key_exists($_POST['shift_key'], $selected_id)) {
        $input_error = 1;
        display_error(_("A shift with this key already exists."));
        set_focus('shift_key');
    }

    if (strlen(trim($_POST['name'])) == 0) {
        $input_error = 1;
        display_error(_("The shift name cannot be empty."));
        set_focus('name');
    }

    $start = avogs_clean_time($_POST['start_time']);
    $end = avogs_clean_time($_POST['end_time']);
    if ($start === false) {
        $input_error = 1;
        display_error(_("Start time must be in HH:MM format."));
        set_focus('start_time');
    }
    if ($end === false) {
        $input_error = 1;
        display_error(_("End time must be in HH:MM format."));
        set_focus('end_time');
    }

    if ($input_error != 1) {
        if ($selected_id != '') {
            update_shift_def($selected_id, $_POST['shift_key'], $_POST['name'], $start, $end, $_POST['sort_order']);
            display_notification(_('Selected shift has been updated'));
        } else {
            add_shift_def($_POST['shift_key'], $_POST['name'], $start, $end, $_POST['sort_order']);
            display_notification(_('New shift has been added'));
        }
        $Mode = 'RESET';
    }
}

//----------------------------------------------------------------------------------

if ($Mode == 'Delete') {
    if (shift_def_used($selected_id)) {
        display_error(_("Cannot delete this shift because it is already used by recorded check-ins/check-outs. Mark it inactive instead."));
    } else {
        delete_shift_def($selected_id);
        display_notification(_('Selected shift has been deleted'));
    }
    $Mode = 'RESET';
}

if ($Mode == 'RESET') {
    $selected_id = '';
    $sav = get_post('show_inactive');
    unset($_POST);
    $_POST['show_inactive'] = $sav;
}

//----------------------------------------------------------------------------------

$result = get_all_shift_defs(check_value('show_inactive'));

start_form();
start_table(TABLESTYLE, "width='60%'");
$th = array(_('Key'), _('Name'), _('Start'), _('End'), _('Order'), "", "");
inactive_control_column($th);
table_header($th);

$k = 0;
while ($myrow = db_fetch($result)) {
    alt_table_row_color($k);

    label_cell($myrow["shift_key"]);
    label_cell($myrow["name"]);
    label_cell($myrow["start_time"] ? substr($myrow["start_time"], 0, 5) : "-", "align=center");
    label_cell($myrow["end_time"] ? substr($myrow["end_time"], 0, 5) : "-", "align=center");
    label_cell($myrow["sort_order"], "align=center");
    inactive_control_cell($myrow["id"], $myrow["inactive"], 'avogs_shift_defs', 'id');
    edit_button_cell("Edit" . $myrow["id"], _("Edit"));
    delete_button_cell("Delete" . $myrow["id"], _("Delete"));
    end_row();
}

inactive_control_row($th);
end_table(1);

//----------------------------------------------------------------------------------

start_table(TABLESTYLE2);

if ($selected_id != '') {
    if ($Mode == 'Edit') {
        $myrow = get_shift_def($selected_id);
        $_POST['shift_key'] = $myrow["shift_key"];
        $_POST['name'] = $myrow["name"];
        $_POST['start_time'] = $myrow["start_time"] ? substr($myrow["start_time"], 0, 5) : '';
        $_POST['end_time'] = $myrow["end_time"] ? substr($myrow["end_time"], 0, 5) : '';
        $_POST['sort_order'] = $myrow["sort_order"];
    }
    hidden('selected_id', $selected_id);
}

text_row(_("Shift Key:"), 'shift_key', null, 12, 10);
text_row(_("Descriptive Name:"), 'name', null, 42, 60);
text_row(_("Start Time (HH:MM):"), 'start_time', null, 8, 5);
text_row(_("End Time (HH:MM):"), 'end_time', null, 8, 5);
text_row(_("Sort Order:"), 'sort_order', null, 6, 4);

end_table(1);

submit_add_or_update_center($selected_id == '', '', 'both');

end_form();

end_page();
