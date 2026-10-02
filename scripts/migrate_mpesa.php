<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
// Usage: php scripts/migrate_mpesa.php [company_id]
// Creates the M-Pesa tables. Idempotent - safe to re-run.
$supplier_company_id = isset($argv[1]) ? $argv[1] : 0;
require __DIR__.'/supplier_types_cli.inc';
require $path_to_root.'/sql/mpesa.php';
migrate_mpesa();
echo "M-Pesa tables are ready for company ".$supplier_company_id.".\n";
