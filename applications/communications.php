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
// Communications module - SMS/Email/WhatsApp notifications. Greenfield
// (see CLAUDE.md) - no v1 client has this beyond nyala's DigiSoft SMS
// gateway, whose request shape is ported as this module's SMS channel
// adapter. Slice A: config layer (gateway settings, message templates,
// the event-rule + recipient opt-in gates, send engine, delivery log).
// Hooks into milk collection/payment, payroll payslips, sales invoices
// and the approval workflows are a later slice - nothing here sends a
// message on its own yet.
//
// Shown only when the top-level "Communications" optional module is
// enabled on admin/company_preferences.php. Per-channel toggles
// (use_communications_sms/email/whatsapp) live on Communications Module
// Setup and gate the send engine, not this menu.
class communications_app extends application
{
	function __construct()
	{
		parent::__construct("communications", _($this->help_context = "Communications"));

		$this->add_module(_("Inquiries and Reports"));
		$this->add_lapp_function(0, _("Delivery &Log"),
			"communications/inquiry/comm_log_inquiry.php?", 'SA_COMMLOG', MENU_INQUIRY);
		$this->add_lapp_function(0, _("SMS &Inquiry"),
			"communications/inquiry/sms_log_inquiry.php?", 'SA_COMMLOG', MENU_INQUIRY);
		$this->add_lapp_function(0, _("Email In&quiry"),
			"communications/inquiry/email_log_inquiry.php?", 'SA_COMMLOG', MENU_INQUIRY);

		$this->add_module(_("Maintenance"));
		$this->add_lapp_function(1, _("Message &Templates"),
			"communications/manage/templates.php?", 'SA_COMMTEMPLATES', MENU_MAINTENANCE);
		$this->add_lapp_function(1, _("Notification &Rules"),
			"communications/manage/notification_rules.php?", 'SA_COMMRULES', MENU_MAINTENANCE);
		$this->add_lapp_function(1, _("&Recipient Preferences"),
			"communications/manage/recipient_preferences.php?", 'SA_COMMRECIPIENTPREFS', MENU_MAINTENANCE);
		$this->add_rapp_function(1, _("&Gateway Settings"),
			"communications/manage/gateway_settings.php?", 'SA_COMMSETUP', MENU_MAINTENANCE);
		$this->add_rapp_function(1, _("Communications Module Se&tup"),
			"communications/manage/communications_module_setup.php?", 'SA_COMMSETUP', MENU_MAINTENANCE);

		$this->add_extensions();
	}
}
