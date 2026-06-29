-- AVO'Gs app tables — ONLY for concepts FrontAccounting has no native table for.
-- Master data (users, customers, items/catalogue, stores) lives in FA's own
-- tables (0_users, 0_debtors_master, 0_stock_master/0_prices, 0_locations).
-- Prefixed with the FA table prefix (0_) and idempotent.
--
-- COLLATION: we pin utf8_general_ci to match FrontAccounting's own tables.
-- On MariaDB 11.5+/12.x, "CHARSET=utf8" alone defaults to utf8mb3_uca1400_ai_ci,
-- which mismatches FA's utf8mb3_general_ci and makes any JOIN between an avogs
-- text column and an FA text column fail with "Illegal mix of collations".

-- API bearer tokens (FA has no token table). user_id references 0_users.id.
CREATE TABLE IF NOT EXISTS `0_avogs_api_tokens` (
  `token` varchar(64) NOT NULL,
  `user_id` int(11) NOT NULL,
  `store_code` varchar(5) DEFAULT NULL,
  `created_at` datetime NOT NULL,
  `expires_at` datetime NOT NULL,
  PRIMARY KEY (`token`),
  KEY `user_id` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_general_ci;

-- Shift definitions (e.g. Morning / Evening) managed in FA maintenance.
CREATE TABLE IF NOT EXISTS `0_avogs_shift_defs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `shift_key` varchar(10) NOT NULL,
  `name` varchar(60) NOT NULL,
  `start_time` time DEFAULT NULL,
  `end_time` time DEFAULT NULL,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `inactive` tinyint(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `shift_key` (`shift_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_general_ci;

INSERT IGNORE INTO `0_avogs_shift_defs` (shift_key, name, start_time, end_time, sort_order, inactive) VALUES
  ('morning','Morning Shift','07:00:00','14:00:00',1,0),
  ('evening','Evening Shift','14:00:00','21:00:00',2,0);

-- Shift sessions (opening/closing checklist runs).
CREATE TABLE IF NOT EXISTS `0_avogs_shifts` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `store_code` varchar(5) NOT NULL,
  `shift_key` varchar(10) NOT NULL,
  `status` varchar(10) NOT NULL DEFAULT 'open',
  `opened_at` datetime DEFAULT NULL,
  `opened_by` varchar(60) DEFAULT NULL,
  `closed_at` datetime DEFAULT NULL,
  `closed_by` varchar(60) DEFAULT NULL,
  `opening_stock` int(11) NOT NULL DEFAULT 0,
  `opening_till` int(11) NOT NULL DEFAULT 0,
  `opening_float` int(11) NOT NULL DEFAULT 0,
  `stock_discrepancy` tinyint(1) NOT NULL DEFAULT 0,
  `cash_discrepancy` tinyint(1) NOT NULL DEFAULT 0,
  `cash_counted` int(11) NOT NULL DEFAULT 0,
  `notes` text,
  `photo_ids` text,
  PRIMARY KEY (`id`),
  KEY `store_status` (`store_code`, `status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_general_ci;

-- Expected stock/cash handed to the NEXT shift (one row per store+shift).
CREATE TABLE IF NOT EXISTS `0_avogs_handover` (
  `store_code` varchar(5) NOT NULL,
  `shift_key` varchar(10) NOT NULL,
  `avo` int(11) NOT NULL DEFAULT 0,
  `till` int(11) NOT NULL DEFAULT 0,
  `flt` int(11) NOT NULL DEFAULT 0,
  `juice` int(11) NOT NULL DEFAULT 0,
  `smoothie` int(11) NOT NULL DEFAULT 0,
  `ginger` int(11) NOT NULL DEFAULT 0,
  `h250` int(11) NOT NULL DEFAULT 0,
  `h450` int(11) NOT NULL DEFAULT 0,
  `h900` int(11) NOT NULL DEFAULT 0,
  `updated_at` datetime NOT NULL,
  PRIMARY KEY (`store_code`, `shift_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_general_ci;

-- Sales records. NOTE FA-native posting (debtor_trans + GL via
-- write_sales_invoice) is the phase-2 hook. These tables are the app
-- shift/POS ledger and reference real FA stock_id / debtor_no values.
CREATE TABLE IF NOT EXISTS `0_avogs_sales` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `reference` varchar(40) NOT NULL,
  `fa_trans_no` int(11) NOT NULL DEFAULT 0,
  `store_code` varchar(5) NOT NULL,
  `shift_key` varchar(10) NOT NULL,
  `customer_id` int(11) NOT NULL DEFAULT 1,
  `customer_name` varchar(100) NOT NULL DEFAULT 'CASH SALES',
  `payment_method` varchar(20) NOT NULL DEFAULT 'Cash',
  `trans_date` datetime NOT NULL,
  `subtotal` int(11) NOT NULL DEFAULT 0,
  `discount` int(11) NOT NULL DEFAULT 0,
  `total` int(11) NOT NULL DEFAULT 0,
  `units` int(11) NOT NULL DEFAULT 0,
  `comments` varchar(255) NOT NULL DEFAULT '',
  PRIMARY KEY (`id`),
  UNIQUE KEY `reference` (`reference`),
  KEY `store_date` (`store_code`, `trans_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_general_ci;

CREATE TABLE IF NOT EXISTS `0_avogs_sale_lines` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `sale_id` int(11) NOT NULL,
  `stock_id` varchar(40) NOT NULL,
  `name` varchar(120) NOT NULL,
  `qty` int(11) NOT NULL DEFAULT 0,
  `unit_price` int(11) NOT NULL DEFAULT 0,
  `discount` int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `sale_id` (`sale_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_general_ci;

CREATE TABLE IF NOT EXISTS `0_avogs_deliveries` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `store_code` varchar(5) NOT NULL,
  `customer` varchar(100) NOT NULL,
  `location` varchar(100) NOT NULL DEFAULT '',
  `type` varchar(60) NOT NULL DEFAULT '',
  `qdesc` varchar(60) NOT NULL DEFAULT '',
  `amount` int(11) NOT NULL DEFAULT 0,
  `pay` varchar(10) NOT NULL DEFAULT 'pending',
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `store_code` (`store_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_general_ci;

CREATE TABLE IF NOT EXISTS `0_avogs_supplies` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `store_code` varchar(5) NOT NULL,
  `type` varchar(40) NOT NULL,
  `qty` int(11) NOT NULL DEFAULT 0,
  `descr` varchar(255) NOT NULL DEFAULT '',
  `income` int(11) NOT NULL DEFAULT 0,
  `supply_date` date DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `store_code` (`store_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_general_ci;

CREATE TABLE IF NOT EXISTS `0_avogs_expenses` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `store_code` varchar(5) NOT NULL,
  `category` varchar(60) NOT NULL,
  `amount` int(11) NOT NULL DEFAULT 0,
  `descr` varchar(255) NOT NULL DEFAULT '',
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `store_code` (`store_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_general_ci;

CREATE TABLE IF NOT EXISTS `0_avogs_wastage` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `store_code` varchar(5) NOT NULL,
  `product` varchar(60) NOT NULL,
  `qty` int(11) NOT NULL DEFAULT 0,
  `reason` varchar(40) NOT NULL DEFAULT '',
  `duration` varchar(40) NOT NULL DEFAULT '',
  `loss` int(11) NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `store_code` (`store_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_general_ci;

CREATE TABLE IF NOT EXISTS `0_avogs_uploads` (
  `upload_id` varchar(40) NOT NULL,
  `path` varchar(255) NOT NULL,
  `url` varchar(255) NOT NULL,
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`upload_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_general_ci;
