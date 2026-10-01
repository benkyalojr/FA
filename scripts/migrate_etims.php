<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
// Usage: php scripts/migrate_etims.php [company_id]
// Seeds the eTIMS stamping preference and creates the config/item-map/
// submissions tables. Idempotent - safe to re-run.
$supplier_company_id = isset($argv[1]) ? $argv[1] : 0;
require __DIR__.'/supplier_types_cli.inc';
// Submissions and retries depend on the shared background queue too.
require $path_to_root.'/sql/bg_tasks.php';
migrate_bg_tasks();
require $path_to_root.'/sql/etims.php';
migrate_etims();
echo "eTIMS tables and stamping preference are ready for company ".$supplier_company_id.".\n";
