<?php
/**********************************************************************
    eTIMS - raw Stanbest/Vibranium "iSale" API calls. Every function here
    returns the same shape etims_http_request() does
    ({ok, http_code, data|error}) - callers (etims_config_db.inc,
    etims_item_mapping_db.inc, etims_submit_db.inc) decide what failure
    means for them.
***********************************************************************/

function etims_login(array $cfg)
{
	return etims_http_post(etims_base_url($cfg) . '/auth/login', array(), array(
		'pin' => $cfg['pin'],
		'username' => $cfg['username'],
		'password' => $cfg['password'],
	));
}

function etims_auth_header()
{
	return 'Authorization: Bearer ' . etims_get_access_token();
}

// $item is a flat AddItem body - see etims_item_mapping_db.inc for how
// it's built from a stock_master row + its etims_item_map row.
function etims_add_item(array $cfg, array $item)
{
	return etims_http_post(etims_base_url($cfg) . '/items', array(etims_auth_header()), $item);
}

// $payload is a full FiscalReceipt body - see
// etims_submit_db.inc::etims_build_fiscal_payload(). $timeout is short
// (a few seconds) when called synchronously right after an invoice
// posts (etims_stamp_now_or_queue()) so a slow Stanbest never makes a
// cashier wait long, and the default (longer) when called from the
// background queue, where nobody's waiting on the request.
function etims_submit_fiscal_receipt(array $cfg, array $payload, $timeout = 30)
{
	return etims_http_post(etims_base_url($cfg) . '/sales/', array(etims_auth_header()), $payload, $timeout);
}

function etims_submit_credit_note(array $cfg, array $payload, $timeout = 30)
{
	return etims_http_post(etims_base_url($cfg) . '/credit', array(etims_auth_header()), $payload, $timeout);
}

// XReportStart - safe, read-only in practice: if a shift is already
// running (the normal case - Stanbest doesn't appear to auto-open/close
// shifts per calendar day on its own), it just reports that shift's
// live totals. Confirmed against the sandbox: calling it repeatedly
// never changed shift state.
function etims_xreport_start(array $cfg)
{
	return etims_http_post(etims_base_url($cfg) . '/xreport/start', array(etims_auth_header()), array());
}

// XReportStartEnd (POST /xreport/end) - despite the docs describing this
// as a "period report", this is the REAL shift-closing action: confirmed
// live (by accident, mid-investigation - see the code comment on
// etims_close_shift() below) that it sets the open shift's status to
// 'done', stamps endTime, and returns full closing totals. This is what
// "Close Day (Z Report)" actually calls - not POST /zreport (Zreport),
// whose own relationship to this endpoint was never tested, to avoid a
// second accidental shift-closing call while investigating it.
function etims_xreport_end(array $cfg)
{
	return etims_http_post(etims_base_url($cfg) . '/xreport/end', array(etims_auth_header()), array());
}

// Zreport (POST /zreport) - present because it's a documented endpoint,
// but never called anywhere in this codebase and never tested against
// the sandbox. Its exact relationship to /xreport/end (which is the
// endpoint actually confirmed to close a shift) is unknown - do not wire
// this into any UI action without testing it against a sandbox/shift you
// can afford to lose first.
function etims_zreport(array $cfg)
{
	return etims_http_post(etims_base_url($cfg) . '/zreport', array(etims_auth_header()), array());
}

// GetProductsClassification - despite the name, this returns the
// business's own custom item categories in Stanbest (e.g. "Default",
// "Food & Drinks"), not a KRA reference list - confirmed against the
// live sandbox. Feeds etims_item_map.kra_item_category_id via
// etims_sync_categories() (etims/includes/db/etims_categories_db.inc).
function etims_get_categories(array $cfg)
{
	return etims_http_get(etims_base_url($cfg) . '/items/category', array(etims_auth_header()));
}

// GetAllProducts / GetAllSales / GetAllCreditNotes - read-only listings of
// what Stanbest itself has on file, paginated (confirmed: all three
// return the same {total, current, pageSize, totalPages} shape
// alongside their own list key - "items"/"sales"/"credit" respectively).
// Feeds the eTIMS Products/Sales/Credit Notes inquiry screens - a
// Stanbest-side view, distinct from etims_item_map (local mapping) and
// etims_submissions (local stamping log).
function etims_get_all_products(array $cfg, $limit = 20, $offset = 0)
{
	return etims_http_get(etims_base_url($cfg) . '/items?offset=' . (int)$offset . '&limit=' . (int)$limit,
		array(etims_auth_header()));
}

function etims_get_all_sales(array $cfg, $limit = 20, $offset = 0)
{
	return etims_http_get(etims_base_url($cfg) . '/sales/?offset=' . (int)$offset . '&limit=' . (int)$limit,
		array(etims_auth_header()));
}

function etims_get_all_credit_notes(array $cfg, $limit = 20, $offset = 0)
{
	return etims_http_get(etims_base_url($cfg) . '/credit?offset=' . (int)$offset . '&limit=' . (int)$limit,
		array(etims_auth_header()));
}

// The three PDF report endpoints - confirmed against the sandbox that
// only zreport/pdf actually works (200, a real "Daily Fiscal Report Z"
// PDF, generated live without requiring the shift to already be closed
// first). xreport/pdf returns a 500 Internal Server Error on Stanbest's
// own side, and zreport/periodical/pdf 404s outright (route doesn't
// exist, tried with and without date query params) - both wired here
// anyway since they're documented endpoints that may start working, but
// callers must handle failure gracefully rather than assume success.
function etims_xreport_pdf(array $cfg)
{
	return etims_http_get_raw(etims_base_url($cfg) . '/xreport/pdf', array(etims_auth_header()));
}

function etims_zreport_pdf(array $cfg)
{
	return etims_http_get_raw(etims_base_url($cfg) . '/zreport/pdf', array(etims_auth_header()));
}

function etims_zreport_periodical_pdf(array $cfg, $start_date, $end_date)
{
	return etims_http_get_raw(etims_base_url($cfg) . '/zreport/periodical/pdf?startDate='
		. urlencode($start_date) . '&endDate=' . urlencode($end_date), array(etims_auth_header()));
}
