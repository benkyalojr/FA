<?php
// Point of sale: counter screen for direct sales (cash, M-Pesa, bank). See pos/includes/pos_lib.inc.
$page_security = 'SA_POS';
$path_to_root = "..";
include_once($path_to_root . "/includes/session.inc");
include_once($path_to_root . "/includes/ui.inc");
include_once($path_to_root . "/pos/includes/pos_lib.inc");
pos_load_fa();

if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = random_id();
$register = pos_register();
$methods = pos_methods($register);
$walkin = pos_walkin_customer();
$walkin_info = $walkin ? pos_customer_info($walkin) : null;

add_js_ufile($path_to_root . '/pos/pos.js?v=' . filemtime(__DIR__ . '/pos.js'));
page(_($help_context = "Point of Sale"));

$config = array(
	'api' => $path_to_root . '/pos/pos_api.php',
	'mpesaApi' => $path_to_root . '/sales/view/mpesa_stk.php',
	'receipt' => $path_to_root . '/pos/pos_receipt.php',
	'token' => $_SESSION['csrf_token'],
	'currency' => get_company_currency(),
	'methods' => $methods,
	'walkin' => $walkin_info,
	'canSetWalkin' => $_SESSION['wa_current_user']->can_access('SA_POSSETUP'),
	'register' => $register,
	'text' => array(
		'noMethods' => _('No payment method is active. Set up a cash or bank account, or M-Pesa.'),
	),
);
$e = 'ma_ui_escape';
?>
<div class="pos" id="pos" data-config="<?= $e(json_encode($config)) ?>">
  <div class="pos-bar">
    <div><strong><?= $e(_('Point of Sale')) ?></strong><span><?= $e($register['name']) ?> &middot; <?= $e($register['location_name']) ?></span></div>
    <div class="pos-bar-right">
      <?php if ($_SESSION['wa_current_user']->can_access('SA_POSSETUP')): ?><a class="pos-link" href="<?= $e($path_to_root.'/sales/manage/sales_points.php') ?>"><?= $e(_('Register settings')) ?></a><?php endif; ?>
      <span class="pos-status"><?= $e(_('Register open')) ?></span>
    </div>
  </div>
  <div class="pos-layout">
    <section class="pos-catalog">
      <div class="pos-toolbar">
        <label class="pos-search"><?= ma_ui_icon('search') ?><input id="pos-search" type="search" autocomplete="off" placeholder="<?= $e(_('Search products, code, or scan barcode...')) ?>" aria-label="<?= $e(_('Search products')) ?>"><kbd>/</kbd></label>
        <button type="button" class="pos-btn" id="pos-held"><?= $e(_('Held orders')) ?> <b id="pos-held-count">0</b></button>
      </div>
      <nav class="pos-categories" id="pos-categories" aria-label="<?= $e(_('Product categories')) ?>"></nav>
      <div class="pos-subhead"><h2><?= $e(_('Products')) ?></h2><span id="pos-count"></span></div>
      <div class="pos-products" id="pos-products"><div class="pos-empty"><?= $e(_('Loading products...')) ?></div></div>
      <p class="pos-hint"><?= $e(_('Press / to search. Enter adds an exact code or barcode match.')) ?></p>
    </section>

    <aside class="pos-cart" aria-label="<?= $e(_('Current order')) ?>">
      <div class="pos-cart-title"><h2><?= $e(_('Current order')) ?> <small id="pos-items">0 <?= $e(_('items')) ?></small></h2><button type="button" class="pos-text" id="pos-clear"><?= $e(_('Clear cart')) ?></button></div>
      <div class="pos-customer">
        <span class="pos-avatar"><?= ma_ui_icon('user') ?></span>
        <div>
          <small><?= $e(_('CUSTOMER')) ?></small>
          <input id="pos-customer" type="text" autocomplete="off" aria-label="<?= $e(_('Customer')) ?>" placeholder="<?= $e(_('Search customer...')) ?>">
          <div class="pos-customer-list" id="pos-customer-list" hidden></div>
        </div>
        <button type="button" class="pos-text" id="pos-walkin" title="<?= $e(_('Use the walk-in customer')) ?>"><?= $e(_('Walk-in')) ?></button>
      </div>
      <div class="pos-lines" id="pos-lines"></div>
      <div class="pos-totals">
        <div class="pos-row"><span id="pos-subtotal-label"><?= $e(_('Subtotal')) ?></span><strong id="pos-subtotal"></strong></div>
        <div class="pos-row"><span><?= $e(_('Discount')) ?></span><span class="pos-discount"><input id="pos-discount" type="number" min="0" step="0.01" value="0" aria-label="<?= $e(_('Discount amount')) ?>"><span><?= $e(get_company_currency()) ?></span></span></div>
        <div class="pos-row"><span id="pos-tax-label"><?= $e(_('Tax')) ?></span><strong id="pos-tax"></strong></div>
        <div class="pos-row pos-total"><span><?= $e(_('Total')) ?></span><strong id="pos-total"></strong></div>
      </div>
      <button type="button" class="pos-btn pos-primary pos-pay" id="pos-pay"><span><?= $e(_('Charge order')) ?></span><span id="pos-pay-total"></span></button>
      <div class="pos-actions"><button type="button" class="pos-btn" id="pos-hold"><?= $e(_('Hold order')) ?></button><button type="button" class="pos-btn" id="pos-new"><?= $e(_('New order')) ?></button></div>
    </aside>
  </div>

  <dialog id="pos-checkout" aria-labelledby="pos-dialog-title">
    <h2 id="pos-dialog-title"><?= $e(_('Payment')) ?></h2>
    <div class="pos-dialog-customer" id="pos-dialog-customer"></div>
    <div class="pos-due" id="pos-due"></div>
    <div id="pos-pay-fields">
      <div class="pos-methods" id="pos-methods" role="group" aria-label="<?= $e(_('Payment method')) ?>"></div>
      <div class="pos-field" id="pos-f-account" hidden><label for="pos-account"><?= $e(_('Account')) ?></label><select id="pos-account"></select></div>
      <div class="pos-field" id="pos-f-phone" hidden><label for="pos-phone"><?= $e(_('Customer M-Pesa number')) ?></label><input id="pos-phone" type="tel" inputmode="tel" placeholder="0712345678"></div>
      <div class="pos-field" id="pos-f-ref" hidden><label for="pos-ref"><?= $e(_('Card slip or transfer reference')) ?></label><input id="pos-ref" type="text"></div>
      <div id="pos-f-cash" hidden>
        <div class="pos-field"><label for="pos-received"><?= $e(_('Amount received')) ?></label><input id="pos-received" type="number" min="0" step="0.01"></div>
        <div class="pos-quick" id="pos-quick"></div>
        <div class="pos-row"><span><?= $e(_('Change due')) ?></span><strong id="pos-change"></strong></div>
      </div>
    </div>
    <div class="pos-notice" id="pos-notice" role="status"></div>
    <div class="pos-dialog-actions"><button type="button" class="pos-btn" id="pos-cancel"><?= $e(_('Cancel')) ?></button><button type="button" class="pos-btn" id="pos-print" hidden><?= $e(_('Print receipt')) ?></button><button type="button" class="pos-btn pos-primary" id="pos-complete"><?= $e(_('Complete sale')) ?></button></div>
  </dialog>
  <div class="pos-toast" id="pos-toast" role="status" hidden></div>
</div>
<?php
end_page();
