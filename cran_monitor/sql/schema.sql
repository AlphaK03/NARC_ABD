-- Esquema mínimo de ejemplo para MySQL
CREATE TABLE IF NOT EXISTS monitor_clients (
  id INT AUTO_INCREMENT PRIMARY KEY,
  title VARCHAR(120) NOT NULL,
  dblink VARCHAR(128) NOT NULL,
  refresh_secs INT NOT NULL DEFAULT 3,
  warn_pct DECIMAL(5,2) NOT NULL DEFAULT 75.00,
  crit_pct DECIMAL(5,2) NOT NULL DEFAULT 85.00,
  enabled TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS sga_alerts (
  id INT AUTO_INCREMENT PRIMARY KEY,
  dblink VARCHAR(128) NOT NULL,
  used_pct DECIMAL(5,2) NOT NULL,
  crit_pct DECIMAL(5,2) NOT NULL,
  total_bytes BIGINT NOT NULL DEFAULT 0,
  used_bytes  BIGINT NOT NULL DEFAULT 0,
  free_bufs   BIGINT NOT NULL DEFAULT 0,
  block_size  INT NOT NULL DEFAULT 0,
  note VARCHAR(255),
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

DROP PROCEDURE IF EXISTS sp_create_sga_alert;
DELIMITER //
CREATE PROCEDURE sp_create_sga_alert(
  IN p_dblink VARCHAR(128),
  IN p_used_pct DECIMAL(5,2),
  IN p_crit_pct DECIMAL(5,2),
  IN p_total_bytes BIGINT,
  IN p_used_bytes BIGINT,
  IN p_free_bufs BIGINT,
  IN p_block_size INT,
  IN p_note VARCHAR(255),
  OUT p_id_alert INT
)
BEGIN
  INSERT INTO sga_alerts(dblink, used_pct, crit_pct, total_bytes, used_bytes, free_bufs, block_size, note)
  VALUES (p_dblink, p_used_pct, p_crit_pct, p_total_bytes, p_used_bytes, p_free_bufs, p_block_size, p_note);
  SET p_id_alert = LAST_INSERT_ID();
END//
DELIMITER ;
