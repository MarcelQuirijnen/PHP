
create database consumer_edge;
use consumer_edge;

/* Enable stoarge of vehicle_id, vin, make, model, mileage, price */

DROP TABLE IF EXISTS `carvana`;
CREATE TABLE `carvana` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `vehicle_id` int(10) NOT NULL DEFAULT '0',
  `vin` varchar(20) NOT NULL DEFAULT '',
  `make` varchar(10) NOT NULL DEFAULT '',
  `model` varchar(30) NOT NULL DEFAULT '',
  `mileage` int(6) NOT NULL DEFAULT '0',
  `price` int(6) NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
