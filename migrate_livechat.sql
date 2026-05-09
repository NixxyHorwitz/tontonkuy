-- ============================================================
-- Migration: LiveChat dengan Telegram & OpenAI
-- ============================================================

-- Chat Sessions (1 sesi per user per visit)
CREATE TABLE IF NOT EXISTS `chat_sessions` (
  `id`              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `session_key`     VARCHAR(64) NOT NULL UNIQUE COMMENT 'UUID sesi unik',
  `user_id`         INT UNSIGNED DEFAULT NULL COMMENT 'NULL = guest',
  `user_name`       VARCHAR(100) NOT NULL DEFAULT 'Guest',
  `user_email`      VARCHAR(120) DEFAULT NULL,
  `tg_thread_id`    INT DEFAULT NULL COMMENT 'Telegram message_thread_id untuk grup',
  `status`          ENUM('open','closed') NOT NULL DEFAULT 'open',
  `mode`            ENUM('admin','ai') NOT NULL DEFAULT 'ai',
  `created_at`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `last_message_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_user_id (`user_id`),
  INDEX idx_status (`status`),
  INDEX idx_session_key (`session_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Chat Messages
CREATE TABLE IF NOT EXISTS `chat_messages` (
  `id`          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `session_id`  BIGINT UNSIGNED NOT NULL,
  `sender`      ENUM('user','admin','ai','system') NOT NULL DEFAULT 'user',
  `message`     TEXT NOT NULL,
  `tg_msg_id`   INT DEFAULT NULL COMMENT 'Telegram message_id',
  `created_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_session (`session_id`),
  FOREIGN KEY (`session_id`) REFERENCES `chat_sessions`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Settings baru untuk livechat
INSERT IGNORE INTO `settings` (`key`, `value`) VALUES
('tg_bot_token',        '')       ,
('tg_chat_id',          '')       ,
('tg_group_is_forum',   '1')      ,
('openai_api_key',      '')       ,
('openai_model',        'gpt-4o-mini'),
('ai_system_prompt',    'Kamu adalah asisten customer service yang ramah dan membantu untuk platform TontonKuy. Jawab pertanyaan dengan singkat, jelas, dan dalam bahasa Indonesia yang sopan.'),
('chat_welcome_msg',    'Halo! 👋 Selamat datang di TontonKuy Support. Ada yang bisa kami bantu?'),
('chat_ai_enabled',     '1')      ,
('chat_admin_enabled',  '1')      ;
