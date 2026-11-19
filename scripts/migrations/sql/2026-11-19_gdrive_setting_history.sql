-- PAPEL: connection and storage folder history for the Director and Research Coordinator.
-- In phpMyAdmin, select this site's database, paste the whole file into the SQL tab,
-- and press Go. Run it separately for staging and production. Safe to run twice.
-- Existing connections and folder changes cannot be reconstructed; new events are
-- recorded after this table exists. OAuth tokens are never written here.

CREATE TABLE IF NOT EXISTS `gdrive_setting_history` (
  `history_id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `event_type` varchar(20) NOT NULL,
  `account_email` varchar(255) NOT NULL DEFAULT '',
  `account_name` varchar(255) NOT NULL DEFAULT '',
  `folder_id` varchar(255) NOT NULL DEFAULT '',
  `folder_name` varchar(255) NOT NULL DEFAULT '',
  `changed_by` int(11) DEFAULT NULL,
  `changed_at` datetime NOT NULL,
  PRIMARY KEY (`history_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

SELECT 'Google Drive setting history is ready.' AS result;
