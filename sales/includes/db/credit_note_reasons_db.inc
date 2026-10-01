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
function add_credit_note_reason($description)
{
	$sql = "INSERT INTO ".TB_PREF."credit_note_reasons (reason_description)
		VALUES (".db_escape($description).")";

	db_query($sql, "could not add credit note reason");
}

function update_credit_note_reason($reason_id, $description)
{
	$sql = "UPDATE ".TB_PREF."credit_note_reasons SET reason_description=".db_escape($description)
		." WHERE id=".db_escape($reason_id);

	db_query($sql, "could not update credit note reason");
}

function get_all_credit_note_reasons($all=false)
{
	$sql = "SELECT * FROM ".TB_PREF."credit_note_reasons";
	if (!$all) $sql .= " WHERE !inactive";

	return db_query($sql, "could not get all credit note reasons");
}

function get_credit_note_reason($reason_id)
{
	$sql = "SELECT * FROM ".TB_PREF."credit_note_reasons WHERE id=".db_escape($reason_id);

	$result = db_query($sql, "could not get credit note reason");

	return db_fetch($result);
}

function delete_credit_note_reason($reason_id)
{
	$sql="DELETE FROM ".TB_PREF."credit_note_reasons WHERE id=".db_escape($reason_id);

	db_query($sql, "could not delete credit note reason");
}
