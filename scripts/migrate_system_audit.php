<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$supplier_company_id=isset($argv[1])?(int)$argv[1]:0;
require __DIR__.'/supplier_types_cli.inc';
require $path_to_root.'/sql/system_audit.php';
require_once $path_to_root.'/includes/db/system_audit.inc';
system_audit_context($db,TB_PREF);
$count=migrate_system_audit($db,TB_PREF);
echo "System audit installed for company $supplier_company_id: $count tables.\n";
