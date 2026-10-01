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
// Customers workspace: the customer, branch and contact lists. The workspace
// tab bar (ui/customers.inc) is how users move between them.
class customer_directory_app extends application
{
	function __construct()
	{
		parent::__construct("customers", _($this->help_context = "&Customers"));

		$this->add_module(_("Customers"));
		$this->add_lapp_function(0, _("&Customers"),
			"sales/manage/customer_list.php?", 'SA_CUSTOMER', MENU_ENTRY);
		$this->add_lapp_function(0, _("&Branches"),
			"sales/manage/branch_list.php?", 'SA_CUSTOMER', MENU_ENTRY);
		$this->add_lapp_function(0, _("C&ontacts"),
			"sales/manage/contact_list.php?", 'SA_CUSTOMER', MENU_ENTRY);

		$this->add_extensions();
	}
}
