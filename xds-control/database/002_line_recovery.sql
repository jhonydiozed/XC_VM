USE `xds`;

CREATE TABLE IF NOT EXISTS `line_operation_batches` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `batch_uuid` char(36) NOT NULL,
  `operation_type` varchar(40) NOT NULL,
  `status` varchar(20) NOT NULL DEFAULT 'applied',
  `admin_user_id` bigint unsigned DEFAULT NULL,
  `description` varchar(255) DEFAULT NULL,
  `affected_count` int unsigned NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `reverted_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_line_operation_batches_uuid` (`batch_uuid`),
  KEY `idx_line_operation_batches_date` (`created_at`),
  KEY `idx_line_operation_batches_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `line_operation_snapshots` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `batch_id` bigint unsigned NOT NULL,
  `line_id` bigint unsigned NOT NULL,
  `username` varchar(255) DEFAULT NULL,
  `operation_type` varchar(40) NOT NULL,
  `before_json` longtext NOT NULL,
  `after_json` longtext DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_line_snapshots_batch` (`batch_id`),
  KEY `idx_line_snapshots_line` (`line_id`),
  CONSTRAINT `fk_line_snapshots_batch` FOREIGN KEY (`batch_id`) REFERENCES `line_operation_batches` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `line_trash` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `line_id` bigint unsigned NOT NULL,
  `username` varchar(255) DEFAULT NULL,
  `snapshot_json` longtext NOT NULL,
  `batch_id` bigint unsigned DEFAULT NULL,
  `deleted_by` bigint unsigned DEFAULT NULL,
  `deleted_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `restored_at` datetime DEFAULT NULL,
  `permanently_deleted_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_line_trash_active_line` (`line_id`, `restored_at`, `permanently_deleted_at`),
  KEY `idx_line_trash_deleted_at` (`deleted_at`),
  KEY `idx_line_trash_batch` (`batch_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `schema_migrations` (`version`) VALUES ('002_line_recovery');
