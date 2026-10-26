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
CREATE TABLE IF NOT EXISTS captcha (
  id         CHAR(24) NOT NULL,
  ip         VARBINARY(16) NOT NULL,
  code_hash  CHAR(64) NOT NULL,
  image      MEDIUMBLOB NULL,
  attempts   TINYINT UNSIGNED NOT NULL DEFAULT 0,
  expires    INT UNSIGNED NOT NULL,
  used       TINYINT UNSIGNED NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_ip (ip),
  KEY idx_exp (expires)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
