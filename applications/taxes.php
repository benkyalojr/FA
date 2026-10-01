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
// Taxes: tax periods and returns, tax set-up and the KRA eTIMS integration.
// Moved here from Setup so everything about tax lives in one workspace.
class taxes_app extends application
{
	function __construct()
	{
		parent::__construct("taxes", _($this->help_context = "&Taxes"));

		$this->add_module(_("Tax Returns"));
		$this->add_lapp_function(0, _("Tax &Periods"),
			"taxes/tax_periods.php?", 'SA_TAXREP', MENU_TRANSACTION);
		$this->add_rapp_function(0, _("eTIMS &Z Report (Close Day)"),
			"etims/manage/etims_z_report.php?", 'SA_ETIMSZREPORT', MENU_MAINTENANCE);

		$this->add_module(_("Inquiries and Reports"));
		$this->add_lapp_function(1, _("Tax &Inquiry"),
			"gl/inquiry/tax_inquiry.php?", 'SA_TAXREP', MENU_INQUIRY);
		$this->add_lapp_function(1, _("eTIMS &Sales"),
			"etims/inquiry/etims_sales.php?", 'SA_ETIMSVIEW', MENU_INQUIRY);
		$this->add_lapp_function(1, _("eTIMS &Credit Notes"),
			"etims/inquiry/etims_credit_notes.php?", 'SA_ETIMSVIEW', MENU_INQUIRY);
		$this->add_rapp_function(1, _("eTIMS S&ubmissions"),
			"etims/inquiry/etims_submissions.php?", 'SA_ETIMSVIEW', MENU_INQUIRY);
		$this->add_rapp_function(1, _("eTIMS &Products"),
			"etims/inquiry/etims_products.php?", 'SA_ETIMSVIEW', MENU_INQUIRY);
		$this->add_rapp_function(1, _("eTIMS &X Report"),
			"etims/manage/etims_x_report.php?", 'SA_ETIMSXREPORT', MENU_INQUIRY);
		$this->add_rapp_function(1, _("Tax &Report"),
			"reporting/reports_main.php?Class=6&REP_ID=709", 'SA_TAXREP', MENU_REPORT);

		$this->add_module(_("Maintenance"));
		$this->add_lapp_function(2, _("&Taxes"),
			"taxes/tax_types.php?", 'SA_TAXRATES', MENU_MAINTENANCE);
		$this->add_lapp_function(2, _("Tax &Groups"),
			"taxes/tax_groups.php?", 'SA_TAXGROUPS', MENU_MAINTENANCE);
		$this->add_lapp_function(2, _("Item Ta&x Types"),
			"taxes/item_tax_types.php?", 'SA_ITEMTAXTYPE', MENU_MAINTENANCE);
		$this->add_rapp_function(2, _("eTIMS &Configuration"),
			"etims/manage/etims_config.php?", 'SA_ETIMSSETUP', MENU_SETTINGS);
		$this->add_rapp_function(2, _("eTIMS &Item Mapping"),
			"etims/manage/etims_item_mapping.php?", 'SA_ETIMSITEMS', MENU_MAINTENANCE);

		$this->add_extensions();
	}
}
