<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
// Usage: php cron/mpesa_jobs.php            (run every minute)
//
// M-Pesa housekeeping:
//  1. retry callbacks that arrived but could not be processed (the raw inbox keeps them),
//  2. ask Safaricom about STK requests nobody answered (the callback may never have come),
//  3. mark requests that stayed unanswered for too long as failed.
// It logs in to FrontAccounting headlessly with the M-Pesa service account (mpesa/config.php).
$_SERVER['SCRIPT_NAME'] = '/cron/mpesa_jobs.php';
$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['HTTP_HOST'] = $_SERVER['HTTP_HOST'] ?? 'localhost';
require dirname(__DIR__).'/mpesa/bootstrap.php';
if (!$mpesa_fa_ready) { fwrite(STDERR, "M-Pesa: could not start FrontAccounting (see the PHP error log).\n"); exit(1); }
require_once $path_to_root.'/mpesa/includes/mpesa_setup.inc';
if (!mpesa_schema_ready()) { echo "M-Pesa tables are not installed.\n"; exit(0); }
require_once $path_to_root.'/mpesa/includes/mpesa_http.inc';
require_once $path_to_root.'/mpesa/includes/mpesa_inbox.inc';

$lock = db_fetch(db_query("SELECT GET_LOCK('mpesa_jobs', 0) AS got"));
if (!$lock || (int)$lock['got'] !== 1) { echo "Another run is in progress - exiting.\n"; exit(0); }
try {
	$retried = mpesa_process_pending_inbox(100);

	$checked = $failed = 0;
	$cfg = mpesa_get_config();
	if (mpesa_configured($cfg)) {
		// pending for 2+ minutes: Daraja's customer prompt lasts about 60-90 seconds
		$res = db_query("SELECT * FROM ".TB_PREF."mpesa_transactions WHERE kind='stk' AND status='pending' AND checkout_request_id IS NOT NULL
			AND created_at < DATE_SUB(NOW(), INTERVAL 2 MINUTE) ORDER BY id LIMIT 50", 'pending STK requests');
		while ($tx = db_fetch($res)) {
			$checked++;
			$r = mpesa_stk_query($cfg, $tx['checkout_request_id']);
			$code = isset($r['data']['ResultCode']) ? (int)$r['data']['ResultCode'] : null;
			if ($code === 0) continue;   // paid: the callback (or its retry) carries the receipt number, which the query does not
			if ($code !== null && $code !== 4999) {   // 4999 = still being processed
				mpesa_tx_update($tx['id'], array('status' => $code === 1032 ? 'cancelled' : 'failed', 'result_code' => $code, 'result_desc' => (string)($r['data']['ResultDesc'] ?? '')));
				$failed++;
			} elseif (strtotime($tx['created_at']) < time() - 3600) {
				mpesa_tx_update($tx['id'], array('status' => 'failed', 'result_desc' => 'No answer from Safaricom within an hour'));
				$failed++;
			}
		}
	}
	echo "Retried $retried callback(s); checked $checked pending request(s), $failed closed as failed.\n";
} finally {
	db_query("SELECT RELEASE_LOCK('mpesa_jobs')");
}
