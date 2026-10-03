<?php
// Point of sale: the counter screen for direct sales (cash, M-Pesa or bank).
// It posts the same documents as Sales > Direct Invoice, so stock, GL and eTIMS behave the same.
class pos_app extends application
{
	function __construct()
	{
		parent::__construct("pos", _($this->help_context = "&Point of Sale"));

		$this->add_module(_("Counter"));
		$this->add_lapp_function(0, _("&Point of Sale"),
			"pos/pos.php?", 'SA_POS', MENU_TRANSACTION);

		$this->add_extensions();
	}
}
