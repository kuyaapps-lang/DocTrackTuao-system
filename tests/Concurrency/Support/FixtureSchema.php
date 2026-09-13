<?php

namespace Tests\Concurrency\Support;

use Illuminate\Support\Facades\DB;
use RuntimeException;

final class FixtureSchema
{
    public const DATABASE = 'doctrack_10b_disposable';
    public const ACCOUNT = 'doctrack10b_runner';
    public const SOURCE = 'doctrack_tuao_20260908_174402.sql';
    public const SOURCE_SHA256 = '800B8AD2BEA7D9B18EDEADC0378E39EFBBEA9D389DE4985446E457FEA4E6C7A0';
    // CREATE TABLE only. Historical AUTO_INCREMENT counters intentionally omitted.
    public static function clear(): void
    {
        foreach (array_reverse(array_keys(self::definitions())) as $table) {
            DB::table($table)->delete();
        }
    }

    public static function seed(string $case, array $secrets): void
    {
        self::clear();
        $time = Worker::TIME;
        foreach (['Administrator', 'Records Officer', 'Office User', 'Viewer'] as $i => $role) {
            DB::table('roles')->insert(['id' => $i + 1, 'name' => $role]);
        }
        foreach (['A', 'B', 'C', 'D'] as $i => $office) {
            DB::table('offices')->insert(['id' => $i + 1, 'office_name' => 'Synthetic '.$office, 'office_code' => 'SYN-'.$office]);
        }
        $office = in_array($case, ['receive', 'processing'], true) ? 2 : 1;
        foreach ([1, 2, 3, 4] as $id) {
            DB::table('users')->insert([
                'id' => $id, 'name' => 'Synthetic user '.$id, 'email' => 'synthetic'.$id.'@example.test',
                'password' => $secrets['password_hash'], 'role_id' => $id === 3 ? 1 : 2,
                'office_id' => $id === 3 ? 3 : $office, 'created_at' => $time, 'updated_at' => $time,
            ]);
            DB::table('personal_access_tokens')->insert([
                'id' => $id, 'tokenable_type' => 'App\\Models\\User', 'tokenable_id' => $id,
                'name' => 'Synthetic baseline', 'token' => hash('sha256', $secrets['tokens'][$id]),
                'abilities' => '["*"]', 'last_used_at' => $time, 'expires_at' => '2026-09-10 02:00:00',
                'created_at' => $time, 'updated_at' => $time,
            ]);
        }
        foreach (['document_types' => 'type_name', 'priorities' => 'priority_name', 'confidentiality_levels' => 'level_name', 'route_actions' => 'action_name'] as $table => $column) {
            DB::table($table)->insert(['id' => 1, $column => $table === 'route_actions' ? 'Forward' : 'Synthetic']);
        }
        foreach (['Pending', 'Forwarded', 'Received'] as $i => $status) {
            DB::table('document_statuses')->insert(['id' => $i + 1, 'status_name' => $status]);
        }
        foreach (['REGISTERED', 'AWAITING_RECEIPT', 'FOR_ACTION', 'UNDER_REVIEW'] as $i => $action) {
            DB::table('processing_actions')->insert(['id' => $i + 1, 'action_code' => $action, 'action_name' => $action, 'is_active' => 1]);
        }
        DB::table('documents')->insert([
            'id' => 1, 'tracking_no' => 'SYNTHETIC-10B', 'title' => 'Synthetic original',
            'document_type_id' => 1, 'status_id' => $case === 'receive' ? 2 : 1,
            'priority_id' => 1, 'confidentiality_level_id' => 1, 'origin_office_id' => 1,
            'current_office_id' => $office, 'current_action_id' => $case === 'receive' ? 2 : ($case === 'processing' ? 3 : 1),
            'created_by' => 1, 'current_action_updated_by' => 1, 'current_action_updated_at' => $time,
            'document_date' => '2026-09-09', 'created_at' => $time, 'updated_at' => $time,
        ]);
        DB::table('document_qr_codes')->insert([
            'id' => 1, 'qr_token' => $secrets['qr'], 'status' => 'unused', 'generated_by' => 1,
            'generated_at' => $time, 'created_at' => $time, 'updated_at' => $time,
        ]);
        if ($case === 'receive') {
            DB::table('document_routes')->insert([
                'id' => 1, 'document_id' => 1, 'from_office_id' => 1, 'to_office_id' => 2,
                'forwarded_by' => 4, 'forwarded_at' => $time, 'status_id' => 2, 'action_id' => 1,
                'created_at' => $time, 'updated_at' => $time,
            ]);
        }
    }

    // Every column is retained. Secrets are hashed for same-run comparisons.
    public static function snapshot(): array
    {
        $snapshot = [];
        foreach (array_keys(self::definitions()) as $table) {
            $snapshot[$table] = DB::table($table)->orderBy('id')->get()->map(function ($row) {
                $row = (array) $row;
                foreach (['password', 'token', 'remember_token', 'qr_token'] as $key) {
                    if (isset($row[$key])) {
                        $row[$key] = hash('sha256', $row[$key]);
                    }
                }
                if (isset($row['abilities'])) {
                    $row['abilities'] = json_decode($row['abilities'], true, flags: JSON_THROW_ON_ERROR);
                    sort($row['abilities']);
                }
                ksort($row);
                return $row;
            })->all();
        }
        return $snapshot;
    }

    public static function verify(): void
    {
        $tables = DB::select('SHOW TABLES');
        $names = array_map(fn ($row) => array_values((array) $row)[0], $tables);
        $expected = array_keys(self::definitions());
        sort($names);
        sort($expected);
        if ($names !== $expected) {
            throw new RuntimeException('Disposable schema table inventory differs.');
        }
        foreach (self::definitions() as $table => $ddl) {
            $actual = array_values((array) DB::selectOne('SHOW CREATE TABLE `'.$table.'`'))[1];
            if (self::normalizeDdl($ddl) !== self::normalizeDdl($actual)) {
                throw new RuntimeException('Disposable schema differs: '.$table);
            }
        }
    }

    public static function normalizeDdl(string $ddl): string
    {
        return preg_replace('/\s+/', ' ', trim(preg_replace('/ AUTO_INCREMENT=\d+/', '', $ddl), ";\r\n "));
    }

    public static function definitions(): array
    {
        return [
            'roles' => <<<'SQL'
CREATE TABLE `roles` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `description` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL,
            'departments' => <<<'SQL'
CREATE TABLE `departments` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `department_name` varchar(150) NOT NULL,
  `department_code` varchar(20) NOT NULL,
  `description` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `departments_department_code_unique` (`department_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL,
            'offices' => <<<'SQL'
CREATE TABLE `offices` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `department_id` bigint(20) unsigned DEFAULT NULL,
  `office_name` varchar(150) NOT NULL,
  `office_code` varchar(20) NOT NULL,
  `description` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `offices_office_code_unique` (`office_code`),
  KEY `offices_department_id_foreign` (`department_id`),
  CONSTRAINT `offices_department_id_foreign` FOREIGN KEY (`department_id`) REFERENCES `departments` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL,
            'users' => <<<'SQL'
CREATE TABLE `users` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `email` varchar(255) NOT NULL,
  `email_verified_at` timestamp NULL DEFAULT NULL,
  `password` varchar(255) NOT NULL,
  `remember_token` varchar(100) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `role_id` bigint(20) unsigned DEFAULT NULL,
  `department_id` bigint(20) unsigned DEFAULT NULL,
  `office_id` bigint(20) unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `users_email_unique` (`email`),
  KEY `users_role_id_foreign` (`role_id`),
  KEY `users_department_id_foreign` (`department_id`),
  KEY `users_office_id_foreign` (`office_id`),
  CONSTRAINT `users_department_id_foreign` FOREIGN KEY (`department_id`) REFERENCES `departments` (`id`) ON DELETE SET NULL,
  CONSTRAINT `users_office_id_foreign` FOREIGN KEY (`office_id`) REFERENCES `offices` (`id`) ON DELETE SET NULL,
  CONSTRAINT `users_role_id_foreign` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL,
            'personal_access_tokens' => <<<'SQL'
CREATE TABLE `personal_access_tokens` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `tokenable_type` varchar(255) NOT NULL,
  `tokenable_id` bigint(20) unsigned NOT NULL,
  `name` text NOT NULL,
  `token` varchar(64) NOT NULL,
  `abilities` text DEFAULT NULL,
  `last_used_at` timestamp NULL DEFAULT NULL,
  `expires_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `personal_access_tokens_token_unique` (`token`),
  KEY `personal_access_tokens_tokenable_type_tokenable_id_index` (`tokenable_type`,`tokenable_id`),
  KEY `personal_access_tokens_expires_at_index` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL,
            'document_types' => <<<'SQL'
CREATE TABLE `document_types` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `type_name` varchar(100) NOT NULL,
  `description` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `document_types_type_name_unique` (`type_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL,
            'document_statuses' => <<<'SQL'
CREATE TABLE `document_statuses` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `status_name` varchar(50) NOT NULL,
  `color` varchar(20) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `document_statuses_status_name_unique` (`status_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL,
            'priorities' => <<<'SQL'
CREATE TABLE `priorities` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `priority_name` varchar(30) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `priorities_priority_name_unique` (`priority_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL,
            'confidentiality_levels' => <<<'SQL'
CREATE TABLE `confidentiality_levels` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `level_name` varchar(50) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `confidentiality_levels_level_name_unique` (`level_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL,
            'processing_actions' => <<<'SQL'
CREATE TABLE `processing_actions` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `action_code` varchar(50) NOT NULL,
  `action_name` varchar(100) NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `sort_order` smallint(5) unsigned NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `processing_actions_action_code_unique` (`action_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL,
            'route_actions' => <<<'SQL'
CREATE TABLE `route_actions` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `action_name` varchar(50) NOT NULL,
  `description` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `route_actions_action_name_unique` (`action_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL,
            'documents' => <<<'SQL'
CREATE TABLE `documents` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `tracking_no` varchar(50) NOT NULL,
  `title` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `document_type_id` bigint(20) unsigned DEFAULT NULL,
  `status_id` bigint(20) unsigned DEFAULT NULL,
  `priority_id` bigint(20) unsigned DEFAULT NULL,
  `confidentiality_level_id` bigint(20) unsigned DEFAULT NULL,
  `origin_office_id` bigint(20) unsigned DEFAULT NULL,
  `current_office_id` bigint(20) unsigned DEFAULT NULL,
  `current_action_id` bigint(20) unsigned DEFAULT NULL,
  `processing_note` text DEFAULT NULL,
  `current_action_updated_by` bigint(20) unsigned DEFAULT NULL,
  `current_action_updated_at` timestamp NULL DEFAULT NULL,
  `created_by` bigint(20) unsigned DEFAULT NULL,
  `document_date` date DEFAULT NULL,
  `due_date` date DEFAULT NULL,
  `status` varchar(255) NOT NULL DEFAULT 'pending',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `documents_tracking_no_unique` (`tracking_no`),
  KEY `documents_document_type_id_foreign` (`document_type_id`),
  KEY `documents_status_id_foreign` (`status_id`),
  KEY `documents_priority_id_foreign` (`priority_id`),
  KEY `documents_confidentiality_level_id_foreign` (`confidentiality_level_id`),
  KEY `documents_origin_office_id_foreign` (`origin_office_id`),
  KEY `documents_current_office_id_foreign` (`current_office_id`),
  KEY `documents_created_by_foreign` (`created_by`),
  KEY `documents_current_action_id_foreign` (`current_action_id`),
  KEY `documents_current_action_updated_by_foreign` (`current_action_updated_by`),
  CONSTRAINT `documents_confidentiality_level_id_foreign` FOREIGN KEY (`confidentiality_level_id`) REFERENCES `confidentiality_levels` (`id`) ON DELETE SET NULL,
  CONSTRAINT `documents_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `documents_current_action_id_foreign` FOREIGN KEY (`current_action_id`) REFERENCES `processing_actions` (`id`) ON DELETE SET NULL,
  CONSTRAINT `documents_current_action_updated_by_foreign` FOREIGN KEY (`current_action_updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `documents_current_office_id_foreign` FOREIGN KEY (`current_office_id`) REFERENCES `offices` (`id`) ON DELETE SET NULL,
  CONSTRAINT `documents_document_type_id_foreign` FOREIGN KEY (`document_type_id`) REFERENCES `document_types` (`id`) ON DELETE SET NULL,
  CONSTRAINT `documents_origin_office_id_foreign` FOREIGN KEY (`origin_office_id`) REFERENCES `offices` (`id`) ON DELETE SET NULL,
  CONSTRAINT `documents_priority_id_foreign` FOREIGN KEY (`priority_id`) REFERENCES `priorities` (`id`) ON DELETE SET NULL,
  CONSTRAINT `documents_status_id_foreign` FOREIGN KEY (`status_id`) REFERENCES `document_statuses` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL,
            'document_qr_codes' => <<<'SQL'
CREATE TABLE `document_qr_codes` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `qr_token` char(36) NOT NULL,
  `status` varchar(20) NOT NULL DEFAULT 'unused',
  `document_id` bigint(20) unsigned DEFAULT NULL,
  `generated_by` bigint(20) unsigned DEFAULT NULL,
  `generated_at` timestamp NULL DEFAULT NULL,
  `registered_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `document_qr_codes_qr_token_unique` (`qr_token`),
  UNIQUE KEY `document_qr_codes_document_id_unique` (`document_id`),
  KEY `document_qr_codes_generated_by_foreign` (`generated_by`),
  KEY `document_qr_codes_status_index` (`status`),
  CONSTRAINT `document_qr_codes_document_id_foreign` FOREIGN KEY (`document_id`) REFERENCES `documents` (`id`) ON DELETE SET NULL,
  CONSTRAINT `document_qr_codes_generated_by_foreign` FOREIGN KEY (`generated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL,
            'document_routes' => <<<'SQL'
CREATE TABLE `document_routes` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `document_id` bigint(20) unsigned NOT NULL,
  `from_office_id` bigint(20) unsigned NOT NULL,
  `to_office_id` bigint(20) unsigned NOT NULL,
  `forwarded_by` bigint(20) unsigned NOT NULL,
  `received_by` bigint(20) unsigned DEFAULT NULL,
  `forwarded_at` timestamp NULL DEFAULT NULL,
  `received_at` timestamp NULL DEFAULT NULL,
  `status_id` bigint(20) unsigned NOT NULL,
  `action_id` bigint(20) unsigned DEFAULT NULL,
  `remarks` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `document_routes_document_id_foreign` (`document_id`),
  KEY `document_routes_from_office_id_foreign` (`from_office_id`),
  KEY `document_routes_to_office_id_foreign` (`to_office_id`),
  KEY `document_routes_forwarded_by_foreign` (`forwarded_by`),
  KEY `document_routes_received_by_foreign` (`received_by`),
  KEY `document_routes_status_id_foreign` (`status_id`),
  KEY `document_routes_action_id_foreign` (`action_id`),
  CONSTRAINT `document_routes_action_id_foreign` FOREIGN KEY (`action_id`) REFERENCES `route_actions` (`id`) ON DELETE SET NULL,
  CONSTRAINT `document_routes_document_id_foreign` FOREIGN KEY (`document_id`) REFERENCES `documents` (`id`) ON DELETE CASCADE,
  CONSTRAINT `document_routes_forwarded_by_foreign` FOREIGN KEY (`forwarded_by`) REFERENCES `users` (`id`),
  CONSTRAINT `document_routes_from_office_id_foreign` FOREIGN KEY (`from_office_id`) REFERENCES `offices` (`id`),
  CONSTRAINT `document_routes_received_by_foreign` FOREIGN KEY (`received_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `document_routes_status_id_foreign` FOREIGN KEY (`status_id`) REFERENCES `document_statuses` (`id`),
  CONSTRAINT `document_routes_to_office_id_foreign` FOREIGN KEY (`to_office_id`) REFERENCES `offices` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL,
            'document_processing_logs' => <<<'SQL'
CREATE TABLE `document_processing_logs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `document_id` bigint(20) unsigned NOT NULL,
  `office_id` bigint(20) unsigned DEFAULT NULL,
  `user_id` bigint(20) unsigned DEFAULT NULL,
  `processing_action_id` bigint(20) unsigned DEFAULT NULL,
  `document_route_id` bigint(20) unsigned DEFAULT NULL,
  `event_type` varchar(50) NOT NULL,
  `processing_note` text DEFAULT NULL,
  `event_note` varchar(1000) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `document_processing_logs_office_id_foreign` (`office_id`),
  KEY `document_processing_logs_user_id_foreign` (`user_id`),
  KEY `document_processing_logs_processing_action_id_foreign` (`processing_action_id`),
  KEY `document_processing_logs_document_route_id_foreign` (`document_route_id`),
  KEY `document_processing_logs_document_id_created_at_index` (`document_id`,`created_at`),
  KEY `document_processing_logs_event_type_index` (`event_type`),
  CONSTRAINT `document_processing_logs_document_id_foreign` FOREIGN KEY (`document_id`) REFERENCES `documents` (`id`) ON DELETE CASCADE,
  CONSTRAINT `document_processing_logs_document_route_id_foreign` FOREIGN KEY (`document_route_id`) REFERENCES `document_routes` (`id`) ON DELETE SET NULL,
  CONSTRAINT `document_processing_logs_office_id_foreign` FOREIGN KEY (`office_id`) REFERENCES `offices` (`id`) ON DELETE SET NULL,
  CONSTRAINT `document_processing_logs_processing_action_id_foreign` FOREIGN KEY (`processing_action_id`) REFERENCES `processing_actions` (`id`) ON DELETE SET NULL,
  CONSTRAINT `document_processing_logs_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL,
            'document_attachments' => <<<'SQL'
CREATE TABLE `document_attachments` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `document_id` bigint(20) unsigned NOT NULL,
  `original_filename` varchar(255) NOT NULL,
  `stored_filename` varchar(255) NOT NULL,
  `file_path` varchar(255) NOT NULL,
  `mime_type` varchar(255) DEFAULT NULL,
  `file_size` bigint(20) unsigned DEFAULT NULL,
  `uploaded_by` bigint(20) unsigned NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `document_attachments_document_id_foreign` (`document_id`),
  KEY `document_attachments_uploaded_by_foreign` (`uploaded_by`),
  CONSTRAINT `document_attachments_document_id_foreign` FOREIGN KEY (`document_id`) REFERENCES `documents` (`id`) ON DELETE CASCADE,
  CONSTRAINT `document_attachments_uploaded_by_foreign` FOREIGN KEY (`uploaded_by`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL,
            'document_comments' => <<<'SQL'
CREATE TABLE `document_comments` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `document_id` bigint(20) unsigned NOT NULL,
  `user_id` bigint(20) unsigned NOT NULL,
  `comment` text NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `document_comments_document_id_foreign` (`document_id`),
  KEY `document_comments_user_id_foreign` (`user_id`),
  CONSTRAINT `document_comments_document_id_foreign` FOREIGN KEY (`document_id`) REFERENCES `documents` (`id`) ON DELETE CASCADE,
  CONSTRAINT `document_comments_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL,
            'audit_logs' => <<<'SQL'
CREATE TABLE `audit_logs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) unsigned DEFAULT NULL,
  `module` varchar(100) NOT NULL,
  `action` varchar(100) NOT NULL,
  `record_id` bigint(20) unsigned DEFAULT NULL,
  `description` text DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` varchar(255) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `audit_logs_user_id_foreign` (`user_id`),
  CONSTRAINT `audit_logs_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL,
            'notifications' => <<<'SQL'
CREATE TABLE `notifications` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `document_id` bigint(20) unsigned DEFAULT NULL,
  `user_id` bigint(20) unsigned NOT NULL,
  `title` varchar(255) NOT NULL,
  `message` text NOT NULL,
  `is_read` tinyint(1) NOT NULL DEFAULT 0,
  `read_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `notifications_document_id_foreign` (`document_id`),
  KEY `notifications_user_id_foreign` (`user_id`),
  CONSTRAINT `notifications_document_id_foreign` FOREIGN KEY (`document_id`) REFERENCES `documents` (`id`) ON DELETE SET NULL,
  CONSTRAINT `notifications_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL,
        ];
    }
}
