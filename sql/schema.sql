-- 创高体育理论考试题库 数据库结构
-- 2026-10-26 重建

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS subjects (
  id        INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name      VARCHAR(64)  NOT NULL,
  sort      INT          NOT NULL DEFAULT 100,
  PRIMARY KEY (id),
  UNIQUE KEY uk_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS questions (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  type        VARCHAR(8)   NOT NULL COMMENT '判断/单选/多选/填空/问答',
  stem        TEXT          NOT NULL,
  stem_norm   CHAR(64)     NOT NULL COMMENT '归一化题干 sha1, 去重',
  options     JSON         NULL,
  answer      TEXT          NOT NULL,
  source      VARCHAR(16)  NOT NULL DEFAULT '2026' COMMENT '2026=权威 / legacy=旧库补充',
  subject_id  INT UNSIGNED NOT NULL,
  created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uk_norm (stem_norm),
  KEY idx_type (type),
  KEY idx_subject (subject_id),
  KEY idx_source (source)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS question_subjects (
  question_id INT UNSIGNED NOT NULL,
  subject_id  INT UNSIGNED NOT NULL,
  PRIMARY KEY (question_id, subject_id),
  KEY idx_subject (subject_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 限流状态: 每 IP 一行
CREATE TABLE IF NOT EXISTS rate_limits (
  ip          VARBINARY(16) NOT NULL,
  win_start   INT UNSIGNED  NOT NULL DEFAULT 0 COMMENT '当前分钟窗口(epoch/60)',
  win_count   INT UNSIGNED  NOT NULL DEFAULT 0,
  burst_start INT UNSIGNED  NOT NULL DEFAULT 0 COMMENT '10秒突发窗口',
  burst_count INT UNSIGNED  NOT NULL DEFAULT 0,
  day_start   INT UNSIGNED  NOT NULL DEFAULT 0 COMMENT '当天 epoch/86400',
  day_count   INT UNSIGNED  NOT NULL DEFAULT 0,
  strikes     TINYINT UNSIGNED NOT NULL DEFAULT 0,
  blocked_until INT UNSIGNED NOT NULL DEFAULT 0,
  first_seen  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (ip)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 请求审计(反爬溯源)
CREATE TABLE IF NOT EXISTS request_log (
  id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  ip         VARBINARY(16) NOT NULL,
  action     VARCHAR(24) NOT NULL,
  query      VARCHAR(160) NOT NULL DEFAULT '',
  subject    VARCHAR(64)  NOT NULL DEFAULT '',
  qtype      VARCHAR(8)   NOT NULL DEFAULT '',
  page       SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  status     SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  ua         VARCHAR(255) NOT NULL DEFAULT '',
  referer    VARCHAR(255) NOT NULL DEFAULT '',
  cost_ms    SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_ip_time (ip, created_at),
  KEY idx_action_time (action, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS settings (
  k VARCHAR(48) NOT NULL,
  v VARCHAR(255) NOT NULL,
  PRIMARY KEY (k)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS serve_budget (
  ip         VARBINARY(16) NOT NULL,
  day        INT UNSIGNED NOT NULL,
  items      INT UNSIGNED NOT NULL DEFAULT 0,
  win_start  INT UNSIGNED NOT NULL DEFAULT 0,
  win_items  INT UNSIGNED NOT NULL DEFAULT 0,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (ip, day),
  KEY idx_day (day)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE rate_limits ADD COLUMN cleared_until INT UNSIGNED NOT NULL DEFAULT 0;

CREATE TABLE IF NOT EXISTS users (
  id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  phone          VARCHAR(20)  NOT NULL,
  searches_used  INT UNSIGNED NOT NULL DEFAULT 0,
  searches_limit INT UNSIGNED NOT NULL DEFAULT 150,
  status         TINYINT NOT NULL DEFAULT 1,
  created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_login     DATETIME NULL,
  last_ip        VARBINARY(16) NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uk_phone (phone)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS auth_sessions (
  token_hash CHAR(64) NOT NULL,
  user_id    INT UNSIGNED NOT NULL,
  ip         VARBINARY(16) NULL,
  expires    INT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (token_hash),
  KEY idx_user (user_id),
  KEY idx_exp (expires)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sms_codes (
  id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  phone      VARCHAR(20) NOT NULL,
  code_hash  CHAR(64) NOT NULL,
  attempts   TINYINT UNSIGNED NOT NULL DEFAULT 0,
  used       TINYINT NOT NULL DEFAULT 0,
  expires    INT UNSIGNED NOT NULL,
  ip         VARBINARY(16) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_phone (phone),
  KEY idx_ip_time (ip, created_at),
  KEY idx_exp (expires)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS search_logs (
  id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id    INT UNSIGNED NOT NULL,
  action     VARCHAR(16) NOT NULL,
  query      VARCHAR(160) NOT NULL DEFAULT '',
  n_items    INT UNSIGNED NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_user (user_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ═══════════════════════════════════════════════════════════
-- 问题反馈 (错题申报 / Bug 反馈 / 点赞 / 评论)
-- ═══════════════════════════════════════════════════════════
CREATE TABLE IF NOT EXISTS `feedback` (
  `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`     INT UNSIGNED    NOT NULL,
  `kind`        ENUM('wrong','bug','like','comment') NOT NULL,
  `question_id` INT UNSIGNED    DEFAULT NULL,
  `reason`      VARCHAR(24)     NOT NULL DEFAULT '',
  `content`     TEXT            DEFAULT NULL,
  `contact`     VARCHAR(64)     NOT NULL DEFAULT '',
  `ip`          VARBINARY(16)   DEFAULT NULL,
  `ua`          VARCHAR(255)    NOT NULL DEFAULT '',
  `status`      TINYINT         NOT NULL DEFAULT 0 COMMENT '0待处理 1已处理 2忽略',
  `admin_note`  VARCHAR(255)    NOT NULL DEFAULT '',
  `created_at`  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `k_user_kind_time` (`user_id`,`kind`,`created_at`),
  KEY `k_status_time`    (`status`,`created_at`),
  KEY `k_question`       (`question_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
