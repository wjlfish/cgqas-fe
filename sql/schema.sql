-- 创高题库 · 数据库结构
-- 用法: mysql -uUSER -p DBNAME < sql/schema.sql
SET NAMES utf8mb4;
CREATE TABLE IF NOT EXISTS `access_logs` (
  `id` int NOT NULL AUTO_INCREMENT,
  `ip` varchar(255) DEFAULT NULL,
  `user_agent` text,
  `device_type` varchar(50) DEFAULT NULL,
  `device_model` text,
  `hostname` varchar(255) DEFAULT NULL,
  `created_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
;

CREATE TABLE IF NOT EXISTS `subjects` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `sort` int NOT NULL DEFAULT '100',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
;

CREATE TABLE IF NOT EXISTS `questions` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `type` varchar(8) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '判断/单选/多选/填空/问答',
  `stem` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `stem_norm` char(64) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '归一化题干 sha1, 去重',
  `options` json DEFAULT NULL,
  `answer` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `source` varchar(16) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '2026' COMMENT '2026=权威 / legacy=旧库补充',
  `subject_id` int unsigned NOT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_norm` (`stem_norm`),
  KEY `idx_type` (`type`),
  KEY `idx_subject` (`subject_id`),
  KEY `idx_source` (`source`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
;

CREATE TABLE IF NOT EXISTS `question_subjects` (
  `question_id` int unsigned NOT NULL,
  `subject_id` int unsigned NOT NULL,
  PRIMARY KEY (`question_id`,`subject_id`),
  KEY `idx_subject` (`subject_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
;

CREATE TABLE IF NOT EXISTS `rate_limits` (
  `ip` varbinary(16) NOT NULL,
  `win_start` int unsigned NOT NULL DEFAULT '0' COMMENT '当前分钟窗口(epoch/60)',
  `win_count` int unsigned NOT NULL DEFAULT '0',
  `burst_start` int unsigned NOT NULL DEFAULT '0' COMMENT '10秒突发窗口',
  `burst_count` int unsigned NOT NULL DEFAULT '0',
  `day_start` int unsigned NOT NULL DEFAULT '0' COMMENT '当天 epoch/86400',
  `day_count` int unsigned NOT NULL DEFAULT '0',
  `strikes` tinyint unsigned NOT NULL DEFAULT '0',
  `blocked_until` int unsigned NOT NULL DEFAULT '0',
  `first_seen` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`ip`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
;

CREATE TABLE IF NOT EXISTS `request_log` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `ip` varbinary(16) NOT NULL,
  `action` varchar(24) NOT NULL,
  `query` varchar(160) NOT NULL DEFAULT '',
  `subject` varchar(64) NOT NULL DEFAULT '',
  `qtype` varchar(8) NOT NULL DEFAULT '',
  `page` smallint unsigned NOT NULL DEFAULT '0',
  `status` smallint unsigned NOT NULL DEFAULT '0',
  `ua` varchar(255) NOT NULL DEFAULT '',
  `referer` varchar(255) NOT NULL DEFAULT '',
  `cost_ms` smallint unsigned NOT NULL DEFAULT '0',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_ip_time` (`ip`,`created_at`),
  KEY `idx_action_time` (`action`,`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
;

CREATE TABLE IF NOT EXISTS `settings` (
  `k` varchar(48) COLLATE utf8mb4_unicode_ci NOT NULL,
  `v` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  PRIMARY KEY (`k`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
;

CREATE TABLE IF NOT EXISTS `dedupe_pairs` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `a_id` int unsigned NOT NULL,
  `b_id` int unsigned NOT NULL,
  `pair_key` char(40) NOT NULL,
  `category` varchar(16) NOT NULL,
  `j_full` decimal(6,4) NOT NULL DEFAULT '0.0000',
  `j_strip` decimal(6,4) NOT NULL DEFAULT '0.0000',
  `ans_same` tinyint NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_pair` (`pair_key`),
  KEY `idx_a` (`a_id`),
  KEY `idx_b` (`b_id`),
  KEY `idx_cat` (`category`,`j_full`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
;

CREATE TABLE IF NOT EXISTS `dedupe_decisions` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `pair_key` char(40) NOT NULL,
  `a_id` int unsigned NOT NULL,
  `b_id` int unsigned NOT NULL,
  `action` varchar(12) NOT NULL,
  `category` varchar(16) NOT NULL DEFAULT '',
  `score` decimal(6,4) NOT NULL DEFAULT '0.0000',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_key` (`pair_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
;

