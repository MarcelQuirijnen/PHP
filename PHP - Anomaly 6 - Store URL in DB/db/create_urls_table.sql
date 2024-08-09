
create database a6;
use a6;

DROP TABLE IF EXISTS urls;
CREATE TABLE `urls` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `url` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `created` datetime NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

/* insert test record to confirm correct database & table creation */
INSERT INTO `urls` (`id`, `url`, `created`) VALUES (NULL, 'www.anomalysix.com', '2021-11-18 01:23:45');

