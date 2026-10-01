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
// Suppliers workspace: the supplier and contact lists (suppliers have no
// branches). The tab bar in ui/suppliers.inc moves between them.
class supplier_directory_app extends application
{
	function __construct()
	{
		parent::__construct("suppliers", _($this->help_context = "&Suppliers"));

		$this->add_module(_("Suppliers"));
		$this->add_lapp_function(0, _("&Suppliers"),
			"purchasing/manage/supplier_list.php?", 'SA_SUPPLIER', MENU_ENTRY);
		$this->add_lapp_function(0, _("C&ontacts"),
			"purchasing/manage/supplier_contact_list.php?", 'SA_SUPPLIER', MENU_ENTRY);

		$this->add_extensions();
	}
}
