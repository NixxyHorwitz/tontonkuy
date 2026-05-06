USE tonton;
CREATE TABLE IF NOT EXISTS page_views (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  path VARCHAR(255) NOT NULL,
  ip_hash VARCHAR(64),
  referrer VARCHAR(500),
  user_agent VARCHAR(300),
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_path(path),
  INDEX idx_date(created_at)
) ENGINE=InnoDB;

INSERT IGNORE INTO settings (`key`,value) VALUES
  ('bank_enabled','1'),
  ('qris_enabled','1'),
  ('seo_title','TontonKuy — Nonton Video, Dapat Reward!'),
  ('seo_description','Platform nonton video YouTube dan dapatkan reward uang tunai setiap hari.'),
  ('seo_og_image',''),
  ('seo_robots','index,follow');

SELECT 'Migration OK' as result;
