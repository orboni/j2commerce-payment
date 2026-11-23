-- Orboni for J2Commerce: what the plugin keeps about each order it took to a payment page.
CREATE TABLE IF NOT EXISTS `#__orboni_j2commerce` (
  `order_id` varchar(255) NOT NULL,
  `data` mediumtext NOT NULL,
  `open` tinyint NOT NULL DEFAULT 1,
  `expires_at` int NOT NULL DEFAULT 0,
  `checked_at` int NOT NULL DEFAULT 0,
  `created_at` int NOT NULL DEFAULT 0,
  PRIMARY KEY (`order_id`),
  KEY `idx_open_checked` (`open`, `checked_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
