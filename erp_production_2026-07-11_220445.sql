/*M!999999\- enable the sandbox mode */ 
-- MariaDB dump 10.19  Distrib 10.11.14-MariaDB, for debian-linux-gnu (x86_64)
--
-- Host: localhost    Database: erp_production
-- ------------------------------------------------------
-- Server version	10.11.14-MariaDB-0ubuntu0.24.04.1

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;

--
-- Current Database: `erp_production`
--

CREATE DATABASE /*!32312 IF NOT EXISTS*/ `erp_production` /*!40100 DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci */;

USE `erp_production`;

--
-- Table structure for table `cache`
--

DROP TABLE IF EXISTS `cache`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `cache` (
  `key` varchar(255) NOT NULL,
  `value` mediumtext NOT NULL,
  `expiration` int(11) NOT NULL,
  PRIMARY KEY (`key`),
  KEY `cache_expiration_index` (`expiration`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `cache`
--

LOCK TABLES `cache` WRITE;
/*!40000 ALTER TABLE `cache` DISABLE KEYS */;
/*!40000 ALTER TABLE `cache` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `cache_locks`
--

DROP TABLE IF EXISTS `cache_locks`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `cache_locks` (
  `key` varchar(255) NOT NULL,
  `owner` varchar(255) NOT NULL,
  `expiration` int(11) NOT NULL,
  PRIMARY KEY (`key`),
  KEY `cache_locks_expiration_index` (`expiration`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `cache_locks`
--

LOCK TABLES `cache_locks` WRITE;
/*!40000 ALTER TABLE `cache_locks` DISABLE KEYS */;
/*!40000 ALTER TABLE `cache_locks` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `failed_jobs`
--

DROP TABLE IF EXISTS `failed_jobs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `failed_jobs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `uuid` varchar(255) NOT NULL,
  `connection` text NOT NULL,
  `queue` text NOT NULL,
  `payload` longtext NOT NULL,
  `exception` longtext NOT NULL,
  `failed_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `failed_jobs_uuid_unique` (`uuid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `failed_jobs`
--

LOCK TABLES `failed_jobs` WRITE;
/*!40000 ALTER TABLE `failed_jobs` DISABLE KEYS */;
/*!40000 ALTER TABLE `failed_jobs` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `global_settings`
--

DROP TABLE IF EXISTS `global_settings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `global_settings` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `key` varchar(100) NOT NULL,
  `value` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `global_settings_key_unique` (`key`)
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `global_settings`
--

LOCK TABLES `global_settings` WRITE;
/*!40000 ALTER TABLE `global_settings` DISABLE KEYS */;
INSERT INTO `global_settings` VALUES
(1,'module.inventory','true','2026-06-25 21:14:30','2026-06-25 21:14:30'),
(2,'module.finance','false','2026-06-25 21:14:30','2026-06-25 21:14:30'),
(3,'module.hr','false','2026-06-25 21:14:30','2026-06-25 21:14:30'),
(4,'module.crm','false','2026-06-25 21:14:30','2026-06-25 21:14:30');
/*!40000 ALTER TABLE `global_settings` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `inv_attribute_types`
--

DROP TABLE IF EXISTS `inv_attribute_types`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `inv_attribute_types` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `category_id` bigint(20) unsigned NOT NULL,
  `product_service_type` enum('product','service') NOT NULL DEFAULT 'product',
  `attribute_type_name` varchar(100) NOT NULL,
  `description` varchar(255) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `inv_attribute_types_category_id_foreign` (`category_id`),
  CONSTRAINT `inv_attribute_types_category_id_foreign` FOREIGN KEY (`category_id`) REFERENCES `inv_categories` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=13 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `inv_attribute_types`
--

LOCK TABLES `inv_attribute_types` WRITE;
/*!40000 ALTER TABLE `inv_attribute_types` DISABLE KEYS */;
INSERT INTO `inv_attribute_types` VALUES
(2,1,'product','Composition',NULL,'2026-07-02 23:14:12','2026-07-02 23:14:12'),
(3,1,'product','GSM',NULL,'2026-07-02 23:14:22','2026-07-02 23:14:22'),
(4,1,'product','Colour',NULL,'2026-07-02 23:14:49','2026-07-02 23:14:49'),
(5,1,'product','Finish',NULL,'2026-07-02 23:15:03','2026-07-02 23:15:03'),
(6,1,'product','Dye Type',NULL,'2026-07-02 23:15:11','2026-07-02 23:15:11'),
(7,1,'product','Pattern',NULL,'2026-07-02 23:15:27','2026-07-02 23:15:27'),
(8,1,'product','Stretch',NULL,'2026-07-02 23:15:37','2026-07-02 23:15:37'),
(9,1,'product','Country of Origin',NULL,'2026-07-02 23:15:46','2026-07-02 23:15:46'),
(10,1,'product','H S Cord',NULL,'2026-07-02 23:15:58','2026-07-03 23:01:15'),
(12,1,'product','Width',NULL,'2026-07-03 23:15:06','2026-07-03 23:15:06');
/*!40000 ALTER TABLE `inv_attribute_types` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `inv_attributes`
--

DROP TABLE IF EXISTS `inv_attributes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `inv_attributes` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `attribute_type_id` bigint(20) unsigned NOT NULL,
  `attribute_name` varchar(100) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `inv_attributes_attribute_type_id_foreign` (`attribute_type_id`),
  CONSTRAINT `inv_attributes_attribute_type_id_foreign` FOREIGN KEY (`attribute_type_id`) REFERENCES `inv_attribute_types` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=214 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `inv_attributes`
--

LOCK TABLES `inv_attributes` WRITE;
/*!40000 ALTER TABLE `inv_attributes` DISABLE KEYS */;
INSERT INTO `inv_attributes` VALUES
(1,2,'100% Cotton','2026-07-02 23:16:51','2026-07-02 23:16:51'),
(2,2,'100% Polyester','2026-07-02 23:16:58','2026-07-02 23:16:58'),
(3,2,'100% Viscose','2026-07-02 23:17:06','2026-07-02 23:17:06'),
(4,2,'100% Rayon','2026-07-02 23:17:13','2026-07-02 23:17:13'),
(11,4,'Black - #03','2026-07-02 23:28:00','2026-07-03 23:50:19'),
(12,4,'White- #14','2026-07-02 23:28:14','2026-07-03 23:52:29'),
(13,4,'Navy-#04','2026-07-02 23:28:21','2026-07-03 23:50:31'),
(14,4,'Grey','2026-07-02 23:28:28','2026-07-02 23:28:28'),
(15,4,'Red- #01','2026-07-02 23:28:34','2026-07-03 23:50:41'),
(16,4,'Green','2026-07-02 23:28:41','2026-07-02 23:28:41'),
(17,4,'Yellow','2026-07-02 23:28:49','2026-07-02 23:28:49'),
(18,4,'Royal Blue','2026-07-02 23:28:58','2026-07-02 23:28:58'),
(19,4,'Maroon','2026-07-02 23:29:07','2026-07-02 23:29:07'),
(20,4,'Pink','2026-07-02 23:29:13','2026-07-02 23:29:13'),
(21,4,'Brown','2026-07-02 23:29:20','2026-07-02 23:29:20'),
(22,4,'Orange','2026-07-02 23:29:28','2026-07-02 23:29:28'),
(23,5,'Bio Wash','2026-07-02 23:49:01','2026-07-02 23:49:01'),
(24,5,'Silicon Wash','2026-07-02 23:49:09','2026-07-02 23:49:09'),
(25,5,'Enzyme Wash','2026-07-02 23:49:16','2026-07-02 23:49:16'),
(26,5,'Peach Finish','2026-07-02 23:49:22','2026-07-02 23:49:22'),
(27,5,'Brushed','2026-07-02 23:49:29','2026-07-02 23:49:29'),
(28,5,'Anti Pilling','2026-07-02 23:49:36','2026-07-02 23:49:36'),
(29,5,'Soft Finish','2026-07-02 23:49:41','2026-07-02 23:49:41'),
(30,5,'Compact Finish','2026-07-02 23:49:48','2026-07-02 23:49:48'),
(31,7,'Solid','2026-07-03 00:58:33','2026-07-03 00:58:33'),
(32,7,'Stripe','2026-07-03 00:58:41','2026-07-03 00:58:41'),
(33,7,'Check','2026-07-03 00:58:47','2026-07-03 00:58:47'),
(34,7,'Printed','2026-07-03 00:58:54','2026-07-03 00:58:54'),
(35,7,'Melange','2026-07-03 00:59:01','2026-07-03 00:59:01'),
(36,7,'Heather','2026-07-03 00:59:07','2026-07-03 00:59:07'),
(37,7,'Jacquard','2026-07-03 00:59:14','2026-07-03 00:59:14'),
(38,8,'Non Stretch','2026-07-03 00:59:44','2026-07-03 00:59:44'),
(39,8,'2 Way Stretch','2026-07-03 00:59:50','2026-07-03 00:59:50'),
(40,8,'4 Way Stretch','2026-07-03 00:59:56','2026-07-03 00:59:56'),
(41,9,'China','2026-07-03 01:00:14','2026-07-03 01:00:14'),
(42,9,'India','2026-07-03 01:00:20','2026-07-03 01:00:20'),
(43,9,'Bangladesh','2026-07-03 01:00:25','2026-07-03 01:00:25'),
(44,9,'Pakistan','2026-07-03 01:00:30','2026-07-03 01:00:30'),
(45,9,'Vietnam','2026-07-03 01:00:36','2026-07-03 01:00:36'),
(46,9,'Turkey','2026-07-03 01:00:41','2026-07-03 01:00:41'),
(47,10,'5208','2026-07-03 01:01:25','2026-07-03 01:01:25'),
(48,10,'5209','2026-07-03 01:01:34','2026-07-03 01:01:34'),
(49,10,'5210','2026-07-03 01:01:42','2026-07-03 01:01:42'),
(50,10,'5211','2026-07-03 01:01:48','2026-07-03 01:01:48'),
(51,10,'5407','2026-07-03 01:01:56','2026-07-03 01:01:56'),
(52,10,'5408','2026-07-03 01:21:04','2026-07-03 01:21:04'),
(53,10,'5512','2026-07-03 01:21:10','2026-07-03 01:21:10'),
(54,10,'5513','2026-07-03 01:21:16','2026-07-03 01:21:16'),
(55,10,'5514','2026-07-03 01:21:21','2026-07-03 01:21:21'),
(56,10,'5804','2026-07-03 01:21:27','2026-07-03 01:21:27'),
(57,10,'5806','2026-07-03 01:21:33','2026-07-03 01:21:33'),
(58,10,'5903','2026-07-03 01:21:37','2026-07-03 01:21:37'),
(59,10,'6001','2026-07-03 01:21:43','2026-07-03 01:21:43'),
(60,10,'6002','2026-07-03 01:21:49','2026-07-03 01:21:49'),
(61,10,'6003','2026-07-03 01:21:54','2026-07-03 01:21:54'),
(62,10,'6004','2026-07-03 01:21:59','2026-07-03 01:21:59'),
(63,10,'6005','2026-07-03 01:22:05','2026-07-03 01:22:05'),
(64,10,'6006','2026-07-03 01:22:10','2026-07-03 01:22:10'),
(67,3,'220 GSM','2026-07-03 03:10:47','2026-07-03 03:10:47'),
(68,3,'160','2026-07-03 07:14:52','2026-07-03 07:14:52'),
(69,3,'110','2026-07-03 07:29:00','2026-07-03 07:29:00'),
(70,3,'190','2026-07-03 09:05:44','2026-07-03 09:05:44'),
(71,3,'180','2026-07-03 09:06:00','2026-07-03 09:06:00'),
(88,3,'260','2026-07-03 09:39:48','2026-07-03 09:39:48'),
(89,4,'Offwhite #10','2026-07-03 09:49:23','2026-07-10 23:45:38'),
(90,5,'Bio & Cool','2026-07-03 23:05:30','2026-07-03 23:05:30'),
(91,12,'72\"(180cm)','2026-07-03 23:16:45','2026-07-03 23:16:45'),
(92,12,'60\"(150cm)','2026-07-03 23:20:07','2026-07-03 23:20:07'),
(94,12,'70\'(175cm)','2026-07-03 23:21:04','2026-07-03 23:21:04'),
(95,12,'74\"(185cm)','2026-07-03 23:21:37','2026-07-03 23:21:37'),
(96,12,'74\"(185cm)','2026-07-03 23:22:10','2026-07-03 23:22:10'),
(97,2,'70% Cotton+ 25% Polyester+ 5% Spandex','2026-07-03 23:35:58','2026-07-03 23:35:58'),
(99,4,'Light Grey- #07','2026-07-03 23:49:19','2026-07-03 23:49:19'),
(100,4,'Light Beige- #11','2026-07-03 23:50:06','2026-07-03 23:50:06'),
(101,4,'Mustard Yellow- #13','2026-07-03 23:52:12','2026-07-03 23:52:12'),
(102,4,'Dusty Blue- #16','2026-07-03 23:53:59','2026-07-03 23:53:59'),
(103,4,'Crimson Red- #19','2026-07-03 23:55:14','2026-07-03 23:55:14'),
(104,4,'Khaki- #20','2026-07-03 23:56:15','2026-07-03 23:56:15'),
(105,4,'Coral Pink- #21','2026-07-04 00:09:41','2026-07-04 00:09:41'),
(106,4,'Cherry Red- #19','2026-07-04 00:28:48','2026-07-04 00:28:48'),
(107,4,'White','2026-07-04 00:36:08','2026-07-04 00:36:08'),
(108,4,'Navy- #01','2026-07-04 00:41:26','2026-07-04 00:41:26'),
(109,4,'Light Blue- #02','2026-07-04 00:44:22','2026-07-04 00:44:22'),
(110,4,'Light Brown- #03','2026-07-04 00:45:33','2026-07-04 00:45:33'),
(111,4,'Sky Blue- #04','2026-07-04 00:46:27','2026-07-04 00:46:27'),
(112,4,'Moss Green- #05','2026-07-04 00:48:35','2026-07-04 00:48:35'),
(113,4,'Deep Navy Blue- #06','2026-07-04 00:49:41','2026-07-04 00:49:41'),
(114,4,'Stone Gray- #07','2026-07-04 00:51:01','2026-07-04 00:51:01'),
(115,4,'Lavender Blue- #08','2026-07-04 00:52:36','2026-07-04 00:52:36'),
(116,4,'Coffee Brown- #09','2026-07-04 00:53:35','2026-07-04 00:54:23'),
(117,4,'Mist Blue- #10','2026-07-04 00:55:25','2026-07-04 00:55:25'),
(118,4,'Lavender Gary- #11','2026-07-04 00:56:51','2026-07-04 00:56:51'),
(119,4,'Soft Lilac- #12','2026-07-04 00:58:26','2026-07-04 00:58:26'),
(120,4,'Plum Purple- #13','2026-07-04 00:59:39','2026-07-04 00:59:39'),
(121,4,'Mint Green- #14','2026-07-04 01:00:27','2026-07-04 01:00:27'),
(122,4,'Ivory- #15','2026-07-04 01:01:36','2026-07-04 01:01:36'),
(123,4,'Sage Green- #16','2026-07-04 01:02:33','2026-07-04 01:02:33'),
(124,4,'Pearl Gray- #17','2026-07-04 01:03:47','2026-07-04 01:03:47'),
(125,4,'Jet Black- #18','2026-07-04 01:04:35','2026-07-04 01:04:35'),
(126,4,'Steel Blue- #19','2026-07-04 01:05:40','2026-07-04 01:05:40'),
(127,4,'Smoke Gray- #20','2026-07-04 01:07:33','2026-07-04 01:07:33'),
(128,4,'Black- #22','2026-07-04 01:09:52','2026-07-04 01:09:52'),
(129,4,'Midnight Blue- #23','2026-07-04 01:11:04','2026-07-04 01:11:04'),
(130,4,'Charcoal Gray- #24','2026-07-04 01:12:19','2026-07-04 01:12:19'),
(131,4,'Rich Black- #25','2026-07-04 01:13:11','2026-07-04 01:13:11'),
(132,4,'Deep Indigo- #26','2026-07-04 01:14:10','2026-07-04 01:14:10'),
(133,4,'Coral Pink- #27','2026-07-04 01:15:05','2026-07-04 01:15:05'),
(134,4,'Dark Maroon- #28','2026-07-04 01:16:07','2026-07-04 01:16:07'),
(135,4,'Deep Teal- #29','2026-07-04 01:19:18','2026-07-04 01:19:18'),
(136,4,'Sage Green- #30','2026-07-04 01:21:09','2026-07-04 01:21:09'),
(137,4,'Steel Blue- #31','2026-07-04 01:22:51','2026-07-04 01:22:51'),
(139,4,'Charcoal Navy- #05','2026-07-04 01:52:19','2026-07-04 01:52:19'),
(141,4,'Ice Blue- #21','2026-07-04 02:00:06','2026-07-04 02:00:06'),
(142,4,'Slate Blue- #32','2026-07-04 02:10:42','2026-07-04 02:10:42'),
(143,6,'Reactive Dye','2026-07-05 22:01:45','2026-07-05 22:01:45'),
(144,6,'Pigment Dye','2026-07-05 22:01:51','2026-07-05 22:01:51'),
(145,6,'Yarn Dyed','2026-07-05 22:01:57','2026-07-05 22:01:57'),
(146,6,'Piece Dyed','2026-07-05 22:02:03','2026-07-05 22:02:03'),
(147,6,'Garment Dyed','2026-07-05 22:02:10','2026-07-05 22:02:10'),
(149,4,'Yellow','2026-07-10 23:45:38','2026-07-10 23:45:38'),
(150,4,'White #02','2026-07-11 02:02:57','2026-07-11 02:02:57'),
(151,4,'Cherry Red #01','2026-07-11 02:45:30','2026-07-11 02:45:30'),
(152,4,'White #02','2026-07-11 02:45:30','2026-07-11 02:45:30'),
(153,4,'Cream Beige #05','2026-07-11 02:45:30','2026-07-11 02:45:30'),
(154,4,'Baby Blue #06','2026-07-11 02:45:30','2026-07-11 02:45:30'),
(155,4,'Baby Pink #08','2026-07-11 02:45:30','2026-07-11 02:45:30'),
(156,4,'Off White #10','2026-07-11 02:45:30','2026-07-11 02:45:30'),
(157,4,'Slate Grey #13','2026-07-11 02:45:30','2026-07-11 02:45:30'),
(158,4,'Dusty Rose #14','2026-07-11 02:45:30','2026-07-11 02:45:30'),
(159,4,'Sky Blue #16','2026-07-11 02:45:30','2026-07-11 02:45:30'),
(160,4,'Peach #17','2026-07-11 02:45:30','2026-07-11 02:45:30'),
(161,4,'Citron Green #20','2026-07-11 02:45:30','2026-07-11 02:45:30'),
(162,4,'Cobalt Blue #21','2026-07-11 02:45:30','2026-07-11 02:45:30'),
(163,4,'Pearl Grey #22','2026-07-11 02:45:30','2026-07-11 02:45:30'),
(164,4,'Celadon Green #23','2026-07-11 02:45:30','2026-07-11 02:45:30'),
(165,4,'Gunmetal Grey #24','2026-07-11 02:45:30','2026-07-11 02:45:30'),
(166,4,'Desert Camel #25','2026-07-11 02:45:30','2026-07-11 02:45:30'),
(167,4,'Seafoam Green #26','2026-07-11 02:45:30','2026-07-11 02:45:30'),
(168,4,'Crimson Red #27','2026-07-11 02:45:30','2026-07-11 02:45:30'),
(169,4,'French Blue #28','2026-07-11 02:45:30','2026-07-11 02:45:30'),
(170,4,'Granite Grey #29','2026-07-11 02:45:30','2026-07-11 02:45:30'),
(171,4,'Heather Grey #33','2026-07-11 02:45:30','2026-07-11 02:45:30'),
(172,4,'Pistachio Green #48','2026-07-11 02:45:30','2026-07-11 02:45:30'),
(173,4,'Rose Pink #51','2026-07-11 02:45:30','2026-07-11 02:45:30'),
(174,4,'Canary Yellow #52','2026-07-11 02:45:30','2026-07-11 02:45:30'),
(175,4,'Burgundy #55','2026-07-11 02:45:30','2026-07-11 02:45:30'),
(176,4,'Golden Apricot #63','2026-07-11 02:45:30','2026-07-11 02:45:30'),
(177,4,'Ice Blue #64','2026-07-11 02:45:30','2026-07-11 02:45:30'),
(178,4,'Sky Blue #66','2026-07-11 02:45:30','2026-07-11 02:45:30'),
(179,4,'Emerald Green #67','2026-07-11 02:45:30','2026-07-11 02:45:30'),
(180,4,'urse. Here’s the refined','2026-07-11 02:45:30','2026-07-11 02:45:30'),
(181,4,'professional version again:  Heather Grey #33','2026-07-11 02:45:30','2026-07-11 02:45:30'),
(182,4,'Pistachio Green #48','2026-07-11 02:45:30','2026-07-11 02:45:30'),
(183,4,'Rose Pink #51','2026-07-11 02:45:30','2026-07-11 02:45:30'),
(184,4,'Canary Yellow #52','2026-07-11 02:45:30','2026-07-11 02:45:30'),
(185,4,'Burgundy #55','2026-07-11 02:45:30','2026-07-11 02:45:30'),
(186,4,'Golden Apricot #63','2026-07-11 02:45:30','2026-07-11 02:45:30'),
(187,4,'Ice Blue #64','2026-07-11 02:45:30','2026-07-11 02:45:30'),
(188,4,'Sky Blue #66','2026-07-11 02:45:30','2026-07-11 02:45:30'),
(189,4,'Emerald Green #67','2026-07-11 02:45:30','2026-07-11 02:45:30'),
(190,4,'Graphite Grey #69','2026-07-11 02:45:30','2026-07-11 02:45:30'),
(191,4,'Mustard Yellow #34','2026-07-11 02:45:30','2026-07-11 02:45:30'),
(192,4,'Navy Blue #35','2026-07-11 02:45:30','2026-07-11 02:45:30'),
(193,4,'Jet Black #36','2026-07-11 02:45:30','2026-07-11 02:45:30'),
(194,4,'Midnight Navy #39','2026-07-11 02:45:30','2026-07-11 02:45:30'),
(195,4,'Turquoise Blue #40','2026-07-11 02:45:30','2026-07-11 02:45:30'),
(196,4,'Sapphire Blue #41','2026-07-11 02:45:30','2026-07-11 02:45:30'),
(197,4,'Peacock Blue #42','2026-07-11 02:45:30','2026-07-11 02:45:30'),
(198,4,'Taupe Brown #45','2026-07-11 02:45:30','2026-07-11 02:45:30'),
(199,4,'Candy Pink #47','2026-07-11 02:45:30','2026-07-11 02:45:30'),
(200,4,'Pink #08','2026-07-11 04:57:00','2026-07-11 04:57:00'),
(201,4,'Ice Blue 50#','2026-07-11 05:50:53','2026-07-11 05:50:53'),
(202,4,'Olive Green 54#','2026-07-11 05:50:53','2026-07-11 05:50:53'),
(203,4,'Burnt Orange 60#','2026-07-11 05:50:53','2026-07-11 05:50:53'),
(204,4,'Dark Green 73#','2026-07-11 05:50:53','2026-07-11 05:50:53'),
(205,4,'Baby Pink 77#','2026-07-11 05:50:53','2026-07-11 05:50:53'),
(206,4,'Dusty Purple 92#','2026-07-11 05:50:53','2026-07-11 05:50:53'),
(207,4,'Raspberry Pink 104#','2026-07-11 05:50:53','2026-07-11 05:50:53'),
(208,4,'Hot Pink 128#','2026-07-11 05:50:53','2026-07-11 05:50:53'),
(209,4,'Peach 129#','2026-07-11 05:50:53','2026-07-11 05:50:53'),
(210,4,'Steel Blue 135#','2026-07-11 05:50:53','2026-07-11 05:50:53'),
(211,4,'Pumpkin Orange 137#','2026-07-11 05:50:53','2026-07-11 05:50:53'),
(212,4,'Ivory Cream 139#','2026-07-11 05:50:53','2026-07-11 05:50:53'),
(213,4,'Pink #109','2026-07-11 05:56:56','2026-07-11 05:56:56');
/*!40000 ALTER TABLE `inv_attributes` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `inv_batches`
--

DROP TABLE IF EXISTS `inv_batches`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `inv_batches` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `batch_no` varchar(100) NOT NULL,
  `product_id` bigint(20) unsigned NOT NULL,
  `supplier_id` bigint(20) unsigned DEFAULT NULL,
  `supplier_batch_no` varchar(100) DEFAULT NULL,
  `mfg_date` date DEFAULT NULL,
  `expiry_date` date DEFAULT NULL,
  `received_date` date NOT NULL,
  `initial_qty` decimal(15,4) NOT NULL DEFAULT 0.0000,
  `current_qty` decimal(15,4) NOT NULL DEFAULT 0.0000,
  `unit_cost` decimal(15,4) NOT NULL DEFAULT 0.0000,
  `status` varchar(20) NOT NULL DEFAULT 'active',
  `country_of_origin` varchar(100) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_by` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `inv_batches_product_id_batch_no_unique` (`product_id`,`batch_no`),
  KEY `inv_batches_product_id_index` (`product_id`),
  KEY `inv_batches_supplier_id_index` (`supplier_id`),
  KEY `inv_batches_status_index` (`status`),
  KEY `inv_batches_expiry_date_index` (`expiry_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `inv_batches`
--

LOCK TABLES `inv_batches` WRITE;
/*!40000 ALTER TABLE `inv_batches` DISABLE KEYS */;
/*!40000 ALTER TABLE `inv_batches` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `inv_categories`
--

DROP TABLE IF EXISTS `inv_categories`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `inv_categories` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `product_service_type` enum('product','service') NOT NULL DEFAULT 'product',
  `industry_id` bigint(20) unsigned DEFAULT NULL,
  `company_id` bigint(20) unsigned DEFAULT NULL,
  `parent_category_id` bigint(20) unsigned DEFAULT NULL,
  `category_name` varchar(100) NOT NULL,
  `reference_name` varchar(100) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `inv_categories_industry_id_foreign` (`industry_id`),
  KEY `inv_categories_company_id_foreign` (`company_id`),
  KEY `inv_categories_parent_category_id_foreign` (`parent_category_id`),
  CONSTRAINT `inv_categories_company_id_foreign` FOREIGN KEY (`company_id`) REFERENCES `inv_companies` (`id`) ON DELETE SET NULL,
  CONSTRAINT `inv_categories_industry_id_foreign` FOREIGN KEY (`industry_id`) REFERENCES `inv_industries` (`id`) ON DELETE SET NULL,
  CONSTRAINT `inv_categories_parent_category_id_foreign` FOREIGN KEY (`parent_category_id`) REFERENCES `inv_categories` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=47 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `inv_categories`
--

LOCK TABLES `inv_categories` WRITE;
/*!40000 ALTER TABLE `inv_categories` DISABLE KEYS */;
INSERT INTO `inv_categories` VALUES
(1,'product',1,1,NULL,'Fabric',NULL,'2026-07-02 22:37:30','2026-07-02 22:37:30'),
(2,'product',1,1,1,'Knitted Fabric',NULL,'2026-07-02 22:38:41','2026-07-02 22:38:41'),
(3,'product',1,1,2,'Single Jersey',NULL,'2026-07-02 22:39:01','2026-07-02 22:39:01'),
(4,'product',1,1,2,'Double Jersey',NULL,'2026-07-02 22:39:14','2026-07-02 22:39:14'),
(5,'product',NULL,NULL,2,'1x1 Rib',NULL,'2026-07-02 22:49:18','2026-07-02 22:49:18'),
(6,'product',NULL,NULL,1,'Woven Fabric',NULL,'2026-07-03 07:20:22','2026-07-03 07:23:19'),
(7,'product',NULL,NULL,6,'Rayon',NULL,'2026-07-03 07:24:34','2026-07-03 07:24:34'),
(8,'product',NULL,NULL,6,'Poplin',NULL,'2026-07-05 22:03:53','2026-07-05 22:03:53'),
(9,'product',NULL,NULL,6,'Twill',NULL,'2026-07-05 22:04:00','2026-07-05 22:04:00'),
(10,'product',NULL,NULL,6,'Denim',NULL,'2026-07-05 22:04:06','2026-07-05 22:04:06'),
(11,'product',NULL,NULL,6,'Canvas',NULL,'2026-07-05 22:04:12','2026-07-05 22:04:12'),
(12,'product',NULL,NULL,6,'Oxford',NULL,'2026-07-05 22:04:19','2026-07-05 22:04:19'),
(13,'product',NULL,NULL,6,'Linen',NULL,'2026-07-05 22:04:27','2026-07-05 22:04:27'),
(14,'product',NULL,NULL,6,'Chambray',NULL,'2026-07-05 22:04:33','2026-07-05 22:04:33'),
(15,'product',NULL,NULL,6,'Satin',NULL,'2026-07-05 22:04:41','2026-07-05 22:04:41'),
(16,'product',NULL,NULL,6,'Chiffon',NULL,'2026-07-05 22:04:47','2026-07-05 22:04:47'),
(17,'product',NULL,NULL,6,'Georgette',NULL,'2026-07-05 22:04:55','2026-07-05 22:04:55'),
(18,'product',NULL,NULL,6,'Crepe',NULL,'2026-07-05 22:05:01','2026-07-05 22:05:01'),
(19,'product',NULL,NULL,6,'Voile',NULL,'2026-07-05 22:05:09','2026-07-05 22:05:09'),
(20,'product',NULL,NULL,6,'Muslin',NULL,'2026-07-05 22:05:15','2026-07-05 22:05:15'),
(21,'product',NULL,NULL,6,'Corduroy',NULL,'2026-07-05 22:05:21','2026-07-05 22:05:21'),
(22,'product',NULL,NULL,6,'Flannel',NULL,'2026-07-05 22:05:27','2026-07-05 22:05:27'),
(23,'product',NULL,NULL,1,'Synthetic Fabric',NULL,'2026-07-05 22:05:48','2026-07-05 22:05:48'),
(24,'product',NULL,NULL,23,'Polyester',NULL,'2026-07-05 22:06:23','2026-07-05 22:06:23'),
(25,'product',NULL,NULL,23,'Nylon',NULL,'2026-07-05 22:06:28','2026-07-05 22:06:28'),
(26,'product',NULL,NULL,23,'Spandex (Lycra)',NULL,'2026-07-05 22:06:34','2026-07-05 22:06:34'),
(27,'product',NULL,NULL,23,'Rayon',NULL,'2026-07-05 22:06:40','2026-07-05 22:06:40'),
(28,'product',NULL,NULL,23,'Viscose',NULL,'2026-07-05 22:06:47','2026-07-05 22:06:47'),
(29,'product',NULL,NULL,23,'Acrylic',NULL,'2026-07-05 22:06:55','2026-07-05 22:06:55'),
(30,'product',NULL,NULL,23,'Microfiber',NULL,'2026-07-05 22:07:00','2026-07-05 22:07:00'),
(31,'product',NULL,NULL,23,'Taffeta',NULL,'2026-07-05 22:07:06','2026-07-05 22:07:06'),
(32,'product',NULL,NULL,1,'Blended Fabric',NULL,'2026-07-05 22:07:17','2026-07-05 22:07:17'),
(33,'product',NULL,NULL,32,'Cotton Polyester',NULL,'2026-07-05 22:07:40','2026-07-05 22:07:40'),
(34,'product',NULL,NULL,32,'Cotton Spandex',NULL,'2026-07-05 22:07:48','2026-07-05 22:07:48'),
(35,'product',NULL,NULL,32,'Polyester Spandex',NULL,'2026-07-05 22:07:55','2026-07-05 22:07:55'),
(36,'product',NULL,NULL,32,'Cotton Rayon',NULL,'2026-07-05 22:08:04','2026-07-05 22:08:04'),
(37,'product',NULL,NULL,32,'Cotton Linen',NULL,'2026-07-05 22:08:10','2026-07-05 22:08:10'),
(38,'product',NULL,NULL,32,'Poly Viscose',NULL,'2026-07-05 22:08:17','2026-07-05 22:08:17'),
(39,'product',NULL,NULL,1,'Traditional Fabric',NULL,'2026-07-05 22:08:36','2026-07-05 22:08:36'),
(40,'product',NULL,NULL,39,'Silk',NULL,'2026-07-05 22:08:49','2026-07-05 22:08:49'),
(41,'product',NULL,NULL,39,'Cotton Silk',NULL,'2026-07-05 22:08:56','2026-07-05 22:08:56'),
(42,'product',NULL,NULL,39,'Banarasi',NULL,'2026-07-05 22:09:07','2026-07-05 22:09:07'),
(43,'product',NULL,NULL,39,'Organza',NULL,'2026-07-05 22:09:13','2026-07-05 22:09:13'),
(44,'product',NULL,NULL,39,'Velvet',NULL,'2026-07-05 22:09:20','2026-07-05 22:09:20'),
(45,'product',NULL,NULL,39,'Net Fabric',NULL,'2026-07-05 22:09:26','2026-07-05 22:09:26'),
(46,'product',NULL,NULL,39,'Lace',NULL,'2026-07-05 22:09:33','2026-07-05 22:09:33');
/*!40000 ALTER TABLE `inv_categories` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `inv_companies`
--

DROP TABLE IF EXISTS `inv_companies`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `inv_companies` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `company_type` varchar(50) DEFAULT NULL,
  `company_name` varchar(100) NOT NULL,
  `registration_no` varchar(50) DEFAULT NULL,
  `tax_reg_no` varchar(50) DEFAULT NULL,
  `street_address` varchar(100) DEFAULT NULL,
  `city` varchar(50) DEFAULT NULL,
  `country` varchar(50) DEFAULT NULL,
  `state` varchar(50) DEFAULT NULL,
  `postal_zip_code` varchar(20) DEFAULT NULL,
  `company_email` varchar(100) DEFAULT NULL,
  `company_mobile` varchar(20) DEFAULT NULL,
  `industry_id` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `inv_companies_registration_no_unique` (`registration_no`),
  KEY `inv_companies_industry_id_foreign` (`industry_id`),
  CONSTRAINT `inv_companies_industry_id_foreign` FOREIGN KEY (`industry_id`) REFERENCES `inv_industries` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `inv_companies`
--

LOCK TABLES `inv_companies` WRITE;
/*!40000 ALTER TABLE `inv_companies` DISABLE KEYS */;
INSERT INTO `inv_companies` VALUES
(1,'Distributor','P.G. Fashion (Pvt) Ltd',NULL,NULL,'No.12 Polwatte road, Maharagama 10280','Maharagama','Srilanka',NULL,'10280','badarapgf@sltnet.lk','0718418695',1,'2026-06-26 05:31:21','2026-06-26 05:31:21');
/*!40000 ALTER TABLE `inv_companies` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `inv_costing_expense_types`
--

DROP TABLE IF EXISTS `inv_costing_expense_types`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `inv_costing_expense_types` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `costing_type` varchar(10) NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `sort_order` smallint(6) NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `inv_costing_expense_types_costing_type_index` (`costing_type`),
  KEY `inv_costing_expense_types_costing_type_is_active_index` (`costing_type`,`is_active`)
) ENGINE=InnoDB AUTO_INCREMENT=11 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `inv_costing_expense_types`
--

LOCK TABLES `inv_costing_expense_types` WRITE;
/*!40000 ALTER TABLE `inv_costing_expense_types` DISABLE KEYS */;
INSERT INTO `inv_costing_expense_types` VALUES
(1,'Freight Charges','fob',1,1,'2026-06-25 21:18:41','2026-06-25 21:18:41'),
(2,'Custom Duty','fob',1,2,'2026-06-25 21:18:41','2026-06-25 21:18:41'),
(3,'Bank Charges','fob',1,3,'2026-06-25 21:18:41','2026-06-25 21:18:41'),
(4,'Bank Commission','fob',1,4,'2026-06-25 21:18:41','2026-06-25 21:18:41'),
(5,'Clearance & Transportation','fob',1,5,'2026-06-25 21:18:41','2026-06-25 21:18:41'),
(6,'Insurance','fob',1,6,'2026-06-25 21:18:41','2026-06-25 21:18:41'),
(7,'Import Customs Duty','cif',1,1,'2026-06-25 21:18:41','2026-06-25 21:18:41'),
(8,'Clearance & Transportation','cif',1,2,'2026-06-25 21:18:41','2026-06-25 21:18:41'),
(9,'Bank Charges','cif',1,3,'2026-06-25 21:18:41','2026-06-25 21:18:41'),
(10,'Bank Commission','cif',1,4,'2026-06-25 21:18:41','2026-06-25 21:18:41');
/*!40000 ALTER TABLE `inv_costing_expense_types` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `inv_costing_expenses`
--

DROP TABLE IF EXISTS `inv_costing_expenses`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `inv_costing_expenses` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `costing_id` bigint(20) unsigned NOT NULL,
  `expense_type_id` bigint(20) unsigned NOT NULL,
  `amount` decimal(15,4) NOT NULL DEFAULT 0.0000,
  `note` varchar(255) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `inv_costing_expenses_costing_id_index` (`costing_id`),
  KEY `inv_costing_expenses_expense_type_id_index` (`expense_type_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `inv_costing_expenses`
--

LOCK TABLES `inv_costing_expenses` WRITE;
/*!40000 ALTER TABLE `inv_costing_expenses` DISABLE KEYS */;
/*!40000 ALTER TABLE `inv_costing_expenses` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `inv_costing_grns`
--

DROP TABLE IF EXISTS `inv_costing_grns`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `inv_costing_grns` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `costing_id` bigint(20) unsigned NOT NULL,
  `grn_id` bigint(20) unsigned NOT NULL,
  `grn_total` decimal(15,4) NOT NULL DEFAULT 0.0000,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `inv_costing_grns_costing_id_grn_id_unique` (`costing_id`,`grn_id`),
  KEY `inv_costing_grns_costing_id_index` (`costing_id`),
  KEY `inv_costing_grns_grn_id_index` (`grn_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `inv_costing_grns`
--

LOCK TABLES `inv_costing_grns` WRITE;
/*!40000 ALTER TABLE `inv_costing_grns` DISABLE KEYS */;
/*!40000 ALTER TABLE `inv_costing_grns` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `inv_costing_items`
--

DROP TABLE IF EXISTS `inv_costing_items`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `inv_costing_items` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `costing_id` bigint(20) unsigned NOT NULL,
  `grn_id` bigint(20) unsigned NOT NULL,
  `grn_item_id` bigint(20) unsigned NOT NULL,
  `product_id` bigint(20) unsigned NOT NULL,
  `attribute_id` bigint(20) unsigned DEFAULT NULL,
  `unit_id` bigint(20) unsigned DEFAULT NULL,
  `quantity` decimal(15,4) NOT NULL DEFAULT 0.0000,
  `unit_price` decimal(15,4) NOT NULL DEFAULT 0.0000,
  `charge_portion` decimal(15,4) NOT NULL DEFAULT 0.0000,
  `landed_unit_cost` decimal(15,4) NOT NULL DEFAULT 0.0000,
  `margin_pct` decimal(8,4) DEFAULT NULL,
  `margin_amount` decimal(15,4) NOT NULL DEFAULT 0.0000,
  `sscl_amount` decimal(15,4) NOT NULL DEFAULT 0.0000,
  `vat_amount` decimal(15,4) NOT NULL DEFAULT 0.0000,
  `selling_price` decimal(15,4) NOT NULL DEFAULT 0.0000,
  `is_price_overridden` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `inv_costing_items_costing_id_grn_item_id_unique` (`costing_id`,`grn_item_id`),
  KEY `inv_costing_items_costing_id_index` (`costing_id`),
  KEY `inv_costing_items_grn_id_index` (`grn_id`),
  KEY `inv_costing_items_product_id_index` (`product_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `inv_costing_items`
--

LOCK TABLES `inv_costing_items` WRITE;
/*!40000 ALTER TABLE `inv_costing_items` DISABLE KEYS */;
/*!40000 ALTER TABLE `inv_costing_items` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `inv_costings`
--

DROP TABLE IF EXISTS `inv_costings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `inv_costings` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `document_no` varchar(30) NOT NULL,
  `reference_no` varchar(30) NOT NULL,
  `supplier_id` bigint(20) unsigned NOT NULL,
  `costing_type` varchar(10) NOT NULL,
  `total_items` decimal(15,4) NOT NULL DEFAULT 0.0000,
  `material_cost` decimal(15,4) NOT NULL DEFAULT 0.0000,
  `bill_of_lading` varchar(100) DEFAULT NULL,
  `expected_date` date DEFAULT NULL,
  `transaction_date` date DEFAULT NULL,
  `note` text DEFAULT NULL,
  `total_additional_expenses` decimal(15,4) NOT NULL DEFAULT 0.0000,
  `raw_material_cost` decimal(15,4) NOT NULL DEFAULT 0.0000,
  `total_landed_cost` decimal(15,4) NOT NULL DEFAULT 0.0000,
  `default_margin_pct` decimal(5,2) NOT NULL DEFAULT 0.00,
  `value_addition_pct` decimal(5,2) NOT NULL DEFAULT 10.00,
  `value_addition_amount` decimal(15,4) NOT NULL DEFAULT 0.0000,
  `fob_cif_cost` decimal(15,4) NOT NULL DEFAULT 0.0000,
  `sscl_pct` decimal(5,2) NOT NULL DEFAULT 2.50,
  `apply_sscl` tinyint(1) NOT NULL DEFAULT 0,
  `sscl_amount` decimal(15,4) NOT NULL DEFAULT 0.0000,
  `gross_fob_cif_value` decimal(15,4) NOT NULL DEFAULT 0.0000,
  `vat_pct` decimal(5,2) NOT NULL DEFAULT 18.00,
  `apply_vat` tinyint(1) NOT NULL DEFAULT 0,
  `vat_amount` decimal(15,4) NOT NULL DEFAULT 0.0000,
  `total_price_with_vat` decimal(15,4) NOT NULL DEFAULT 0.0000,
  `status` varchar(20) NOT NULL DEFAULT 'draft',
  `created_by` bigint(20) unsigned DEFAULT NULL,
  `confirmed_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `inv_costings_document_no_unique` (`document_no`),
  UNIQUE KEY `inv_costings_reference_no_unique` (`reference_no`),
  KEY `inv_costings_supplier_id_index` (`supplier_id`),
  KEY `inv_costings_costing_type_index` (`costing_type`),
  KEY `inv_costings_status_index` (`status`),
  KEY `inv_costings_transaction_date_index` (`transaction_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `inv_costings`
--

LOCK TABLES `inv_costings` WRITE;
/*!40000 ALTER TABLE `inv_costings` DISABLE KEYS */;
/*!40000 ALTER TABLE `inv_costings` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `inv_customer_attachments`
--

DROP TABLE IF EXISTS `inv_customer_attachments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `inv_customer_attachments` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `customer_master_id` bigint(20) unsigned NOT NULL,
  `file_name` varchar(255) NOT NULL,
  `file_path` varchar(500) NOT NULL,
  `file_size` bigint(20) unsigned DEFAULT NULL,
  `mime_type` varchar(100) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `inv_customer_attachments_customer_master_id_index` (`customer_master_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `inv_customer_attachments`
--

LOCK TABLES `inv_customer_attachments` WRITE;
/*!40000 ALTER TABLE `inv_customer_attachments` DISABLE KEYS */;
/*!40000 ALTER TABLE `inv_customer_attachments` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `inv_customer_masters`
--

DROP TABLE IF EXISTS `inv_customer_masters`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `inv_customer_masters` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `customer_code` varchar(50) DEFAULT NULL,
  `reference_no` varchar(50) DEFAULT NULL,
  `customer_type` enum('Trade','Retail','Wholesale','Corporate') DEFAULT NULL,
  `title` varchar(20) DEFAULT NULL,
  `customer_name` varchar(100) NOT NULL,
  `nic_passport_driving_licence` varchar(50) DEFAULT NULL,
  `attachments` varchar(255) DEFAULT NULL,
  `br_no` varchar(50) DEFAULT NULL,
  `customer_tin` varchar(50) DEFAULT NULL,
  `customer_mobile` varchar(20) DEFAULT NULL,
  `customer_land_line` varchar(20) DEFAULT NULL,
  `customer_email` varchar(100) DEFAULT NULL,
  `customer_fax` varchar(20) DEFAULT NULL,
  `billing_address_line1` varchar(100) DEFAULT NULL,
  `billing_address_line2` varchar(100) DEFAULT NULL,
  `billing_address_line3` varchar(100) DEFAULT NULL,
  `billing_city` varchar(50) DEFAULT NULL,
  `billing_zip_postal` varchar(20) DEFAULT NULL,
  `billing_state_province` varchar(50) DEFAULT NULL,
  `billing_country` varchar(50) DEFAULT NULL,
  `shipping_address_line1` varchar(100) DEFAULT NULL,
  `shipping_address_line2` varchar(100) DEFAULT NULL,
  `shipping_address_line3` varchar(100) DEFAULT NULL,
  `shipping_city` varchar(50) DEFAULT NULL,
  `shipping_zip_postal` varchar(20) DEFAULT NULL,
  `shipping_state_province` varchar(50) DEFAULT NULL,
  `shipping_country` varchar(50) DEFAULT NULL,
  `sale_manager` varchar(100) DEFAULT NULL,
  `sales_executive` varchar(100) DEFAULT NULL,
  `sales_person` varchar(100) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `inv_customer_masters_customer_code_unique` (`customer_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `inv_customer_masters`
--

LOCK TABLES `inv_customer_masters` WRITE;
/*!40000 ALTER TABLE `inv_customer_masters` DISABLE KEYS */;
/*!40000 ALTER TABLE `inv_customer_masters` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `inv_delivery_order_items`
--

DROP TABLE IF EXISTS `inv_delivery_order_items`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `inv_delivery_order_items` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `do_id` bigint(20) unsigned NOT NULL,
  `so_item_id` bigint(20) unsigned NOT NULL,
  `product_id` bigint(20) unsigned NOT NULL,
  `unit_id` bigint(20) unsigned DEFAULT NULL,
  `attribute_id` bigint(20) unsigned DEFAULT NULL,
  `is_scanned` tinyint(1) NOT NULL DEFAULT 0,
  `quantity` decimal(15,4) NOT NULL DEFAULT 0.0000,
  `remarks` varchar(255) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `inv_delivery_order_items_do_id_index` (`do_id`),
  KEY `inv_delivery_order_items_so_item_id_index` (`so_item_id`),
  KEY `inv_delivery_order_items_product_id_index` (`product_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `inv_delivery_order_items`
--

LOCK TABLES `inv_delivery_order_items` WRITE;
/*!40000 ALTER TABLE `inv_delivery_order_items` DISABLE KEYS */;
/*!40000 ALTER TABLE `inv_delivery_order_items` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `inv_delivery_order_pieces`
--

DROP TABLE IF EXISTS `inv_delivery_order_pieces`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `inv_delivery_order_pieces` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `do_id` bigint(20) unsigned NOT NULL,
  `do_item_id` bigint(20) unsigned NOT NULL,
  `so_piece_id` bigint(20) unsigned NOT NULL,
  `piece_id` bigint(20) unsigned NOT NULL,
  `piece_code` varchar(40) NOT NULL,
  `weight` decimal(15,4) NOT NULL DEFAULT 0.0000,
  `store_id` bigint(20) unsigned DEFAULT NULL,
  `location_id` bigint(20) unsigned DEFAULT NULL,
  `batch_id` bigint(20) unsigned DEFAULT NULL,
  `stock_transaction_id` bigint(20) unsigned DEFAULT NULL,
  `created_by` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `inv_delivery_order_pieces_piece_id_unique` (`piece_id`),
  KEY `inv_delivery_order_pieces_do_id_index` (`do_id`),
  KEY `inv_delivery_order_pieces_do_item_id_index` (`do_item_id`),
  KEY `inv_delivery_order_pieces_so_piece_id_index` (`so_piece_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `inv_delivery_order_pieces`
--

LOCK TABLES `inv_delivery_order_pieces` WRITE;
/*!40000 ALTER TABLE `inv_delivery_order_pieces` DISABLE KEYS */;
/*!40000 ALTER TABLE `inv_delivery_order_pieces` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `inv_delivery_orders`
--

DROP TABLE IF EXISTS `inv_delivery_orders`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `inv_delivery_orders` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `do_no` varchar(30) NOT NULL,
  `document_date` date DEFAULT NULL,
  `so_id` bigint(20) unsigned NOT NULL,
  `customer_id` bigint(20) unsigned NOT NULL,
  `driver_id` bigint(20) unsigned DEFAULT NULL,
  `vehicle_id` bigint(20) unsigned DEFAULT NULL,
  `store_id` bigint(20) unsigned DEFAULT NULL,
  `location_id` bigint(20) unsigned DEFAULT NULL,
  `delivery_date` date NOT NULL,
  `delivery_mode` varchar(40) DEFAULT NULL,
  `delivery_vehicle` varchar(150) DEFAULT NULL,
  `responsible_person` varchar(150) DEFAULT NULL,
  `delivery_address` text DEFAULT NULL,
  `status` varchar(30) NOT NULL DEFAULT 'draft',
  `remarks` text DEFAULT NULL,
  `created_by` bigint(20) unsigned DEFAULT NULL,
  `confirmed_at` timestamp NULL DEFAULT NULL,
  `confirmed_by` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `inv_delivery_orders_do_no_unique` (`do_no`),
  KEY `inv_delivery_orders_so_id_index` (`so_id`),
  KEY `inv_delivery_orders_customer_id_index` (`customer_id`),
  KEY `inv_delivery_orders_status_index` (`status`),
  KEY `inv_delivery_orders_delivery_date_index` (`delivery_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `inv_delivery_orders`
--

LOCK TABLES `inv_delivery_orders` WRITE;
/*!40000 ALTER TABLE `inv_delivery_orders` DISABLE KEYS */;
/*!40000 ALTER TABLE `inv_delivery_orders` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `inv_drivers`
--

DROP TABLE IF EXISTS `inv_drivers`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `inv_drivers` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `driver_code` varchar(20) NOT NULL,
  `first_name` varchar(100) NOT NULL,
  `last_name` varchar(100) DEFAULT NULL,
  `nic_number` varchar(50) DEFAULT NULL,
  `date_of_birth` date DEFAULT NULL,
  `license_number` varchar(50) NOT NULL,
  `license_type` varchar(50) DEFAULT NULL,
  `license_expiry_date` date DEFAULT NULL,
  `phone` varchar(20) DEFAULT NULL,
  `email` varchar(100) DEFAULT NULL,
  `address_line1` varchar(150) DEFAULT NULL,
  `address_line2` varchar(150) DEFAULT NULL,
  `city` varchar(100) DEFAULT NULL,
  `state` varchar(100) DEFAULT NULL,
  `country` varchar(100) DEFAULT NULL,
  `postal_code` varchar(20) DEFAULT NULL,
  `hired_date` date DEFAULT NULL,
  `status` enum('active','inactive','suspended') NOT NULL DEFAULT 'active',
  `notes` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `inv_drivers_driver_code_unique` (`driver_code`),
  UNIQUE KEY `inv_drivers_license_number_unique` (`license_number`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `inv_drivers`
--

LOCK TABLES `inv_drivers` WRITE;
/*!40000 ALTER TABLE `inv_drivers` DISABLE KEYS */;
/*!40000 ALTER TABLE `inv_drivers` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `inv_goods_received_note_items`
--

DROP TABLE IF EXISTS `inv_goods_received_note_items`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `inv_goods_received_note_items` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `grn_id` bigint(20) unsigned NOT NULL,
  `po_item_id` bigint(20) unsigned DEFAULT NULL,
  `product_id` bigint(20) unsigned NOT NULL,
  `unit_id` bigint(20) unsigned DEFAULT NULL,
  `attribute_id` bigint(20) unsigned DEFAULT NULL,
  `quantity_ordered` decimal(15,4) NOT NULL DEFAULT 0.0000,
  `quantity_received` decimal(15,4) NOT NULL DEFAULT 0.0000,
  `no_of_pieces` int(10) unsigned NOT NULL DEFAULT 0,
  `unit_price` decimal(15,4) NOT NULL DEFAULT 0.0000,
  `discount` decimal(8,4) NOT NULL DEFAULT 0.0000,
  `tax` decimal(8,4) NOT NULL DEFAULT 0.0000,
  `line_total` decimal(15,4) NOT NULL DEFAULT 0.0000,
  `batch_no` varchar(100) DEFAULT NULL,
  `expiry_date` date DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `inv_goods_received_note_items_grn_id_index` (`grn_id`),
  KEY `inv_goods_received_note_items_po_item_id_index` (`po_item_id`),
  KEY `inv_goods_received_note_items_product_id_index` (`product_id`),
  KEY `inv_goods_received_note_items_attribute_id_foreign` (`attribute_id`),
  CONSTRAINT `inv_goods_received_note_items_attribute_id_foreign` FOREIGN KEY (`attribute_id`) REFERENCES `inv_attributes` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=588 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `inv_goods_received_note_items`
--

LOCK TABLES `inv_goods_received_note_items` WRITE;
/*!40000 ALTER TABLE `inv_goods_received_note_items` DISABLE KEYS */;
INSERT INTO `inv_goods_received_note_items` VALUES
(501,1,NULL,7,2,107,0.0000,22171.0000,169,181.4400,0.0000,0.0000,4022706.2400,NULL,NULL,'2026-07-11 06:55:17','2026-07-11 06:55:17'),
(502,1,NULL,6,1,108,0.0000,184.7000,7,1646.4000,0.0000,0.0000,304090.0800,NULL,NULL,'2026-07-11 06:55:18','2026-07-11 06:55:18'),
(503,1,NULL,6,1,109,0.0000,231.1000,8,1646.4000,0.0000,0.0000,380483.0400,NULL,NULL,'2026-07-11 06:55:18','2026-07-11 06:55:18'),
(504,1,NULL,6,1,110,0.0000,229.0000,8,1646.4000,0.0000,0.0000,377025.6000,NULL,NULL,'2026-07-11 06:55:18','2026-07-11 06:55:18'),
(505,1,NULL,6,1,111,0.0000,161.6000,6,1646.4000,0.0000,0.0000,266058.2400,NULL,NULL,'2026-07-11 06:55:18','2026-07-11 06:55:18'),
(506,1,NULL,6,1,139,0.0000,21.4000,1,1646.4000,0.0000,0.0000,35232.9600,NULL,NULL,'2026-07-11 06:55:18','2026-07-11 06:55:18'),
(507,1,NULL,6,1,113,0.0000,97.4000,4,1646.4000,0.0000,0.0000,160359.3600,NULL,NULL,'2026-07-11 06:55:18','2026-07-11 06:55:18'),
(508,1,NULL,6,1,99,0.0000,141.8000,5,1646.4000,0.0000,0.0000,233459.5200,NULL,NULL,'2026-07-11 06:55:18','2026-07-11 06:55:18'),
(509,1,NULL,6,1,115,0.0000,135.8000,5,1646.4000,0.0000,0.0000,223581.1200,NULL,NULL,'2026-07-11 06:55:18','2026-07-11 06:55:18'),
(510,1,NULL,6,1,116,0.0000,112.5000,4,1646.4000,0.0000,0.0000,185220.0000,NULL,NULL,'2026-07-11 06:55:18','2026-07-11 06:55:18'),
(511,1,NULL,6,1,117,0.0000,121.1000,4,1646.4000,0.0000,0.0000,199379.0400,NULL,NULL,'2026-07-11 06:55:18','2026-07-11 06:55:18'),
(512,1,NULL,6,1,118,0.0000,103.7000,4,1646.4000,0.0000,0.0000,170731.6800,NULL,NULL,'2026-07-11 06:55:18','2026-07-11 06:55:18'),
(513,1,NULL,6,1,119,0.0000,109.3000,4,1646.4000,0.0000,0.0000,179951.5200,NULL,NULL,'2026-07-11 06:55:18','2026-07-11 06:55:18'),
(514,1,NULL,6,1,120,0.0000,158.7000,6,1646.4000,0.0000,0.0000,261283.6800,NULL,NULL,'2026-07-11 06:55:18','2026-07-11 06:55:18'),
(515,1,NULL,6,1,121,0.0000,82.4000,3,1646.4000,0.0000,0.0000,135663.3600,NULL,NULL,'2026-07-11 06:55:18','2026-07-11 06:55:18'),
(516,1,NULL,6,1,122,0.0000,81.2000,3,1646.4000,0.0000,0.0000,133687.6800,NULL,NULL,'2026-07-11 06:55:18','2026-07-11 06:55:18'),
(517,1,NULL,6,1,123,0.0000,80.2000,3,1646.4000,0.0000,0.0000,132041.2800,NULL,NULL,'2026-07-11 06:55:18','2026-07-11 06:55:18'),
(518,1,NULL,6,1,124,0.0000,225.7000,8,1646.4000,0.0000,0.0000,371592.4800,NULL,NULL,'2026-07-11 06:55:18','2026-07-11 06:55:18'),
(519,1,NULL,6,1,125,0.0000,500.6000,19,1646.4000,0.0000,0.0000,824187.8400,NULL,NULL,'2026-07-11 06:55:18','2026-07-11 06:55:18'),
(520,1,NULL,6,1,126,0.0000,120.6000,4,1646.4000,0.0000,0.0000,198555.8400,NULL,NULL,'2026-07-11 06:55:19','2026-07-11 06:55:19'),
(521,1,NULL,6,1,127,0.0000,105.9000,4,1646.4000,0.0000,0.0000,174353.7600,NULL,NULL,'2026-07-11 06:55:19','2026-07-11 06:55:19'),
(522,1,NULL,6,1,141,0.0000,105.0000,4,1646.4000,0.0000,0.0000,172872.0000,NULL,NULL,'2026-07-11 06:55:19','2026-07-11 06:55:19'),
(523,1,NULL,6,1,128,0.0000,92.2000,4,1646.4000,0.0000,0.0000,151798.0800,NULL,NULL,'2026-07-11 06:55:19','2026-07-11 06:55:19'),
(524,1,NULL,6,1,129,0.0000,391.1000,13,1646.4000,0.0000,0.0000,643907.0400,NULL,NULL,'2026-07-11 06:55:19','2026-07-11 06:55:19'),
(525,1,NULL,6,1,130,0.0000,218.5000,8,1646.4000,0.0000,0.0000,359738.4000,NULL,NULL,'2026-07-11 06:55:19','2026-07-11 06:55:19'),
(526,1,NULL,6,1,131,0.0000,195.4000,8,1646.4000,0.0000,0.0000,321706.5600,NULL,NULL,'2026-07-11 06:55:19','2026-07-11 06:55:19'),
(527,1,NULL,6,1,132,0.0000,415.5000,15,1646.4000,0.0000,0.0000,684079.2000,NULL,NULL,'2026-07-11 06:55:19','2026-07-11 06:55:19'),
(528,1,NULL,6,1,133,0.0000,100.7000,5,1646.4000,0.0000,0.0000,165792.4800,NULL,NULL,'2026-07-11 06:55:19','2026-07-11 06:55:19'),
(529,1,NULL,6,1,134,0.0000,388.6000,13,1646.4000,0.0000,0.0000,639791.0400,NULL,NULL,'2026-07-11 06:55:19','2026-07-11 06:55:19'),
(530,1,NULL,6,1,135,0.0000,386.7000,13,1646.4000,0.0000,0.0000,636662.8800,NULL,NULL,'2026-07-11 06:55:19','2026-07-11 06:55:19'),
(531,1,NULL,6,1,136,0.0000,233.2000,8,1646.4000,0.0000,0.0000,383940.4800,NULL,NULL,'2026-07-11 06:55:19','2026-07-11 06:55:19'),
(532,1,NULL,6,1,137,0.0000,44.3000,2,1646.4000,0.0000,0.0000,72935.5200,NULL,NULL,'2026-07-11 06:55:19','2026-07-11 06:55:19'),
(533,1,NULL,6,1,142,0.0000,511.0000,21,1646.4000,0.0000,0.0000,841310.4000,NULL,NULL,'2026-07-11 06:55:19','2026-07-11 06:55:19'),
(534,1,NULL,10,1,89,0.0000,1283.9000,55,1041.6000,0.0000,0.0000,1337310.2400,NULL,NULL,'2026-07-11 06:55:19','2026-07-11 06:55:19'),
(535,1,NULL,10,1,150,0.0000,58.1000,3,1041.6000,0.0000,0.0000,60516.9600,NULL,NULL,'2026-07-11 06:55:19','2026-07-11 06:55:19'),
(536,1,NULL,10,1,171,0.0000,69.9000,3,1041.6000,0.0000,0.0000,72807.8400,NULL,NULL,'2026-07-11 06:55:19','2026-07-11 06:55:19'),
(537,1,NULL,10,1,163,0.0000,169.1000,7,1041.6000,0.0000,0.0000,176134.5600,NULL,NULL,'2026-07-11 06:55:19','2026-07-11 06:55:19'),
(538,1,NULL,10,1,190,0.0000,413.1000,18,1041.6000,0.0000,0.0000,430284.9600,NULL,NULL,'2026-07-11 06:55:20','2026-07-11 06:55:20'),
(539,1,NULL,10,1,196,0.0000,134.3000,6,1041.6000,0.0000,0.0000,139886.8800,NULL,NULL,'2026-07-11 06:55:20','2026-07-11 06:55:20'),
(540,1,NULL,10,1,154,0.0000,66.2000,3,1041.6000,0.0000,0.0000,68953.9200,NULL,NULL,'2026-07-11 06:55:20','2026-07-11 06:55:20'),
(541,1,NULL,10,1,197,0.0000,69.0000,3,1041.6000,0.0000,0.0000,71870.4000,NULL,NULL,'2026-07-11 06:55:20','2026-07-11 06:55:20'),
(542,1,NULL,10,1,153,0.0000,112.8000,5,1041.6000,0.0000,0.0000,117492.4800,NULL,NULL,'2026-07-11 06:55:20','2026-07-11 06:55:20'),
(543,1,NULL,10,1,164,0.0000,312.9000,13,1041.6000,0.0000,0.0000,325916.6400,NULL,NULL,'2026-07-11 06:55:20','2026-07-11 06:55:20'),
(544,1,NULL,10,1,176,0.0000,64.8000,3,1041.6000,0.0000,0.0000,67495.6800,NULL,NULL,'2026-07-11 06:55:20','2026-07-11 06:55:20'),
(545,1,NULL,10,1,178,0.0000,59.7000,3,1041.6000,0.0000,0.0000,62183.5200,NULL,NULL,'2026-07-11 06:55:20','2026-07-11 06:55:20'),
(546,1,NULL,10,1,179,0.0000,153.9000,7,1041.6000,0.0000,0.0000,160302.2400,NULL,NULL,'2026-07-11 06:55:20','2026-07-11 06:55:20'),
(547,1,NULL,10,1,191,0.0000,283.7000,12,1041.6000,0.0000,0.0000,295501.9200,NULL,NULL,'2026-07-11 06:55:20','2026-07-11 06:55:20'),
(548,1,NULL,10,1,174,0.0000,275.2000,12,1041.6000,0.0000,0.0000,286648.3200,NULL,NULL,'2026-07-11 06:55:20','2026-07-11 06:55:20'),
(549,1,NULL,10,1,172,0.0000,63.6000,3,1041.6000,0.0000,0.0000,66245.7600,NULL,NULL,'2026-07-11 06:55:21','2026-07-11 06:55:21'),
(550,1,NULL,10,1,193,0.0000,222.6000,10,1041.6000,0.0000,0.0000,231860.1600,NULL,NULL,'2026-07-11 06:55:21','2026-07-11 06:55:21'),
(551,1,NULL,10,1,198,0.0000,68.3000,3,1041.6000,0.0000,0.0000,71141.2800,NULL,NULL,'2026-07-11 06:55:21','2026-07-11 06:55:21'),
(552,1,NULL,10,1,151,0.0000,73.9000,3,1041.6000,0.0000,0.0000,76974.2400,NULL,NULL,'2026-07-11 06:55:21','2026-07-11 06:55:21'),
(553,1,NULL,10,1,171,0.0000,280.8000,11,1041.6000,0.0000,0.0000,292481.2800,NULL,NULL,'2026-07-11 06:55:21','2026-07-11 06:55:21'),
(554,1,NULL,10,1,157,0.0000,132.1000,6,1041.6000,0.0000,0.0000,137595.3600,NULL,NULL,'2026-07-11 06:55:21','2026-07-11 06:55:21'),
(555,1,NULL,10,1,161,0.0000,66.6000,3,1041.6000,0.0000,0.0000,69370.5600,NULL,NULL,'2026-07-11 06:55:21','2026-07-11 06:55:21'),
(556,1,NULL,10,1,192,0.0000,67.7000,3,1041.6000,0.0000,0.0000,70516.3200,NULL,NULL,'2026-07-11 06:55:21','2026-07-11 06:55:21'),
(557,1,NULL,10,1,155,0.0000,146.9000,7,1041.6000,0.0000,0.0000,153011.0400,NULL,NULL,'2026-07-11 06:55:21','2026-07-11 06:55:21'),
(558,1,NULL,10,1,195,0.0000,130.5000,6,1041.6000,0.0000,0.0000,135928.8000,NULL,NULL,'2026-07-11 06:55:21','2026-07-11 06:55:21'),
(559,1,NULL,10,1,158,0.0000,63.5000,3,1041.6000,0.0000,0.0000,66141.6000,NULL,NULL,'2026-07-11 06:55:21','2026-07-11 06:55:21'),
(560,1,NULL,10,1,167,0.0000,41.8000,2,1041.6000,0.0000,0.0000,43538.8800,NULL,NULL,'2026-07-11 06:55:21','2026-07-11 06:55:21'),
(561,1,NULL,10,1,169,0.0000,67.1000,3,1041.6000,0.0000,0.0000,69891.3600,NULL,NULL,'2026-07-11 06:55:21','2026-07-11 06:55:21'),
(562,1,NULL,10,1,199,0.0000,89.7000,4,1041.6000,0.0000,0.0000,93431.5200,NULL,NULL,'2026-07-11 06:55:21','2026-07-11 06:55:21'),
(563,1,NULL,10,1,162,0.0000,210.5000,9,1041.6000,0.0000,0.0000,219256.8000,NULL,NULL,'2026-07-11 06:55:21','2026-07-11 06:55:21'),
(564,1,NULL,10,1,102,0.0000,145.0000,6,1041.6000,0.0000,0.0000,151032.0000,NULL,NULL,'2026-07-11 06:55:21','2026-07-11 06:55:21'),
(565,1,NULL,10,1,194,0.0000,68.2000,3,1041.6000,0.0000,0.0000,71037.1200,NULL,NULL,'2026-07-11 06:55:21','2026-07-11 06:55:21'),
(566,1,NULL,10,1,168,0.0000,43.1000,2,1041.6000,0.0000,0.0000,44892.9600,NULL,NULL,'2026-07-11 06:55:21','2026-07-11 06:55:21'),
(567,1,NULL,10,1,160,0.0000,26.7000,1,1041.6000,0.0000,0.0000,27810.7200,NULL,NULL,'2026-07-11 06:55:21','2026-07-11 06:55:21'),
(568,1,NULL,10,1,166,0.0000,19.7000,1,1041.6000,0.0000,0.0000,20519.5200,NULL,NULL,'2026-07-11 06:55:21','2026-07-11 06:55:21'),
(569,1,NULL,10,1,170,0.0000,31.4000,1,1041.6000,0.0000,0.0000,32706.2400,NULL,NULL,'2026-07-11 06:55:21','2026-07-11 06:55:21'),
(570,1,NULL,10,1,130,0.0000,59.6000,3,1041.6000,0.0000,0.0000,62079.3600,NULL,NULL,'2026-07-11 06:55:21','2026-07-11 06:55:21'),
(571,1,NULL,10,1,175,0.0000,38.8000,2,1041.6000,0.0000,0.0000,40414.0800,NULL,NULL,'2026-07-11 06:55:21','2026-07-11 06:55:21'),
(572,1,NULL,10,1,173,0.0000,18.6000,1,1041.6000,0.0000,0.0000,19373.7600,NULL,NULL,'2026-07-11 06:55:21','2026-07-11 06:55:21'),
(573,1,NULL,10,1,177,0.0000,19.0000,1,1041.6000,0.0000,0.0000,19790.4000,NULL,NULL,'2026-07-11 06:55:21','2026-07-11 06:55:21'),
(574,1,NULL,8,1,206,0.0000,396.6000,16,1024.8000,0.0000,0.0000,406435.6800,NULL,NULL,'2026-07-11 06:55:21','2026-07-11 06:55:21'),
(575,1,NULL,8,1,202,0.0000,259.5000,11,1024.8000,0.0000,0.0000,265935.6000,NULL,NULL,'2026-07-11 06:55:21','2026-07-11 06:55:21'),
(576,1,NULL,8,1,205,0.0000,382.1000,16,1024.8000,0.0000,0.0000,391576.0800,NULL,NULL,'2026-07-11 06:55:21','2026-07-11 06:55:21'),
(577,1,NULL,8,1,213,0.0000,216.8000,9,1024.8000,0.0000,0.0000,222176.6400,NULL,NULL,'2026-07-11 06:55:21','2026-07-11 06:55:21'),
(578,1,NULL,8,1,201,0.0000,188.3000,8,1024.8000,0.0000,0.0000,192969.8400,NULL,NULL,'2026-07-11 06:55:21','2026-07-11 06:55:21'),
(579,1,NULL,8,1,208,0.0000,308.9000,13,1024.8000,0.0000,0.0000,316560.7200,NULL,NULL,'2026-07-11 06:55:21','2026-07-11 06:55:21'),
(580,1,NULL,8,1,209,0.0000,358.6000,15,1024.8000,0.0000,0.0000,367493.2800,NULL,NULL,'2026-07-11 06:55:21','2026-07-11 06:55:21'),
(581,1,NULL,8,1,210,0.0000,310.2000,13,1024.8000,0.0000,0.0000,317892.9600,NULL,NULL,'2026-07-11 06:55:21','2026-07-11 06:55:21'),
(582,1,NULL,8,1,211,0.0000,247.2000,10,1024.8000,0.0000,0.0000,253330.5600,NULL,NULL,'2026-07-11 06:55:21','2026-07-11 06:55:21'),
(583,1,NULL,8,1,204,0.0000,444.0000,18,1024.8000,0.0000,0.0000,455011.2000,NULL,NULL,'2026-07-11 06:55:21','2026-07-11 06:55:21'),
(584,1,NULL,8,1,203,0.0000,168.8000,7,1024.8000,0.0000,0.0000,172986.2400,NULL,NULL,'2026-07-11 06:55:21','2026-07-11 06:55:21'),
(585,1,NULL,8,1,212,0.0000,69.6000,3,1024.8000,0.0000,0.0000,71326.0800,NULL,NULL,'2026-07-11 06:55:21','2026-07-11 06:55:21'),
(586,1,NULL,8,1,206,0.0000,41.2000,2,1024.8000,0.0000,0.0000,42221.7600,NULL,NULL,'2026-07-11 06:55:21','2026-07-11 06:55:21'),
(587,1,NULL,8,1,213,0.0000,24.2000,1,1024.8000,0.0000,0.0000,24800.1600,NULL,NULL,'2026-07-11 06:55:21','2026-07-11 06:55:21');
/*!40000 ALTER TABLE `inv_goods_received_note_items` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `inv_goods_received_notes`
--

DROP TABLE IF EXISTS `inv_goods_received_notes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `inv_goods_received_notes` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `grn_no` varchar(30) NOT NULL,
  `reference_no` varchar(100) DEFAULT NULL,
  `shipping_code` varchar(100) DEFAULT NULL,
  `po_id` bigint(20) unsigned DEFAULT NULL,
  `supplier_id` bigint(20) unsigned DEFAULT NULL,
  `grn_date` date NOT NULL,
  `transaction_date` date DEFAULT NULL,
  `store_id` bigint(20) unsigned DEFAULT NULL,
  `location_id` bigint(20) unsigned DEFAULT NULL,
  `status` varchar(20) NOT NULL DEFAULT 'draft',
  `total_amount` decimal(15,4) NOT NULL DEFAULT 0.0000,
  `remarks` text DEFAULT NULL,
  `payment_terms` varchar(100) DEFAULT NULL,
  `attachments` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`attachments`)),
  `received_by` bigint(20) unsigned DEFAULT NULL,
  `confirmed_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `inv_goods_received_notes_grn_no_unique` (`grn_no`),
  KEY `inv_goods_received_notes_po_id_index` (`po_id`),
  KEY `inv_goods_received_notes_supplier_id_index` (`supplier_id`),
  KEY `inv_goods_received_notes_store_id_index` (`store_id`),
  KEY `inv_goods_received_notes_status_index` (`status`),
  KEY `inv_goods_received_notes_grn_date_index` (`grn_date`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `inv_goods_received_notes`
--

LOCK TABLES `inv_goods_received_notes` WRITE;
/*!40000 ALTER TABLE `inv_goods_received_notes` DISABLE KEYS */;
INSERT INTO `inv_goods_received_notes` VALUES
(1,'GRN-2026-0001',NULL,'HES260401',NULL,5,'2026-07-10','2026-07-10',1,1,'draft',23505242.8800,NULL,NULL,NULL,1,'2026-07-10 07:22:00','2026-07-10 06:03:42','2026-07-11 06:47:46',NULL);
/*!40000 ALTER TABLE `inv_goods_received_notes` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `inv_grn_attachments`
--

DROP TABLE IF EXISTS `inv_grn_attachments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `inv_grn_attachments` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `grn_id` bigint(20) unsigned NOT NULL,
  `file_name` varchar(255) NOT NULL,
  `file_path` varchar(255) NOT NULL,
  `file_size` bigint(20) unsigned DEFAULT NULL,
  `mime_type` varchar(100) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `inv_grn_attachments_grn_id_index` (`grn_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `inv_grn_attachments`
--

LOCK TABLES `inv_grn_attachments` WRITE;
/*!40000 ALTER TABLE `inv_grn_attachments` DISABLE KEYS */;
/*!40000 ALTER TABLE `inv_grn_attachments` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `inv_grn_item_batches`
--

DROP TABLE IF EXISTS `inv_grn_item_batches`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `inv_grn_item_batches` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `grn_item_id` bigint(20) unsigned NOT NULL,
  `batch_id` bigint(20) unsigned NOT NULL,
  `quantity` decimal(15,4) NOT NULL,
  `unit_cost` decimal(15,4) NOT NULL DEFAULT 0.0000,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `inv_grn_item_batches_grn_item_id_index` (`grn_item_id`),
  KEY `inv_grn_item_batches_batch_id_index` (`batch_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `inv_grn_item_batches`
--

LOCK TABLES `inv_grn_item_batches` WRITE;
/*!40000 ALTER TABLE `inv_grn_item_batches` DISABLE KEYS */;
/*!40000 ALTER TABLE `inv_grn_item_batches` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `inv_grn_item_pieces`
--

DROP TABLE IF EXISTS `inv_grn_item_pieces`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `inv_grn_item_pieces` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `grn_item_id` bigint(20) unsigned NOT NULL,
  `grn_id` bigint(20) unsigned NOT NULL,
  `product_id` bigint(20) unsigned NOT NULL,
  `batch_id` bigint(20) unsigned DEFAULT NULL,
  `stock_transaction_id` bigint(20) unsigned DEFAULT NULL,
  `store_id` bigint(20) unsigned DEFAULT NULL,
  `location_id` bigint(20) unsigned DEFAULT NULL,
  `piece_no` int(10) unsigned NOT NULL,
  `weight` decimal(15,4) DEFAULT NULL,
  `roll_no` varchar(100) DEFAULT NULL,
  `piece_code` varchar(40) DEFAULT NULL,
  `status` varchar(20) NOT NULL DEFAULT 'in_stock',
  `printed_at` timestamp NULL DEFAULT NULL,
  `created_by` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `inv_grn_item_pieces_piece_code_unique` (`piece_code`),
  KEY `inv_grn_item_pieces_grn_item_id_index` (`grn_item_id`),
  KEY `inv_grn_item_pieces_grn_id_index` (`grn_id`),
  KEY `inv_grn_item_pieces_product_id_index` (`product_id`),
  KEY `inv_grn_item_pieces_batch_id_index` (`batch_id`),
  KEY `inv_grn_item_pieces_status_index` (`status`),
  KEY `inv_grn_item_pieces_stock_transaction_id_index` (`stock_transaction_id`)
) ENGINE=InnoDB AUTO_INCREMENT=7623 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `inv_grn_item_pieces`
--

LOCK TABLES `inv_grn_item_pieces` WRITE;
/*!40000 ALTER TABLE `inv_grn_item_pieces` DISABLE KEYS */;
INSERT INTO `inv_grn_item_pieces` VALUES
(6838,501,1,7,NULL,NULL,1,1,1,139.0000,'1',NULL,'draft',NULL,1,'2026-07-11 06:55:17','2026-07-11 06:55:17'),
(6839,501,1,7,NULL,NULL,1,1,2,192.0000,'2',NULL,'draft',NULL,1,'2026-07-11 06:55:17','2026-07-11 06:55:17'),
(6840,501,1,7,NULL,NULL,1,1,3,125.0000,'3',NULL,'draft',NULL,1,'2026-07-11 06:55:17','2026-07-11 06:55:17'),
(6841,501,1,7,NULL,NULL,1,1,4,165.0000,'4',NULL,'draft',NULL,1,'2026-07-11 06:55:17','2026-07-11 06:55:17'),
(6842,501,1,7,NULL,NULL,1,1,5,134.0000,'5',NULL,'draft',NULL,1,'2026-07-11 06:55:17','2026-07-11 06:55:17'),
(6843,501,1,7,NULL,NULL,1,1,6,105.0000,'6',NULL,'draft',NULL,1,'2026-07-11 06:55:17','2026-07-11 06:55:17'),
(6844,501,1,7,NULL,NULL,1,1,7,112.0000,'7',NULL,'draft',NULL,1,'2026-07-11 06:55:17','2026-07-11 06:55:17'),
(6845,501,1,7,NULL,NULL,1,1,8,84.0000,'8',NULL,'draft',NULL,1,'2026-07-11 06:55:17','2026-07-11 06:55:17'),
(6846,501,1,7,NULL,NULL,1,1,9,105.0000,'9',NULL,'draft',NULL,1,'2026-07-11 06:55:17','2026-07-11 06:55:17'),
(6847,501,1,7,NULL,NULL,1,1,10,147.0000,'10',NULL,'draft',NULL,1,'2026-07-11 06:55:17','2026-07-11 06:55:17'),
(6848,501,1,7,NULL,NULL,1,1,11,145.0000,'11',NULL,'draft',NULL,1,'2026-07-11 06:55:17','2026-07-11 06:55:17'),
(6849,501,1,7,NULL,NULL,1,1,12,128.0000,'12',NULL,'draft',NULL,1,'2026-07-11 06:55:17','2026-07-11 06:55:17'),
(6850,501,1,7,NULL,NULL,1,1,13,140.0000,'13',NULL,'draft',NULL,1,'2026-07-11 06:55:17','2026-07-11 06:55:17'),
(6851,501,1,7,NULL,NULL,1,1,14,137.0000,'14',NULL,'draft',NULL,1,'2026-07-11 06:55:17','2026-07-11 06:55:17'),
(6852,501,1,7,NULL,NULL,1,1,15,102.0000,'15',NULL,'draft',NULL,1,'2026-07-11 06:55:17','2026-07-11 06:55:17'),
(6853,501,1,7,NULL,NULL,1,1,16,108.0000,'16',NULL,'draft',NULL,1,'2026-07-11 06:55:17','2026-07-11 06:55:17'),
(6854,501,1,7,NULL,NULL,1,1,17,138.0000,'17',NULL,'draft',NULL,1,'2026-07-11 06:55:17','2026-07-11 06:55:17'),
(6855,501,1,7,NULL,NULL,1,1,18,151.0000,'18',NULL,'draft',NULL,1,'2026-07-11 06:55:17','2026-07-11 06:55:17'),
(6856,501,1,7,NULL,NULL,1,1,19,100.0000,'19',NULL,'draft',NULL,1,'2026-07-11 06:55:17','2026-07-11 06:55:17'),
(6857,501,1,7,NULL,NULL,1,1,20,96.0000,'20',NULL,'draft',NULL,1,'2026-07-11 06:55:17','2026-07-11 06:55:17'),
(6858,501,1,7,NULL,NULL,1,1,21,143.0000,'21',NULL,'draft',NULL,1,'2026-07-11 06:55:17','2026-07-11 06:55:17'),
(6859,501,1,7,NULL,NULL,1,1,22,125.0000,'22',NULL,'draft',NULL,1,'2026-07-11 06:55:17','2026-07-11 06:55:17'),
(6860,501,1,7,NULL,NULL,1,1,23,136.0000,'23',NULL,'draft',NULL,1,'2026-07-11 06:55:17','2026-07-11 06:55:17'),
(6861,501,1,7,NULL,NULL,1,1,24,100.0000,'24',NULL,'draft',NULL,1,'2026-07-11 06:55:17','2026-07-11 06:55:17'),
(6862,501,1,7,NULL,NULL,1,1,25,103.0000,'25',NULL,'draft',NULL,1,'2026-07-11 06:55:17','2026-07-11 06:55:17'),
(6863,501,1,7,NULL,NULL,1,1,26,104.0000,'26',NULL,'draft',NULL,1,'2026-07-11 06:55:17','2026-07-11 06:55:17'),
(6864,501,1,7,NULL,NULL,1,1,27,107.0000,'27',NULL,'draft',NULL,1,'2026-07-11 06:55:17','2026-07-11 06:55:17'),
(6865,501,1,7,NULL,NULL,1,1,28,170.0000,'28',NULL,'draft',NULL,1,'2026-07-11 06:55:17','2026-07-11 06:55:17'),
(6866,501,1,7,NULL,NULL,1,1,29,141.0000,'29',NULL,'draft',NULL,1,'2026-07-11 06:55:17','2026-07-11 06:55:17'),
(6867,501,1,7,NULL,NULL,1,1,30,122.0000,'30',NULL,'draft',NULL,1,'2026-07-11 06:55:17','2026-07-11 06:55:17'),
(6868,501,1,7,NULL,NULL,1,1,31,128.0000,'31',NULL,'draft',NULL,1,'2026-07-11 06:55:17','2026-07-11 06:55:17'),
(6869,501,1,7,NULL,NULL,1,1,32,120.0000,'32',NULL,'draft',NULL,1,'2026-07-11 06:55:17','2026-07-11 06:55:17'),
(6870,501,1,7,NULL,NULL,1,1,33,133.0000,'33',NULL,'draft',NULL,1,'2026-07-11 06:55:17','2026-07-11 06:55:17'),
(6871,501,1,7,NULL,NULL,1,1,34,107.0000,'34',NULL,'draft',NULL,1,'2026-07-11 06:55:17','2026-07-11 06:55:17'),
(6872,501,1,7,NULL,NULL,1,1,35,98.0000,'35',NULL,'draft',NULL,1,'2026-07-11 06:55:17','2026-07-11 06:55:17'),
(6873,501,1,7,NULL,NULL,1,1,36,135.0000,'36',NULL,'draft',NULL,1,'2026-07-11 06:55:17','2026-07-11 06:55:17'),
(6874,501,1,7,NULL,NULL,1,1,37,161.0000,'37',NULL,'draft',NULL,1,'2026-07-11 06:55:17','2026-07-11 06:55:17'),
(6875,501,1,7,NULL,NULL,1,1,38,137.0000,'38',NULL,'draft',NULL,1,'2026-07-11 06:55:17','2026-07-11 06:55:17'),
(6876,501,1,7,NULL,NULL,1,1,39,144.0000,'39',NULL,'draft',NULL,1,'2026-07-11 06:55:17','2026-07-11 06:55:17'),
(6877,501,1,7,NULL,NULL,1,1,40,133.0000,'40',NULL,'draft',NULL,1,'2026-07-11 06:55:17','2026-07-11 06:55:17'),
(6878,501,1,7,NULL,NULL,1,1,41,133.0000,'41',NULL,'draft',NULL,1,'2026-07-11 06:55:17','2026-07-11 06:55:17'),
(6879,501,1,7,NULL,NULL,1,1,42,121.0000,'42',NULL,'draft',NULL,1,'2026-07-11 06:55:17','2026-07-11 06:55:17'),
(6880,501,1,7,NULL,NULL,1,1,43,130.0000,'43',NULL,'draft',NULL,1,'2026-07-11 06:55:17','2026-07-11 06:55:17'),
(6881,501,1,7,NULL,NULL,1,1,44,134.0000,'44',NULL,'draft',NULL,1,'2026-07-11 06:55:17','2026-07-11 06:55:17'),
(6882,501,1,7,NULL,NULL,1,1,45,152.0000,'45',NULL,'draft',NULL,1,'2026-07-11 06:55:17','2026-07-11 06:55:17'),
(6883,501,1,7,NULL,NULL,1,1,46,152.0000,'46',NULL,'draft',NULL,1,'2026-07-11 06:55:18','2026-07-11 06:55:18'),
(6884,501,1,7,NULL,NULL,1,1,47,120.0000,'47',NULL,'draft',NULL,1,'2026-07-11 06:55:18','2026-07-11 06:55:18'),
(6885,501,1,7,NULL,NULL,1,1,48,123.0000,'48',NULL,'draft',NULL,1,'2026-07-11 06:55:18','2026-07-11 06:55:18'),
(6886,501,1,7,NULL,NULL,1,1,49,129.0000,'49',NULL,'draft',NULL,1,'2026-07-11 06:55:18','2026-07-11 06:55:18'),
(6887,501,1,7,NULL,NULL,1,1,50,135.0000,'50',NULL,'draft',NULL,1,'2026-07-11 06:55:18','2026-07-11 06:55:18'),
(6888,501,1,7,NULL,NULL,1,1,51,128.0000,'51',NULL,'draft',NULL,1,'2026-07-11 06:55:18','2026-07-11 06:55:18'),
(6889,501,1,7,NULL,NULL,1,1,52,143.0000,'52',NULL,'draft',NULL,1,'2026-07-11 06:55:18','2026-07-11 06:55:18'),
(6890,501,1,7,NULL,NULL,1,1,53,159.0000,'53',NULL,'draft',NULL,1,'2026-07-11 06:55:18','2026-07-11 06:55:18'),
(6891,501,1,7,NULL,NULL,1,1,54,111.0000,'54',NULL,'draft',NULL,1,'2026-07-11 06:55:18','2026-07-11 06:55:18'),
(6892,501,1,7,NULL,NULL,1,1,55,145.0000,'55',NULL,'draft',NULL,1,'2026-07-11 06:55:18','2026-07-11 06:55:18'),
(6893,501,1,7,NULL,NULL,1,1,56,136.0000,'56',NULL,'draft',NULL,1,'2026-07-11 06:55:18','2026-07-11 06:55:18'),
(6894,501,1,7,NULL,NULL,1,1,57,115.0000,'57',NULL,'draft',NULL,1,'2026-07-11 06:55:18','2026-07-11 06:55:18'),
(6895,501,1,7,NULL,NULL,1,1,58,116.0000,'58',NULL,'draft',NULL,1,'2026-07-11 06:55:18','2026-07-11 06:55:18'),
(6896,501,1,7,NULL,NULL,1,1,59,127.0000,'59',NULL,'draft',NULL,1,'2026-07-11 06:55:18','2026-07-11 06:55:18'),
(6897,501,1,7,NULL,NULL,1,1,60,129.0000,'60',NULL,'draft',NULL,1,'2026-07-11 06:55:18','2026-07-11 06:55:18'),
(6898,501,1,7,NULL,NULL,1,1,61,135.0000,'61',NULL,'draft',NULL,1,'2026-07-11 06:55:18','2026-07-11 06:55:18'),
(6899,501,1,7,NULL,NULL,1,1,62,130.0000,'62',NULL,'draft',NULL,1,'2026-07-11 06:55:18','2026-07-11 06:55:18'),
(6900,501,1,7,NULL,NULL,1,1,63,114.0000,'63',NULL,'draft',NULL,1,'2026-07-11 06:55:18','2026-07-11 06:55:18'),
(6901,501,1,7,NULL,NULL,1,1,64,129.0000,'64',NULL,'draft',NULL,1,'2026-07-11 06:55:18','2026-07-11 06:55:18'),
(6902,501,1,7,NULL,NULL,1,1,65,133.0000,'65',NULL,'draft',NULL,1,'2026-07-11 06:55:18','2026-07-11 06:55:18'),
(6903,501,1,7,NULL,NULL,1,1,66,133.0000,'66',NULL,'draft',NULL,1,'2026-07-11 06:55:18','2026-07-11 06:55:18'),
(6904,501,1,7,NULL,NULL,1,1,67,136.0000,'67',NULL,'draft',NULL,1,'2026-07-11 06:55:18','2026-07-11 06:55:18'),
(6905,501,1,7,NULL,NULL,1,1,68,140.0000,'68',NULL,'draft',NULL,1,'2026-07-11 06:55:18','2026-07-11 06:55:18'),
(6906,501,1,7,NULL,NULL,1,1,69,123.0000,'69',NULL,'draft',NULL,1,'2026-07-11 06:55:18','2026-07-11 06:55:18'),
(6907,501,1,7,NULL,NULL,1,1,70,141.0000,'70',NULL,'draft',NULL,1,'2026-07-11 06:55:18','2026-07-11 06:55:18'),
(6908,501,1,7,NULL,NULL,1,1,71,142.0000,'71',NULL,'draft',NULL,1,'2026-07-11 06:55:18','2026-07-11 06:55:18'),
(6909,501,1,7,NULL,NULL,1,1,72,131.0000,'72',NULL,'draft',NULL,1,'2026-07-11 06:55:18','2026-07-11 06:55:18'),
(6910,501,1,7,NULL,NULL,1,1,73,134.0000,'73',NULL,'draft',NULL,1,'2026-07-11 06:55:18','2026-07-11 06:55:18'),
(6911,501,1,7,NULL,NULL,1,1,74,144.0000,'74',NULL,'draft',NULL,1,'2026-07-11 06:55:18','2026-07-11 06:55:18'),
(6912,501,1,7,NULL,NULL,1,1,75,131.0000,'75',NULL,'draft',NULL,1,'2026-07-11 06:55:18','2026-07-11 06:55:18'),
(6913,501,1,7,NULL,NULL,1,1,76,137.0000,'76',NULL,'draft',NULL,1,'2026-07-11 06:55:18','2026-07-11 06:55:18'),
(6914,501,1,7,NULL,NULL,1,1,77,141.0000,'77',NULL,'draft',NULL,1,'2026-07-11 06:55:18','2026-07-11 06:55:18'),
(6915,501,1,7,NULL,NULL,1,1,78,108.0000,'78',NULL,'draft',NULL,1,'2026-07-11 06:55:18','2026-07-11 06:55:18'),
(6916,501,1,7,NULL,NULL,1,1,79,142.0000,'79',NULL,'draft',NULL,1,'2026-07-11 06:55:18','2026-07-11 06:55:18'),
(6917,501,1,7,NULL,NULL,1,1,80,146.0000,'80',NULL,'draft',NULL,1,'2026-07-11 06:55:18','2026-07-11 06:55:18'),
(6918,501,1,7,NULL,NULL,1,1,81,145.0000,'81',NULL,'draft',NULL,1,'2026-07-11 06:55:18','2026-07-11 06:55:18'),
(6919,501,1,7,NULL,NULL,1,1,82,144.0000,'82',NULL,'draft',NULL,1,'2026-07-11 06:55:18','2026-07-11 06:55:18'),
(6920,501,1,7,NULL,NULL,1,1,83,132.0000,'83',NULL,'draft',NULL,1,'2026-07-11 06:55:18','2026-07-11 06:55:18'),
(6921,501,1,7,NULL,NULL,1,1,84,134.0000,'84',NULL,'draft',NULL,1,'2026-07-11 06:55:18','2026-07-11 06:55:18'),
(6922,501,1,7,NULL,NULL,1,1,85,133.0000,'85',NULL,'draft',NULL,1,'2026-07-11 06:55:18','2026-07-11 06:55:18'),
(6923,501,1,7,NULL,NULL,1,1,86,121.0000,'86',NULL,'draft',NULL,1,'2026-07-11 06:55:18','2026-07-11 06:55:18'),
(6924,501,1,7,NULL,NULL,1,1,87,111.0000,'87',NULL,'draft',NULL,1,'2026-07-11 06:55:18','2026-07-11 06:55:18'),
(6925,501,1,7,NULL,NULL,1,1,88,118.0000,'88',NULL,'draft',NULL,1,'2026-07-11 06:55:18','2026-07-11 06:55:18'),
(6926,501,1,7,NULL,NULL,1,1,89,129.0000,'89',NULL,'draft',NULL,1,'2026-07-11 06:55:18','2026-07-11 06:55:18'),
(6927,501,1,7,NULL,NULL,1,1,90,126.0000,'90',NULL,'draft',NULL,1,'2026-07-11 06:55:18','2026-07-11 06:55:18'),
(6928,501,1,7,NULL,NULL,1,1,91,167.0000,'91',NULL,'draft',NULL,1,'2026-07-11 06:55:18','2026-07-11 06:55:18'),
(6929,501,1,7,NULL,NULL,1,1,92,138.0000,'92',NULL,'draft',NULL,1,'2026-07-11 06:55:18','2026-07-11 06:55:18'),
(6930,501,1,7,NULL,NULL,1,1,93,131.0000,'93',NULL,'draft',NULL,1,'2026-07-11 06:55:18','2026-07-11 06:55:18'),
(6931,501,1,7,NULL,NULL,1,1,94,126.0000,'94',NULL,'draft',NULL,1,'2026-07-11 06:55:18','2026-07-11 06:55:18'),
(6932,501,1,7,NULL,NULL,1,1,95,137.0000,'95',NULL,'draft',NULL,1,'2026-07-11 06:55:18','2026-07-11 06:55:18'),
(6933,501,1,7,NULL,NULL,1,1,96,131.0000,'96',NULL,'draft',NULL,1,'2026-07-11 06:55:18','2026-07-11 06:55:18'),
(6934,501,1,7,NULL,NULL,1,1,97,134.0000,'97',NULL,'draft',NULL,1,'2026-07-11 06:55:18','2026-07-11 06:55:18'),
(6935,501,1,7,NULL,NULL,1,1,98,130.0000,'98',NULL,'draft',NULL,1,'2026-07-11 06:55:18','2026-07-11 06:55:18'),
(6936,501,1,7,NULL,NULL,1,1,99,134.0000,'99',NULL,'draft',NULL,1,'2026-07-11 06:55:18','2026-07-11 06:55:18'),
(6937,501,1,7,NULL,NULL,1,1,100,150.0000,'100',NULL,'draft',NULL,1,'2026-07-11 06:55:18','2026-07-11 06:55:18'),
(6938,501,1,7,NULL,NULL,1,1,101,110.0000,'101',NULL,'draft',NULL,1,'2026-07-11 06:55:18','2026-07-11 06:55:18'),
(6939,501,1,7,NULL,NULL,1,1,102,140.0000,'102',NULL,'draft',NULL,1,'2026-07-11 06:55:18','2026-07-11 06:55:18'),
(6940,501,1,7,NULL,NULL,1,1,103,126.0000,'103',NULL,'draft',NULL,1,'2026-07-11 06:55:18','2026-07-11 06:55:18'),
(6941,501,1,7,NULL,NULL,1,1,104,146.0000,'104',NULL,'draft',NULL,1,'2026-07-11 06:55:18','2026-07-11 06:55:18'),
(6942,501,1,7,NULL,NULL,1,1,105,137.0000,'105',NULL,'draft',NULL,1,'2026-07-11 06:55:18','2026-07-11 06:55:18'),
(6943,501,1,7,NULL,NULL,1,1,106,134.0000,'106',NULL,'draft',NULL,1,'2026-07-11 06:55:18','2026-07-11 06:55:18'),
(6944,501,1,7,NULL,NULL,1,1,107,135.0000,'107',NULL,'draft',NULL,1,'2026-07-11 06:55:18','2026-07-11 06:55:18'),
(6945,501,1,7,NULL,NULL,1,1,108,121.0000,'108',NULL,'draft',NULL,1,'2026-07-11 06:55:18','2026-07-11 06:55:18'),
(6946,501,1,7,NULL,NULL,1,1,109,137.0000,'109',NULL,'draft',NULL,1,'2026-07-11 06:55:18','2026-07-11 06:55:18'),
(6947,501,1,7,NULL,NULL,1,1,110,161.0000,'110',NULL,'draft',NULL,1,'2026-07-11 06:55:18','2026-07-11 06:55:18'),
(6948,501,1,7,NULL,NULL,1,1,111,138.0000,'111',NULL,'draft',NULL,1,'2026-07-11 06:55:18','2026-07-11 06:55:18'),
(6949,501,1,7,NULL,NULL,1,1,112,136.0000,'112',NULL,'draft',NULL,1,'2026-07-11 06:55:18','2026-07-11 06:55:18'),
(6950,501,1,7,NULL,NULL,1,1,113,167.0000,'113',NULL,'draft',NULL,1,'2026-07-11 06:55:18','2026-07-11 06:55:18'),
(6951,501,1,7,NULL,NULL,1,1,114,144.0000,'114',NULL,'draft',NULL,1,'2026-07-11 06:55:18','2026-07-11 06:55:18'),
(6952,501,1,7,NULL,NULL,1,1,115,133.0000,'115',NULL,'draft',NULL,1,'2026-07-11 06:55:18','2026-07-11 06:55:18'),
(6953,501,1,7,NULL,NULL,1,1,116,105.0000,'116',NULL,'draft',NULL,1,'2026-07-11 06:55:18','2026-07-11 06:55:18'),
(6954,501,1,7,NULL,NULL,1,1,117,108.0000,'117',NULL,'draft',NULL,1,'2026-07-11 06:55:18','2026-07-11 06:55:18'),
(6955,501,1,7,NULL,NULL,1,1,118,138.0000,'118',NULL,'draft',NULL,1,'2026-07-11 06:55:18','2026-07-11 06:55:18'),
(6956,501,1,7,NULL,NULL,1,1,119,133.0000,'119',NULL,'draft',NULL,1,'2026-07-11 06:55:18','2026-07-11 06:55:18'),
(6957,501,1,7,NULL,NULL,1,1,120,147.0000,'120',NULL,'draft',NULL,1,'2026-07-11 06:55:18','2026-07-11 06:55:18'),
(6958,501,1,7,NULL,NULL,1,1,121,118.0000,'121',NULL,'draft',NULL,1,'2026-07-11 06:55:18','2026-07-11 06:55:18'),
(6959,501,1,7,NULL,NULL,1,1,122,128.0000,'122',NULL,'draft',NULL,1,'2026-07-11 06:55:18','2026-07-11 06:55:18'),
(6960,501,1,7,NULL,NULL,1,1,123,132.0000,'123',NULL,'draft',NULL,1,'2026-07-11 06:55:18','2026-07-11 06:55:18'),
(6961,501,1,7,NULL,NULL,1,1,124,149.0000,'124',NULL,'draft',NULL,1,'2026-07-11 06:55:18','2026-07-11 06:55:18'),
(6962,501,1,7,NULL,NULL,1,1,125,135.0000,'125',NULL,'draft',NULL,1,'2026-07-11 06:55:18','2026-07-11 06:55:18'),
(6963,501,1,7,NULL,NULL,1,1,126,135.0000,'126',NULL,'draft',NULL,1,'2026-07-11 06:55:18','2026-07-11 06:55:18'),
(6964,501,1,7,NULL,NULL,1,1,127,119.0000,'127',NULL,'draft',NULL,1,'2026-07-11 06:55:18','2026-07-11 06:55:18'),
(6965,501,1,7,NULL,NULL,1,1,128,160.0000,'128',NULL,'draft',NULL,1,'2026-07-11 06:55:18','2026-07-11 06:55:18'),
(6966,501,1,7,NULL,NULL,1,1,129,136.0000,'129',NULL,'draft',NULL,1,'2026-07-11 06:55:18','2026-07-11 06:55:18'),
(6967,501,1,7,NULL,NULL,1,1,130,110.0000,'130',NULL,'draft',NULL,1,'2026-07-11 06:55:18','2026-07-11 06:55:18'),
(6968,501,1,7,NULL,NULL,1,1,131,135.0000,'131',NULL,'draft',NULL,1,'2026-07-11 06:55:18','2026-07-11 06:55:18'),
(6969,501,1,7,NULL,NULL,1,1,132,142.0000,'132',NULL,'draft',NULL,1,'2026-07-11 06:55:18','2026-07-11 06:55:18'),
(6970,501,1,7,NULL,NULL,1,1,133,163.0000,'133',NULL,'draft',NULL,1,'2026-07-11 06:55:18','2026-07-11 06:55:18'),
(6971,501,1,7,NULL,NULL,1,1,134,136.0000,'134',NULL,'draft',NULL,1,'2026-07-11 06:55:18','2026-07-11 06:55:18'),
(6972,501,1,7,NULL,NULL,1,1,135,130.0000,'135',NULL,'draft',NULL,1,'2026-07-11 06:55:18','2026-07-11 06:55:18'),
(6973,501,1,7,NULL,NULL,1,1,136,133.0000,'136',NULL,'draft',NULL,1,'2026-07-11 06:55:18','2026-07-11 06:55:18'),
(6974,501,1,7,NULL,NULL,1,1,137,123.0000,'137',NULL,'draft',NULL,1,'2026-07-11 06:55:18','2026-07-11 06:55:18'),
(6975,501,1,7,NULL,NULL,1,1,138,164.0000,'138',NULL,'draft',NULL,1,'2026-07-11 06:55:18','2026-07-11 06:55:18'),
(6976,501,1,7,NULL,NULL,1,1,139,133.0000,'139',NULL,'draft',NULL,1,'2026-07-11 06:55:18','2026-07-11 06:55:18'),
(6977,501,1,7,NULL,NULL,1,1,140,130.0000,'140',NULL,'draft',NULL,1,'2026-07-11 06:55:18','2026-07-11 06:55:18'),
(6978,501,1,7,NULL,NULL,1,1,141,136.0000,'141',NULL,'draft',NULL,1,'2026-07-11 06:55:18','2026-07-11 06:55:18'),
(6979,501,1,7,NULL,NULL,1,1,142,134.0000,'142',NULL,'draft',NULL,1,'2026-07-11 06:55:18','2026-07-11 06:55:18'),
(6980,501,1,7,NULL,NULL,1,1,143,143.0000,'143',NULL,'draft',NULL,1,'2026-07-11 06:55:18','2026-07-11 06:55:18'),
(6981,501,1,7,NULL,NULL,1,1,144,110.0000,'144',NULL,'draft',NULL,1,'2026-07-11 06:55:18','2026-07-11 06:55:18'),
(6982,501,1,7,NULL,NULL,1,1,145,93.0000,'145',NULL,'draft',NULL,1,'2026-07-11 06:55:18','2026-07-11 06:55:18'),
(6983,501,1,7,NULL,NULL,1,1,146,135.0000,'146',NULL,'draft',NULL,1,'2026-07-11 06:55:18','2026-07-11 06:55:18'),
(6984,501,1,7,NULL,NULL,1,1,147,135.0000,'147',NULL,'draft',NULL,1,'2026-07-11 06:55:18','2026-07-11 06:55:18'),
(6985,501,1,7,NULL,NULL,1,1,148,112.0000,'148',NULL,'draft',NULL,1,'2026-07-11 06:55:18','2026-07-11 06:55:18'),
(6986,501,1,7,NULL,NULL,1,1,149,132.0000,'149',NULL,'draft',NULL,1,'2026-07-11 06:55:18','2026-07-11 06:55:18'),
(6987,501,1,7,NULL,NULL,1,1,150,108.0000,'150',NULL,'draft',NULL,1,'2026-07-11 06:55:18','2026-07-11 06:55:18'),
(6988,501,1,7,NULL,NULL,1,1,151,142.0000,'151',NULL,'draft',NULL,1,'2026-07-11 06:55:18','2026-07-11 06:55:18'),
(6989,501,1,7,NULL,NULL,1,1,152,118.0000,'152',NULL,'draft',NULL,1,'2026-07-11 06:55:18','2026-07-11 06:55:18'),
(6990,501,1,7,NULL,NULL,1,1,153,135.0000,'153',NULL,'draft',NULL,1,'2026-07-11 06:55:18','2026-07-11 06:55:18'),
(6991,501,1,7,NULL,NULL,1,1,154,143.0000,'154',NULL,'draft',NULL,1,'2026-07-11 06:55:18','2026-07-11 06:55:18'),
(6992,501,1,7,NULL,NULL,1,1,155,138.0000,'155',NULL,'draft',NULL,1,'2026-07-11 06:55:18','2026-07-11 06:55:18'),
(6993,501,1,7,NULL,NULL,1,1,156,133.0000,'156',NULL,'draft',NULL,1,'2026-07-11 06:55:18','2026-07-11 06:55:18'),
(6994,501,1,7,NULL,NULL,1,1,157,136.0000,'157',NULL,'draft',NULL,1,'2026-07-11 06:55:18','2026-07-11 06:55:18'),
(6995,501,1,7,NULL,NULL,1,1,158,111.0000,'158',NULL,'draft',NULL,1,'2026-07-11 06:55:18','2026-07-11 06:55:18'),
(6996,501,1,7,NULL,NULL,1,1,159,99.0000,'159',NULL,'draft',NULL,1,'2026-07-11 06:55:18','2026-07-11 06:55:18'),
(6997,501,1,7,NULL,NULL,1,1,160,133.0000,'160',NULL,'draft',NULL,1,'2026-07-11 06:55:18','2026-07-11 06:55:18'),
(6998,501,1,7,NULL,NULL,1,1,161,130.0000,'161',NULL,'draft',NULL,1,'2026-07-11 06:55:18','2026-07-11 06:55:18'),
(6999,501,1,7,NULL,NULL,1,1,162,123.0000,'162',NULL,'draft',NULL,1,'2026-07-11 06:55:18','2026-07-11 06:55:18'),
(7000,501,1,7,NULL,NULL,1,1,163,135.0000,'163',NULL,'draft',NULL,1,'2026-07-11 06:55:18','2026-07-11 06:55:18'),
(7001,501,1,7,NULL,NULL,1,1,164,147.0000,'164',NULL,'draft',NULL,1,'2026-07-11 06:55:18','2026-07-11 06:55:18'),
(7002,501,1,7,NULL,NULL,1,1,165,158.0000,'165',NULL,'draft',NULL,1,'2026-07-11 06:55:18','2026-07-11 06:55:18'),
(7003,501,1,7,NULL,NULL,1,1,166,135.0000,'166',NULL,'draft',NULL,1,'2026-07-11 06:55:18','2026-07-11 06:55:18'),
(7004,501,1,7,NULL,NULL,1,1,167,100.0000,'167',NULL,'draft',NULL,1,'2026-07-11 06:55:18','2026-07-11 06:55:18'),
(7005,501,1,7,NULL,NULL,1,1,168,103.0000,'168',NULL,'draft',NULL,1,'2026-07-11 06:55:18','2026-07-11 06:55:18'),
(7006,501,1,7,NULL,NULL,1,1,169,128.0000,'169',NULL,'draft',NULL,1,'2026-07-11 06:55:18','2026-07-11 06:55:18'),
(7007,502,1,6,NULL,NULL,1,1,1,22.1000,'1',NULL,'draft',NULL,1,'2026-07-11 06:55:18','2026-07-11 06:55:18'),
(7008,502,1,6,NULL,NULL,1,1,2,27.3000,'2',NULL,'draft',NULL,1,'2026-07-11 06:55:18','2026-07-11 06:55:18'),
(7009,502,1,6,NULL,NULL,1,1,3,26.4000,'3',NULL,'draft',NULL,1,'2026-07-11 06:55:18','2026-07-11 06:55:18'),
(7010,502,1,6,NULL,NULL,1,1,4,27.4000,'4',NULL,'draft',NULL,1,'2026-07-11 06:55:18','2026-07-11 06:55:18'),
(7011,502,1,6,NULL,NULL,1,1,5,26.5000,'5',NULL,'draft',NULL,1,'2026-07-11 06:55:18','2026-07-11 06:55:18'),
(7012,502,1,6,NULL,NULL,1,1,6,28.0000,'6',NULL,'draft',NULL,1,'2026-07-11 06:55:18','2026-07-11 06:55:18'),
(7013,502,1,6,NULL,NULL,1,1,7,27.0000,'7',NULL,'draft',NULL,1,'2026-07-11 06:55:18','2026-07-11 06:55:18'),
(7014,503,1,6,NULL,NULL,1,1,1,27.2000,'8',NULL,'draft',NULL,1,'2026-07-11 06:55:18','2026-07-11 06:55:18'),
(7015,503,1,6,NULL,NULL,1,1,2,29.8000,'9',NULL,'draft',NULL,1,'2026-07-11 06:55:18','2026-07-11 06:55:18'),
(7016,503,1,6,NULL,NULL,1,1,3,29.4000,'10',NULL,'draft',NULL,1,'2026-07-11 06:55:18','2026-07-11 06:55:18'),
(7017,503,1,6,NULL,NULL,1,1,4,29.6000,'11',NULL,'draft',NULL,1,'2026-07-11 06:55:18','2026-07-11 06:55:18'),
(7018,503,1,6,NULL,NULL,1,1,5,30.0000,'12',NULL,'draft',NULL,1,'2026-07-11 06:55:18','2026-07-11 06:55:18'),
(7019,503,1,6,NULL,NULL,1,1,6,29.1000,'13',NULL,'draft',NULL,1,'2026-07-11 06:55:18','2026-07-11 06:55:18'),
(7020,503,1,6,NULL,NULL,1,1,7,27.8000,'14',NULL,'draft',NULL,1,'2026-07-11 06:55:18','2026-07-11 06:55:18'),
(7021,503,1,6,NULL,NULL,1,1,8,28.2000,'15',NULL,'draft',NULL,1,'2026-07-11 06:55:18','2026-07-11 06:55:18'),
(7022,504,1,6,NULL,NULL,1,1,1,26.5000,'16',NULL,'draft',NULL,1,'2026-07-11 06:55:18','2026-07-11 06:55:18'),
(7023,504,1,6,NULL,NULL,1,1,2,29.2000,'17',NULL,'draft',NULL,1,'2026-07-11 06:55:18','2026-07-11 06:55:18'),
(7024,504,1,6,NULL,NULL,1,1,3,27.5000,'18',NULL,'draft',NULL,1,'2026-07-11 06:55:18','2026-07-11 06:55:18'),
(7025,504,1,6,NULL,NULL,1,1,4,28.9000,'19',NULL,'draft',NULL,1,'2026-07-11 06:55:18','2026-07-11 06:55:18'),
(7026,504,1,6,NULL,NULL,1,1,5,29.0000,'20',NULL,'draft',NULL,1,'2026-07-11 06:55:18','2026-07-11 06:55:18'),
(7027,504,1,6,NULL,NULL,1,1,6,29.1000,'21',NULL,'draft',NULL,1,'2026-07-11 06:55:18','2026-07-11 06:55:18'),
(7028,504,1,6,NULL,NULL,1,1,7,29.0000,'22',NULL,'draft',NULL,1,'2026-07-11 06:55:18','2026-07-11 06:55:18'),
(7029,504,1,6,NULL,NULL,1,1,8,29.8000,'23',NULL,'draft',NULL,1,'2026-07-11 06:55:18','2026-07-11 06:55:18'),
(7030,505,1,6,NULL,NULL,1,1,1,27.3000,'24',NULL,'draft',NULL,1,'2026-07-11 06:55:18','2026-07-11 06:55:18'),
(7031,505,1,6,NULL,NULL,1,1,2,25.6000,'25',NULL,'draft',NULL,1,'2026-07-11 06:55:18','2026-07-11 06:55:18'),
(7032,505,1,6,NULL,NULL,1,1,3,26.6000,'26',NULL,'draft',NULL,1,'2026-07-11 06:55:18','2026-07-11 06:55:18'),
(7033,505,1,6,NULL,NULL,1,1,4,27.6000,'27',NULL,'draft',NULL,1,'2026-07-11 06:55:18','2026-07-11 06:55:18'),
(7034,505,1,6,NULL,NULL,1,1,5,28.1000,'28',NULL,'draft',NULL,1,'2026-07-11 06:55:18','2026-07-11 06:55:18'),
(7035,505,1,6,NULL,NULL,1,1,6,26.4000,'29',NULL,'draft',NULL,1,'2026-07-11 06:55:18','2026-07-11 06:55:18'),
(7036,506,1,6,NULL,NULL,1,1,1,21.4000,'30',NULL,'draft',NULL,1,'2026-07-11 06:55:18','2026-07-11 06:55:18'),
(7037,507,1,6,NULL,NULL,1,1,1,24.3000,'31',NULL,'draft',NULL,1,'2026-07-11 06:55:18','2026-07-11 06:55:18'),
(7038,507,1,6,NULL,NULL,1,1,2,25.4000,'32',NULL,'draft',NULL,1,'2026-07-11 06:55:18','2026-07-11 06:55:18'),
(7039,507,1,6,NULL,NULL,1,1,3,23.0000,'33',NULL,'draft',NULL,1,'2026-07-11 06:55:18','2026-07-11 06:55:18'),
(7040,507,1,6,NULL,NULL,1,1,4,24.7000,'34',NULL,'draft',NULL,1,'2026-07-11 06:55:18','2026-07-11 06:55:18'),
(7041,508,1,6,NULL,NULL,1,1,1,27.2000,'35',NULL,'draft',NULL,1,'2026-07-11 06:55:18','2026-07-11 06:55:18'),
(7042,508,1,6,NULL,NULL,1,1,2,28.8000,'36',NULL,'draft',NULL,1,'2026-07-11 06:55:18','2026-07-11 06:55:18'),
(7043,508,1,6,NULL,NULL,1,1,3,28.5000,'37',NULL,'draft',NULL,1,'2026-07-11 06:55:18','2026-07-11 06:55:18'),
(7044,508,1,6,NULL,NULL,1,1,4,28.2000,'38',NULL,'draft',NULL,1,'2026-07-11 06:55:18','2026-07-11 06:55:18'),
(7045,508,1,6,NULL,NULL,1,1,5,29.1000,'39',NULL,'draft',NULL,1,'2026-07-11 06:55:18','2026-07-11 06:55:18'),
(7046,509,1,6,NULL,NULL,1,1,1,27.6000,'40',NULL,'draft',NULL,1,'2026-07-11 06:55:18','2026-07-11 06:55:18'),
(7047,509,1,6,NULL,NULL,1,1,2,25.7000,'41',NULL,'draft',NULL,1,'2026-07-11 06:55:18','2026-07-11 06:55:18'),
(7048,509,1,6,NULL,NULL,1,1,3,27.1000,'42',NULL,'draft',NULL,1,'2026-07-11 06:55:18','2026-07-11 06:55:18'),
(7049,509,1,6,NULL,NULL,1,1,4,27.4000,'43',NULL,'draft',NULL,1,'2026-07-11 06:55:18','2026-07-11 06:55:18'),
(7050,509,1,6,NULL,NULL,1,1,5,28.0000,'44',NULL,'draft',NULL,1,'2026-07-11 06:55:18','2026-07-11 06:55:18'),
(7051,510,1,6,NULL,NULL,1,1,1,29.4000,'45',NULL,'draft',NULL,1,'2026-07-11 06:55:18','2026-07-11 06:55:18'),
(7052,510,1,6,NULL,NULL,1,1,2,28.9000,'46',NULL,'draft',NULL,1,'2026-07-11 06:55:18','2026-07-11 06:55:18'),
(7053,510,1,6,NULL,NULL,1,1,3,28.5000,'47',NULL,'draft',NULL,1,'2026-07-11 06:55:18','2026-07-11 06:55:18'),
(7054,510,1,6,NULL,NULL,1,1,4,25.7000,'48',NULL,'draft',NULL,1,'2026-07-11 06:55:18','2026-07-11 06:55:18'),
(7055,511,1,6,NULL,NULL,1,1,1,30.2000,'49',NULL,'draft',NULL,1,'2026-07-11 06:55:18','2026-07-11 06:55:18'),
(7056,511,1,6,NULL,NULL,1,1,2,31.2000,'50',NULL,'draft',NULL,1,'2026-07-11 06:55:18','2026-07-11 06:55:18'),
(7057,511,1,6,NULL,NULL,1,1,3,30.0000,'51',NULL,'draft',NULL,1,'2026-07-11 06:55:18','2026-07-11 06:55:18'),
(7058,511,1,6,NULL,NULL,1,1,4,29.7000,'52',NULL,'draft',NULL,1,'2026-07-11 06:55:18','2026-07-11 06:55:18'),
(7059,512,1,6,NULL,NULL,1,1,1,24.2000,'53',NULL,'draft',NULL,1,'2026-07-11 06:55:18','2026-07-11 06:55:18'),
(7060,512,1,6,NULL,NULL,1,1,2,27.3000,'54',NULL,'draft',NULL,1,'2026-07-11 06:55:18','2026-07-11 06:55:18'),
(7061,512,1,6,NULL,NULL,1,1,3,26.4000,'55',NULL,'draft',NULL,1,'2026-07-11 06:55:18','2026-07-11 06:55:18'),
(7062,512,1,6,NULL,NULL,1,1,4,25.8000,'56',NULL,'draft',NULL,1,'2026-07-11 06:55:18','2026-07-11 06:55:18'),
(7063,513,1,6,NULL,NULL,1,1,1,29.2000,'57',NULL,'draft',NULL,1,'2026-07-11 06:55:18','2026-07-11 06:55:18'),
(7064,513,1,6,NULL,NULL,1,1,2,28.9000,'58',NULL,'draft',NULL,1,'2026-07-11 06:55:18','2026-07-11 06:55:18'),
(7065,513,1,6,NULL,NULL,1,1,3,29.4000,'59',NULL,'draft',NULL,1,'2026-07-11 06:55:18','2026-07-11 06:55:18'),
(7066,513,1,6,NULL,NULL,1,1,4,21.8000,'60',NULL,'draft',NULL,1,'2026-07-11 06:55:18','2026-07-11 06:55:18'),
(7067,514,1,6,NULL,NULL,1,1,1,25.4000,'61',NULL,'draft',NULL,1,'2026-07-11 06:55:18','2026-07-11 06:55:18'),
(7068,514,1,6,NULL,NULL,1,1,2,27.4000,'62',NULL,'draft',NULL,1,'2026-07-11 06:55:18','2026-07-11 06:55:18'),
(7069,514,1,6,NULL,NULL,1,1,3,25.2000,'63',NULL,'draft',NULL,1,'2026-07-11 06:55:18','2026-07-11 06:55:18'),
(7070,514,1,6,NULL,NULL,1,1,4,26.2000,'64',NULL,'draft',NULL,1,'2026-07-11 06:55:18','2026-07-11 06:55:18'),
(7071,514,1,6,NULL,NULL,1,1,5,27.2000,'65',NULL,'draft',NULL,1,'2026-07-11 06:55:18','2026-07-11 06:55:18'),
(7072,514,1,6,NULL,NULL,1,1,6,27.3000,'66',NULL,'draft',NULL,1,'2026-07-11 06:55:18','2026-07-11 06:55:18'),
(7073,515,1,6,NULL,NULL,1,1,1,28.0000,'67',NULL,'draft',NULL,1,'2026-07-11 06:55:18','2026-07-11 06:55:18'),
(7074,515,1,6,NULL,NULL,1,1,2,26.2000,'68',NULL,'draft',NULL,1,'2026-07-11 06:55:18','2026-07-11 06:55:18'),
(7075,515,1,6,NULL,NULL,1,1,3,28.2000,'69',NULL,'draft',NULL,1,'2026-07-11 06:55:18','2026-07-11 06:55:18'),
(7076,516,1,6,NULL,NULL,1,1,1,29.4000,'70',NULL,'draft',NULL,1,'2026-07-11 06:55:18','2026-07-11 06:55:18'),
(7077,516,1,6,NULL,NULL,1,1,2,25.6000,'71',NULL,'draft',NULL,1,'2026-07-11 06:55:18','2026-07-11 06:55:18'),
(7078,516,1,6,NULL,NULL,1,1,3,26.2000,'72',NULL,'draft',NULL,1,'2026-07-11 06:55:18','2026-07-11 06:55:18'),
(7079,517,1,6,NULL,NULL,1,1,1,27.6000,'73',NULL,'draft',NULL,1,'2026-07-11 06:55:18','2026-07-11 06:55:18'),
(7080,517,1,6,NULL,NULL,1,1,2,24.0000,'74',NULL,'draft',NULL,1,'2026-07-11 06:55:18','2026-07-11 06:55:18'),
(7081,517,1,6,NULL,NULL,1,1,3,28.6000,'75',NULL,'draft',NULL,1,'2026-07-11 06:55:18','2026-07-11 06:55:18'),
(7082,518,1,6,NULL,NULL,1,1,1,29.0000,'76',NULL,'draft',NULL,1,'2026-07-11 06:55:18','2026-07-11 06:55:18'),
(7083,518,1,6,NULL,NULL,1,1,2,28.9000,'77',NULL,'draft',NULL,1,'2026-07-11 06:55:18','2026-07-11 06:55:18'),
(7084,518,1,6,NULL,NULL,1,1,3,27.7000,'78',NULL,'draft',NULL,1,'2026-07-11 06:55:18','2026-07-11 06:55:18'),
(7085,518,1,6,NULL,NULL,1,1,4,26.1000,'79',NULL,'draft',NULL,1,'2026-07-11 06:55:18','2026-07-11 06:55:18'),
(7086,518,1,6,NULL,NULL,1,1,5,28.6000,'80',NULL,'draft',NULL,1,'2026-07-11 06:55:18','2026-07-11 06:55:18'),
(7087,518,1,6,NULL,NULL,1,1,6,29.3000,'81',NULL,'draft',NULL,1,'2026-07-11 06:55:18','2026-07-11 06:55:18'),
(7088,518,1,6,NULL,NULL,1,1,7,27.9000,'82',NULL,'draft',NULL,1,'2026-07-11 06:55:18','2026-07-11 06:55:18'),
(7089,518,1,6,NULL,NULL,1,1,8,28.2000,'83',NULL,'draft',NULL,1,'2026-07-11 06:55:18','2026-07-11 06:55:18'),
(7090,519,1,6,NULL,NULL,1,1,1,27.4000,'84',NULL,'draft',NULL,1,'2026-07-11 06:55:18','2026-07-11 06:55:18'),
(7091,519,1,6,NULL,NULL,1,1,2,25.7000,'85',NULL,'draft',NULL,1,'2026-07-11 06:55:18','2026-07-11 06:55:18'),
(7092,519,1,6,NULL,NULL,1,1,3,26.2000,'86',NULL,'draft',NULL,1,'2026-07-11 06:55:18','2026-07-11 06:55:18'),
(7093,519,1,6,NULL,NULL,1,1,4,24.3000,'87',NULL,'draft',NULL,1,'2026-07-11 06:55:18','2026-07-11 06:55:18'),
(7094,519,1,6,NULL,NULL,1,1,5,26.9000,'88',NULL,'draft',NULL,1,'2026-07-11 06:55:18','2026-07-11 06:55:18'),
(7095,519,1,6,NULL,NULL,1,1,6,27.2000,'89',NULL,'draft',NULL,1,'2026-07-11 06:55:18','2026-07-11 06:55:18'),
(7096,519,1,6,NULL,NULL,1,1,7,26.0000,'90',NULL,'draft',NULL,1,'2026-07-11 06:55:18','2026-07-11 06:55:18'),
(7097,519,1,6,NULL,NULL,1,1,8,26.3000,'91',NULL,'draft',NULL,1,'2026-07-11 06:55:18','2026-07-11 06:55:18'),
(7098,519,1,6,NULL,NULL,1,1,9,26.4000,'92',NULL,'draft',NULL,1,'2026-07-11 06:55:18','2026-07-11 06:55:18'),
(7099,519,1,6,NULL,NULL,1,1,10,27.0000,'93',NULL,'draft',NULL,1,'2026-07-11 06:55:18','2026-07-11 06:55:18'),
(7100,519,1,6,NULL,NULL,1,1,11,26.3000,'94',NULL,'draft',NULL,1,'2026-07-11 06:55:19','2026-07-11 06:55:19'),
(7101,519,1,6,NULL,NULL,1,1,12,27.3000,'95',NULL,'draft',NULL,1,'2026-07-11 06:55:19','2026-07-11 06:55:19'),
(7102,519,1,6,NULL,NULL,1,1,13,27.0000,'96',NULL,'draft',NULL,1,'2026-07-11 06:55:19','2026-07-11 06:55:19'),
(7103,519,1,6,NULL,NULL,1,1,14,25.9000,'97',NULL,'draft',NULL,1,'2026-07-11 06:55:19','2026-07-11 06:55:19'),
(7104,519,1,6,NULL,NULL,1,1,15,26.0000,'98',NULL,'draft',NULL,1,'2026-07-11 06:55:19','2026-07-11 06:55:19'),
(7105,519,1,6,NULL,NULL,1,1,16,26.1000,'99',NULL,'draft',NULL,1,'2026-07-11 06:55:19','2026-07-11 06:55:19'),
(7106,519,1,6,NULL,NULL,1,1,17,26.3000,'100',NULL,'draft',NULL,1,'2026-07-11 06:55:19','2026-07-11 06:55:19'),
(7107,519,1,6,NULL,NULL,1,1,18,26.1000,'101',NULL,'draft',NULL,1,'2026-07-11 06:55:19','2026-07-11 06:55:19'),
(7108,519,1,6,NULL,NULL,1,1,19,26.2000,'102',NULL,'draft',NULL,1,'2026-07-11 06:55:19','2026-07-11 06:55:19'),
(7109,520,1,6,NULL,NULL,1,1,1,30.1000,'103',NULL,'draft',NULL,1,'2026-07-11 06:55:19','2026-07-11 06:55:19'),
(7110,520,1,6,NULL,NULL,1,1,2,29.9000,'104',NULL,'draft',NULL,1,'2026-07-11 06:55:19','2026-07-11 06:55:19'),
(7111,520,1,6,NULL,NULL,1,1,3,30.5000,'105',NULL,'draft',NULL,1,'2026-07-11 06:55:19','2026-07-11 06:55:19'),
(7112,520,1,6,NULL,NULL,1,1,4,30.1000,'106',NULL,'draft',NULL,1,'2026-07-11 06:55:19','2026-07-11 06:55:19'),
(7113,521,1,6,NULL,NULL,1,1,1,25.3000,'107',NULL,'draft',NULL,1,'2026-07-11 06:55:19','2026-07-11 06:55:19'),
(7114,521,1,6,NULL,NULL,1,1,2,25.4000,'108',NULL,'draft',NULL,1,'2026-07-11 06:55:19','2026-07-11 06:55:19'),
(7115,521,1,6,NULL,NULL,1,1,3,28.0000,'109',NULL,'draft',NULL,1,'2026-07-11 06:55:19','2026-07-11 06:55:19'),
(7116,521,1,6,NULL,NULL,1,1,4,27.2000,'110',NULL,'draft',NULL,1,'2026-07-11 06:55:19','2026-07-11 06:55:19'),
(7117,522,1,6,NULL,NULL,1,1,1,24.8000,'111',NULL,'draft',NULL,1,'2026-07-11 06:55:19','2026-07-11 06:55:19'),
(7118,522,1,6,NULL,NULL,1,1,2,26.1000,'112',NULL,'draft',NULL,1,'2026-07-11 06:55:19','2026-07-11 06:55:19'),
(7119,522,1,6,NULL,NULL,1,1,3,26.8000,'113',NULL,'draft',NULL,1,'2026-07-11 06:55:19','2026-07-11 06:55:19'),
(7120,522,1,6,NULL,NULL,1,1,4,27.3000,'114',NULL,'draft',NULL,1,'2026-07-11 06:55:19','2026-07-11 06:55:19'),
(7121,523,1,6,NULL,NULL,1,1,1,23.6000,'115',NULL,'draft',NULL,1,'2026-07-11 06:55:19','2026-07-11 06:55:19'),
(7122,523,1,6,NULL,NULL,1,1,2,23.7000,'116',NULL,'draft',NULL,1,'2026-07-11 06:55:19','2026-07-11 06:55:19'),
(7123,523,1,6,NULL,NULL,1,1,3,24.0000,'117',NULL,'draft',NULL,1,'2026-07-11 06:55:19','2026-07-11 06:55:19'),
(7124,523,1,6,NULL,NULL,1,1,4,20.9000,'118',NULL,'draft',NULL,1,'2026-07-11 06:55:19','2026-07-11 06:55:19'),
(7125,524,1,6,NULL,NULL,1,1,1,30.0000,'119',NULL,'draft',NULL,1,'2026-07-11 06:55:19','2026-07-11 06:55:19'),
(7126,524,1,6,NULL,NULL,1,1,2,29.8000,'120',NULL,'draft',NULL,1,'2026-07-11 06:55:19','2026-07-11 06:55:19'),
(7127,524,1,6,NULL,NULL,1,1,3,29.2000,'121',NULL,'draft',NULL,1,'2026-07-11 06:55:19','2026-07-11 06:55:19'),
(7128,524,1,6,NULL,NULL,1,1,4,30.5000,'122',NULL,'draft',NULL,1,'2026-07-11 06:55:19','2026-07-11 06:55:19'),
(7129,524,1,6,NULL,NULL,1,1,5,29.7000,'123',NULL,'draft',NULL,1,'2026-07-11 06:55:19','2026-07-11 06:55:19'),
(7130,524,1,6,NULL,NULL,1,1,6,30.3000,'124',NULL,'draft',NULL,1,'2026-07-11 06:55:19','2026-07-11 06:55:19'),
(7131,524,1,6,NULL,NULL,1,1,7,29.7000,'125',NULL,'draft',NULL,1,'2026-07-11 06:55:19','2026-07-11 06:55:19'),
(7132,524,1,6,NULL,NULL,1,1,8,30.0000,'126',NULL,'draft',NULL,1,'2026-07-11 06:55:19','2026-07-11 06:55:19'),
(7133,524,1,6,NULL,NULL,1,1,9,30.6000,'127',NULL,'draft',NULL,1,'2026-07-11 06:55:19','2026-07-11 06:55:19'),
(7134,524,1,6,NULL,NULL,1,1,10,30.5000,'128',NULL,'draft',NULL,1,'2026-07-11 06:55:19','2026-07-11 06:55:19'),
(7135,524,1,6,NULL,NULL,1,1,11,29.8000,'129',NULL,'draft',NULL,1,'2026-07-11 06:55:19','2026-07-11 06:55:19'),
(7136,524,1,6,NULL,NULL,1,1,12,30.6000,'130',NULL,'draft',NULL,1,'2026-07-11 06:55:19','2026-07-11 06:55:19'),
(7137,524,1,6,NULL,NULL,1,1,13,30.4000,'131',NULL,'draft',NULL,1,'2026-07-11 06:55:19','2026-07-11 06:55:19'),
(7138,525,1,6,NULL,NULL,1,1,1,27.0000,'132',NULL,'draft',NULL,1,'2026-07-11 06:55:19','2026-07-11 06:55:19'),
(7139,525,1,6,NULL,NULL,1,1,2,27.3000,'133',NULL,'draft',NULL,1,'2026-07-11 06:55:19','2026-07-11 06:55:19'),
(7140,525,1,6,NULL,NULL,1,1,3,26.6000,'134',NULL,'draft',NULL,1,'2026-07-11 06:55:19','2026-07-11 06:55:19'),
(7141,525,1,6,NULL,NULL,1,1,4,32.6000,'135',NULL,'draft',NULL,1,'2026-07-11 06:55:19','2026-07-11 06:55:19'),
(7142,525,1,6,NULL,NULL,1,1,5,28.0000,'136',NULL,'draft',NULL,1,'2026-07-11 06:55:19','2026-07-11 06:55:19'),
(7143,525,1,6,NULL,NULL,1,1,6,23.2000,'137',NULL,'draft',NULL,1,'2026-07-11 06:55:19','2026-07-11 06:55:19'),
(7144,525,1,6,NULL,NULL,1,1,7,28.1000,'138',NULL,'draft',NULL,1,'2026-07-11 06:55:19','2026-07-11 06:55:19'),
(7145,525,1,6,NULL,NULL,1,1,8,25.7000,'139',NULL,'draft',NULL,1,'2026-07-11 06:55:19','2026-07-11 06:55:19'),
(7146,526,1,6,NULL,NULL,1,1,1,26.1000,'140',NULL,'draft',NULL,1,'2026-07-11 06:55:19','2026-07-11 06:55:19'),
(7147,526,1,6,NULL,NULL,1,1,2,22.8000,'141',NULL,'draft',NULL,1,'2026-07-11 06:55:19','2026-07-11 06:55:19'),
(7148,526,1,6,NULL,NULL,1,1,3,25.9000,'142',NULL,'draft',NULL,1,'2026-07-11 06:55:19','2026-07-11 06:55:19'),
(7149,526,1,6,NULL,NULL,1,1,4,20.7000,'143',NULL,'draft',NULL,1,'2026-07-11 06:55:19','2026-07-11 06:55:19'),
(7150,526,1,6,NULL,NULL,1,1,5,26.0000,'144',NULL,'draft',NULL,1,'2026-07-11 06:55:19','2026-07-11 06:55:19'),
(7151,526,1,6,NULL,NULL,1,1,6,26.2000,'145',NULL,'draft',NULL,1,'2026-07-11 06:55:19','2026-07-11 06:55:19'),
(7152,526,1,6,NULL,NULL,1,1,7,23.1000,'146',NULL,'draft',NULL,1,'2026-07-11 06:55:19','2026-07-11 06:55:19'),
(7153,526,1,6,NULL,NULL,1,1,8,24.6000,'147',NULL,'draft',NULL,1,'2026-07-11 06:55:19','2026-07-11 06:55:19'),
(7154,527,1,6,NULL,NULL,1,1,1,25.1000,'148',NULL,'draft',NULL,1,'2026-07-11 06:55:19','2026-07-11 06:55:19'),
(7155,527,1,6,NULL,NULL,1,1,2,29.2000,'149',NULL,'draft',NULL,1,'2026-07-11 06:55:19','2026-07-11 06:55:19'),
(7156,527,1,6,NULL,NULL,1,1,3,29.3000,'150',NULL,'draft',NULL,1,'2026-07-11 06:55:19','2026-07-11 06:55:19'),
(7157,527,1,6,NULL,NULL,1,1,4,29.1000,'151',NULL,'draft',NULL,1,'2026-07-11 06:55:19','2026-07-11 06:55:19'),
(7158,527,1,6,NULL,NULL,1,1,5,29.5000,'152',NULL,'draft',NULL,1,'2026-07-11 06:55:19','2026-07-11 06:55:19'),
(7159,527,1,6,NULL,NULL,1,1,6,27.9000,'153',NULL,'draft',NULL,1,'2026-07-11 06:55:19','2026-07-11 06:55:19'),
(7160,527,1,6,NULL,NULL,1,1,7,23.4000,'154',NULL,'draft',NULL,1,'2026-07-11 06:55:19','2026-07-11 06:55:19'),
(7161,527,1,6,NULL,NULL,1,1,8,28.8000,'155',NULL,'draft',NULL,1,'2026-07-11 06:55:19','2026-07-11 06:55:19'),
(7162,527,1,6,NULL,NULL,1,1,9,29.1000,'156',NULL,'draft',NULL,1,'2026-07-11 06:55:19','2026-07-11 06:55:19'),
(7163,527,1,6,NULL,NULL,1,1,10,29.1000,'157',NULL,'draft',NULL,1,'2026-07-11 06:55:19','2026-07-11 06:55:19'),
(7164,527,1,6,NULL,NULL,1,1,11,28.8000,'158',NULL,'draft',NULL,1,'2026-07-11 06:55:19','2026-07-11 06:55:19'),
(7165,527,1,6,NULL,NULL,1,1,12,29.7000,'159',NULL,'draft',NULL,1,'2026-07-11 06:55:19','2026-07-11 06:55:19'),
(7166,527,1,6,NULL,NULL,1,1,13,23.0000,'160',NULL,'draft',NULL,1,'2026-07-11 06:55:19','2026-07-11 06:55:19'),
(7167,527,1,6,NULL,NULL,1,1,14,24.6000,'161',NULL,'draft',NULL,1,'2026-07-11 06:55:19','2026-07-11 06:55:19'),
(7168,527,1,6,NULL,NULL,1,1,15,28.9000,'162',NULL,'draft',NULL,1,'2026-07-11 06:55:19','2026-07-11 06:55:19'),
(7169,528,1,6,NULL,NULL,1,1,1,19.1000,'163',NULL,'draft',NULL,1,'2026-07-11 06:55:19','2026-07-11 06:55:19'),
(7170,528,1,6,NULL,NULL,1,1,2,17.5000,'164',NULL,'draft',NULL,1,'2026-07-11 06:55:19','2026-07-11 06:55:19'),
(7171,528,1,6,NULL,NULL,1,1,3,21.6000,'165',NULL,'draft',NULL,1,'2026-07-11 06:55:19','2026-07-11 06:55:19'),
(7172,528,1,6,NULL,NULL,1,1,4,21.8000,'166',NULL,'draft',NULL,1,'2026-07-11 06:55:19','2026-07-11 06:55:19'),
(7173,528,1,6,NULL,NULL,1,1,5,20.7000,'167',NULL,'draft',NULL,1,'2026-07-11 06:55:19','2026-07-11 06:55:19'),
(7174,529,1,6,NULL,NULL,1,1,1,29.8000,'168',NULL,'draft',NULL,1,'2026-07-11 06:55:19','2026-07-11 06:55:19'),
(7175,529,1,6,NULL,NULL,1,1,2,28.6000,'169',NULL,'draft',NULL,1,'2026-07-11 06:55:19','2026-07-11 06:55:19'),
(7176,529,1,6,NULL,NULL,1,1,3,30.3000,'170',NULL,'draft',NULL,1,'2026-07-11 06:55:19','2026-07-11 06:55:19'),
(7177,529,1,6,NULL,NULL,1,1,4,23.7000,'171',NULL,'draft',NULL,1,'2026-07-11 06:55:19','2026-07-11 06:55:19'),
(7178,529,1,6,NULL,NULL,1,1,5,30.1000,'172',NULL,'draft',NULL,1,'2026-07-11 06:55:19','2026-07-11 06:55:19'),
(7179,529,1,6,NULL,NULL,1,1,6,33.6000,'173',NULL,'draft',NULL,1,'2026-07-11 06:55:19','2026-07-11 06:55:19'),
(7180,529,1,6,NULL,NULL,1,1,7,30.3000,'174',NULL,'draft',NULL,1,'2026-07-11 06:55:19','2026-07-11 06:55:19'),
(7181,529,1,6,NULL,NULL,1,1,8,30.7000,'175',NULL,'draft',NULL,1,'2026-07-11 06:55:19','2026-07-11 06:55:19'),
(7182,529,1,6,NULL,NULL,1,1,9,30.1000,'176',NULL,'draft',NULL,1,'2026-07-11 06:55:19','2026-07-11 06:55:19'),
(7183,529,1,6,NULL,NULL,1,1,10,30.4000,'177',NULL,'draft',NULL,1,'2026-07-11 06:55:19','2026-07-11 06:55:19'),
(7184,529,1,6,NULL,NULL,1,1,11,29.6000,'178',NULL,'draft',NULL,1,'2026-07-11 06:55:19','2026-07-11 06:55:19'),
(7185,529,1,6,NULL,NULL,1,1,12,31.3000,'179',NULL,'draft',NULL,1,'2026-07-11 06:55:19','2026-07-11 06:55:19'),
(7186,529,1,6,NULL,NULL,1,1,13,30.1000,'180',NULL,'draft',NULL,1,'2026-07-11 06:55:19','2026-07-11 06:55:19'),
(7187,530,1,6,NULL,NULL,1,1,1,29.1000,'181',NULL,'draft',NULL,1,'2026-07-11 06:55:19','2026-07-11 06:55:19'),
(7188,530,1,6,NULL,NULL,1,1,2,29.9000,'182',NULL,'draft',NULL,1,'2026-07-11 06:55:19','2026-07-11 06:55:19'),
(7189,530,1,6,NULL,NULL,1,1,3,30.0000,'183',NULL,'draft',NULL,1,'2026-07-11 06:55:19','2026-07-11 06:55:19'),
(7190,530,1,6,NULL,NULL,1,1,4,30.1000,'184',NULL,'draft',NULL,1,'2026-07-11 06:55:19','2026-07-11 06:55:19'),
(7191,530,1,6,NULL,NULL,1,1,5,30.0000,'185',NULL,'draft',NULL,1,'2026-07-11 06:55:19','2026-07-11 06:55:19'),
(7192,530,1,6,NULL,NULL,1,1,6,29.9000,'186',NULL,'draft',NULL,1,'2026-07-11 06:55:19','2026-07-11 06:55:19'),
(7193,530,1,6,NULL,NULL,1,1,7,30.0000,'187',NULL,'draft',NULL,1,'2026-07-11 06:55:19','2026-07-11 06:55:19'),
(7194,530,1,6,NULL,NULL,1,1,8,29.2000,'188',NULL,'draft',NULL,1,'2026-07-11 06:55:19','2026-07-11 06:55:19'),
(7195,530,1,6,NULL,NULL,1,1,9,30.0000,'189',NULL,'draft',NULL,1,'2026-07-11 06:55:19','2026-07-11 06:55:19'),
(7196,530,1,6,NULL,NULL,1,1,10,29.8000,'190',NULL,'draft',NULL,1,'2026-07-11 06:55:19','2026-07-11 06:55:19'),
(7197,530,1,6,NULL,NULL,1,1,11,29.7000,'191',NULL,'draft',NULL,1,'2026-07-11 06:55:19','2026-07-11 06:55:19'),
(7198,530,1,6,NULL,NULL,1,1,12,29.9000,'192',NULL,'draft',NULL,1,'2026-07-11 06:55:19','2026-07-11 06:55:19'),
(7199,530,1,6,NULL,NULL,1,1,13,29.1000,'193',NULL,'draft',NULL,1,'2026-07-11 06:55:19','2026-07-11 06:55:19'),
(7200,531,1,6,NULL,NULL,1,1,1,29.0000,'194',NULL,'draft',NULL,1,'2026-07-11 06:55:19','2026-07-11 06:55:19'),
(7201,531,1,6,NULL,NULL,1,1,2,29.0000,'195',NULL,'draft',NULL,1,'2026-07-11 06:55:19','2026-07-11 06:55:19'),
(7202,531,1,6,NULL,NULL,1,1,3,29.1000,'196',NULL,'draft',NULL,1,'2026-07-11 06:55:19','2026-07-11 06:55:19'),
(7203,531,1,6,NULL,NULL,1,1,4,29.1000,'197',NULL,'draft',NULL,1,'2026-07-11 06:55:19','2026-07-11 06:55:19'),
(7204,531,1,6,NULL,NULL,1,1,5,29.9000,'198',NULL,'draft',NULL,1,'2026-07-11 06:55:19','2026-07-11 06:55:19'),
(7205,531,1,6,NULL,NULL,1,1,6,29.2000,'199',NULL,'draft',NULL,1,'2026-07-11 06:55:19','2026-07-11 06:55:19'),
(7206,531,1,6,NULL,NULL,1,1,7,29.6000,'200',NULL,'draft',NULL,1,'2026-07-11 06:55:19','2026-07-11 06:55:19'),
(7207,531,1,6,NULL,NULL,1,1,8,28.3000,'201',NULL,'draft',NULL,1,'2026-07-11 06:55:19','2026-07-11 06:55:19'),
(7208,532,1,6,NULL,NULL,1,1,1,18.3000,'202',NULL,'draft',NULL,1,'2026-07-11 06:55:19','2026-07-11 06:55:19'),
(7209,532,1,6,NULL,NULL,1,1,2,26.0000,'203',NULL,'draft',NULL,1,'2026-07-11 06:55:19','2026-07-11 06:55:19'),
(7210,533,1,6,NULL,NULL,1,1,1,24.1000,'204',NULL,'draft',NULL,1,'2026-07-11 06:55:19','2026-07-11 06:55:19'),
(7211,533,1,6,NULL,NULL,1,1,2,24.1000,'205',NULL,'draft',NULL,1,'2026-07-11 06:55:19','2026-07-11 06:55:19'),
(7212,533,1,6,NULL,NULL,1,1,3,24.2000,'206',NULL,'draft',NULL,1,'2026-07-11 06:55:19','2026-07-11 06:55:19'),
(7213,533,1,6,NULL,NULL,1,1,4,24.0000,'207',NULL,'draft',NULL,1,'2026-07-11 06:55:19','2026-07-11 06:55:19'),
(7214,533,1,6,NULL,NULL,1,1,5,24.5000,'208',NULL,'draft',NULL,1,'2026-07-11 06:55:19','2026-07-11 06:55:19'),
(7215,533,1,6,NULL,NULL,1,1,6,24.8000,'209',NULL,'draft',NULL,1,'2026-07-11 06:55:19','2026-07-11 06:55:19'),
(7216,533,1,6,NULL,NULL,1,1,7,23.9000,'210',NULL,'draft',NULL,1,'2026-07-11 06:55:19','2026-07-11 06:55:19'),
(7217,533,1,6,NULL,NULL,1,1,8,24.0000,'211',NULL,'draft',NULL,1,'2026-07-11 06:55:19','2026-07-11 06:55:19'),
(7218,533,1,6,NULL,NULL,1,1,9,25.0000,'212',NULL,'draft',NULL,1,'2026-07-11 06:55:19','2026-07-11 06:55:19'),
(7219,533,1,6,NULL,NULL,1,1,10,24.9000,'213',NULL,'draft',NULL,1,'2026-07-11 06:55:19','2026-07-11 06:55:19'),
(7220,533,1,6,NULL,NULL,1,1,11,25.2000,'214',NULL,'draft',NULL,1,'2026-07-11 06:55:19','2026-07-11 06:55:19'),
(7221,533,1,6,NULL,NULL,1,1,12,24.4000,'215',NULL,'draft',NULL,1,'2026-07-11 06:55:19','2026-07-11 06:55:19'),
(7222,533,1,6,NULL,NULL,1,1,13,23.9000,'216',NULL,'draft',NULL,1,'2026-07-11 06:55:19','2026-07-11 06:55:19'),
(7223,533,1,6,NULL,NULL,1,1,14,23.6000,'217',NULL,'draft',NULL,1,'2026-07-11 06:55:19','2026-07-11 06:55:19'),
(7224,533,1,6,NULL,NULL,1,1,15,23.8000,'218',NULL,'draft',NULL,1,'2026-07-11 06:55:19','2026-07-11 06:55:19'),
(7225,533,1,6,NULL,NULL,1,1,16,23.7000,'219',NULL,'draft',NULL,1,'2026-07-11 06:55:19','2026-07-11 06:55:19'),
(7226,533,1,6,NULL,NULL,1,1,17,24.9000,'220',NULL,'draft',NULL,1,'2026-07-11 06:55:19','2026-07-11 06:55:19'),
(7227,533,1,6,NULL,NULL,1,1,18,24.5000,'221',NULL,'draft',NULL,1,'2026-07-11 06:55:19','2026-07-11 06:55:19'),
(7228,533,1,6,NULL,NULL,1,1,19,24.1000,'222',NULL,'draft',NULL,1,'2026-07-11 06:55:19','2026-07-11 06:55:19'),
(7229,533,1,6,NULL,NULL,1,1,20,24.5000,'223',NULL,'draft',NULL,1,'2026-07-11 06:55:19','2026-07-11 06:55:19'),
(7230,533,1,6,NULL,NULL,1,1,21,24.9000,'224',NULL,'draft',NULL,1,'2026-07-11 06:55:19','2026-07-11 06:55:19'),
(7231,534,1,10,NULL,NULL,1,1,1,23.7000,'1',NULL,'draft',NULL,1,'2026-07-11 06:55:19','2026-07-11 06:55:19'),
(7232,534,1,10,NULL,NULL,1,1,2,24.3000,'2',NULL,'draft',NULL,1,'2026-07-11 06:55:19','2026-07-11 06:55:19'),
(7233,534,1,10,NULL,NULL,1,1,3,29.2000,'3',NULL,'draft',NULL,1,'2026-07-11 06:55:19','2026-07-11 06:55:19'),
(7234,534,1,10,NULL,NULL,1,1,4,23.9000,'4',NULL,'draft',NULL,1,'2026-07-11 06:55:19','2026-07-11 06:55:19'),
(7235,534,1,10,NULL,NULL,1,1,5,23.9000,'5',NULL,'draft',NULL,1,'2026-07-11 06:55:19','2026-07-11 06:55:19'),
(7236,534,1,10,NULL,NULL,1,1,6,24.0000,'6',NULL,'draft',NULL,1,'2026-07-11 06:55:19','2026-07-11 06:55:19'),
(7237,534,1,10,NULL,NULL,1,1,7,23.2000,'7',NULL,'draft',NULL,1,'2026-07-11 06:55:19','2026-07-11 06:55:19'),
(7238,534,1,10,NULL,NULL,1,1,8,16.6000,'8',NULL,'draft',NULL,1,'2026-07-11 06:55:19','2026-07-11 06:55:19'),
(7239,534,1,10,NULL,NULL,1,1,9,23.4000,'9',NULL,'draft',NULL,1,'2026-07-11 06:55:19','2026-07-11 06:55:19'),
(7240,534,1,10,NULL,NULL,1,1,10,21.9000,'10',NULL,'draft',NULL,1,'2026-07-11 06:55:19','2026-07-11 06:55:19'),
(7241,534,1,10,NULL,NULL,1,1,11,24.2000,'11',NULL,'draft',NULL,1,'2026-07-11 06:55:19','2026-07-11 06:55:19'),
(7242,534,1,10,NULL,NULL,1,1,12,24.1000,'12',NULL,'draft',NULL,1,'2026-07-11 06:55:19','2026-07-11 06:55:19'),
(7243,534,1,10,NULL,NULL,1,1,13,24.4000,'13',NULL,'draft',NULL,1,'2026-07-11 06:55:19','2026-07-11 06:55:19'),
(7244,534,1,10,NULL,NULL,1,1,14,23.6000,'14',NULL,'draft',NULL,1,'2026-07-11 06:55:19','2026-07-11 06:55:19'),
(7245,534,1,10,NULL,NULL,1,1,15,23.6000,'15',NULL,'draft',NULL,1,'2026-07-11 06:55:19','2026-07-11 06:55:19'),
(7246,534,1,10,NULL,NULL,1,1,16,22.0000,'16',NULL,'draft',NULL,1,'2026-07-11 06:55:19','2026-07-11 06:55:19'),
(7247,534,1,10,NULL,NULL,1,1,17,23.7000,'17',NULL,'draft',NULL,1,'2026-07-11 06:55:19','2026-07-11 06:55:19'),
(7248,534,1,10,NULL,NULL,1,1,18,24.2000,'18',NULL,'draft',NULL,1,'2026-07-11 06:55:19','2026-07-11 06:55:19'),
(7249,534,1,10,NULL,NULL,1,1,19,23.6000,'19',NULL,'draft',NULL,1,'2026-07-11 06:55:19','2026-07-11 06:55:19'),
(7250,534,1,10,NULL,NULL,1,1,20,23.8000,'20',NULL,'draft',NULL,1,'2026-07-11 06:55:19','2026-07-11 06:55:19'),
(7251,534,1,10,NULL,NULL,1,1,21,23.8000,'21',NULL,'draft',NULL,1,'2026-07-11 06:55:19','2026-07-11 06:55:19'),
(7252,534,1,10,NULL,NULL,1,1,22,25.5000,'22',NULL,'draft',NULL,1,'2026-07-11 06:55:19','2026-07-11 06:55:19'),
(7253,534,1,10,NULL,NULL,1,1,23,24.0000,'23',NULL,'draft',NULL,1,'2026-07-11 06:55:19','2026-07-11 06:55:19'),
(7254,534,1,10,NULL,NULL,1,1,24,23.5000,'24',NULL,'draft',NULL,1,'2026-07-11 06:55:19','2026-07-11 06:55:19'),
(7255,534,1,10,NULL,NULL,1,1,25,24.2000,'25',NULL,'draft',NULL,1,'2026-07-11 06:55:19','2026-07-11 06:55:19'),
(7256,534,1,10,NULL,NULL,1,1,26,22.9000,'26',NULL,'draft',NULL,1,'2026-07-11 06:55:19','2026-07-11 06:55:19'),
(7257,534,1,10,NULL,NULL,1,1,27,15.4000,'27',NULL,'draft',NULL,1,'2026-07-11 06:55:19','2026-07-11 06:55:19'),
(7258,534,1,10,NULL,NULL,1,1,28,23.3000,'28',NULL,'draft',NULL,1,'2026-07-11 06:55:19','2026-07-11 06:55:19'),
(7259,534,1,10,NULL,NULL,1,1,29,23.5000,'29',NULL,'draft',NULL,1,'2026-07-11 06:55:19','2026-07-11 06:55:19'),
(7260,534,1,10,NULL,NULL,1,1,30,24.2000,'30',NULL,'draft',NULL,1,'2026-07-11 06:55:19','2026-07-11 06:55:19'),
(7261,534,1,10,NULL,NULL,1,1,31,23.9000,'31',NULL,'draft',NULL,1,'2026-07-11 06:55:19','2026-07-11 06:55:19'),
(7262,534,1,10,NULL,NULL,1,1,32,24.5000,'32',NULL,'draft',NULL,1,'2026-07-11 06:55:19','2026-07-11 06:55:19'),
(7263,534,1,10,NULL,NULL,1,1,33,23.7000,'33',NULL,'draft',NULL,1,'2026-07-11 06:55:19','2026-07-11 06:55:19'),
(7264,534,1,10,NULL,NULL,1,1,34,23.3000,'34',NULL,'draft',NULL,1,'2026-07-11 06:55:19','2026-07-11 06:55:19'),
(7265,534,1,10,NULL,NULL,1,1,35,24.2000,'35',NULL,'draft',NULL,1,'2026-07-11 06:55:19','2026-07-11 06:55:19'),
(7266,534,1,10,NULL,NULL,1,1,36,23.7000,'36',NULL,'draft',NULL,1,'2026-07-11 06:55:19','2026-07-11 06:55:19'),
(7267,534,1,10,NULL,NULL,1,1,37,24.2000,'37',NULL,'draft',NULL,1,'2026-07-11 06:55:19','2026-07-11 06:55:19'),
(7268,534,1,10,NULL,NULL,1,1,38,24.1000,'38',NULL,'draft',NULL,1,'2026-07-11 06:55:19','2026-07-11 06:55:19'),
(7269,534,1,10,NULL,NULL,1,1,39,23.0000,'39',NULL,'draft',NULL,1,'2026-07-11 06:55:19','2026-07-11 06:55:19'),
(7270,534,1,10,NULL,NULL,1,1,40,24.3000,'40',NULL,'draft',NULL,1,'2026-07-11 06:55:19','2026-07-11 06:55:19'),
(7271,534,1,10,NULL,NULL,1,1,41,19.5000,'41',NULL,'draft',NULL,1,'2026-07-11 06:55:19','2026-07-11 06:55:19'),
(7272,534,1,10,NULL,NULL,1,1,42,23.3000,'42',NULL,'draft',NULL,1,'2026-07-11 06:55:19','2026-07-11 06:55:19'),
(7273,534,1,10,NULL,NULL,1,1,43,23.2000,'43',NULL,'draft',NULL,1,'2026-07-11 06:55:19','2026-07-11 06:55:19'),
(7274,534,1,10,NULL,NULL,1,1,44,24.0000,'44',NULL,'draft',NULL,1,'2026-07-11 06:55:19','2026-07-11 06:55:19'),
(7275,534,1,10,NULL,NULL,1,1,45,16.7000,'45',NULL,'draft',NULL,1,'2026-07-11 06:55:19','2026-07-11 06:55:19'),
(7276,534,1,10,NULL,NULL,1,1,46,21.2000,'46',NULL,'draft',NULL,1,'2026-07-11 06:55:19','2026-07-11 06:55:19'),
(7277,534,1,10,NULL,NULL,1,1,47,24.3000,'47',NULL,'draft',NULL,1,'2026-07-11 06:55:19','2026-07-11 06:55:19'),
(7278,534,1,10,NULL,NULL,1,1,48,24.1000,'48',NULL,'draft',NULL,1,'2026-07-11 06:55:19','2026-07-11 06:55:19'),
(7279,534,1,10,NULL,NULL,1,1,49,23.2000,'49',NULL,'draft',NULL,1,'2026-07-11 06:55:19','2026-07-11 06:55:19'),
(7280,534,1,10,NULL,NULL,1,1,50,23.8000,'50',NULL,'draft',NULL,1,'2026-07-11 06:55:19','2026-07-11 06:55:19'),
(7281,534,1,10,NULL,NULL,1,1,51,24.1000,'51',NULL,'draft',NULL,1,'2026-07-11 06:55:19','2026-07-11 06:55:19'),
(7282,534,1,10,NULL,NULL,1,1,52,24.1000,'52',NULL,'draft',NULL,1,'2026-07-11 06:55:19','2026-07-11 06:55:19'),
(7283,534,1,10,NULL,NULL,1,1,53,23.7000,'53',NULL,'draft',NULL,1,'2026-07-11 06:55:19','2026-07-11 06:55:19'),
(7284,534,1,10,NULL,NULL,1,1,54,23.9000,'54',NULL,'draft',NULL,1,'2026-07-11 06:55:19','2026-07-11 06:55:19'),
(7285,534,1,10,NULL,NULL,1,1,55,24.3000,'55',NULL,'draft',NULL,1,'2026-07-11 06:55:19','2026-07-11 06:55:19'),
(7286,535,1,10,NULL,NULL,1,1,1,20.5000,'56',NULL,'draft',NULL,1,'2026-07-11 06:55:19','2026-07-11 06:55:19'),
(7287,535,1,10,NULL,NULL,1,1,2,20.6000,'57',NULL,'draft',NULL,1,'2026-07-11 06:55:19','2026-07-11 06:55:19'),
(7288,535,1,10,NULL,NULL,1,1,3,17.0000,'58',NULL,'draft',NULL,1,'2026-07-11 06:55:19','2026-07-11 06:55:19'),
(7289,536,1,10,NULL,NULL,1,1,1,22.3000,'59',NULL,'draft',NULL,1,'2026-07-11 06:55:19','2026-07-11 06:55:19'),
(7290,536,1,10,NULL,NULL,1,1,2,23.9000,'60',NULL,'draft',NULL,1,'2026-07-11 06:55:19','2026-07-11 06:55:19'),
(7291,536,1,10,NULL,NULL,1,1,3,23.7000,'61',NULL,'draft',NULL,1,'2026-07-11 06:55:19','2026-07-11 06:55:19'),
(7292,537,1,10,NULL,NULL,1,1,1,23.1000,'62',NULL,'draft',NULL,1,'2026-07-11 06:55:19','2026-07-11 06:55:19'),
(7293,537,1,10,NULL,NULL,1,1,2,26.5000,'63',NULL,'draft',NULL,1,'2026-07-11 06:55:19','2026-07-11 06:55:19'),
(7294,537,1,10,NULL,NULL,1,1,3,22.7000,'64',NULL,'draft',NULL,1,'2026-07-11 06:55:19','2026-07-11 06:55:19'),
(7295,537,1,10,NULL,NULL,1,1,4,22.6000,'65',NULL,'draft',NULL,1,'2026-07-11 06:55:19','2026-07-11 06:55:19'),
(7296,537,1,10,NULL,NULL,1,1,5,26.0000,'66',NULL,'draft',NULL,1,'2026-07-11 06:55:20','2026-07-11 06:55:20'),
(7297,537,1,10,NULL,NULL,1,1,6,24.6000,'67',NULL,'draft',NULL,1,'2026-07-11 06:55:20','2026-07-11 06:55:20'),
(7298,537,1,10,NULL,NULL,1,1,7,23.6000,'68',NULL,'draft',NULL,1,'2026-07-11 06:55:20','2026-07-11 06:55:20'),
(7299,538,1,10,NULL,NULL,1,1,1,23.2000,'69',NULL,'draft',NULL,1,'2026-07-11 06:55:20','2026-07-11 06:55:20'),
(7300,538,1,10,NULL,NULL,1,1,2,21.7000,'70',NULL,'draft',NULL,1,'2026-07-11 06:55:20','2026-07-11 06:55:20'),
(7301,538,1,10,NULL,NULL,1,1,3,23.6000,'71',NULL,'draft',NULL,1,'2026-07-11 06:55:20','2026-07-11 06:55:20'),
(7302,538,1,10,NULL,NULL,1,1,4,24.0000,'72',NULL,'draft',NULL,1,'2026-07-11 06:55:20','2026-07-11 06:55:20'),
(7303,538,1,10,NULL,NULL,1,1,5,24.2000,'73',NULL,'draft',NULL,1,'2026-07-11 06:55:20','2026-07-11 06:55:20'),
(7304,538,1,10,NULL,NULL,1,1,6,23.5000,'74',NULL,'draft',NULL,1,'2026-07-11 06:55:20','2026-07-11 06:55:20'),
(7305,538,1,10,NULL,NULL,1,1,7,24.2000,'75',NULL,'draft',NULL,1,'2026-07-11 06:55:20','2026-07-11 06:55:20'),
(7306,538,1,10,NULL,NULL,1,1,8,22.6000,'76',NULL,'draft',NULL,1,'2026-07-11 06:55:20','2026-07-11 06:55:20'),
(7307,538,1,10,NULL,NULL,1,1,9,23.4000,'77',NULL,'draft',NULL,1,'2026-07-11 06:55:20','2026-07-11 06:55:20'),
(7308,538,1,10,NULL,NULL,1,1,10,22.8000,'78',NULL,'draft',NULL,1,'2026-07-11 06:55:20','2026-07-11 06:55:20'),
(7309,538,1,10,NULL,NULL,1,1,11,24.2000,'79',NULL,'draft',NULL,1,'2026-07-11 06:55:20','2026-07-11 06:55:20'),
(7310,538,1,10,NULL,NULL,1,1,12,22.9000,'80',NULL,'draft',NULL,1,'2026-07-11 06:55:20','2026-07-11 06:55:20'),
(7311,538,1,10,NULL,NULL,1,1,13,24.6000,'81',NULL,'draft',NULL,1,'2026-07-11 06:55:20','2026-07-11 06:55:20'),
(7312,538,1,10,NULL,NULL,1,1,14,24.2000,'82',NULL,'draft',NULL,1,'2026-07-11 06:55:20','2026-07-11 06:55:20'),
(7313,538,1,10,NULL,NULL,1,1,15,23.5000,'83',NULL,'draft',NULL,1,'2026-07-11 06:55:20','2026-07-11 06:55:20'),
(7314,538,1,10,NULL,NULL,1,1,16,17.9000,'84',NULL,'draft',NULL,1,'2026-07-11 06:55:20','2026-07-11 06:55:20'),
(7315,538,1,10,NULL,NULL,1,1,17,18.4000,'85',NULL,'draft',NULL,1,'2026-07-11 06:55:20','2026-07-11 06:55:20'),
(7316,538,1,10,NULL,NULL,1,1,18,24.2000,'90',NULL,'draft',NULL,1,'2026-07-11 06:55:20','2026-07-11 06:55:20'),
(7317,539,1,10,NULL,NULL,1,1,1,24.0000,'86',NULL,'draft',NULL,1,'2026-07-11 06:55:20','2026-07-11 06:55:20'),
(7318,539,1,10,NULL,NULL,1,1,2,23.3000,'99',NULL,'draft',NULL,1,'2026-07-11 06:55:20','2026-07-11 06:55:20'),
(7319,539,1,10,NULL,NULL,1,1,3,23.4000,'100',NULL,'draft',NULL,1,'2026-07-11 06:55:20','2026-07-11 06:55:20'),
(7320,539,1,10,NULL,NULL,1,1,4,20.7000,'101',NULL,'draft',NULL,1,'2026-07-11 06:55:20','2026-07-11 06:55:20'),
(7321,539,1,10,NULL,NULL,1,1,5,21.3000,'102',NULL,'draft',NULL,1,'2026-07-11 06:55:20','2026-07-11 06:55:20'),
(7322,539,1,10,NULL,NULL,1,1,6,21.6000,'103',NULL,'draft',NULL,1,'2026-07-11 06:55:20','2026-07-11 06:55:20'),
(7323,540,1,10,NULL,NULL,1,1,1,23.4000,'87',NULL,'draft',NULL,1,'2026-07-11 06:55:20','2026-07-11 06:55:20'),
(7324,540,1,10,NULL,NULL,1,1,2,19.6000,'88',NULL,'draft',NULL,1,'2026-07-11 06:55:20','2026-07-11 06:55:20'),
(7325,540,1,10,NULL,NULL,1,1,3,23.2000,'89',NULL,'draft',NULL,1,'2026-07-11 06:55:20','2026-07-11 06:55:20'),
(7326,541,1,10,NULL,NULL,1,1,1,20.4000,'91',NULL,'draft',NULL,1,'2026-07-11 06:55:20','2026-07-11 06:55:20'),
(7327,541,1,10,NULL,NULL,1,1,2,23.7000,'92',NULL,'draft',NULL,1,'2026-07-11 06:55:20','2026-07-11 06:55:20'),
(7328,541,1,10,NULL,NULL,1,1,3,24.9000,'93',NULL,'draft',NULL,1,'2026-07-11 06:55:20','2026-07-11 06:55:20'),
(7329,542,1,10,NULL,NULL,1,1,1,23.5000,'94',NULL,'draft',NULL,1,'2026-07-11 06:55:20','2026-07-11 06:55:20'),
(7330,542,1,10,NULL,NULL,1,1,2,23.0000,'95',NULL,'draft',NULL,1,'2026-07-11 06:55:20','2026-07-11 06:55:20'),
(7331,542,1,10,NULL,NULL,1,1,3,23.9000,'96',NULL,'draft',NULL,1,'2026-07-11 06:55:20','2026-07-11 06:55:20'),
(7332,542,1,10,NULL,NULL,1,1,4,23.8000,'97',NULL,'draft',NULL,1,'2026-07-11 06:55:20','2026-07-11 06:55:20'),
(7333,542,1,10,NULL,NULL,1,1,5,18.6000,'98',NULL,'draft',NULL,1,'2026-07-11 06:55:20','2026-07-11 06:55:20'),
(7334,543,1,10,NULL,NULL,1,1,1,25.4000,'104',NULL,'draft',NULL,1,'2026-07-11 06:55:20','2026-07-11 06:55:20'),
(7335,543,1,10,NULL,NULL,1,1,2,25.9000,'105',NULL,'draft',NULL,1,'2026-07-11 06:55:20','2026-07-11 06:55:20'),
(7336,543,1,10,NULL,NULL,1,1,3,23.1000,'106',NULL,'draft',NULL,1,'2026-07-11 06:55:20','2026-07-11 06:55:20'),
(7337,543,1,10,NULL,NULL,1,1,4,24.1000,'107',NULL,'draft',NULL,1,'2026-07-11 06:55:20','2026-07-11 06:55:20'),
(7338,543,1,10,NULL,NULL,1,1,5,20.6000,'108',NULL,'draft',NULL,1,'2026-07-11 06:55:20','2026-07-11 06:55:20'),
(7339,543,1,10,NULL,NULL,1,1,6,25.2000,'109',NULL,'draft',NULL,1,'2026-07-11 06:55:20','2026-07-11 06:55:20'),
(7340,543,1,10,NULL,NULL,1,1,7,24.4000,'110',NULL,'draft',NULL,1,'2026-07-11 06:55:20','2026-07-11 06:55:20'),
(7341,543,1,10,NULL,NULL,1,1,8,25.1000,'111',NULL,'draft',NULL,1,'2026-07-11 06:55:20','2026-07-11 06:55:20'),
(7342,543,1,10,NULL,NULL,1,1,9,26.4000,'112',NULL,'draft',NULL,1,'2026-07-11 06:55:20','2026-07-11 06:55:20'),
(7343,543,1,10,NULL,NULL,1,1,10,25.0000,'113',NULL,'draft',NULL,1,'2026-07-11 06:55:20','2026-07-11 06:55:20'),
(7344,543,1,10,NULL,NULL,1,1,11,24.0000,'114',NULL,'draft',NULL,1,'2026-07-11 06:55:20','2026-07-11 06:55:20'),
(7345,543,1,10,NULL,NULL,1,1,12,24.2000,'115',NULL,'draft',NULL,1,'2026-07-11 06:55:20','2026-07-11 06:55:20'),
(7346,543,1,10,NULL,NULL,1,1,13,19.5000,'116',NULL,'draft',NULL,1,'2026-07-11 06:55:20','2026-07-11 06:55:20'),
(7347,544,1,10,NULL,NULL,1,1,1,19.5000,'117',NULL,'draft',NULL,1,'2026-07-11 06:55:20','2026-07-11 06:55:20'),
(7348,544,1,10,NULL,NULL,1,1,2,24.0000,'118',NULL,'draft',NULL,1,'2026-07-11 06:55:20','2026-07-11 06:55:20'),
(7349,544,1,10,NULL,NULL,1,1,3,21.3000,'119',NULL,'draft',NULL,1,'2026-07-11 06:55:20','2026-07-11 06:55:20'),
(7350,545,1,10,NULL,NULL,1,1,1,19.3000,'120',NULL,'draft',NULL,1,'2026-07-11 06:55:20','2026-07-11 06:55:20'),
(7351,545,1,10,NULL,NULL,1,1,2,23.3000,'121',NULL,'draft',NULL,1,'2026-07-11 06:55:20','2026-07-11 06:55:20'),
(7352,545,1,10,NULL,NULL,1,1,3,17.1000,'122',NULL,'draft',NULL,1,'2026-07-11 06:55:20','2026-07-11 06:55:20'),
(7353,546,1,10,NULL,NULL,1,1,1,23.4000,'123',NULL,'draft',NULL,1,'2026-07-11 06:55:20','2026-07-11 06:55:20'),
(7354,546,1,10,NULL,NULL,1,1,2,18.5000,'124',NULL,'draft',NULL,1,'2026-07-11 06:55:20','2026-07-11 06:55:20'),
(7355,546,1,10,NULL,NULL,1,1,3,23.2000,'125',NULL,'draft',NULL,1,'2026-07-11 06:55:20','2026-07-11 06:55:20'),
(7356,546,1,10,NULL,NULL,1,1,4,24.0000,'126',NULL,'draft',NULL,1,'2026-07-11 06:55:20','2026-07-11 06:55:20'),
(7357,546,1,10,NULL,NULL,1,1,5,21.6000,'127',NULL,'draft',NULL,1,'2026-07-11 06:55:20','2026-07-11 06:55:20'),
(7358,546,1,10,NULL,NULL,1,1,6,21.9000,'128',NULL,'draft',NULL,1,'2026-07-11 06:55:20','2026-07-11 06:55:20'),
(7359,546,1,10,NULL,NULL,1,1,7,21.3000,'129',NULL,'draft',NULL,1,'2026-07-11 06:55:20','2026-07-11 06:55:20'),
(7360,547,1,10,NULL,NULL,1,1,1,24.5000,'130',NULL,'draft',NULL,1,'2026-07-11 06:55:20','2026-07-11 06:55:20'),
(7361,547,1,10,NULL,NULL,1,1,2,24.6000,'131',NULL,'draft',NULL,1,'2026-07-11 06:55:20','2026-07-11 06:55:20'),
(7362,547,1,10,NULL,NULL,1,1,3,23.8000,'132',NULL,'draft',NULL,1,'2026-07-11 06:55:20','2026-07-11 06:55:20'),
(7363,547,1,10,NULL,NULL,1,1,4,22.9000,'133',NULL,'draft',NULL,1,'2026-07-11 06:55:20','2026-07-11 06:55:20'),
(7364,547,1,10,NULL,NULL,1,1,5,23.6000,'134',NULL,'draft',NULL,1,'2026-07-11 06:55:20','2026-07-11 06:55:20'),
(7365,547,1,10,NULL,NULL,1,1,6,24.4000,'135',NULL,'draft',NULL,1,'2026-07-11 06:55:20','2026-07-11 06:55:20'),
(7366,547,1,10,NULL,NULL,1,1,7,23.7000,'136',NULL,'draft',NULL,1,'2026-07-11 06:55:20','2026-07-11 06:55:20'),
(7367,547,1,10,NULL,NULL,1,1,8,24.5000,'137',NULL,'draft',NULL,1,'2026-07-11 06:55:20','2026-07-11 06:55:20'),
(7368,547,1,10,NULL,NULL,1,1,9,23.4000,'138',NULL,'draft',NULL,1,'2026-07-11 06:55:20','2026-07-11 06:55:20'),
(7369,547,1,10,NULL,NULL,1,1,10,23.2000,'139',NULL,'draft',NULL,1,'2026-07-11 06:55:20','2026-07-11 06:55:20'),
(7370,547,1,10,NULL,NULL,1,1,11,23.7000,'140',NULL,'draft',NULL,1,'2026-07-11 06:55:20','2026-07-11 06:55:20'),
(7371,547,1,10,NULL,NULL,1,1,12,21.4000,'141',NULL,'draft',NULL,1,'2026-07-11 06:55:20','2026-07-11 06:55:20'),
(7372,548,1,10,NULL,NULL,1,1,1,23.2000,'142',NULL,'draft',NULL,1,'2026-07-11 06:55:20','2026-07-11 06:55:20'),
(7373,548,1,10,NULL,NULL,1,1,2,23.4000,'143',NULL,'draft',NULL,1,'2026-07-11 06:55:20','2026-07-11 06:55:20'),
(7374,548,1,10,NULL,NULL,1,1,3,22.5000,'144',NULL,'draft',NULL,1,'2026-07-11 06:55:20','2026-07-11 06:55:20'),
(7375,548,1,10,NULL,NULL,1,1,4,23.8000,'145',NULL,'draft',NULL,1,'2026-07-11 06:55:20','2026-07-11 06:55:20'),
(7376,548,1,10,NULL,NULL,1,1,5,22.2000,'146',NULL,'draft',NULL,1,'2026-07-11 06:55:20','2026-07-11 06:55:20'),
(7377,548,1,10,NULL,NULL,1,1,6,20.4000,'147',NULL,'draft',NULL,1,'2026-07-11 06:55:20','2026-07-11 06:55:20'),
(7378,548,1,10,NULL,NULL,1,1,7,24.0000,'148',NULL,'draft',NULL,1,'2026-07-11 06:55:20','2026-07-11 06:55:20'),
(7379,548,1,10,NULL,NULL,1,1,8,22.6000,'149',NULL,'draft',NULL,1,'2026-07-11 06:55:20','2026-07-11 06:55:20'),
(7380,548,1,10,NULL,NULL,1,1,9,22.6000,'150',NULL,'draft',NULL,1,'2026-07-11 06:55:21','2026-07-11 06:55:21'),
(7381,548,1,10,NULL,NULL,1,1,10,23.4000,'151',NULL,'draft',NULL,1,'2026-07-11 06:55:21','2026-07-11 06:55:21'),
(7382,548,1,10,NULL,NULL,1,1,11,23.7000,'152',NULL,'draft',NULL,1,'2026-07-11 06:55:21','2026-07-11 06:55:21'),
(7383,548,1,10,NULL,NULL,1,1,12,23.4000,'153',NULL,'draft',NULL,1,'2026-07-11 06:55:21','2026-07-11 06:55:21'),
(7384,549,1,10,NULL,NULL,1,1,1,20.4000,'154',NULL,'draft',NULL,1,'2026-07-11 06:55:21','2026-07-11 06:55:21'),
(7385,549,1,10,NULL,NULL,1,1,2,21.3000,'155',NULL,'draft',NULL,1,'2026-07-11 06:55:21','2026-07-11 06:55:21'),
(7386,549,1,10,NULL,NULL,1,1,3,21.9000,'156',NULL,'draft',NULL,1,'2026-07-11 06:55:21','2026-07-11 06:55:21'),
(7387,550,1,10,NULL,NULL,1,1,1,14.1000,'157',NULL,'draft',NULL,1,'2026-07-11 06:55:21','2026-07-11 06:55:21'),
(7388,550,1,10,NULL,NULL,1,1,2,23.8000,'158',NULL,'draft',NULL,1,'2026-07-11 06:55:21','2026-07-11 06:55:21'),
(7389,550,1,10,NULL,NULL,1,1,3,21.3000,'159',NULL,'draft',NULL,1,'2026-07-11 06:55:21','2026-07-11 06:55:21'),
(7390,550,1,10,NULL,NULL,1,1,4,22.8000,'160',NULL,'draft',NULL,1,'2026-07-11 06:55:21','2026-07-11 06:55:21'),
(7391,550,1,10,NULL,NULL,1,1,5,20.4000,'161',NULL,'draft',NULL,1,'2026-07-11 06:55:21','2026-07-11 06:55:21'),
(7392,550,1,10,NULL,NULL,1,1,6,21.1000,'162',NULL,'draft',NULL,1,'2026-07-11 06:55:21','2026-07-11 06:55:21'),
(7393,550,1,10,NULL,NULL,1,1,7,22.7000,'163',NULL,'draft',NULL,1,'2026-07-11 06:55:21','2026-07-11 06:55:21'),
(7394,550,1,10,NULL,NULL,1,1,8,25.0000,'164',NULL,'draft',NULL,1,'2026-07-11 06:55:21','2026-07-11 06:55:21'),
(7395,550,1,10,NULL,NULL,1,1,9,26.4000,'165',NULL,'draft',NULL,1,'2026-07-11 06:55:21','2026-07-11 06:55:21'),
(7396,550,1,10,NULL,NULL,1,1,10,25.0000,'166',NULL,'draft',NULL,1,'2026-07-11 06:55:21','2026-07-11 06:55:21'),
(7397,551,1,10,NULL,NULL,1,1,1,23.9000,'167',NULL,'draft',NULL,1,'2026-07-11 06:55:21','2026-07-11 06:55:21'),
(7398,551,1,10,NULL,NULL,1,1,2,22.3000,'168',NULL,'draft',NULL,1,'2026-07-11 06:55:21','2026-07-11 06:55:21'),
(7399,551,1,10,NULL,NULL,1,1,3,22.1000,'169',NULL,'draft',NULL,1,'2026-07-11 06:55:21','2026-07-11 06:55:21'),
(7400,552,1,10,NULL,NULL,1,1,1,22.6000,'170',NULL,'draft',NULL,1,'2026-07-11 06:55:21','2026-07-11 06:55:21'),
(7401,552,1,10,NULL,NULL,1,1,2,23.5000,'171',NULL,'draft',NULL,1,'2026-07-11 06:55:21','2026-07-11 06:55:21'),
(7402,552,1,10,NULL,NULL,1,1,3,27.8000,'172',NULL,'draft',NULL,1,'2026-07-11 06:55:21','2026-07-11 06:55:21'),
(7403,553,1,10,NULL,NULL,1,1,1,23.5000,'173',NULL,'draft',NULL,1,'2026-07-11 06:55:21','2026-07-11 06:55:21'),
(7404,553,1,10,NULL,NULL,1,1,2,25.1000,'174',NULL,'draft',NULL,1,'2026-07-11 06:55:21','2026-07-11 06:55:21'),
(7405,553,1,10,NULL,NULL,1,1,3,25.9000,'175',NULL,'draft',NULL,1,'2026-07-11 06:55:21','2026-07-11 06:55:21'),
(7406,553,1,10,NULL,NULL,1,1,4,23.7000,'176',NULL,'draft',NULL,1,'2026-07-11 06:55:21','2026-07-11 06:55:21'),
(7407,553,1,10,NULL,NULL,1,1,5,26.2000,'177',NULL,'draft',NULL,1,'2026-07-11 06:55:21','2026-07-11 06:55:21'),
(7408,553,1,10,NULL,NULL,1,1,6,23.0000,'178',NULL,'draft',NULL,1,'2026-07-11 06:55:21','2026-07-11 06:55:21'),
(7409,553,1,10,NULL,NULL,1,1,7,25.8000,'179',NULL,'draft',NULL,1,'2026-07-11 06:55:21','2026-07-11 06:55:21'),
(7410,553,1,10,NULL,NULL,1,1,8,32.4000,'180',NULL,'draft',NULL,1,'2026-07-11 06:55:21','2026-07-11 06:55:21'),
(7411,553,1,10,NULL,NULL,1,1,9,24.6000,'181',NULL,'draft',NULL,1,'2026-07-11 06:55:21','2026-07-11 06:55:21'),
(7412,553,1,10,NULL,NULL,1,1,10,26.6000,'182',NULL,'draft',NULL,1,'2026-07-11 06:55:21','2026-07-11 06:55:21'),
(7413,553,1,10,NULL,NULL,1,1,11,24.0000,'183',NULL,'draft',NULL,1,'2026-07-11 06:55:21','2026-07-11 06:55:21'),
(7414,554,1,10,NULL,NULL,1,1,1,23.1000,'184',NULL,'draft',NULL,1,'2026-07-11 06:55:21','2026-07-11 06:55:21'),
(7415,554,1,10,NULL,NULL,1,1,2,22.4000,'185',NULL,'draft',NULL,1,'2026-07-11 06:55:21','2026-07-11 06:55:21'),
(7416,554,1,10,NULL,NULL,1,1,3,24.1000,'186',NULL,'draft',NULL,1,'2026-07-11 06:55:21','2026-07-11 06:55:21'),
(7417,554,1,10,NULL,NULL,1,1,4,21.9000,'187',NULL,'draft',NULL,1,'2026-07-11 06:55:21','2026-07-11 06:55:21'),
(7418,554,1,10,NULL,NULL,1,1,5,23.2000,'188',NULL,'draft',NULL,1,'2026-07-11 06:55:21','2026-07-11 06:55:21'),
(7419,554,1,10,NULL,NULL,1,1,6,17.4000,'189',NULL,'draft',NULL,1,'2026-07-11 06:55:21','2026-07-11 06:55:21'),
(7420,555,1,10,NULL,NULL,1,1,1,22.9000,'190',NULL,'draft',NULL,1,'2026-07-11 06:55:21','2026-07-11 06:55:21'),
(7421,555,1,10,NULL,NULL,1,1,2,22.6000,'191',NULL,'draft',NULL,1,'2026-07-11 06:55:21','2026-07-11 06:55:21'),
(7422,555,1,10,NULL,NULL,1,1,3,21.1000,'192',NULL,'draft',NULL,1,'2026-07-11 06:55:21','2026-07-11 06:55:21'),
(7423,556,1,10,NULL,NULL,1,1,1,22.4000,'193',NULL,'draft',NULL,1,'2026-07-11 06:55:21','2026-07-11 06:55:21'),
(7424,556,1,10,NULL,NULL,1,1,2,24.6000,'194',NULL,'draft',NULL,1,'2026-07-11 06:55:21','2026-07-11 06:55:21'),
(7425,556,1,10,NULL,NULL,1,1,3,20.7000,'195',NULL,'draft',NULL,1,'2026-07-11 06:55:21','2026-07-11 06:55:21'),
(7426,557,1,10,NULL,NULL,1,1,1,23.6000,'196',NULL,'draft',NULL,1,'2026-07-11 06:55:21','2026-07-11 06:55:21'),
(7427,557,1,10,NULL,NULL,1,1,2,22.1000,'197',NULL,'draft',NULL,1,'2026-07-11 06:55:21','2026-07-11 06:55:21'),
(7428,557,1,10,NULL,NULL,1,1,3,23.2000,'198',NULL,'draft',NULL,1,'2026-07-11 06:55:21','2026-07-11 06:55:21'),
(7429,557,1,10,NULL,NULL,1,1,4,21.8000,'199',NULL,'draft',NULL,1,'2026-07-11 06:55:21','2026-07-11 06:55:21'),
(7430,557,1,10,NULL,NULL,1,1,5,22.4000,'200',NULL,'draft',NULL,1,'2026-07-11 06:55:21','2026-07-11 06:55:21'),
(7431,557,1,10,NULL,NULL,1,1,6,23.6000,'201',NULL,'draft',NULL,1,'2026-07-11 06:55:21','2026-07-11 06:55:21'),
(7432,557,1,10,NULL,NULL,1,1,7,10.2000,'202',NULL,'draft',NULL,1,'2026-07-11 06:55:21','2026-07-11 06:55:21'),
(7433,558,1,10,NULL,NULL,1,1,1,23.7000,'203',NULL,'draft',NULL,1,'2026-07-11 06:55:21','2026-07-11 06:55:21'),
(7434,558,1,10,NULL,NULL,1,1,2,22.3000,'204',NULL,'draft',NULL,1,'2026-07-11 06:55:21','2026-07-11 06:55:21'),
(7435,558,1,10,NULL,NULL,1,1,3,22.3000,'205',NULL,'draft',NULL,1,'2026-07-11 06:55:21','2026-07-11 06:55:21'),
(7436,558,1,10,NULL,NULL,1,1,4,22.2000,'206',NULL,'draft',NULL,1,'2026-07-11 06:55:21','2026-07-11 06:55:21'),
(7437,558,1,10,NULL,NULL,1,1,5,21.6000,'207',NULL,'draft',NULL,1,'2026-07-11 06:55:21','2026-07-11 06:55:21'),
(7438,558,1,10,NULL,NULL,1,1,6,18.4000,'208',NULL,'draft',NULL,1,'2026-07-11 06:55:21','2026-07-11 06:55:21'),
(7439,559,1,10,NULL,NULL,1,1,1,24.3000,'209',NULL,'draft',NULL,1,'2026-07-11 06:55:21','2026-07-11 06:55:21'),
(7440,559,1,10,NULL,NULL,1,1,2,23.4000,'210',NULL,'draft',NULL,1,'2026-07-11 06:55:21','2026-07-11 06:55:21'),
(7441,559,1,10,NULL,NULL,1,1,3,15.8000,'211',NULL,'draft',NULL,1,'2026-07-11 06:55:21','2026-07-11 06:55:21'),
(7442,560,1,10,NULL,NULL,1,1,1,20.0000,'212',NULL,'draft',NULL,1,'2026-07-11 06:55:21','2026-07-11 06:55:21'),
(7443,560,1,10,NULL,NULL,1,1,2,21.8000,'213',NULL,'draft',NULL,1,'2026-07-11 06:55:21','2026-07-11 06:55:21'),
(7444,561,1,10,NULL,NULL,1,1,1,19.4000,'214',NULL,'draft',NULL,1,'2026-07-11 06:55:21','2026-07-11 06:55:21'),
(7445,561,1,10,NULL,NULL,1,1,2,23.3000,'215',NULL,'draft',NULL,1,'2026-07-11 06:55:21','2026-07-11 06:55:21'),
(7446,561,1,10,NULL,NULL,1,1,3,24.4000,'216',NULL,'draft',NULL,1,'2026-07-11 06:55:21','2026-07-11 06:55:21'),
(7447,562,1,10,NULL,NULL,1,1,1,24.0000,'217',NULL,'draft',NULL,1,'2026-07-11 06:55:21','2026-07-11 06:55:21'),
(7448,562,1,10,NULL,NULL,1,1,2,24.0000,'218',NULL,'draft',NULL,1,'2026-07-11 06:55:21','2026-07-11 06:55:21'),
(7449,562,1,10,NULL,NULL,1,1,3,18.2000,'219',NULL,'draft',NULL,1,'2026-07-11 06:55:21','2026-07-11 06:55:21'),
(7450,562,1,10,NULL,NULL,1,1,4,23.5000,'220',NULL,'draft',NULL,1,'2026-07-11 06:55:21','2026-07-11 06:55:21'),
(7451,563,1,10,NULL,NULL,1,1,1,24.4000,'221',NULL,'draft',NULL,1,'2026-07-11 06:55:21','2026-07-11 06:55:21'),
(7452,563,1,10,NULL,NULL,1,1,2,19.0000,'222',NULL,'draft',NULL,1,'2026-07-11 06:55:21','2026-07-11 06:55:21'),
(7453,563,1,10,NULL,NULL,1,1,3,24.0000,'223',NULL,'draft',NULL,1,'2026-07-11 06:55:21','2026-07-11 06:55:21'),
(7454,563,1,10,NULL,NULL,1,1,4,24.4000,'224',NULL,'draft',NULL,1,'2026-07-11 06:55:21','2026-07-11 06:55:21'),
(7455,563,1,10,NULL,NULL,1,1,5,23.7000,'225',NULL,'draft',NULL,1,'2026-07-11 06:55:21','2026-07-11 06:55:21'),
(7456,563,1,10,NULL,NULL,1,1,6,22.7000,'226',NULL,'draft',NULL,1,'2026-07-11 06:55:21','2026-07-11 06:55:21'),
(7457,563,1,10,NULL,NULL,1,1,7,24.2000,'227',NULL,'draft',NULL,1,'2026-07-11 06:55:21','2026-07-11 06:55:21'),
(7458,563,1,10,NULL,NULL,1,1,8,24.1000,'228',NULL,'draft',NULL,1,'2026-07-11 06:55:21','2026-07-11 06:55:21'),
(7459,563,1,10,NULL,NULL,1,1,9,24.0000,'229',NULL,'draft',NULL,1,'2026-07-11 06:55:21','2026-07-11 06:55:21'),
(7460,564,1,10,NULL,NULL,1,1,1,21.0000,'230',NULL,'draft',NULL,1,'2026-07-11 06:55:21','2026-07-11 06:55:21'),
(7461,564,1,10,NULL,NULL,1,1,2,24.8000,'231',NULL,'draft',NULL,1,'2026-07-11 06:55:21','2026-07-11 06:55:21'),
(7462,564,1,10,NULL,NULL,1,1,3,25.2000,'232',NULL,'draft',NULL,1,'2026-07-11 06:55:21','2026-07-11 06:55:21'),
(7463,564,1,10,NULL,NULL,1,1,4,25.0000,'233',NULL,'draft',NULL,1,'2026-07-11 06:55:21','2026-07-11 06:55:21'),
(7464,564,1,10,NULL,NULL,1,1,5,24.5000,'23424.5',NULL,'draft',NULL,1,'2026-07-11 06:55:21','2026-07-11 06:55:21'),
(7465,564,1,10,NULL,NULL,1,1,6,24.5000,'235',NULL,'draft',NULL,1,'2026-07-11 06:55:21','2026-07-11 06:55:21'),
(7466,565,1,10,NULL,NULL,1,1,1,22.4000,'236',NULL,'draft',NULL,1,'2026-07-11 06:55:21','2026-07-11 06:55:21'),
(7467,565,1,10,NULL,NULL,1,1,2,23.4000,'237',NULL,'draft',NULL,1,'2026-07-11 06:55:21','2026-07-11 06:55:21'),
(7468,565,1,10,NULL,NULL,1,1,3,22.4000,'238',NULL,'draft',NULL,1,'2026-07-11 06:55:21','2026-07-11 06:55:21'),
(7469,566,1,10,NULL,NULL,1,1,1,24.0000,'240',NULL,'draft',NULL,1,'2026-07-11 06:55:21','2026-07-11 06:55:21'),
(7470,566,1,10,NULL,NULL,1,1,2,19.1000,'241',NULL,'draft',NULL,1,'2026-07-11 06:55:21','2026-07-11 06:55:21'),
(7471,567,1,10,NULL,NULL,1,1,1,26.7000,'242',NULL,'draft',NULL,1,'2026-07-11 06:55:21','2026-07-11 06:55:21'),
(7472,568,1,10,NULL,NULL,1,1,1,19.7000,'243',NULL,'draft',NULL,1,'2026-07-11 06:55:21','2026-07-11 06:55:21'),
(7473,569,1,10,NULL,NULL,1,1,1,31.4000,'243',NULL,'draft',NULL,1,'2026-07-11 06:55:21','2026-07-11 06:55:21'),
(7474,570,1,10,NULL,NULL,1,1,1,22.6000,'245',NULL,'draft',NULL,1,'2026-07-11 06:55:21','2026-07-11 06:55:21'),
(7475,570,1,10,NULL,NULL,1,1,2,21.1000,'246',NULL,'draft',NULL,1,'2026-07-11 06:55:21','2026-07-11 06:55:21'),
(7476,570,1,10,NULL,NULL,1,1,3,15.9000,'247',NULL,'draft',NULL,1,'2026-07-11 06:55:21','2026-07-11 06:55:21'),
(7477,571,1,10,NULL,NULL,1,1,1,23.1000,'248',NULL,'draft',NULL,1,'2026-07-11 06:55:21','2026-07-11 06:55:21'),
(7478,571,1,10,NULL,NULL,1,1,2,15.7000,'249',NULL,'draft',NULL,1,'2026-07-11 06:55:21','2026-07-11 06:55:21'),
(7479,572,1,10,NULL,NULL,1,1,1,18.6000,'250',NULL,'draft',NULL,1,'2026-07-11 06:55:21','2026-07-11 06:55:21'),
(7480,573,1,10,NULL,NULL,1,1,1,19.0000,'251',NULL,'draft',NULL,1,'2026-07-11 06:55:21','2026-07-11 06:55:21'),
(7481,574,1,8,NULL,NULL,1,1,1,25.0000,'1',NULL,'draft',NULL,1,'2026-07-11 06:55:21','2026-07-11 06:55:21'),
(7482,574,1,8,NULL,NULL,1,1,2,25.1000,'2',NULL,'draft',NULL,1,'2026-07-11 06:55:21','2026-07-11 06:55:21'),
(7483,574,1,8,NULL,NULL,1,1,3,24.5000,'3',NULL,'draft',NULL,1,'2026-07-11 06:55:21','2026-07-11 06:55:21'),
(7484,574,1,8,NULL,NULL,1,1,4,26.1000,'4',NULL,'draft',NULL,1,'2026-07-11 06:55:21','2026-07-11 06:55:21'),
(7485,574,1,8,NULL,NULL,1,1,5,24.8000,'5',NULL,'draft',NULL,1,'2026-07-11 06:55:21','2026-07-11 06:55:21'),
(7486,574,1,8,NULL,NULL,1,1,6,25.0000,'6',NULL,'draft',NULL,1,'2026-07-11 06:55:21','2026-07-11 06:55:21'),
(7487,574,1,8,NULL,NULL,1,1,7,25.0000,'7',NULL,'draft',NULL,1,'2026-07-11 06:55:21','2026-07-11 06:55:21'),
(7488,574,1,8,NULL,NULL,1,1,8,25.1000,'8',NULL,'draft',NULL,1,'2026-07-11 06:55:21','2026-07-11 06:55:21'),
(7489,574,1,8,NULL,NULL,1,1,9,24.3000,'9',NULL,'draft',NULL,1,'2026-07-11 06:55:21','2026-07-11 06:55:21'),
(7490,574,1,8,NULL,NULL,1,1,10,25.1000,'10',NULL,'draft',NULL,1,'2026-07-11 06:55:21','2026-07-11 06:55:21'),
(7491,574,1,8,NULL,NULL,1,1,11,23.4000,'11',NULL,'draft',NULL,1,'2026-07-11 06:55:21','2026-07-11 06:55:21'),
(7492,574,1,8,NULL,NULL,1,1,12,24.3000,'12',NULL,'draft',NULL,1,'2026-07-11 06:55:21','2026-07-11 06:55:21'),
(7493,574,1,8,NULL,NULL,1,1,13,24.6000,'13',NULL,'draft',NULL,1,'2026-07-11 06:55:21','2026-07-11 06:55:21'),
(7494,574,1,8,NULL,NULL,1,1,14,24.9000,'14',NULL,'draft',NULL,1,'2026-07-11 06:55:21','2026-07-11 06:55:21'),
(7495,574,1,8,NULL,NULL,1,1,15,24.4000,'15',NULL,'draft',NULL,1,'2026-07-11 06:55:21','2026-07-11 06:55:21'),
(7496,574,1,8,NULL,NULL,1,1,16,25.0000,'16',NULL,'draft',NULL,1,'2026-07-11 06:55:21','2026-07-11 06:55:21'),
(7497,575,1,8,NULL,NULL,1,1,1,23.9000,'17',NULL,'draft',NULL,1,'2026-07-11 06:55:21','2026-07-11 06:55:21'),
(7498,575,1,8,NULL,NULL,1,1,2,24.1000,'18',NULL,'draft',NULL,1,'2026-07-11 06:55:21','2026-07-11 06:55:21'),
(7499,575,1,8,NULL,NULL,1,1,3,23.5000,'19',NULL,'draft',NULL,1,'2026-07-11 06:55:21','2026-07-11 06:55:21'),
(7500,575,1,8,NULL,NULL,1,1,4,24.4000,'20',NULL,'draft',NULL,1,'2026-07-11 06:55:21','2026-07-11 06:55:21'),
(7501,575,1,8,NULL,NULL,1,1,5,24.3000,'21',NULL,'draft',NULL,1,'2026-07-11 06:55:21','2026-07-11 06:55:21'),
(7502,575,1,8,NULL,NULL,1,1,6,24.5000,'22',NULL,'draft',NULL,1,'2026-07-11 06:55:21','2026-07-11 06:55:21'),
(7503,575,1,8,NULL,NULL,1,1,7,24.3000,'23',NULL,'draft',NULL,1,'2026-07-11 06:55:21','2026-07-11 06:55:21'),
(7504,575,1,8,NULL,NULL,1,1,8,24.4000,'24',NULL,'draft',NULL,1,'2026-07-11 06:55:21','2026-07-11 06:55:21'),
(7505,575,1,8,NULL,NULL,1,1,9,23.6000,'25',NULL,'draft',NULL,1,'2026-07-11 06:55:21','2026-07-11 06:55:21'),
(7506,575,1,8,NULL,NULL,1,1,10,23.6000,'26',NULL,'draft',NULL,1,'2026-07-11 06:55:21','2026-07-11 06:55:21'),
(7507,575,1,8,NULL,NULL,1,1,11,18.9000,'27',NULL,'draft',NULL,1,'2026-07-11 06:55:21','2026-07-11 06:55:21'),
(7508,576,1,8,NULL,NULL,1,1,1,23.8000,'28',NULL,'draft',NULL,1,'2026-07-11 06:55:21','2026-07-11 06:55:21'),
(7509,576,1,8,NULL,NULL,1,1,2,23.4000,'29',NULL,'draft',NULL,1,'2026-07-11 06:55:21','2026-07-11 06:55:21'),
(7510,576,1,8,NULL,NULL,1,1,3,23.9000,'30',NULL,'draft',NULL,1,'2026-07-11 06:55:21','2026-07-11 06:55:21'),
(7511,576,1,8,NULL,NULL,1,1,4,24.1000,'31',NULL,'draft',NULL,1,'2026-07-11 06:55:21','2026-07-11 06:55:21'),
(7512,576,1,8,NULL,NULL,1,1,5,23.9000,'32',NULL,'draft',NULL,1,'2026-07-11 06:55:21','2026-07-11 06:55:21'),
(7513,576,1,8,NULL,NULL,1,1,6,23.8000,'33',NULL,'draft',NULL,1,'2026-07-11 06:55:21','2026-07-11 06:55:21'),
(7514,576,1,8,NULL,NULL,1,1,7,23.9000,'34',NULL,'draft',NULL,1,'2026-07-11 06:55:21','2026-07-11 06:55:21'),
(7515,576,1,8,NULL,NULL,1,1,8,24.0000,'35',NULL,'draft',NULL,1,'2026-07-11 06:55:21','2026-07-11 06:55:21'),
(7516,576,1,8,NULL,NULL,1,1,9,23.5000,'36',NULL,'draft',NULL,1,'2026-07-11 06:55:21','2026-07-11 06:55:21'),
(7517,576,1,8,NULL,NULL,1,1,10,23.9000,'37',NULL,'draft',NULL,1,'2026-07-11 06:55:21','2026-07-11 06:55:21'),
(7518,576,1,8,NULL,NULL,1,1,11,24.0000,'38',NULL,'draft',NULL,1,'2026-07-11 06:55:21','2026-07-11 06:55:21'),
(7519,576,1,8,NULL,NULL,1,1,12,24.0000,'39',NULL,'draft',NULL,1,'2026-07-11 06:55:21','2026-07-11 06:55:21'),
(7520,576,1,8,NULL,NULL,1,1,13,23.7000,'40',NULL,'draft',NULL,1,'2026-07-11 06:55:21','2026-07-11 06:55:21'),
(7521,576,1,8,NULL,NULL,1,1,14,24.0000,'41',NULL,'draft',NULL,1,'2026-07-11 06:55:21','2026-07-11 06:55:21'),
(7522,576,1,8,NULL,NULL,1,1,15,23.9000,'42',NULL,'draft',NULL,1,'2026-07-11 06:55:21','2026-07-11 06:55:21'),
(7523,576,1,8,NULL,NULL,1,1,16,24.3000,'43',NULL,'draft',NULL,1,'2026-07-11 06:55:21','2026-07-11 06:55:21'),
(7524,577,1,8,NULL,NULL,1,1,1,23.4000,'44',NULL,'draft',NULL,1,'2026-07-11 06:55:21','2026-07-11 06:55:21'),
(7525,577,1,8,NULL,NULL,1,1,2,23.9000,'45',NULL,'draft',NULL,1,'2026-07-11 06:55:21','2026-07-11 06:55:21'),
(7526,577,1,8,NULL,NULL,1,1,3,23.7000,'46',NULL,'draft',NULL,1,'2026-07-11 06:55:21','2026-07-11 06:55:21'),
(7527,577,1,8,NULL,NULL,1,1,4,24.2000,'47',NULL,'draft',NULL,1,'2026-07-11 06:55:21','2026-07-11 06:55:21'),
(7528,577,1,8,NULL,NULL,1,1,5,24.0000,'48',NULL,'draft',NULL,1,'2026-07-11 06:55:21','2026-07-11 06:55:21'),
(7529,577,1,8,NULL,NULL,1,1,6,24.7000,'49',NULL,'draft',NULL,1,'2026-07-11 06:55:21','2026-07-11 06:55:21'),
(7530,577,1,8,NULL,NULL,1,1,7,24.1000,'50',NULL,'draft',NULL,1,'2026-07-11 06:55:21','2026-07-11 06:55:21'),
(7531,577,1,8,NULL,NULL,1,1,8,24.4000,'51',NULL,'draft',NULL,1,'2026-07-11 06:55:21','2026-07-11 06:55:21'),
(7532,577,1,8,NULL,NULL,1,1,9,24.4000,'52',NULL,'draft',NULL,1,'2026-07-11 06:55:21','2026-07-11 06:55:21'),
(7533,578,1,8,NULL,NULL,1,1,1,24.2000,'53',NULL,'draft',NULL,1,'2026-07-11 06:55:21','2026-07-11 06:55:21'),
(7534,578,1,8,NULL,NULL,1,1,2,23.2000,'54',NULL,'draft',NULL,1,'2026-07-11 06:55:21','2026-07-11 06:55:21'),
(7535,578,1,8,NULL,NULL,1,1,3,24.7000,'55',NULL,'draft',NULL,1,'2026-07-11 06:55:21','2026-07-11 06:55:21'),
(7536,578,1,8,NULL,NULL,1,1,4,24.0000,'56',NULL,'draft',NULL,1,'2026-07-11 06:55:21','2026-07-11 06:55:21'),
(7537,578,1,8,NULL,NULL,1,1,5,23.2000,'57',NULL,'draft',NULL,1,'2026-07-11 06:55:21','2026-07-11 06:55:21'),
(7538,578,1,8,NULL,NULL,1,1,6,22.7000,'58',NULL,'draft',NULL,1,'2026-07-11 06:55:21','2026-07-11 06:55:21'),
(7539,578,1,8,NULL,NULL,1,1,7,22.8000,'59',NULL,'draft',NULL,1,'2026-07-11 06:55:21','2026-07-11 06:55:21'),
(7540,578,1,8,NULL,NULL,1,1,8,23.5000,'60',NULL,'draft',NULL,1,'2026-07-11 06:55:21','2026-07-11 06:55:21'),
(7541,579,1,8,NULL,NULL,1,1,1,23.7000,'61',NULL,'draft',NULL,1,'2026-07-11 06:55:21','2026-07-11 06:55:21'),
(7542,579,1,8,NULL,NULL,1,1,2,23.7000,'62',NULL,'draft',NULL,1,'2026-07-11 06:55:21','2026-07-11 06:55:21'),
(7543,579,1,8,NULL,NULL,1,1,3,23.6000,'63',NULL,'draft',NULL,1,'2026-07-11 06:55:21','2026-07-11 06:55:21'),
(7544,579,1,8,NULL,NULL,1,1,4,23.9000,'64',NULL,'draft',NULL,1,'2026-07-11 06:55:21','2026-07-11 06:55:21'),
(7545,579,1,8,NULL,NULL,1,1,5,23.8000,'65',NULL,'draft',NULL,1,'2026-07-11 06:55:21','2026-07-11 06:55:21'),
(7546,579,1,8,NULL,NULL,1,1,6,23.8000,'66',NULL,'draft',NULL,1,'2026-07-11 06:55:21','2026-07-11 06:55:21'),
(7547,579,1,8,NULL,NULL,1,1,7,23.8000,'67',NULL,'draft',NULL,1,'2026-07-11 06:55:21','2026-07-11 06:55:21'),
(7548,579,1,8,NULL,NULL,1,1,8,23.6000,'68',NULL,'draft',NULL,1,'2026-07-11 06:55:21','2026-07-11 06:55:21'),
(7549,579,1,8,NULL,NULL,1,1,9,23.9000,'69',NULL,'draft',NULL,1,'2026-07-11 06:55:21','2026-07-11 06:55:21'),
(7550,579,1,8,NULL,NULL,1,1,10,23.7000,'70',NULL,'draft',NULL,1,'2026-07-11 06:55:21','2026-07-11 06:55:21'),
(7551,579,1,8,NULL,NULL,1,1,11,24.0000,'71',NULL,'draft',NULL,1,'2026-07-11 06:55:21','2026-07-11 06:55:21'),
(7552,579,1,8,NULL,NULL,1,1,12,23.6000,'72',NULL,'draft',NULL,1,'2026-07-11 06:55:21','2026-07-11 06:55:21'),
(7553,579,1,8,NULL,NULL,1,1,13,23.8000,'73',NULL,'draft',NULL,1,'2026-07-11 06:55:21','2026-07-11 06:55:21'),
(7554,580,1,8,NULL,NULL,1,1,1,24.1000,'74',NULL,'draft',NULL,1,'2026-07-11 06:55:21','2026-07-11 06:55:21'),
(7555,580,1,8,NULL,NULL,1,1,2,23.9000,'75',NULL,'draft',NULL,1,'2026-07-11 06:55:21','2026-07-11 06:55:21'),
(7556,580,1,8,NULL,NULL,1,1,3,23.6000,'76',NULL,'draft',NULL,1,'2026-07-11 06:55:21','2026-07-11 06:55:21'),
(7557,580,1,8,NULL,NULL,1,1,4,23.9000,'77',NULL,'draft',NULL,1,'2026-07-11 06:55:21','2026-07-11 06:55:21'),
(7558,580,1,8,NULL,NULL,1,1,5,24.1000,'78',NULL,'draft',NULL,1,'2026-07-11 06:55:21','2026-07-11 06:55:21'),
(7559,580,1,8,NULL,NULL,1,1,6,24.0000,'79',NULL,'draft',NULL,1,'2026-07-11 06:55:21','2026-07-11 06:55:21'),
(7560,580,1,8,NULL,NULL,1,1,7,23.8000,'80',NULL,'draft',NULL,1,'2026-07-11 06:55:21','2026-07-11 06:55:21'),
(7561,580,1,8,NULL,NULL,1,1,8,23.7000,'81',NULL,'draft',NULL,1,'2026-07-11 06:55:21','2026-07-11 06:55:21'),
(7562,580,1,8,NULL,NULL,1,1,9,24.0000,'82',NULL,'draft',NULL,1,'2026-07-11 06:55:21','2026-07-11 06:55:21'),
(7563,580,1,8,NULL,NULL,1,1,10,24.0000,'83',NULL,'draft',NULL,1,'2026-07-11 06:55:21','2026-07-11 06:55:21'),
(7564,580,1,8,NULL,NULL,1,1,11,24.0000,'84',NULL,'draft',NULL,1,'2026-07-11 06:55:21','2026-07-11 06:55:21'),
(7565,580,1,8,NULL,NULL,1,1,12,24.0000,'85',NULL,'draft',NULL,1,'2026-07-11 06:55:21','2026-07-11 06:55:21'),
(7566,580,1,8,NULL,NULL,1,1,13,23.5000,'86',NULL,'draft',NULL,1,'2026-07-11 06:55:21','2026-07-11 06:55:21'),
(7567,580,1,8,NULL,NULL,1,1,14,24.0000,'87',NULL,'draft',NULL,1,'2026-07-11 06:55:21','2026-07-11 06:55:21'),
(7568,580,1,8,NULL,NULL,1,1,15,24.0000,'88',NULL,'draft',NULL,1,'2026-07-11 06:55:21','2026-07-11 06:55:21'),
(7569,581,1,8,NULL,NULL,1,1,1,23.6000,'89',NULL,'draft',NULL,1,'2026-07-11 06:55:21','2026-07-11 06:55:21'),
(7570,581,1,8,NULL,NULL,1,1,2,23.5000,'90',NULL,'draft',NULL,1,'2026-07-11 06:55:21','2026-07-11 06:55:21'),
(7571,581,1,8,NULL,NULL,1,1,3,24.1000,'91',NULL,'draft',NULL,1,'2026-07-11 06:55:21','2026-07-11 06:55:21'),
(7572,581,1,8,NULL,NULL,1,1,4,24.2000,'92',NULL,'draft',NULL,1,'2026-07-11 06:55:21','2026-07-11 06:55:21'),
(7573,581,1,8,NULL,NULL,1,1,5,24.1000,'93',NULL,'draft',NULL,1,'2026-07-11 06:55:21','2026-07-11 06:55:21'),
(7574,581,1,8,NULL,NULL,1,1,6,24.0000,'94',NULL,'draft',NULL,1,'2026-07-11 06:55:21','2026-07-11 06:55:21'),
(7575,581,1,8,NULL,NULL,1,1,7,24.0000,'95',NULL,'draft',NULL,1,'2026-07-11 06:55:21','2026-07-11 06:55:21'),
(7576,581,1,8,NULL,NULL,1,1,8,24.0000,'96',NULL,'draft',NULL,1,'2026-07-11 06:55:21','2026-07-11 06:55:21'),
(7577,581,1,8,NULL,NULL,1,1,9,24.2000,'97',NULL,'draft',NULL,1,'2026-07-11 06:55:21','2026-07-11 06:55:21'),
(7578,581,1,8,NULL,NULL,1,1,10,24.2000,'98',NULL,'draft',NULL,1,'2026-07-11 06:55:21','2026-07-11 06:55:21'),
(7579,581,1,8,NULL,NULL,1,1,11,23.0000,'99',NULL,'draft',NULL,1,'2026-07-11 06:55:21','2026-07-11 06:55:21'),
(7580,581,1,8,NULL,NULL,1,1,12,24.2000,'100',NULL,'draft',NULL,1,'2026-07-11 06:55:21','2026-07-11 06:55:21'),
(7581,581,1,8,NULL,NULL,1,1,13,23.1000,'101',NULL,'draft',NULL,1,'2026-07-11 06:55:21','2026-07-11 06:55:21'),
(7582,582,1,8,NULL,NULL,1,1,1,24.1000,'102',NULL,'draft',NULL,1,'2026-07-11 06:55:21','2026-07-11 06:55:21'),
(7583,582,1,8,NULL,NULL,1,1,2,24.7000,'103',NULL,'draft',NULL,1,'2026-07-11 06:55:21','2026-07-11 06:55:21'),
(7584,582,1,8,NULL,NULL,1,1,3,25.1000,'104',NULL,'draft',NULL,1,'2026-07-11 06:55:21','2026-07-11 06:55:21'),
(7585,582,1,8,NULL,NULL,1,1,4,25.1000,'105',NULL,'draft',NULL,1,'2026-07-11 06:55:21','2026-07-11 06:55:21'),
(7586,582,1,8,NULL,NULL,1,1,5,23.5000,'106',NULL,'draft',NULL,1,'2026-07-11 06:55:21','2026-07-11 06:55:21'),
(7587,582,1,8,NULL,NULL,1,1,6,25.1000,'107',NULL,'draft',NULL,1,'2026-07-11 06:55:21','2026-07-11 06:55:21'),
(7588,582,1,8,NULL,NULL,1,1,7,24.5000,'108',NULL,'draft',NULL,1,'2026-07-11 06:55:21','2026-07-11 06:55:21'),
(7589,582,1,8,NULL,NULL,1,1,8,25.2000,'109',NULL,'draft',NULL,1,'2026-07-11 06:55:21','2026-07-11 06:55:21'),
(7590,582,1,8,NULL,NULL,1,1,9,24.9000,'110',NULL,'draft',NULL,1,'2026-07-11 06:55:21','2026-07-11 06:55:21'),
(7591,582,1,8,NULL,NULL,1,1,10,25.0000,'111',NULL,'draft',NULL,1,'2026-07-11 06:55:21','2026-07-11 06:55:21'),
(7592,583,1,8,NULL,NULL,1,1,1,24.8000,'112',NULL,'draft',NULL,1,'2026-07-11 06:55:21','2026-07-11 06:55:21'),
(7593,583,1,8,NULL,NULL,1,1,2,24.4000,'113',NULL,'draft',NULL,1,'2026-07-11 06:55:21','2026-07-11 06:55:21'),
(7594,583,1,8,NULL,NULL,1,1,3,24.8000,'114',NULL,'draft',NULL,1,'2026-07-11 06:55:21','2026-07-11 06:55:21'),
(7595,583,1,8,NULL,NULL,1,1,4,24.4000,'115',NULL,'draft',NULL,1,'2026-07-11 06:55:21','2026-07-11 06:55:21'),
(7596,583,1,8,NULL,NULL,1,1,5,24.6000,'116',NULL,'draft',NULL,1,'2026-07-11 06:55:21','2026-07-11 06:55:21'),
(7597,583,1,8,NULL,NULL,1,1,6,24.6000,'117',NULL,'draft',NULL,1,'2026-07-11 06:55:21','2026-07-11 06:55:21'),
(7598,583,1,8,NULL,NULL,1,1,7,25.0000,'118',NULL,'draft',NULL,1,'2026-07-11 06:55:21','2026-07-11 06:55:21'),
(7599,583,1,8,NULL,NULL,1,1,8,24.8000,'119',NULL,'draft',NULL,1,'2026-07-11 06:55:21','2026-07-11 06:55:21'),
(7600,583,1,8,NULL,NULL,1,1,9,24.9000,'120',NULL,'draft',NULL,1,'2026-07-11 06:55:21','2026-07-11 06:55:21'),
(7601,583,1,8,NULL,NULL,1,1,10,24.5000,'121',NULL,'draft',NULL,1,'2026-07-11 06:55:21','2026-07-11 06:55:21'),
(7602,583,1,8,NULL,NULL,1,1,11,24.5000,'122',NULL,'draft',NULL,1,'2026-07-11 06:55:21','2026-07-11 06:55:21'),
(7603,583,1,8,NULL,NULL,1,1,12,24.9000,'123',NULL,'draft',NULL,1,'2026-07-11 06:55:21','2026-07-11 06:55:21'),
(7604,583,1,8,NULL,NULL,1,1,13,24.8000,'124',NULL,'draft',NULL,1,'2026-07-11 06:55:21','2026-07-11 06:55:21'),
(7605,583,1,8,NULL,NULL,1,1,14,24.7000,'125',NULL,'draft',NULL,1,'2026-07-11 06:55:21','2026-07-11 06:55:21'),
(7606,583,1,8,NULL,NULL,1,1,15,24.2000,'126',NULL,'draft',NULL,1,'2026-07-11 06:55:21','2026-07-11 06:55:21'),
(7607,583,1,8,NULL,NULL,1,1,16,24.5000,'127',NULL,'draft',NULL,1,'2026-07-11 06:55:21','2026-07-11 06:55:21'),
(7608,583,1,8,NULL,NULL,1,1,17,24.8000,'128',NULL,'draft',NULL,1,'2026-07-11 06:55:21','2026-07-11 06:55:21'),
(7609,583,1,8,NULL,NULL,1,1,18,24.8000,'129',NULL,'draft',NULL,1,'2026-07-11 06:55:21','2026-07-11 06:55:21'),
(7610,584,1,8,NULL,NULL,1,1,1,24.0000,'130',NULL,'draft',NULL,1,'2026-07-11 06:55:21','2026-07-11 06:55:21'),
(7611,584,1,8,NULL,NULL,1,1,2,24.1000,'131',NULL,'draft',NULL,1,'2026-07-11 06:55:21','2026-07-11 06:55:21'),
(7612,584,1,8,NULL,NULL,1,1,3,24.2000,'132',NULL,'draft',NULL,1,'2026-07-11 06:55:21','2026-07-11 06:55:21'),
(7613,584,1,8,NULL,NULL,1,1,4,24.6000,'133',NULL,'draft',NULL,1,'2026-07-11 06:55:21','2026-07-11 06:55:21'),
(7614,584,1,8,NULL,NULL,1,1,5,24.0000,'134',NULL,'draft',NULL,1,'2026-07-11 06:55:21','2026-07-11 06:55:21'),
(7615,584,1,8,NULL,NULL,1,1,6,23.6000,'135',NULL,'draft',NULL,1,'2026-07-11 06:55:21','2026-07-11 06:55:21'),
(7616,584,1,8,NULL,NULL,1,1,7,24.3000,'136',NULL,'draft',NULL,1,'2026-07-11 06:55:21','2026-07-11 06:55:21'),
(7617,585,1,8,NULL,NULL,1,1,1,23.3000,'137',NULL,'draft',NULL,1,'2026-07-11 06:55:21','2026-07-11 06:55:21'),
(7618,585,1,8,NULL,NULL,1,1,2,23.5000,'138',NULL,'draft',NULL,1,'2026-07-11 06:55:21','2026-07-11 06:55:21'),
(7619,585,1,8,NULL,NULL,1,1,3,22.8000,'139',NULL,'draft',NULL,1,'2026-07-11 06:55:21','2026-07-11 06:55:21'),
(7620,586,1,8,NULL,NULL,1,1,1,23.5000,'140',NULL,'draft',NULL,1,'2026-07-11 06:55:21','2026-07-11 06:55:21'),
(7621,586,1,8,NULL,NULL,1,1,2,17.7000,'141',NULL,'draft',NULL,1,'2026-07-11 06:55:21','2026-07-11 06:55:21'),
(7622,587,1,8,NULL,NULL,1,1,1,24.2000,'142',NULL,'draft',NULL,1,'2026-07-11 06:55:21','2026-07-11 06:55:21');
/*!40000 ALTER TABLE `inv_grn_item_pieces` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `inv_industries`
--

DROP TABLE IF EXISTS `inv_industries`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `inv_industries` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `description` varchar(255) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `inv_industries`
--

LOCK TABLES `inv_industries` WRITE;
/*!40000 ALTER TABLE `inv_industries` DISABLE KEYS */;
INSERT INTO `inv_industries` VALUES
(1,'Textile & Apparel',NULL,'2026-06-26 05:26:17','2026-06-26 05:26:17');
/*!40000 ALTER TABLE `inv_industries` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `inv_invoice_items`
--

DROP TABLE IF EXISTS `inv_invoice_items`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `inv_invoice_items` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `invoice_id` bigint(20) unsigned NOT NULL,
  `so_item_id` bigint(20) unsigned NOT NULL,
  `do_item_id` bigint(20) unsigned DEFAULT NULL,
  `product_id` bigint(20) unsigned NOT NULL,
  `unit_id` bigint(20) unsigned DEFAULT NULL,
  `attribute_id` bigint(20) unsigned DEFAULT NULL,
  `quantity` decimal(15,4) NOT NULL DEFAULT 0.0000,
  `unit_price` decimal(15,4) NOT NULL DEFAULT 0.0000,
  `discount` decimal(8,4) NOT NULL DEFAULT 0.0000,
  `tax` decimal(8,4) NOT NULL DEFAULT 0.0000,
  `line_total` decimal(15,4) NOT NULL DEFAULT 0.0000,
  `remarks` varchar(255) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `inv_invoice_items_invoice_id_index` (`invoice_id`),
  KEY `inv_invoice_items_so_item_id_index` (`so_item_id`),
  KEY `inv_invoice_items_product_id_index` (`product_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `inv_invoice_items`
--

LOCK TABLES `inv_invoice_items` WRITE;
/*!40000 ALTER TABLE `inv_invoice_items` DISABLE KEYS */;
/*!40000 ALTER TABLE `inv_invoice_items` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `inv_invoices`
--

DROP TABLE IF EXISTS `inv_invoices`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `inv_invoices` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `invoice_no` varchar(30) NOT NULL,
  `so_id` bigint(20) unsigned NOT NULL,
  `do_id` bigint(20) unsigned DEFAULT NULL,
  `customer_id` bigint(20) unsigned NOT NULL,
  `invoice_date` date NOT NULL,
  `due_date` date DEFAULT NULL,
  `status` varchar(30) NOT NULL DEFAULT 'draft',
  `subtotal` decimal(15,4) NOT NULL DEFAULT 0.0000,
  `transport_charge` decimal(15,4) NOT NULL DEFAULT 0.0000,
  `grand_total` decimal(15,4) NOT NULL DEFAULT 0.0000,
  `delivery_address` text DEFAULT NULL,
  `remarks` text DEFAULT NULL,
  `created_by` bigint(20) unsigned DEFAULT NULL,
  `issued_at` timestamp NULL DEFAULT NULL,
  `paid_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `inv_invoices_invoice_no_unique` (`invoice_no`),
  KEY `inv_invoices_so_id_index` (`so_id`),
  KEY `inv_invoices_do_id_index` (`do_id`),
  KEY `inv_invoices_customer_id_index` (`customer_id`),
  KEY `inv_invoices_status_index` (`status`),
  KEY `inv_invoices_invoice_date_index` (`invoice_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `inv_invoices`
--

LOCK TABLES `inv_invoices` WRITE;
/*!40000 ALTER TABLE `inv_invoices` DISABLE KEYS */;
/*!40000 ALTER TABLE `inv_invoices` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `inv_location_stores`
--

DROP TABLE IF EXISTS `inv_location_stores`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `inv_location_stores` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `location_id` bigint(20) unsigned NOT NULL,
  `store_id` bigint(20) unsigned NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `inv_location_stores_location_id_store_id_unique` (`location_id`,`store_id`),
  KEY `inv_location_stores_store_id_foreign` (`store_id`),
  CONSTRAINT `inv_location_stores_location_id_foreign` FOREIGN KEY (`location_id`) REFERENCES `inv_locations` (`id`),
  CONSTRAINT `inv_location_stores_store_id_foreign` FOREIGN KEY (`store_id`) REFERENCES `inv_stores` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `inv_location_stores`
--

LOCK TABLES `inv_location_stores` WRITE;
/*!40000 ALTER TABLE `inv_location_stores` DISABLE KEYS */;
/*!40000 ALTER TABLE `inv_location_stores` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `inv_locations`
--

DROP TABLE IF EXISTS `inv_locations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `inv_locations` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint(20) unsigned NOT NULL,
  `industry_id` bigint(20) unsigned NOT NULL,
  `parent_location_id` bigint(20) unsigned DEFAULT NULL,
  `location_code` varchar(50) NOT NULL,
  `location_name` varchar(100) NOT NULL,
  `location_type` varchar(50) DEFAULT NULL,
  `country` varchar(100) NOT NULL,
  `loc_street_address` varchar(150) NOT NULL,
  `loc_city` varchar(50) NOT NULL,
  `loc_country` varchar(100) NOT NULL,
  `loc_state` varchar(50) NOT NULL,
  `loc_postal_zip_code` varchar(20) NOT NULL,
  `billing_same_as_location` tinyint(1) NOT NULL DEFAULT 0,
  `bill_street_address` varchar(150) DEFAULT NULL,
  `bill_city` varchar(50) DEFAULT NULL,
  `bill_country` varchar(100) DEFAULT NULL,
  `bill_state` varchar(50) DEFAULT NULL,
  `bill_postal_zip_code` varchar(20) DEFAULT NULL,
  `company_email` varchar(100) DEFAULT NULL,
  `customer_facing_email` varchar(100) DEFAULT NULL,
  `company_phone` varchar(30) DEFAULT NULL,
  `mobile` varchar(30) DEFAULT NULL,
  `fax` varchar(30) DEFAULT NULL,
  `website` varchar(255) DEFAULT NULL,
  `longitude` decimal(11,8) DEFAULT NULL,
  `latitude` decimal(10,8) DEFAULT NULL,
  `map_url` varchar(500) DEFAULT NULL,
  `date_format` varchar(30) DEFAULT 'M d, Y',
  `number_format` varchar(30) DEFAULT '#,###.##',
  `time_format` varchar(30) DEFAULT 'H:i:s',
  `float_precision` tinyint(3) unsigned DEFAULT 3,
  `base_currency` varchar(10) NOT NULL DEFAULT 'USD',
  `time_zone` varchar(100) DEFAULT NULL,
  `financial_year` varchar(50) NOT NULL,
  `open_hours_from` time DEFAULT NULL,
  `open_hours_to` time DEFAULT NULL,
  `available_modules` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`available_modules`)),
  `stock_releasing_method` enum('LIFO','FIFO','AVG') NOT NULL,
  `logo_path` varchar(500) DEFAULT NULL,
  `header_path` varchar(500) DEFAULT NULL,
  `footer_path` varchar(500) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `inv_locations_location_code_unique` (`location_code`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `inv_locations`
--

LOCK TABLES `inv_locations` WRITE;
/*!40000 ALTER TABLE `inv_locations` DISABLE KEYS */;
INSERT INTO `inv_locations` VALUES
(1,1,1,NULL,'01','P.G Fashion (Pvt) Ltd -Maharagama','Head Office','Sri Lanka','No.12 Polwatte road, Maharagama','Maharagama','Sri Lanka','Maharagama','10280',1,'No.12 Polwatte road, Maharagama',NULL,NULL,'Maharagama',NULL,'bandarapgf@sltnet.lk','bandarapgf@sltnet.lk','0112099272','0718418695',NULL,NULL,NULL,NULL,NULL,'M d, Y','#,###.##','H:i:s',3,'LKR','Asia/Colombo','Jan-Dec','09:00:00','18:00:00','[\"Inventory\"]','FIFO',NULL,NULL,NULL,'2026-06-26 05:36:12','2026-06-26 05:36:12',NULL);
/*!40000 ALTER TABLE `inv_locations` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `inv_payment_modes`
--

DROP TABLE IF EXISTS `inv_payment_modes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `inv_payment_modes` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `payment_mode_name` varchar(50) NOT NULL,
  `code` varchar(30) NOT NULL,
  `requires_bank_details` tinyint(1) NOT NULL DEFAULT 0,
  `requires_reference_no` tinyint(1) NOT NULL DEFAULT 0,
  `requires_date` tinyint(1) NOT NULL DEFAULT 0,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `inv_payment_modes_payment_mode_name_unique` (`payment_mode_name`),
  UNIQUE KEY `inv_payment_modes_code_unique` (`code`)
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `inv_payment_modes`
--

LOCK TABLES `inv_payment_modes` WRITE;
/*!40000 ALTER TABLE `inv_payment_modes` DISABLE KEYS */;
INSERT INTO `inv_payment_modes` VALUES
(1,'Cash','cash',0,0,0,1,1,'2026-07-02 22:59:59','2026-07-02 22:59:59',NULL),
(2,'Cheque','cheque',1,1,1,2,1,'2026-07-02 22:59:59','2026-07-02 22:59:59',NULL),
(3,'Card','card',1,1,0,3,1,'2026-07-02 22:59:59','2026-07-02 22:59:59',NULL),
(4,'Setoff','setoff',0,0,0,4,1,'2026-07-02 22:59:59','2026-07-02 22:59:59',NULL);
/*!40000 ALTER TABLE `inv_payment_modes` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `inv_product_attributes`
--

DROP TABLE IF EXISTS `inv_product_attributes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `inv_product_attributes` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `product_id` bigint(20) unsigned NOT NULL,
  `attribute_type_id` bigint(20) unsigned DEFAULT NULL,
  `attribute_id` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `inv_product_attributes_product_id_foreign` (`product_id`),
  KEY `inv_product_attributes_attribute_type_id_foreign` (`attribute_type_id`),
  KEY `inv_product_attributes_attribute_id_foreign` (`attribute_id`),
  CONSTRAINT `inv_product_attributes_attribute_id_foreign` FOREIGN KEY (`attribute_id`) REFERENCES `inv_attributes` (`id`) ON DELETE SET NULL,
  CONSTRAINT `inv_product_attributes_attribute_type_id_foreign` FOREIGN KEY (`attribute_type_id`) REFERENCES `inv_attribute_types` (`id`) ON DELETE SET NULL,
  CONSTRAINT `inv_product_attributes_product_id_foreign` FOREIGN KEY (`product_id`) REFERENCES `inv_products` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=571 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `inv_product_attributes`
--

LOCK TABLES `inv_product_attributes` WRITE;
/*!40000 ALTER TABLE `inv_product_attributes` DISABLE KEYS */;
INSERT INTO `inv_product_attributes` VALUES
(70,2,2,NULL,'2026-07-03 08:51:45','2026-07-03 08:51:45'),
(71,2,7,31,'2026-07-03 08:51:45','2026-07-03 08:51:45'),
(72,2,10,64,'2026-07-03 08:51:45','2026-07-03 08:51:45'),
(81,4,2,NULL,'2026-07-03 08:55:09','2026-07-03 08:55:09'),
(82,4,8,39,'2026-07-03 08:55:09','2026-07-03 08:55:09'),
(83,4,3,67,'2026-07-03 08:55:09','2026-07-03 08:55:09'),
(84,4,10,64,'2026-07-03 08:55:09','2026-07-03 08:55:09'),
(175,1,2,2,'2026-07-03 23:09:41','2026-07-03 23:09:41'),
(176,1,7,31,'2026-07-03 23:09:41','2026-07-03 23:09:41'),
(376,7,2,4,'2026-07-05 02:59:57','2026-07-05 02:59:57'),
(377,7,3,69,'2026-07-05 02:59:57','2026-07-05 02:59:57'),
(378,7,4,107,'2026-07-05 02:59:57','2026-07-05 02:59:57'),
(379,7,9,41,'2026-07-05 02:59:57','2026-07-05 02:59:57'),
(380,6,3,68,'2026-07-05 03:00:03','2026-07-05 03:00:03'),
(381,6,4,108,'2026-07-05 03:00:03','2026-07-05 03:00:03'),
(382,6,4,109,'2026-07-05 03:00:03','2026-07-05 03:00:03'),
(383,6,4,110,'2026-07-05 03:00:03','2026-07-05 03:00:03'),
(384,6,4,111,'2026-07-05 03:00:03','2026-07-05 03:00:03'),
(385,6,4,139,'2026-07-05 03:00:03','2026-07-05 03:00:03'),
(386,6,4,113,'2026-07-05 03:00:03','2026-07-05 03:00:03'),
(387,6,4,99,'2026-07-05 03:00:03','2026-07-05 03:00:03'),
(388,6,4,115,'2026-07-05 03:00:03','2026-07-05 03:00:03'),
(389,6,4,116,'2026-07-05 03:00:03','2026-07-05 03:00:03'),
(390,6,4,117,'2026-07-05 03:00:03','2026-07-05 03:00:03'),
(391,6,4,118,'2026-07-05 03:00:03','2026-07-05 03:00:03'),
(392,6,4,119,'2026-07-05 03:00:03','2026-07-05 03:00:03'),
(393,6,4,120,'2026-07-05 03:00:03','2026-07-05 03:00:03'),
(394,6,4,121,'2026-07-05 03:00:03','2026-07-05 03:00:03'),
(395,6,4,122,'2026-07-05 03:00:03','2026-07-05 03:00:03'),
(396,6,4,123,'2026-07-05 03:00:03','2026-07-05 03:00:03'),
(397,6,4,124,'2026-07-05 03:00:03','2026-07-05 03:00:03'),
(398,6,4,125,'2026-07-05 03:00:03','2026-07-05 03:00:03'),
(399,6,4,126,'2026-07-05 03:00:03','2026-07-05 03:00:03'),
(400,6,4,127,'2026-07-05 03:00:03','2026-07-05 03:00:03'),
(401,6,4,141,'2026-07-05 03:00:03','2026-07-05 03:00:03'),
(402,6,4,128,'2026-07-05 03:00:03','2026-07-05 03:00:03'),
(403,6,4,129,'2026-07-05 03:00:03','2026-07-05 03:00:03'),
(404,6,4,130,'2026-07-05 03:00:03','2026-07-05 03:00:03'),
(405,6,4,131,'2026-07-05 03:00:03','2026-07-05 03:00:03'),
(406,6,4,132,'2026-07-05 03:00:03','2026-07-05 03:00:03'),
(407,6,4,133,'2026-07-05 03:00:03','2026-07-05 03:00:03'),
(408,6,4,134,'2026-07-05 03:00:03','2026-07-05 03:00:03'),
(409,6,4,135,'2026-07-05 03:00:03','2026-07-05 03:00:03'),
(410,6,4,136,'2026-07-05 03:00:03','2026-07-05 03:00:03'),
(411,6,4,137,'2026-07-05 03:00:03','2026-07-05 03:00:03'),
(412,6,4,142,'2026-07-05 03:00:03','2026-07-05 03:00:03'),
(413,6,5,90,'2026-07-05 03:00:03','2026-07-05 03:00:03'),
(414,6,8,40,'2026-07-05 03:00:03','2026-07-05 03:00:03'),
(415,6,9,41,'2026-07-05 03:00:03','2026-07-05 03:00:03'),
(416,6,12,91,'2026-07-05 03:00:03','2026-07-05 03:00:03'),
(423,3,5,29,'2026-07-05 03:00:21','2026-07-05 03:00:21'),
(424,3,7,31,'2026-07-05 03:00:21','2026-07-05 03:00:21'),
(425,3,8,39,'2026-07-05 03:00:21','2026-07-05 03:00:21'),
(426,5,3,67,'2026-07-05 03:00:25','2026-07-05 03:00:25'),
(427,5,5,29,'2026-07-05 03:00:25','2026-07-05 03:00:25'),
(428,5,8,39,'2026-07-05 03:00:25','2026-07-05 03:00:25'),
(429,5,10,64,'2026-07-05 03:00:25','2026-07-05 03:00:25'),
(435,9,3,70,'2026-07-05 03:00:43','2026-07-05 03:00:43'),
(436,9,4,15,'2026-07-05 03:00:43','2026-07-05 03:00:43'),
(437,9,4,11,'2026-07-05 03:00:43','2026-07-05 03:00:43'),
(438,9,4,13,'2026-07-05 03:00:43','2026-07-05 03:00:43'),
(439,9,4,99,'2026-07-05 03:00:43','2026-07-05 03:00:43'),
(440,9,4,100,'2026-07-05 03:00:43','2026-07-05 03:00:43'),
(441,9,4,101,'2026-07-05 03:00:43','2026-07-05 03:00:43'),
(442,9,4,12,'2026-07-05 03:00:43','2026-07-05 03:00:43'),
(443,9,4,102,'2026-07-05 03:00:43','2026-07-05 03:00:43'),
(444,9,4,103,'2026-07-05 03:00:43','2026-07-05 03:00:43'),
(445,9,4,104,'2026-07-05 03:00:43','2026-07-05 03:00:43'),
(446,9,4,105,'2026-07-05 03:00:43','2026-07-05 03:00:43'),
(447,9,5,29,'2026-07-05 03:00:43','2026-07-05 03:00:43'),
(448,9,8,39,'2026-07-05 03:00:43','2026-07-05 03:00:43'),
(449,9,9,41,'2026-07-05 03:00:43','2026-07-05 03:00:43'),
(450,9,12,94,'2026-07-05 03:00:43','2026-07-05 03:00:43'),
(494,10,2,1,'2026-07-11 05:04:33','2026-07-11 05:04:33'),
(495,10,3,88,'2026-07-11 05:04:33','2026-07-11 05:04:33'),
(496,10,4,89,'2026-07-11 05:04:33','2026-07-11 05:04:33'),
(497,10,4,151,'2026-07-11 05:04:33','2026-07-11 05:04:33'),
(498,10,4,150,'2026-07-11 05:04:33','2026-07-11 05:04:33'),
(499,10,4,153,'2026-07-11 05:04:33','2026-07-11 05:04:33'),
(500,10,4,154,'2026-07-11 05:04:33','2026-07-11 05:04:33'),
(501,10,4,157,'2026-07-11 05:04:33','2026-07-11 05:04:33'),
(502,10,4,158,'2026-07-11 05:04:33','2026-07-11 05:04:33'),
(503,10,4,102,'2026-07-11 05:04:33','2026-07-11 05:04:33'),
(504,10,4,160,'2026-07-11 05:04:33','2026-07-11 05:04:33'),
(505,10,4,161,'2026-07-11 05:04:33','2026-07-11 05:04:33'),
(506,10,4,162,'2026-07-11 05:04:33','2026-07-11 05:04:33'),
(507,10,4,163,'2026-07-11 05:04:33','2026-07-11 05:04:33'),
(508,10,4,164,'2026-07-11 05:04:33','2026-07-11 05:04:33'),
(509,10,4,130,'2026-07-11 05:04:33','2026-07-11 05:04:33'),
(510,10,4,166,'2026-07-11 05:04:33','2026-07-11 05:04:33'),
(511,10,4,167,'2026-07-11 05:04:33','2026-07-11 05:04:33'),
(512,10,4,168,'2026-07-11 05:04:33','2026-07-11 05:04:33'),
(513,10,4,169,'2026-07-11 05:04:33','2026-07-11 05:04:33'),
(514,10,4,170,'2026-07-11 05:04:33','2026-07-11 05:04:33'),
(515,10,4,171,'2026-07-11 05:04:33','2026-07-11 05:04:33'),
(516,10,4,172,'2026-07-11 05:04:33','2026-07-11 05:04:33'),
(517,10,4,173,'2026-07-11 05:04:33','2026-07-11 05:04:33'),
(518,10,4,174,'2026-07-11 05:04:33','2026-07-11 05:04:33'),
(519,10,4,175,'2026-07-11 05:04:33','2026-07-11 05:04:33'),
(520,10,4,176,'2026-07-11 05:04:33','2026-07-11 05:04:33'),
(521,10,4,177,'2026-07-11 05:04:33','2026-07-11 05:04:33'),
(522,10,4,178,'2026-07-11 05:04:33','2026-07-11 05:04:33'),
(523,10,4,179,'2026-07-11 05:04:33','2026-07-11 05:04:33'),
(524,10,4,190,'2026-07-11 05:04:33','2026-07-11 05:04:33'),
(525,10,4,191,'2026-07-11 05:04:33','2026-07-11 05:04:33'),
(526,10,4,192,'2026-07-11 05:04:33','2026-07-11 05:04:33'),
(527,10,4,193,'2026-07-11 05:04:33','2026-07-11 05:04:33'),
(528,10,4,194,'2026-07-11 05:04:33','2026-07-11 05:04:33'),
(529,10,4,195,'2026-07-11 05:04:33','2026-07-11 05:04:33'),
(530,10,4,196,'2026-07-11 05:04:33','2026-07-11 05:04:33'),
(531,10,4,197,'2026-07-11 05:04:33','2026-07-11 05:04:33'),
(532,10,4,198,'2026-07-11 05:04:33','2026-07-11 05:04:33'),
(533,10,4,199,'2026-07-11 05:04:33','2026-07-11 05:04:33'),
(534,10,4,155,'2026-07-11 05:04:33','2026-07-11 05:04:33'),
(535,10,5,28,'2026-07-11 05:04:33','2026-07-11 05:04:33'),
(536,10,9,41,'2026-07-11 05:04:33','2026-07-11 05:04:33'),
(537,10,12,96,'2026-07-11 05:04:33','2026-07-11 05:04:33'),
(554,8,3,70,'2026-07-11 05:57:25','2026-07-11 05:57:25'),
(555,8,4,89,'2026-07-11 05:57:25','2026-07-11 05:57:25'),
(556,8,4,201,'2026-07-11 05:57:25','2026-07-11 05:57:25'),
(557,8,4,202,'2026-07-11 05:57:25','2026-07-11 05:57:25'),
(558,8,4,203,'2026-07-11 05:57:25','2026-07-11 05:57:25'),
(559,8,4,204,'2026-07-11 05:57:25','2026-07-11 05:57:25'),
(560,8,4,205,'2026-07-11 05:57:25','2026-07-11 05:57:25'),
(561,8,4,206,'2026-07-11 05:57:25','2026-07-11 05:57:25'),
(562,8,4,208,'2026-07-11 05:57:25','2026-07-11 05:57:25'),
(563,8,4,209,'2026-07-11 05:57:25','2026-07-11 05:57:25'),
(564,8,4,210,'2026-07-11 05:57:25','2026-07-11 05:57:25'),
(565,8,4,211,'2026-07-11 05:57:25','2026-07-11 05:57:25'),
(566,8,4,212,'2026-07-11 05:57:25','2026-07-11 05:57:25'),
(567,8,4,213,'2026-07-11 05:57:25','2026-07-11 05:57:25'),
(568,8,5,29,'2026-07-11 05:57:25','2026-07-11 05:57:25'),
(569,8,9,41,'2026-07-11 05:57:25','2026-07-11 05:57:25'),
(570,8,12,91,'2026-07-11 05:57:25','2026-07-11 05:57:25');
/*!40000 ALTER TABLE `inv_product_attributes` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `inv_product_images`
--

DROP TABLE IF EXISTS `inv_product_images`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `inv_product_images` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `product_id` bigint(20) unsigned NOT NULL,
  `url` varchar(255) NOT NULL DEFAULT 'default_product.webp',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `inv_product_images_product_id_foreign` (`product_id`),
  CONSTRAINT `inv_product_images_product_id_foreign` FOREIGN KEY (`product_id`) REFERENCES `inv_products` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `inv_product_images`
--

LOCK TABLES `inv_product_images` WRITE;
/*!40000 ALTER TABLE `inv_product_images` DISABLE KEYS */;
/*!40000 ALTER TABLE `inv_product_images` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `inv_product_location_stores`
--

DROP TABLE IF EXISTS `inv_product_location_stores`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `inv_product_location_stores` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `product_id` bigint(20) unsigned NOT NULL,
  `location_id` bigint(20) unsigned DEFAULT NULL,
  `store_id` bigint(20) unsigned DEFAULT NULL,
  `current_stock` decimal(15,4) NOT NULL DEFAULT 0.0000,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `inv_product_location_stores_product_id_foreign` (`product_id`),
  KEY `inv_product_location_stores_location_id_foreign` (`location_id`),
  KEY `inv_product_location_stores_store_id_foreign` (`store_id`),
  CONSTRAINT `inv_product_location_stores_location_id_foreign` FOREIGN KEY (`location_id`) REFERENCES `inv_locations` (`id`) ON DELETE SET NULL,
  CONSTRAINT `inv_product_location_stores_product_id_foreign` FOREIGN KEY (`product_id`) REFERENCES `inv_products` (`id`) ON DELETE CASCADE,
  CONSTRAINT `inv_product_location_stores_store_id_foreign` FOREIGN KEY (`store_id`) REFERENCES `inv_stores` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=10 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `inv_product_location_stores`
--

LOCK TABLES `inv_product_location_stores` WRITE;
/*!40000 ALTER TABLE `inv_product_location_stores` DISABLE KEYS */;
INSERT INTO `inv_product_location_stores` VALUES
(1,1,1,1,0.0000,'2026-07-03 02:46:40','2026-07-03 02:46:40'),
(2,2,1,1,0.0000,'2026-07-03 02:56:06','2026-07-03 02:56:06'),
(3,4,1,1,0.0000,'2026-07-03 03:12:56','2026-07-03 03:12:56'),
(4,5,1,1,0.0000,'2026-07-03 03:17:37','2026-07-03 03:17:37'),
(5,6,1,1,0.0000,'2026-07-03 07:14:08','2026-07-03 07:14:08'),
(6,7,1,1,44342.0000,'2026-07-03 07:28:33','2026-07-10 07:22:00'),
(7,8,1,1,0.0000,'2026-07-03 09:04:02','2026-07-03 09:04:02'),
(8,9,1,1,0.0000,'2026-07-03 09:32:43','2026-07-03 09:32:43'),
(9,10,1,1,0.0000,'2026-07-03 09:38:45','2026-07-03 09:38:45');
/*!40000 ALTER TABLE `inv_product_location_stores` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `inv_product_sales_channels`
--

DROP TABLE IF EXISTS `inv_product_sales_channels`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `inv_product_sales_channels` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `product_id` bigint(20) unsigned NOT NULL,
  `sales_channel_id` bigint(20) unsigned NOT NULL,
  `unit_type_id` bigint(20) unsigned DEFAULT NULL,
  `uom` varchar(50) DEFAULT NULL,
  `num_of_units` decimal(15,4) DEFAULT NULL,
  `cost_price` decimal(15,4) DEFAULT NULL,
  `margin` decimal(10,4) DEFAULT NULL,
  `margin_type` enum('percentage','amount') NOT NULL DEFAULT 'percentage',
  `selling_price` decimal(15,4) DEFAULT NULL,
  `max_price` decimal(15,4) DEFAULT NULL,
  `min_price` decimal(15,4) DEFAULT NULL,
  `wholesale_price` decimal(15,4) DEFAULT NULL,
  `sale_privileges_discount` decimal(5,2) DEFAULT NULL,
  `purchasing_privileges_discount` decimal(5,2) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `inv_product_sales_channels_product_id_foreign` (`product_id`),
  KEY `inv_product_sales_channels_sales_channel_id_foreign` (`sales_channel_id`),
  KEY `inv_product_sales_channels_unit_type_id_foreign` (`unit_type_id`),
  CONSTRAINT `inv_product_sales_channels_product_id_foreign` FOREIGN KEY (`product_id`) REFERENCES `inv_products` (`id`) ON DELETE CASCADE,
  CONSTRAINT `inv_product_sales_channels_sales_channel_id_foreign` FOREIGN KEY (`sales_channel_id`) REFERENCES `inv_sales_channels` (`id`),
  CONSTRAINT `inv_product_sales_channels_unit_type_id_foreign` FOREIGN KEY (`unit_type_id`) REFERENCES `inv_unit_types` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=11 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `inv_product_sales_channels`
--

LOCK TABLES `inv_product_sales_channels` WRITE;
/*!40000 ALTER TABLE `inv_product_sales_channels` DISABLE KEYS */;
INSERT INTO `inv_product_sales_channels` VALUES
(1,1,1,1,NULL,1.0000,2.4000,0.0000,'percentage',2.4000,NULL,NULL,NULL,NULL,NULL,'2026-07-03 02:46:40','2026-07-03 23:09:41'),
(2,2,1,1,NULL,1.0000,52.4000,0.0000,'percentage',52.4000,NULL,NULL,NULL,NULL,NULL,'2026-07-03 02:56:06','2026-07-03 08:51:45'),
(3,3,1,1,NULL,1.0000,2.2600,0.0000,'percentage',2.2600,NULL,NULL,NULL,NULL,NULL,'2026-07-03 03:05:42','2026-07-05 03:00:21'),
(4,4,1,1,NULL,1.0000,2.2600,0.0000,'percentage',2.2600,NULL,NULL,NULL,NULL,NULL,'2026-07-03 03:12:56','2026-07-03 08:55:09'),
(5,5,1,1,NULL,1.0000,2.2600,0.0000,'percentage',2.2600,NULL,NULL,NULL,NULL,NULL,'2026-07-03 03:17:37','2026-07-05 03:00:25'),
(6,6,1,1,NULL,1.0000,2100.0000,17.8571,'percentage',2475.0000,NULL,NULL,NULL,NULL,NULL,'2026-07-03 07:14:08','2026-07-05 03:00:03'),
(7,7,1,1,NULL,1.0000,181.4400,42.1053,'percentage',270.0000,NULL,NULL,NULL,NULL,0.00,'2026-07-03 07:28:33','2026-07-10 07:22:00'),
(8,8,1,1,NULL,1.0000,1425.0000,17.5439,'percentage',1675.0000,NULL,NULL,NULL,NULL,NULL,'2026-07-03 09:04:02','2026-07-11 05:57:25'),
(9,9,1,1,NULL,1.0000,1725.0000,21.7391,'percentage',2100.0000,NULL,NULL,NULL,NULL,NULL,'2026-07-03 09:32:43','2026-07-05 03:00:43'),
(10,10,1,1,NULL,1.0000,1450.0000,18.9655,'percentage',1725.0000,NULL,NULL,NULL,NULL,NULL,'2026-07-03 09:38:45','2026-07-11 05:04:33');
/*!40000 ALTER TABLE `inv_product_sales_channels` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `inv_product_supplier`
--

DROP TABLE IF EXISTS `inv_product_supplier`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `inv_product_supplier` (
  `product_id` bigint(20) unsigned NOT NULL,
  `supplier_master_id` bigint(20) unsigned NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`product_id`,`supplier_master_id`),
  KEY `inv_product_supplier_supplier_master_id_foreign` (`supplier_master_id`),
  CONSTRAINT `inv_product_supplier_product_id_foreign` FOREIGN KEY (`product_id`) REFERENCES `inv_products` (`id`),
  CONSTRAINT `inv_product_supplier_supplier_master_id_foreign` FOREIGN KEY (`supplier_master_id`) REFERENCES `inv_supplier_masters` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `inv_product_supplier`
--

LOCK TABLES `inv_product_supplier` WRITE;
/*!40000 ALTER TABLE `inv_product_supplier` DISABLE KEYS */;
INSERT INTO `inv_product_supplier` VALUES
(1,1,'2026-07-03 02:46:40','2026-07-03 02:46:40'),
(2,1,'2026-07-03 02:56:06','2026-07-03 02:56:06'),
(3,1,'2026-07-03 03:05:42','2026-07-03 03:05:42'),
(4,1,'2026-07-03 03:12:56','2026-07-03 03:12:56'),
(5,1,'2026-07-03 03:17:37','2026-07-03 03:17:37'),
(6,5,'2026-07-03 07:14:08','2026-07-03 07:14:08'),
(7,5,'2026-07-03 07:28:33','2026-07-03 07:28:33'),
(8,5,'2026-07-03 09:04:02','2026-07-03 09:04:02'),
(9,5,'2026-07-03 09:32:43','2026-07-03 09:32:43'),
(10,5,'2026-07-03 09:38:45','2026-07-03 09:38:45');
/*!40000 ALTER TABLE `inv_product_supplier` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `inv_products`
--

DROP TABLE IF EXISTS `inv_products`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `inv_products` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `product_code` varchar(50) DEFAULT NULL,
  `reference_no` varchar(50) DEFAULT NULL,
  `ean_13` varchar(50) DEFAULT NULL,
  `name` varchar(100) NOT NULL,
  `display_name` varchar(100) DEFAULT NULL,
  `product_type` varchar(50) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `category_id` bigint(20) unsigned NOT NULL,
  `location_id` bigint(20) unsigned DEFAULT NULL,
  `reorder_level` decimal(15,4) DEFAULT NULL,
  `reorder_qty` decimal(15,4) DEFAULT NULL,
  `reorder_period` smallint(5) unsigned DEFAULT NULL,
  `stock_releasing_method` varchar(50) DEFAULT NULL,
  `tracking_type` enum('Batch','Serial') DEFAULT NULL,
  `lock_purchase` tinyint(1) NOT NULL DEFAULT 0,
  `allow_complimentary_items` tinyint(1) NOT NULL DEFAULT 0,
  `free_issue` tinyint(1) NOT NULL DEFAULT 0,
  `allow_minus` tinyint(1) NOT NULL DEFAULT 0,
  `not_allow_direct_sale` tinyint(1) NOT NULL DEFAULT 0,
  `non_returnable` tinyint(1) NOT NULL DEFAULT 0,
  `is_empty` tinyint(1) NOT NULL DEFAULT 0,
  `service_charge` tinyint(1) NOT NULL DEFAULT 0,
  `loyalty` tinyint(1) NOT NULL DEFAULT 0,
  `is_batch` tinyint(1) NOT NULL DEFAULT 0,
  `is_serial` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `inv_products_product_code_unique` (`product_code`)
) ENGINE=InnoDB AUTO_INCREMENT=11 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `inv_products`
--

LOCK TABLES `inv_products` WRITE;
/*!40000 ALTER TABLE `inv_products` DISABLE KEYS */;
INSERT INTO `inv_products` VALUES
(1,'PRD-0001',NULL,NULL,'POLO JERSEY FABRIC(100% POLYESTER) KNITTED FABRIC','POLO JERSEY FABRIC(100% POLYESTER) KNITTED FABRIC','Inventory',NULL,3,NULL,NULL,NULL,NULL,'FIFO',NULL,0,0,0,0,0,0,0,0,0,0,0,'2026-07-03 02:46:40','2026-07-03 08:50:49'),
(2,'PRD-0002',NULL,NULL,'POLO-1x1 RIB FABRIC(95% POLYESTER, 5% SPANDEX) KNITED FADRIC','POLO-1x1 RIB FABRIC(95% POLYESTER, 5% SPANDEX) KNITED FADRIC','Inventory',NULL,5,NULL,NULL,NULL,NULL,'FIFO',NULL,0,0,0,0,0,0,0,0,0,0,0,'2026-07-03 02:56:06','2026-07-03 08:51:45'),
(3,'PRD-0003',NULL,NULL,'FRILL SOUARE-DOUBLE JERSEY FABRIC(96% POLYESTER, 4% SPANDEX) KNITTED FABRIC.','FRILL SOUARE-DOUBLE JERSEY FABRIC(96% POLYESTER, 4% SPANDEX) KNITTED FABRIC.','Inventory',NULL,4,NULL,NULL,NULL,NULL,'FIFO',NULL,0,0,0,0,0,0,0,0,0,1,0,'2026-07-03 03:05:42','2026-07-03 08:48:56'),
(4,'PRD-0004',NULL,NULL,'TWILL-DOUBLE JERSEY FABRIC(96%POLYESTER, 4% SPANDEX) KNITED FABRIC','TWILL-DOUBLE JERSEY FABRIC(96%POLYESTER, 4% SPANDEX) KNITED FABRIC','Inventory',NULL,4,NULL,NULL,NULL,NULL,'FIFO',NULL,0,0,0,0,0,0,0,0,0,0,0,'2026-07-03 03:12:56','2026-07-03 08:55:09'),
(5,'PRD-0005',NULL,NULL,'FRILL VANITY-DOUBLE JERSEY FABRIC(96% POLYESTER, 4% SPANDEX) KNITTED FABRIC','FRILL VANITY-DOUBLE JERSEY FABRIC(96% POLYESTER, 4% SPANDEX) KNITTED FABRIC','Inventory',NULL,4,NULL,NULL,NULL,NULL,'FIFO',NULL,0,0,0,0,0,0,0,0,0,0,0,'2026-07-03 03:17:37','2026-07-03 08:48:34'),
(6,'PRD-0006',NULL,NULL,'COTTON SPANDEX(95% COTTON, 5% SPANDEX) KNITTED FABRIC','COTTON SPANDEX(95% COTTON, 5% SPANDEX) KNITTED FABRIC','Inventory',NULL,3,NULL,NULL,NULL,NULL,'FIFO',NULL,0,0,0,0,0,0,0,0,0,1,0,'2026-07-03 07:14:08','2026-07-03 23:08:17'),
(7,'PRD-0007',NULL,NULL,'100% RAYON FABRIC','100% RAYON FABRIC','Inventory',NULL,7,NULL,NULL,NULL,NULL,NULL,NULL,0,0,0,0,0,0,0,0,0,1,0,'2026-07-03 07:28:33','2026-07-03 23:08:29'),
(8,'PRD-0008',NULL,NULL,'KINTTED DENIM SOLID(70% COTTON, 25% POLYESTER, 5% SPANDEX) FABRIC','KINTTED DENIM SOLID(70% COTTON, 25% POLYESTER, 5% SPANDEX) FABRIC','Inventory',NULL,2,NULL,NULL,NULL,NULL,NULL,NULL,0,0,0,0,0,0,0,0,0,1,0,'2026-07-03 09:04:02','2026-07-03 23:09:03'),
(9,'PRD-0009',NULL,NULL,'PIQUE(95% COTTON BAMBOO, 5% SPANDEX) FABRIC','PIQUE(95% COTTON BAMBOO, 5% SPANDEX) FABRIC','Inventory',NULL,2,NULL,NULL,NULL,NULL,NULL,NULL,0,0,0,0,0,0,0,0,0,1,0,'2026-07-03 09:32:43','2026-07-03 23:09:18'),
(10,'PRD-0010',NULL,NULL,'FRENCH TERRY(100% COTTON) FABRIC','FRENCH TERRY(100% COTTON) FABRIC','Inventory',NULL,2,NULL,NULL,NULL,NULL,NULL,NULL,0,0,0,0,0,0,0,0,0,0,0,'2026-07-03 09:38:45','2026-07-03 23:08:48');
/*!40000 ALTER TABLE `inv_products` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `inv_purchase_order_items`
--

DROP TABLE IF EXISTS `inv_purchase_order_items`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `inv_purchase_order_items` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `po_id` bigint(20) unsigned NOT NULL,
  `product_id` bigint(20) unsigned NOT NULL,
  `unit_id` bigint(20) unsigned DEFAULT NULL,
  `attribute_id` bigint(20) unsigned DEFAULT NULL,
  `pr_item_id` bigint(20) unsigned DEFAULT NULL,
  `quantity_ordered` decimal(15,4) NOT NULL DEFAULT 0.0000,
  `quantity_received` decimal(15,4) NOT NULL DEFAULT 0.0000,
  `unit_price` decimal(15,4) NOT NULL DEFAULT 0.0000,
  `discount` decimal(8,4) NOT NULL DEFAULT 0.0000,
  `tax` decimal(8,4) NOT NULL DEFAULT 0.0000,
  `line_total` decimal(15,4) NOT NULL DEFAULT 0.0000,
  `remarks` varchar(255) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `inv_purchase_order_items_po_id_index` (`po_id`),
  KEY `inv_purchase_order_items_product_id_index` (`product_id`),
  KEY `inv_purchase_order_items_attribute_id_foreign` (`attribute_id`),
  CONSTRAINT `inv_purchase_order_items_attribute_id_foreign` FOREIGN KEY (`attribute_id`) REFERENCES `inv_attributes` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `inv_purchase_order_items`
--

LOCK TABLES `inv_purchase_order_items` WRITE;
/*!40000 ALTER TABLE `inv_purchase_order_items` DISABLE KEYS */;
/*!40000 ALTER TABLE `inv_purchase_order_items` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `inv_purchase_orders`
--

DROP TABLE IF EXISTS `inv_purchase_orders`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `inv_purchase_orders` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `po_no` varchar(30) NOT NULL,
  `reference_no` varchar(100) DEFAULT NULL,
  `pr_id` bigint(20) unsigned DEFAULT NULL,
  `supplier_id` bigint(20) unsigned NOT NULL,
  `store_id` bigint(20) unsigned NOT NULL,
  `location_id` bigint(20) unsigned DEFAULT NULL,
  `order_date` date NOT NULL,
  `expected_delivery_date` date DEFAULT NULL,
  `location` varchar(255) DEFAULT NULL,
  `payment_terms` varchar(100) DEFAULT NULL,
  `contact_person_name` varchar(100) DEFAULT NULL,
  `contact_person_phone` varchar(30) DEFAULT NULL,
  `is_consignment` tinyint(1) NOT NULL DEFAULT 0,
  `billing_address` text DEFAULT NULL,
  `shipping_address` text DEFAULT NULL,
  `status` varchar(30) NOT NULL DEFAULT 'draft',
  `subtotal` decimal(15,4) NOT NULL DEFAULT 0.0000,
  `grand_total` decimal(15,4) NOT NULL DEFAULT 0.0000,
  `remarks` text DEFAULT NULL,
  `created_by` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `inv_purchase_orders_po_no_unique` (`po_no`),
  KEY `inv_purchase_orders_status_index` (`status`),
  KEY `inv_purchase_orders_supplier_id_index` (`supplier_id`),
  KEY `inv_purchase_orders_store_id_index` (`store_id`),
  KEY `inv_purchase_orders_order_date_index` (`order_date`),
  KEY `inv_purchase_orders_pr_id_index` (`pr_id`),
  KEY `inv_purchase_orders_location_id_index` (`location_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `inv_purchase_orders`
--

LOCK TABLES `inv_purchase_orders` WRITE;
/*!40000 ALTER TABLE `inv_purchase_orders` DISABLE KEYS */;
/*!40000 ALTER TABLE `inv_purchase_orders` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `inv_purchase_request_items`
--

DROP TABLE IF EXISTS `inv_purchase_request_items`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `inv_purchase_request_items` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `pr_id` bigint(20) unsigned NOT NULL,
  `product_id` bigint(20) unsigned NOT NULL,
  `unit_id` bigint(20) unsigned DEFAULT NULL,
  `attribute_id` bigint(20) unsigned DEFAULT NULL,
  `quantity` decimal(15,4) NOT NULL DEFAULT 0.0000,
  `estimated_unit_price` decimal(15,4) DEFAULT NULL,
  `remarks` varchar(255) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `inv_purchase_request_items_pr_id_index` (`pr_id`),
  KEY `inv_purchase_request_items_product_id_index` (`product_id`),
  KEY `inv_purchase_request_items_attribute_id_foreign` (`attribute_id`),
  CONSTRAINT `inv_purchase_request_items_attribute_id_foreign` FOREIGN KEY (`attribute_id`) REFERENCES `inv_attributes` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `inv_purchase_request_items`
--

LOCK TABLES `inv_purchase_request_items` WRITE;
/*!40000 ALTER TABLE `inv_purchase_request_items` DISABLE KEYS */;
/*!40000 ALTER TABLE `inv_purchase_request_items` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `inv_purchase_requests`
--

DROP TABLE IF EXISTS `inv_purchase_requests`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `inv_purchase_requests` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `pr_no` varchar(30) NOT NULL,
  `reference_no` varchar(50) NOT NULL,
  `request_date` date NOT NULL,
  `required_date` date DEFAULT NULL,
  `purpose` varchar(200) DEFAULT NULL,
  `source_location_id` bigint(20) unsigned DEFAULT NULL,
  `source_store_id` bigint(20) unsigned DEFAULT NULL,
  `target_location_id` bigint(20) unsigned DEFAULT NULL,
  `target_store_id` bigint(20) unsigned DEFAULT NULL,
  `customer_id` bigint(20) unsigned DEFAULT NULL,
  `transport_mode` varchar(100) DEFAULT NULL,
  `remarks` text DEFAULT NULL,
  `status` varchar(30) NOT NULL DEFAULT 'draft',
  `requested_by` bigint(20) unsigned DEFAULT NULL,
  `approved_by` bigint(20) unsigned DEFAULT NULL,
  `approved_at` timestamp NULL DEFAULT NULL,
  `rejection_reason` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `inv_purchase_requests_pr_no_unique` (`pr_no`),
  UNIQUE KEY `inv_purchase_requests_reference_no_unique` (`reference_no`),
  KEY `inv_purchase_requests_status_index` (`status`),
  KEY `inv_purchase_requests_request_date_index` (`request_date`),
  KEY `inv_purchase_requests_source_store_id_index` (`source_store_id`),
  KEY `inv_purchase_requests_target_store_id_index` (`target_store_id`),
  KEY `inv_purchase_requests_customer_id_index` (`customer_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `inv_purchase_requests`
--

LOCK TABLES `inv_purchase_requests` WRITE;
/*!40000 ALTER TABLE `inv_purchase_requests` DISABLE KEYS */;
/*!40000 ALTER TABLE `inv_purchase_requests` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `inv_sales_channels`
--

DROP TABLE IF EXISTS `inv_sales_channels`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `inv_sales_channels` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `type` enum('Wholesale','e-commerce','Retail') DEFAULT NULL,
  `sales_channel_name` varchar(100) NOT NULL,
  `max_qty` decimal(15,4) DEFAULT NULL,
  `applicable_from` date DEFAULT NULL,
  `applicable_to` date DEFAULT NULL,
  `description` varchar(255) DEFAULT NULL,
  `status` varchar(50) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `inv_sales_channels`
--

LOCK TABLES `inv_sales_channels` WRITE;
/*!40000 ALTER TABLE `inv_sales_channels` DISABLE KEYS */;
INSERT INTO `inv_sales_channels` VALUES
(1,'Wholesale','Warehouse',1000.0000,'2026-06-26','2068-12-26','Main Warehouse Channel','Active','2026-06-26 05:59:25','2026-06-26 05:59:25');
/*!40000 ALTER TABLE `inv_sales_channels` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `inv_sales_order_items`
--

DROP TABLE IF EXISTS `inv_sales_order_items`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `inv_sales_order_items` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `so_id` bigint(20) unsigned NOT NULL,
  `product_id` bigint(20) unsigned NOT NULL,
  `unit_id` bigint(20) unsigned DEFAULT NULL,
  `attribute_id` bigint(20) unsigned DEFAULT NULL,
  `is_scanned` tinyint(1) NOT NULL DEFAULT 0,
  `quantity` decimal(15,4) NOT NULL DEFAULT 0.0000,
  `quantity_delivered` decimal(15,4) NOT NULL DEFAULT 0.0000,
  `unit_price` decimal(15,4) NOT NULL DEFAULT 0.0000,
  `discount` decimal(8,4) NOT NULL DEFAULT 0.0000,
  `tax` decimal(8,4) NOT NULL DEFAULT 0.0000,
  `line_total` decimal(15,4) NOT NULL DEFAULT 0.0000,
  `remarks` varchar(255) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `inv_sales_order_items_so_id_index` (`so_id`),
  KEY `inv_sales_order_items_product_id_index` (`product_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `inv_sales_order_items`
--

LOCK TABLES `inv_sales_order_items` WRITE;
/*!40000 ALTER TABLE `inv_sales_order_items` DISABLE KEYS */;
/*!40000 ALTER TABLE `inv_sales_order_items` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `inv_sales_order_pieces`
--

DROP TABLE IF EXISTS `inv_sales_order_pieces`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `inv_sales_order_pieces` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `so_id` bigint(20) unsigned NOT NULL,
  `so_item_id` bigint(20) unsigned NOT NULL,
  `piece_id` bigint(20) unsigned NOT NULL,
  `piece_code` varchar(40) NOT NULL,
  `weight` decimal(15,4) NOT NULL DEFAULT 0.0000,
  `grn_unit_price` decimal(15,4) NOT NULL DEFAULT 0.0000,
  `created_by` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `inv_sales_order_pieces_piece_id_unique` (`piece_id`),
  KEY `inv_sales_order_pieces_so_id_index` (`so_id`),
  KEY `inv_sales_order_pieces_so_item_id_index` (`so_item_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `inv_sales_order_pieces`
--

LOCK TABLES `inv_sales_order_pieces` WRITE;
/*!40000 ALTER TABLE `inv_sales_order_pieces` DISABLE KEYS */;
/*!40000 ALTER TABLE `inv_sales_order_pieces` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `inv_sales_orders`
--

DROP TABLE IF EXISTS `inv_sales_orders`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `inv_sales_orders` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `so_no` varchar(30) NOT NULL,
  `reference_no` varchar(100) DEFAULT NULL,
  `customer_id` bigint(20) unsigned NOT NULL,
  `sales_person_id` bigint(20) unsigned NOT NULL,
  `order_taken_by` bigint(20) unsigned DEFAULT NULL,
  `customer_type` varchar(30) DEFAULT NULL,
  `order_date` date NOT NULL,
  `expected_date` date DEFAULT NULL,
  `transaction_date` date DEFAULT NULL,
  `order_source` varchar(30) DEFAULT NULL,
  `delivery_address` text DEFAULT NULL,
  `status` varchar(30) NOT NULL DEFAULT 'draft',
  `subtotal` decimal(15,4) NOT NULL DEFAULT 0.0000,
  `transport_charge` decimal(15,4) NOT NULL DEFAULT 0.0000,
  `grand_total` decimal(15,4) NOT NULL DEFAULT 0.0000,
  `remarks` text DEFAULT NULL,
  `created_by` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `inv_sales_orders_so_no_unique` (`so_no`),
  KEY `inv_sales_orders_status_index` (`status`),
  KEY `inv_sales_orders_customer_id_index` (`customer_id`),
  KEY `inv_sales_orders_sales_person_id_index` (`sales_person_id`),
  KEY `inv_sales_orders_order_date_index` (`order_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `inv_sales_orders`
--

LOCK TABLES `inv_sales_orders` WRITE;
/*!40000 ALTER TABLE `inv_sales_orders` DISABLE KEYS */;
/*!40000 ALTER TABLE `inv_sales_orders` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `inv_stock_reference_types`
--

DROP TABLE IF EXISTS `inv_stock_reference_types`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `inv_stock_reference_types` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `code` varchar(50) NOT NULL,
  `label` varchar(100) NOT NULL,
  `sort_order` int(10) unsigned NOT NULL DEFAULT 0,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `inv_stock_reference_types_code_unique` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `inv_stock_reference_types`
--

LOCK TABLES `inv_stock_reference_types` WRITE;
/*!40000 ALTER TABLE `inv_stock_reference_types` DISABLE KEYS */;
/*!40000 ALTER TABLE `inv_stock_reference_types` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `inv_stock_transactions`
--

DROP TABLE IF EXISTS `inv_stock_transactions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `inv_stock_transactions` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `transaction_date` datetime NOT NULL,
  `reference_type` varchar(50) NOT NULL,
  `reference_id` bigint(20) unsigned NOT NULL,
  `product_id` bigint(20) unsigned NOT NULL,
  `store_id` bigint(20) unsigned DEFAULT NULL,
  `location_id` bigint(20) unsigned DEFAULT NULL,
  `batch_no` varchar(100) DEFAULT NULL,
  `batch_id` bigint(20) unsigned DEFAULT NULL,
  `expiry_date` date DEFAULT NULL,
  `qty_in` decimal(15,4) NOT NULL DEFAULT 0.0000,
  `qty_out` decimal(15,4) NOT NULL DEFAULT 0.0000,
  `unit_id` bigint(20) unsigned DEFAULT NULL,
  `unit_price` decimal(15,4) DEFAULT NULL,
  `created_by` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_stock_position` (`product_id`,`store_id`,`location_id`),
  KEY `idx_reference` (`reference_type`,`reference_id`),
  KEY `inv_stock_transactions_transaction_date_index` (`transaction_date`),
  KEY `inv_stock_transactions_batch_id_index` (`batch_id`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `inv_stock_transactions`
--

LOCK TABLES `inv_stock_transactions` WRITE;
/*!40000 ALTER TABLE `inv_stock_transactions` DISABLE KEYS */;
/*!40000 ALTER TABLE `inv_stock_transactions` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `inv_store_types`
--

DROP TABLE IF EXISTS `inv_store_types`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `inv_store_types` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `store_type_name` varchar(100) NOT NULL,
  `description` text DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `inv_store_types`
--

LOCK TABLES `inv_store_types` WRITE;
/*!40000 ALTER TABLE `inv_store_types` DISABLE KEYS */;
INSERT INTO `inv_store_types` VALUES
(1,'Warehouse',NULL,1,'2026-06-26 05:38:07','2026-06-26 05:38:07',NULL);
/*!40000 ALTER TABLE `inv_store_types` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `inv_stores`
--

DROP TABLE IF EXISTS `inv_stores`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `inv_stores` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `store_type_id` bigint(20) unsigned NOT NULL,
  `location_id` bigint(20) unsigned DEFAULT NULL,
  `parent_store_id` bigint(20) unsigned DEFAULT NULL,
  `store_code` varchar(50) NOT NULL,
  `store_name` varchar(150) NOT NULL,
  `uom` varchar(50) DEFAULT NULL,
  `capacity` decimal(15,4) DEFAULT NULL,
  `address_line_1` varchar(150) DEFAULT NULL,
  `address_line_2` varchar(150) DEFAULT NULL,
  `city` varchar(100) DEFAULT NULL,
  `state` varchar(100) DEFAULT NULL,
  `country` varchar(100) DEFAULT NULL,
  `postal_code` varchar(20) DEFAULT NULL,
  `manager_name` varchar(100) DEFAULT NULL,
  `phone` varchar(20) DEFAULT NULL,
  `email` varchar(100) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `inv_stores_store_code_unique` (`store_code`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `inv_stores`
--

LOCK TABLES `inv_stores` WRITE;
/*!40000 ALTER TABLE `inv_stores` DISABLE KEYS */;
INSERT INTO `inv_stores` VALUES
(1,1,1,NULL,'01','Main Wharehouse',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,1,'2026-06-26 05:38:34','2026-06-26 05:38:34',NULL);
/*!40000 ALTER TABLE `inv_stores` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `inv_supplier_attachments`
--

DROP TABLE IF EXISTS `inv_supplier_attachments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `inv_supplier_attachments` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `supplier_master_id` bigint(20) unsigned NOT NULL,
  `file_name` varchar(255) NOT NULL,
  `file_path` varchar(255) NOT NULL,
  `file_size` bigint(20) unsigned DEFAULT NULL,
  `mime_type` varchar(100) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `inv_supplier_attachments_supplier_master_id_index` (`supplier_master_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `inv_supplier_attachments`
--

LOCK TABLES `inv_supplier_attachments` WRITE;
/*!40000 ALTER TABLE `inv_supplier_attachments` DISABLE KEYS */;
/*!40000 ALTER TABLE `inv_supplier_attachments` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `inv_supplier_credit_notes`
--

DROP TABLE IF EXISTS `inv_supplier_credit_notes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `inv_supplier_credit_notes` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `credit_note_no` varchar(30) DEFAULT NULL,
  `supplier_id` bigint(20) unsigned NOT NULL,
  `credit_type` varchar(20) NOT NULL,
  `amount` decimal(15,4) NOT NULL DEFAULT 0.0000,
  `remaining_balance` decimal(15,4) NOT NULL DEFAULT 0.0000,
  `remark` text DEFAULT NULL,
  `status` varchar(20) NOT NULL DEFAULT 'open',
  `source_payment_id` bigint(20) unsigned DEFAULT NULL,
  `created_by` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `inv_supplier_credit_notes_credit_note_no_unique` (`credit_note_no`),
  KEY `inv_supplier_credit_notes_supplier_id_index` (`supplier_id`),
  KEY `inv_supplier_credit_notes_status_index` (`status`),
  KEY `inv_supplier_credit_notes_credit_type_index` (`credit_type`),
  KEY `inv_supplier_credit_notes_source_payment_id_index` (`source_payment_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `inv_supplier_credit_notes`
--

LOCK TABLES `inv_supplier_credit_notes` WRITE;
/*!40000 ALTER TABLE `inv_supplier_credit_notes` DISABLE KEYS */;
/*!40000 ALTER TABLE `inv_supplier_credit_notes` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `inv_supplier_masters`
--

DROP TABLE IF EXISTS `inv_supplier_masters`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `inv_supplier_masters` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `supplier_code` varchar(50) DEFAULT NULL,
  `reference_no` varchar(50) DEFAULT NULL,
  `supplier_type` varchar(50) DEFAULT NULL,
  `supplier_name` varchar(100) NOT NULL,
  `check_writer_name` varchar(100) DEFAULT NULL,
  `mobile` varchar(20) DEFAULT NULL,
  `land_line` varchar(20) DEFAULT NULL,
  `email` varchar(100) DEFAULT NULL,
  `wechat` varchar(100) DEFAULT NULL,
  `whatsapp` varchar(20) DEFAULT NULL,
  `fax` varchar(20) DEFAULT NULL,
  `website` varchar(255) DEFAULT NULL,
  `bil_address_line_1` varchar(100) DEFAULT NULL,
  `bil_address_line_2` varchar(100) DEFAULT NULL,
  `bil_address_line_3` varchar(100) DEFAULT NULL,
  `bil_city` varchar(50) DEFAULT NULL,
  `bil_postal_code` varchar(20) DEFAULT NULL,
  `bil_country` varchar(50) DEFAULT NULL,
  `bil_state_province` varchar(50) DEFAULT NULL,
  `tax_type` varchar(50) DEFAULT NULL,
  `tax_no` varchar(50) DEFAULT NULL,
  `tax_regis_no` varchar(50) DEFAULT NULL,
  `credit_limit` decimal(15,2) DEFAULT NULL,
  `credit_period` smallint(5) unsigned DEFAULT NULL,
  `privileges_discount` decimal(5,2) DEFAULT NULL,
  `bank_name` varchar(100) DEFAULT NULL,
  `bank_branch` varchar(100) DEFAULT NULL,
  `bank_acc_holder_name` varchar(100) DEFAULT NULL,
  `bank_acc_no` varchar(50) DEFAULT NULL,
  `contact_person_name` varchar(100) DEFAULT NULL,
  `contact_person_designation` varchar(100) DEFAULT NULL,
  `contact_person_mobile` varchar(20) DEFAULT NULL,
  `contact_person_email` varchar(100) DEFAULT NULL,
  `contact_person_fax` varchar(20) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `inv_supplier_masters_supplier_code_unique` (`supplier_code`)
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `inv_supplier_masters`
--

LOCK TABLES `inv_supplier_masters` WRITE;
/*!40000 ALTER TABLE `inv_supplier_masters` DISABLE KEYS */;
INSERT INTO `inv_supplier_masters` VALUES
(1,'SUP-0645','REF-001','Trade','SUMICOT VASUKAMAL','SUMICOT VASUKAMAL','+919850015588','+919850015588','lav.kabra@svopl.co.in',NULL,'+919850015588',NULL,'https://www.sumicot.com/','809,8th Floor Rajhans Mantessa,B/s Le Meridine Hotel,Near Airport-Dumas Rd, Magdella,Surat-395007,','Gujarat,India',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'Lav kabra',NULL,'+9850015588',NULL,NULL,'2026-06-26 06:10:34','2026-06-27 04:16:06'),
(2,'SUP-3887',NULL,'Service','SHAOXING CITI YILIN TEXTILE CO., Limited',NULL,'+8618932903575','+8613282918793','sales102@hanlintextile.com','+8618333159520',NULL,NULL,NULL,'Room 1701, East Zone, Yulan International, No 1108, Hudong Road, Keqiao District, Shaoxing City,','Zhejiang Province, China',NULL,'Shaoxing City','312000','China','Zhejiang',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'Cai Miya','Manager','+8618333159520',NULL,NULL,'2026-06-27 04:35:04','2026-06-27 04:35:04'),
(3,'SUP-6266',NULL,'Service','HEBEI HANLIN TEXTILE CO.Limited',NULL,'+8615833901261','+8615833901261','sales102@hanlintextile.com',NULL,'+8615833901261',NULL,NULL,'Room 1910, Caiku Guoji, Yuhua District, Shijiazhuang City, Hebei Province, China',NULL,NULL,'Shijiazhuang','312000','China','Zhejiang',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'Jessica',NULL,'+8615833901261',NULL,NULL,'2026-06-27 04:43:31','2026-06-27 04:43:31'),
(4,'SUP-1485',NULL,'Trade','SHAOXING CITY XUANLIN TEXTILE CO., Limited',NULL,'+8618333159520','+8618333159520','sales102@hanlintextile.com',NULL,'+8618333159520',NULL,NULL,'Room 203, 2nd Floor, Dashugang Qiaodingfan, Yuecheng District, Shaoxing City, Zhejiang Province','China',NULL,'Shaoxing','312000','China',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'Lu','G/Manager','+8618333159520',NULL,NULL,'2026-06-27 04:57:15','2026-06-27 04:57:15'),
(5,'SUP-8116',NULL,'Trade','YIWU DAOCHANG IMPORT & EXPORT CO., LIMITED',NULL,'+8618333159520','+8618333159520','sales102@hanlintextile.com',NULL,NULL,NULL,NULL,'Flat 07 BLK b23/F Hoover Bldg, No 26-38, Kwai Cheong Rd, Kwai Chung NT, Hong Kong',NULL,NULL,'Kwai Chung','999077','Hong KOng',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'Han',NULL,'+8618333159520',NULL,NULL,'2026-06-27 05:15:21','2026-06-27 05:15:21');
/*!40000 ALTER TABLE `inv_supplier_masters` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `inv_supplier_payment_allocations`
--

DROP TABLE IF EXISTS `inv_supplier_payment_allocations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `inv_supplier_payment_allocations` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `payment_id` bigint(20) unsigned NOT NULL,
  `reference_type` varchar(30) NOT NULL DEFAULT 'grn',
  `reference_id` bigint(20) unsigned NOT NULL,
  `grn_date` date DEFAULT NULL,
  `po_no` varchar(30) DEFAULT NULL,
  `reference_no` varchar(100) DEFAULT NULL,
  `grn_amount` decimal(15,4) NOT NULL DEFAULT 0.0000,
  `due_date` date DEFAULT NULL,
  `outstanding_before` decimal(15,4) NOT NULL DEFAULT 0.0000,
  `discount` decimal(15,4) NOT NULL DEFAULT 0.0000,
  `payment_amount` decimal(15,4) NOT NULL DEFAULT 0.0000,
  `line_remark` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `inv_supplier_payment_allocations_payment_id_foreign` (`payment_id`),
  KEY `inv_sp_allocations_ref_idx` (`reference_type`,`reference_id`),
  CONSTRAINT `inv_supplier_payment_allocations_payment_id_foreign` FOREIGN KEY (`payment_id`) REFERENCES `inv_supplier_payments` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `inv_supplier_payment_allocations`
--

LOCK TABLES `inv_supplier_payment_allocations` WRITE;
/*!40000 ALTER TABLE `inv_supplier_payment_allocations` DISABLE KEYS */;
/*!40000 ALTER TABLE `inv_supplier_payment_allocations` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `inv_supplier_payment_setoffs`
--

DROP TABLE IF EXISTS `inv_supplier_payment_setoffs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `inv_supplier_payment_setoffs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `payment_id` bigint(20) unsigned NOT NULL,
  `setoff_type` varchar(20) NOT NULL,
  `credit_note_id` bigint(20) unsigned DEFAULT NULL,
  `amount` decimal(15,4) NOT NULL DEFAULT 0.0000,
  `remark` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `inv_supplier_payment_setoffs_credit_note_id_foreign` (`credit_note_id`),
  KEY `inv_supplier_payment_setoffs_payment_id_index` (`payment_id`),
  CONSTRAINT `inv_supplier_payment_setoffs_credit_note_id_foreign` FOREIGN KEY (`credit_note_id`) REFERENCES `inv_supplier_credit_notes` (`id`) ON DELETE SET NULL,
  CONSTRAINT `inv_supplier_payment_setoffs_payment_id_foreign` FOREIGN KEY (`payment_id`) REFERENCES `inv_supplier_payments` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `inv_supplier_payment_setoffs`
--

LOCK TABLES `inv_supplier_payment_setoffs` WRITE;
/*!40000 ALTER TABLE `inv_supplier_payment_setoffs` DISABLE KEYS */;
/*!40000 ALTER TABLE `inv_supplier_payment_setoffs` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `inv_supplier_payment_settlements`
--

DROP TABLE IF EXISTS `inv_supplier_payment_settlements`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `inv_supplier_payment_settlements` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `payment_id` bigint(20) unsigned NOT NULL,
  `payment_mode_id` bigint(20) unsigned NOT NULL,
  `payment_mode_code` varchar(30) NOT NULL,
  `payment_mode_name` varchar(50) NOT NULL,
  `amount` decimal(15,4) NOT NULL DEFAULT 0.0000,
  `bank_name` varchar(100) DEFAULT NULL,
  `bank_account_no` varchar(50) DEFAULT NULL,
  `reference_no` varchar(50) DEFAULT NULL,
  `instrument_date` date DEFAULT NULL,
  `is_thirdparty` tinyint(1) NOT NULL DEFAULT 0,
  `remark` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `inv_supplier_payment_settlements_payment_id_index` (`payment_id`),
  CONSTRAINT `inv_supplier_payment_settlements_payment_id_foreign` FOREIGN KEY (`payment_id`) REFERENCES `inv_supplier_payments` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `inv_supplier_payment_settlements`
--

LOCK TABLES `inv_supplier_payment_settlements` WRITE;
/*!40000 ALTER TABLE `inv_supplier_payment_settlements` DISABLE KEYS */;
/*!40000 ALTER TABLE `inv_supplier_payment_settlements` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `inv_supplier_payments`
--

DROP TABLE IF EXISTS `inv_supplier_payments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `inv_supplier_payments` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `payment_no` varchar(30) NOT NULL,
  `payment_date` date NOT NULL,
  `transaction_date` date DEFAULT NULL,
  `reference_no` varchar(100) DEFAULT NULL,
  `supplier_type` varchar(50) DEFAULT NULL,
  `supplier_id` bigint(20) unsigned NOT NULL,
  `payment_remark` text DEFAULT NULL,
  `is_advance` tinyint(1) NOT NULL DEFAULT 0,
  `advance_amount` decimal(15,4) NOT NULL DEFAULT 0.0000,
  `gross_amount` decimal(15,4) NOT NULL DEFAULT 0.0000,
  `discount_amount` decimal(15,4) NOT NULL DEFAULT 0.0000,
  `setoff_amount` decimal(15,4) NOT NULL DEFAULT 0.0000,
  `net_amount` decimal(15,4) NOT NULL DEFAULT 0.0000,
  `status` varchar(20) NOT NULL DEFAULT 'draft',
  `created_by` bigint(20) unsigned DEFAULT NULL,
  `confirmed_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `inv_supplier_payments_payment_no_unique` (`payment_no`),
  KEY `inv_supplier_payments_supplier_id_index` (`supplier_id`),
  KEY `inv_supplier_payments_status_index` (`status`),
  KEY `inv_supplier_payments_payment_date_index` (`payment_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `inv_supplier_payments`
--

LOCK TABLES `inv_supplier_payments` WRITE;
/*!40000 ALTER TABLE `inv_supplier_payments` DISABLE KEYS */;
/*!40000 ALTER TABLE `inv_supplier_payments` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `inv_unit_categories`
--

DROP TABLE IF EXISTS `inv_unit_categories`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `inv_unit_categories` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `description` varchar(255) DEFAULT NULL,
  `base_unit_type_id` bigint(20) unsigned DEFAULT NULL,
  `is_default` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `inv_unit_categories`
--

LOCK TABLES `inv_unit_categories` WRITE;
/*!40000 ALTER TABLE `inv_unit_categories` DISABLE KEYS */;
INSERT INTO `inv_unit_categories` VALUES
(1,'Weight',NULL,NULL,0,'2026-06-26 05:46:02','2026-06-26 05:47:32'),
(2,'Length',NULL,NULL,0,'2026-06-26 05:48:03','2026-06-26 05:48:03');
/*!40000 ALTER TABLE `inv_unit_categories` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `inv_unit_conversions`
--

DROP TABLE IF EXISTS `inv_unit_conversions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `inv_unit_conversions` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `from_unit_type_id` bigint(20) unsigned NOT NULL,
  `to_unit_type_id` bigint(20) unsigned NOT NULL,
  `multiplier` decimal(20,10) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `inv_unit_conversions_from_unit_type_id_to_unit_type_id_unique` (`from_unit_type_id`,`to_unit_type_id`),
  KEY `inv_unit_conversions_to_unit_type_id_foreign` (`to_unit_type_id`),
  CONSTRAINT `inv_unit_conversions_from_unit_type_id_foreign` FOREIGN KEY (`from_unit_type_id`) REFERENCES `inv_unit_types` (`id`),
  CONSTRAINT `inv_unit_conversions_to_unit_type_id_foreign` FOREIGN KEY (`to_unit_type_id`) REFERENCES `inv_unit_types` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `inv_unit_conversions`
--

LOCK TABLES `inv_unit_conversions` WRITE;
/*!40000 ALTER TABLE `inv_unit_conversions` DISABLE KEYS */;
/*!40000 ALTER TABLE `inv_unit_conversions` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `inv_unit_types`
--

DROP TABLE IF EXISTS `inv_unit_types`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `inv_unit_types` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `unit_category_id` bigint(20) unsigned NOT NULL,
  `name` varchar(100) NOT NULL,
  `symbol` varchar(45) NOT NULL,
  `country` varchar(45) DEFAULT NULL,
  `unit_position` enum('prefix','suffix') NOT NULL DEFAULT 'suffix',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `inv_unit_types_unit_category_id_foreign` (`unit_category_id`),
  CONSTRAINT `inv_unit_types_unit_category_id_foreign` FOREIGN KEY (`unit_category_id`) REFERENCES `inv_unit_categories` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `inv_unit_types`
--

LOCK TABLES `inv_unit_types` WRITE;
/*!40000 ALTER TABLE `inv_unit_types` DISABLE KEYS */;
INSERT INTO `inv_unit_types` VALUES
(1,1,'Kilograms','KG',NULL,'suffix','2026-06-26 05:46:50','2026-06-26 05:46:50'),
(2,2,'Meter','M',NULL,'suffix','2026-06-26 05:58:02','2026-06-26 05:58:02'),
(4,1,'Gram','G',NULL,'suffix','2026-06-26 05:59:58','2026-06-26 06:04:03');
/*!40000 ALTER TABLE `inv_unit_types` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `inv_vehicle_drivers`
--

DROP TABLE IF EXISTS `inv_vehicle_drivers`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `inv_vehicle_drivers` (
  `vehicle_master_id` bigint(20) unsigned NOT NULL,
  `driver_id` bigint(20) unsigned NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`vehicle_master_id`,`driver_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `inv_vehicle_drivers`
--

LOCK TABLES `inv_vehicle_drivers` WRITE;
/*!40000 ALTER TABLE `inv_vehicle_drivers` DISABLE KEYS */;
/*!40000 ALTER TABLE `inv_vehicle_drivers` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `inv_vehicle_masters`
--

DROP TABLE IF EXISTS `inv_vehicle_masters`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `inv_vehicle_masters` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `vehicle_code` varchar(20) NOT NULL,
  `registration_number` varchar(50) NOT NULL,
  `make` varchar(100) DEFAULT NULL,
  `model` varchar(100) DEFAULT NULL,
  `year` smallint(5) unsigned DEFAULT NULL,
  `color` varchar(50) DEFAULT NULL,
  `vehicle_type` enum('Car','Van','Truck','Bus','Motorcycle','Heavy Truck') DEFAULT NULL,
  `fuel_type` enum('Petrol','Diesel','Electric','Hybrid','CNG') DEFAULT NULL,
  `engine_number` varchar(100) DEFAULT NULL,
  `chassis_number` varchar(100) DEFAULT NULL,
  `seating_capacity` tinyint(3) unsigned DEFAULT NULL,
  `payload_capacity` decimal(10,2) DEFAULT NULL,
  `insurance_policy_no` varchar(50) DEFAULT NULL,
  `insurance_expiry_date` date DEFAULT NULL,
  `road_tax_expiry_date` date DEFAULT NULL,
  `emission_test_expiry_date` date DEFAULT NULL,
  `assigned_driver_id` bigint(20) unsigned DEFAULT NULL,
  `status` enum('active','inactive','under_maintenance') NOT NULL DEFAULT 'active',
  `notes` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `inv_vehicle_masters_vehicle_code_unique` (`vehicle_code`),
  UNIQUE KEY `inv_vehicle_masters_registration_number_unique` (`registration_number`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `inv_vehicle_masters`
--

LOCK TABLES `inv_vehicle_masters` WRITE;
/*!40000 ALTER TABLE `inv_vehicle_masters` DISABLE KEYS */;
/*!40000 ALTER TABLE `inv_vehicle_masters` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `job_batches`
--

DROP TABLE IF EXISTS `job_batches`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `job_batches` (
  `id` varchar(255) NOT NULL,
  `name` varchar(255) NOT NULL,
  `total_jobs` int(11) NOT NULL,
  `pending_jobs` int(11) NOT NULL,
  `failed_jobs` int(11) NOT NULL,
  `failed_job_ids` longtext NOT NULL,
  `options` mediumtext DEFAULT NULL,
  `cancelled_at` int(11) DEFAULT NULL,
  `created_at` int(11) NOT NULL,
  `finished_at` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `job_batches`
--

LOCK TABLES `job_batches` WRITE;
/*!40000 ALTER TABLE `job_batches` DISABLE KEYS */;
/*!40000 ALTER TABLE `job_batches` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `jobs`
--

DROP TABLE IF EXISTS `jobs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `jobs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `queue` varchar(255) NOT NULL,
  `payload` longtext NOT NULL,
  `attempts` tinyint(3) unsigned NOT NULL,
  `reserved_at` int(10) unsigned DEFAULT NULL,
  `available_at` int(10) unsigned NOT NULL,
  `created_at` int(10) unsigned NOT NULL,
  PRIMARY KEY (`id`),
  KEY `jobs_queue_index` (`queue`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `jobs`
--

LOCK TABLES `jobs` WRITE;
/*!40000 ALTER TABLE `jobs` DISABLE KEYS */;
/*!40000 ALTER TABLE `jobs` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `migrations`
--

DROP TABLE IF EXISTS `migrations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `migrations` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `migration` varchar(255) NOT NULL,
  `batch` int(11) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=78 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `migrations`
--

LOCK TABLES `migrations` WRITE;
/*!40000 ALTER TABLE `migrations` DISABLE KEYS */;
INSERT INTO `migrations` VALUES
(1,'0001_01_01_000000_create_users_table',1),
(2,'0001_01_01_000001_create_cache_table',1),
(3,'0001_01_01_000002_create_jobs_table',1),
(4,'2026_06_11_184821_create_personal_access_tokens_table',1),
(5,'2026_06_12_000001_create_unit_categories_table',1),
(6,'2026_06_12_000002_create_unit_types_table',1),
(7,'2026_06_12_000003_create_unit_conversions_table',1),
(8,'2026_06_12_000004_create_products_table',1),
(9,'2026_06_12_000005_create_supplier_masters_table',1),
(10,'2026_06_12_000006_create_product_supplier_table',1),
(11,'2026_06_12_065346_create_permission_tables',1),
(12,'2026_06_12_200000_create_global_settings_table',1),
(13,'2026_06_12_310000_create_inv_industries_table',1),
(14,'2026_06_12_310001_create_inv_companies_table',1),
(15,'2026_06_12_310002_create_inv_locations_table',1),
(16,'2026_06_12_310003_create_inv_categories_table',1),
(17,'2026_06_12_310004_create_inv_attribute_types_table',1),
(18,'2026_06_12_310005_create_inv_attributes_table',1),
(19,'2026_06_12_310006_create_inv_customer_masters_table',1),
(20,'2026_06_12_310007_create_inv_product_images_table',1),
(21,'2026_06_12_310008_create_inv_product_attributes_table',1),
(22,'2026_06_12_310009_create_inv_store_types_table',1),
(23,'2026_06_12_310010_create_inv_stores_table',1),
(24,'2026_06_12_310011_create_inv_location_stores_table',1),
(25,'2026_06_12_310012_create_inv_product_location_stores_table',1),
(26,'2026_06_12_310013_create_inv_sales_channels_table',1),
(27,'2026_06_12_310014_create_inv_product_sales_channels_table',1),
(28,'2026_06_12_310015_create_inv_vehicle_masters_table',1),
(29,'2026_06_12_310016_create_inv_drivers_table',1),
(30,'2026_06_12_310017_create_inv_vehicle_drivers_table',1),
(31,'2026_06_13_500001_create_inv_customer_attachments_table',1),
(32,'2026_06_19_100001_create_inv_purchase_requests_table',1),
(33,'2026_06_19_100002_create_inv_purchase_request_items_table',1),
(34,'2026_06_19_100003_create_inv_purchase_orders_table',1),
(35,'2026_06_19_100004_create_inv_purchase_order_items_table',1),
(36,'2026_06_19_100005_create_inv_goods_received_notes_table',1),
(37,'2026_06_19_100006_create_inv_goods_received_note_items_table',1),
(38,'2026_06_19_100007_create_inv_stock_transactions_table',1),
(39,'2026_06_20_000006_create_inv_batches_table',1),
(40,'2026_06_20_000007_create_inv_grn_item_batches_table',1),
(41,'2026_06_21_000001_create_inv_costing_expense_types_table',1),
(42,'2026_06_21_000002_create_inv_costings_table',1),
(43,'2026_06_21_000003_create_inv_costing_grns_table',1),
(44,'2026_06_21_000004_create_inv_costing_expenses_table',1),
(45,'2026_06_21_100001_create_inv_grn_attachments_table',1),
(46,'2026_06_26_000001_add_wechat_whatsapp_to_inv_supplier_masters',1),
(47,'2026_06_26_000002_create_inv_supplier_attachments_table',1),
(48,'2026_06_26_000003_add_customer_tin_to_inv_customer_masters',1),
(49,'2026_07_02_000001_add_no_of_pieces_to_inv_goods_received_note_items_table',2),
(50,'2026_07_02_000002_create_inv_grn_item_pieces_table',2),
(51,'2026_07_02_000003_create_inv_supplier_payments_table',2),
(52,'2026_07_02_000004_create_inv_supplier_payment_allocations_table',2),
(53,'2026_07_02_000005_create_inv_supplier_credit_notes_table',2),
(54,'2026_07_02_000006_create_inv_supplier_payment_setoffs_table',2),
(55,'2026_07_03_000001_create_inv_payment_modes_table',2),
(56,'2026_07_03_000002_create_inv_supplier_payment_settlements_table',2),
(57,'2026_07_03_000003_add_discount_fields_to_inv_supplier_payment_tables',2),
(58,'2026_07_03_000004_add_credit_note_no_to_inv_supplier_credit_notes_table',2),
(59,'2026_07_03_000005_add_unit_type_id_to_inv_product_sales_channels_table',3),
(60,'2026_07_03_000002_add_roll_details_to_inv_grn_item_pieces_table',4),
(61,'2026_07_05_000001_add_attribute_id_to_inv_purchase_order_items_table',4),
(62,'2026_07_05_000002_add_attribute_id_to_inv_goods_received_note_items_table',4),
(63,'2026_07_06_000001_add_shipping_code_to_inv_goods_received_notes_table',4),
(64,'2026_07_07_000001_add_attribute_id_to_inv_purchase_request_items_table',4),
(65,'2026_07_07_000002_create_inv_stock_reference_types_table',4),
(66,'2026_07_08_100001_create_inv_sales_orders_table',4),
(67,'2026_07_08_100002_create_inv_sales_order_items_table',4),
(68,'2026_07_08_100003_create_inv_sales_order_pieces_table',4),
(69,'2026_07_09_000001_create_inv_delivery_orders_table',4),
(70,'2026_07_09_000002_create_inv_delivery_order_items_table',4),
(71,'2026_07_09_000003_create_inv_delivery_order_pieces_table',4),
(72,'2026_07_09_000004_add_quantity_delivered_to_inv_sales_order_items_table',4),
(73,'2026_07_09_000005_create_inv_invoices_table',4),
(74,'2026_07_09_000006_create_inv_invoice_items_table',4),
(75,'2026_07_10_000001_add_dispatch_fields_to_inv_delivery_orders_table',4),
(76,'2026_07_10_000001_create_inv_costing_items_table',4),
(77,'2026_07_10_000002_add_pricing_fields_to_inv_costings_table',4);
/*!40000 ALTER TABLE `migrations` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `model_has_permissions`
--

DROP TABLE IF EXISTS `model_has_permissions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `model_has_permissions` (
  `permission_id` bigint(20) unsigned NOT NULL,
  `model_type` varchar(255) NOT NULL,
  `model_id` bigint(20) unsigned NOT NULL,
  PRIMARY KEY (`permission_id`,`model_id`,`model_type`),
  KEY `model_has_permissions_model_id_model_type_index` (`model_id`,`model_type`),
  CONSTRAINT `model_has_permissions_permission_id_foreign` FOREIGN KEY (`permission_id`) REFERENCES `permissions` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `model_has_permissions`
--

LOCK TABLES `model_has_permissions` WRITE;
/*!40000 ALTER TABLE `model_has_permissions` DISABLE KEYS */;
/*!40000 ALTER TABLE `model_has_permissions` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `model_has_roles`
--

DROP TABLE IF EXISTS `model_has_roles`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `model_has_roles` (
  `role_id` bigint(20) unsigned NOT NULL,
  `model_type` varchar(255) NOT NULL,
  `model_id` bigint(20) unsigned NOT NULL,
  PRIMARY KEY (`role_id`,`model_id`,`model_type`),
  KEY `model_has_roles_model_id_model_type_index` (`model_id`,`model_type`),
  CONSTRAINT `model_has_roles_role_id_foreign` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `model_has_roles`
--

LOCK TABLES `model_has_roles` WRITE;
/*!40000 ALTER TABLE `model_has_roles` DISABLE KEYS */;
INSERT INTO `model_has_roles` VALUES
(1,'App\\Models\\User',1);
/*!40000 ALTER TABLE `model_has_roles` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `password_reset_tokens`
--

DROP TABLE IF EXISTS `password_reset_tokens`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `password_reset_tokens` (
  `email` varchar(255) NOT NULL,
  `token` varchar(255) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `password_reset_tokens`
--

LOCK TABLES `password_reset_tokens` WRITE;
/*!40000 ALTER TABLE `password_reset_tokens` DISABLE KEYS */;
/*!40000 ALTER TABLE `password_reset_tokens` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `permissions`
--

DROP TABLE IF EXISTS `permissions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `permissions` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `guard_name` varchar(255) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `permissions_name_guard_name_unique` (`name`,`guard_name`)
) ENGINE=InnoDB AUTO_INCREMENT=100 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `permissions`
--

LOCK TABLES `permissions` WRITE;
/*!40000 ALTER TABLE `permissions` DISABLE KEYS */;
INSERT INTO `permissions` VALUES
(1,'view_products','web','2026-06-25 21:14:29','2026-06-25 21:14:29'),
(2,'create_products','web','2026-06-25 21:14:29','2026-06-25 21:14:29'),
(3,'edit_products','web','2026-06-25 21:14:29','2026-06-25 21:14:29'),
(4,'delete_products','web','2026-06-25 21:14:29','2026-06-25 21:14:29'),
(5,'view_categories','web','2026-06-25 21:14:29','2026-06-25 21:14:29'),
(6,'create_categories','web','2026-06-25 21:14:29','2026-06-25 21:14:29'),
(7,'edit_categories','web','2026-06-25 21:14:29','2026-06-25 21:14:29'),
(8,'delete_categories','web','2026-06-25 21:14:29','2026-06-25 21:14:29'),
(9,'view_unit_categories','web','2026-06-25 21:14:29','2026-06-25 21:14:29'),
(10,'create_unit_categories','web','2026-06-25 21:14:29','2026-06-25 21:14:29'),
(11,'edit_unit_categories','web','2026-06-25 21:14:29','2026-06-25 21:14:29'),
(12,'delete_unit_categories','web','2026-06-25 21:14:29','2026-06-25 21:14:29'),
(13,'view_unit_types','web','2026-06-25 21:14:29','2026-06-25 21:14:29'),
(14,'create_unit_types','web','2026-06-25 21:14:29','2026-06-25 21:14:29'),
(15,'edit_unit_types','web','2026-06-25 21:14:29','2026-06-25 21:14:29'),
(16,'delete_unit_types','web','2026-06-25 21:14:29','2026-06-25 21:14:29'),
(17,'view_unit_conversions','web','2026-06-25 21:14:29','2026-06-25 21:14:29'),
(18,'create_unit_conversions','web','2026-06-25 21:14:29','2026-06-25 21:14:29'),
(19,'edit_unit_conversions','web','2026-06-25 21:14:29','2026-06-25 21:14:29'),
(20,'delete_unit_conversions','web','2026-06-25 21:14:29','2026-06-25 21:14:29'),
(21,'view_supplier_masters','web','2026-06-25 21:14:29','2026-06-25 21:14:29'),
(22,'create_supplier_masters','web','2026-06-25 21:14:29','2026-06-25 21:14:29'),
(23,'edit_supplier_masters','web','2026-06-25 21:14:29','2026-06-25 21:14:29'),
(24,'delete_supplier_masters','web','2026-06-25 21:14:29','2026-06-25 21:14:29'),
(25,'view_sales_channels','web','2026-06-25 21:14:29','2026-06-25 21:14:29'),
(26,'create_sales_channels','web','2026-06-25 21:14:29','2026-06-25 21:14:29'),
(27,'edit_sales_channels','web','2026-06-25 21:14:29','2026-06-25 21:14:29'),
(28,'delete_sales_channels','web','2026-06-25 21:14:29','2026-06-25 21:14:29'),
(29,'view_industries','web','2026-06-25 21:14:29','2026-06-25 21:14:29'),
(30,'create_industries','web','2026-06-25 21:14:29','2026-06-25 21:14:29'),
(31,'edit_industries','web','2026-06-25 21:14:29','2026-06-25 21:14:29'),
(32,'delete_industries','web','2026-06-25 21:14:29','2026-06-25 21:14:29'),
(33,'view_companies','web','2026-06-25 21:14:29','2026-06-25 21:14:29'),
(34,'create_companies','web','2026-06-25 21:14:29','2026-06-25 21:14:29'),
(35,'edit_companies','web','2026-06-25 21:14:29','2026-06-25 21:14:29'),
(36,'delete_companies','web','2026-06-25 21:14:29','2026-06-25 21:14:29'),
(37,'view_locations','web','2026-06-25 21:14:29','2026-06-25 21:14:29'),
(38,'create_locations','web','2026-06-25 21:14:29','2026-06-25 21:14:29'),
(39,'edit_locations','web','2026-06-25 21:14:29','2026-06-25 21:14:29'),
(40,'delete_locations','web','2026-06-25 21:14:29','2026-06-25 21:14:29'),
(41,'view_attribute_types','web','2026-06-25 21:14:29','2026-06-25 21:14:29'),
(42,'create_attribute_types','web','2026-06-25 21:14:29','2026-06-25 21:14:29'),
(43,'edit_attribute_types','web','2026-06-25 21:14:29','2026-06-25 21:14:29'),
(44,'delete_attribute_types','web','2026-06-25 21:14:29','2026-06-25 21:14:29'),
(45,'view_attributes','web','2026-06-25 21:14:29','2026-06-25 21:14:29'),
(46,'create_attributes','web','2026-06-25 21:14:29','2026-06-25 21:14:29'),
(47,'edit_attributes','web','2026-06-25 21:14:29','2026-06-25 21:14:29'),
(48,'delete_attributes','web','2026-06-25 21:14:29','2026-06-25 21:14:29'),
(49,'view_customer_masters','web','2026-06-25 21:14:29','2026-06-25 21:14:29'),
(50,'create_customer_masters','web','2026-06-25 21:14:29','2026-06-25 21:14:29'),
(51,'edit_customer_masters','web','2026-06-25 21:14:29','2026-06-25 21:14:29'),
(52,'delete_customer_masters','web','2026-06-25 21:14:29','2026-06-25 21:14:29'),
(53,'view_store_types','web','2026-06-25 21:14:29','2026-06-25 21:14:29'),
(54,'create_store_types','web','2026-06-25 21:14:29','2026-06-25 21:14:29'),
(55,'edit_store_types','web','2026-06-25 21:14:29','2026-06-25 21:14:29'),
(56,'delete_store_types','web','2026-06-25 21:14:29','2026-06-25 21:14:29'),
(57,'view_stores','web','2026-06-25 21:14:29','2026-06-25 21:14:29'),
(58,'create_stores','web','2026-06-25 21:14:29','2026-06-25 21:14:29'),
(59,'edit_stores','web','2026-06-25 21:14:29','2026-06-25 21:14:29'),
(60,'delete_stores','web','2026-06-25 21:14:29','2026-06-25 21:14:29'),
(61,'view_drivers','web','2026-06-25 21:14:29','2026-06-25 21:14:29'),
(62,'create_drivers','web','2026-06-25 21:14:29','2026-06-25 21:14:29'),
(63,'edit_drivers','web','2026-06-25 21:14:29','2026-06-25 21:14:29'),
(64,'delete_drivers','web','2026-06-25 21:14:29','2026-06-25 21:14:29'),
(65,'view_vehicle_masters','web','2026-06-25 21:14:29','2026-06-25 21:14:29'),
(66,'create_vehicle_masters','web','2026-06-25 21:14:29','2026-06-25 21:14:29'),
(67,'edit_vehicle_masters','web','2026-06-25 21:14:29','2026-06-25 21:14:29'),
(68,'delete_vehicle_masters','web','2026-06-25 21:14:29','2026-06-25 21:14:29'),
(69,'view_purchase_requests','web','2026-06-25 21:14:29','2026-06-25 21:14:29'),
(70,'create_purchase_requests','web','2026-06-25 21:14:29','2026-06-25 21:14:29'),
(71,'edit_purchase_requests','web','2026-06-25 21:14:29','2026-06-25 21:14:29'),
(72,'delete_purchase_requests','web','2026-06-25 21:14:29','2026-06-25 21:14:29'),
(73,'view_purchase_orders','web','2026-06-25 21:14:29','2026-06-25 21:14:29'),
(74,'create_purchase_orders','web','2026-06-25 21:14:29','2026-06-25 21:14:29'),
(75,'edit_purchase_orders','web','2026-06-25 21:14:29','2026-06-25 21:14:29'),
(76,'delete_purchase_orders','web','2026-06-25 21:14:29','2026-06-25 21:14:29'),
(77,'view_costings','web','2026-06-25 21:14:29','2026-06-25 21:14:29'),
(78,'create_costings','web','2026-06-25 21:14:29','2026-06-25 21:14:29'),
(79,'edit_costings','web','2026-06-25 21:14:29','2026-06-25 21:14:29'),
(80,'delete_costings','web','2026-06-25 21:14:29','2026-06-25 21:14:29'),
(81,'approve_purchase_requests','web','2026-06-25 21:14:29','2026-06-25 21:14:29'),
(82,'view_grns','web','2026-06-25 21:14:29','2026-06-25 21:14:29'),
(83,'create_grns','web','2026-06-25 21:14:29','2026-06-25 21:14:29'),
(84,'edit_grns','web','2026-06-25 21:14:29','2026-06-25 21:14:29'),
(85,'confirm_grns','web','2026-06-25 21:14:29','2026-06-25 21:14:29'),
(86,'delete_grns','web','2026-06-25 21:14:29','2026-06-25 21:14:29'),
(87,'confirm_costings','web','2026-06-25 21:14:29','2026-06-25 21:14:29'),
(88,'manage_costing_expense_types','web','2026-06-25 21:14:29','2026-06-25 21:14:29'),
(89,'view_reports','web','2026-06-25 21:14:29','2026-06-25 21:14:29'),
(90,'view_payment_modes','web','2026-07-02 22:59:48','2026-07-02 22:59:48'),
(91,'create_payment_modes','web','2026-07-02 22:59:48','2026-07-02 22:59:48'),
(92,'edit_payment_modes','web','2026-07-02 22:59:48','2026-07-02 22:59:48'),
(93,'delete_payment_modes','web','2026-07-02 22:59:48','2026-07-02 22:59:48'),
(94,'view_supplier_payments','web','2026-07-02 22:59:48','2026-07-02 22:59:48'),
(95,'create_supplier_payments','web','2026-07-02 22:59:48','2026-07-02 22:59:48'),
(96,'edit_supplier_payments','web','2026-07-02 22:59:48','2026-07-02 22:59:48'),
(97,'confirm_supplier_payments','web','2026-07-02 22:59:48','2026-07-02 22:59:48'),
(98,'delete_supplier_payments','web','2026-07-02 22:59:48','2026-07-02 22:59:48'),
(99,'view_supplier_credit_notes','web','2026-07-02 22:59:48','2026-07-02 22:59:48');
/*!40000 ALTER TABLE `permissions` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `personal_access_tokens`
--

DROP TABLE IF EXISTS `personal_access_tokens`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `personal_access_tokens` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `tokenable_type` varchar(255) NOT NULL,
  `tokenable_id` bigint(20) unsigned NOT NULL,
  `name` text NOT NULL,
  `token` varchar(64) NOT NULL,
  `abilities` text DEFAULT NULL,
  `last_used_at` timestamp NULL DEFAULT NULL,
  `expires_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `personal_access_tokens_token_unique` (`token`),
  KEY `personal_access_tokens_tokenable_type_tokenable_id_index` (`tokenable_type`,`tokenable_id`),
  KEY `personal_access_tokens_expires_at_index` (`expires_at`)
) ENGINE=InnoDB AUTO_INCREMENT=31 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `personal_access_tokens`
--

LOCK TABLES `personal_access_tokens` WRITE;
/*!40000 ALTER TABLE `personal_access_tokens` DISABLE KEYS */;
INSERT INTO `personal_access_tokens` VALUES
(1,'App\\Models\\User',1,'api-token','eb670f70f0cc449362dc056ad3bdc48cd6d4675d9481e872c2a972af3d185956','[\"*\"]','2026-06-25 21:18:54',NULL,'2026-06-25 21:14:49','2026-06-25 21:18:54'),
(2,'App\\Models\\User',1,'api-token','3bb876e2bd039f5c5e5d62df24a8947aa9750a45c88c8e063bae453e3004959f','[\"*\"]','2026-06-26 07:11:52',NULL,'2026-06-26 05:25:25','2026-06-26 07:11:52'),
(3,'App\\Models\\User',1,'api-token','fd9a3fef5f27c967e16d1ab629e050b9d29aa0f5107fc31a4d677567c2009845','[\"*\"]',NULL,NULL,'2026-06-26 05:33:16','2026-06-26 05:33:16'),
(4,'App\\Models\\User',1,'api-token','1413d614b157d3be499c34442375dc506937af4d3a83d3becf60dccda6f7a3ae','[\"*\"]','2026-06-26 06:11:54',NULL,'2026-06-26 05:34:50','2026-06-26 06:11:54'),
(5,'App\\Models\\User',1,'api-token','01f1543a6c8ed2f031726ceef9d9cb8fb320294bb33da0b00dee1b9a7af66315','[\"*\"]','2026-06-26 06:21:42',NULL,'2026-06-26 06:14:28','2026-06-26 06:21:42'),
(6,'App\\Models\\User',1,'api-token','89e1f220b687b09cc28e5ef70d0558cb01555c099a0297f5046b285bac1e8c78','[\"*\"]','2026-06-27 05:15:45',NULL,'2026-06-27 04:02:41','2026-06-27 05:15:45'),
(7,'App\\Models\\User',1,'api-token','21ba4f583427016622604171fe2b931d2e4495f92dfbac4153ca4a9b05482c9b','[\"*\"]','2026-06-28 22:45:24',NULL,'2026-06-28 22:28:09','2026-06-28 22:45:24'),
(8,'App\\Models\\User',1,'api-token','3328b335742988bd7234d08c7b9ef1b6f9eb9001b798ca4fe4e25b5fb3ed1423','[\"*\"]','2026-07-11 04:14:54',NULL,'2026-06-28 22:47:25','2026-07-11 04:14:54'),
(9,'App\\Models\\User',1,'api-token','6a6987bc1051c0f77ae8570b4048bcdd43e9469ac07b6b7e81fbd9f82e4a67ad','[\"*\"]','2026-07-02 22:04:13',NULL,'2026-07-02 21:53:13','2026-07-02 22:04:13'),
(10,'App\\Models\\User',1,'api-token','9e528f22c077490ca391a96b9e9874edb4e6b24c480c5fca652a3cc8248b701a','[\"*\"]','2026-07-05 22:13:14',NULL,'2026-07-02 23:00:46','2026-07-05 22:13:14'),
(11,'App\\Models\\User',1,'api-token','d511857ceb7e1d207987ba9313602789b8b4a5f9b8778810a38f5d5f2a952fbc','[\"*\"]','2026-07-03 07:56:55',NULL,'2026-07-03 05:31:56','2026-07-03 07:56:55'),
(12,'App\\Models\\User',1,'api-token','71b93f598b94d62ce408e5061822a8f1e47d265a125828ad3be62f300113b35b','[\"*\"]','2026-07-03 10:47:34',NULL,'2026-07-03 08:30:12','2026-07-03 10:47:34'),
(13,'App\\Models\\User',1,'api-token','490fc64ca11851d9407978173278abce934b830d549b1b88f3f74a57d89a7ba9','[\"*\"]','2026-07-03 23:30:57',NULL,'2026-07-03 22:54:29','2026-07-03 23:30:57'),
(14,'App\\Models\\User',1,'api-token','7300a98155352000dfc3cd837e46b47543464eb8297a19b6768a68ca4dbefd23','[\"*\"]','2026-07-04 00:14:46',NULL,'2026-07-03 23:31:32','2026-07-04 00:14:46'),
(15,'App\\Models\\User',1,'api-token','8ec3db802320bda925774659a14af5197e806f2d14818009441d56c6a95521aa','[\"*\"]','2026-07-04 02:15:11',NULL,'2026-07-04 00:21:22','2026-07-04 02:15:11'),
(16,'App\\Models\\User',1,'api-token','fd363c47db2a5c20ec5c71b92414a74939967186c95791fa7399b17a85bef08b','[\"*\"]','2026-07-04 03:26:32',NULL,'2026-07-04 01:45:56','2026-07-04 03:26:32'),
(17,'App\\Models\\User',1,'api-token','69ce53697e03ecd9a25f22829a444e743de04ae930373f6b480f691d33aeb473','[\"*\"]','2026-07-11 10:16:12',NULL,'2026-07-05 22:15:14','2026-07-11 10:16:12'),
(18,'App\\Models\\User',1,'api-token','2f8b78174410d1aa5973d0f0b965d85e0bb22ea39fd0ae6a7b9edcca9e2d1e28','[\"*\"]',NULL,NULL,'2026-07-10 02:32:20','2026-07-10 02:32:20'),
(19,'App\\Models\\User',1,'api-token','bca9f39b282dd69e83d83bb92a8a6f788100fe7690ac39d6cc3234c46632ef0b','[\"*\"]','2026-07-10 02:39:46',NULL,'2026-07-10 02:32:29','2026-07-10 02:39:46'),
(20,'App\\Models\\User',1,'api-token','2bc93e7ca6fc679d0df43551081b5f8bae4febd528d55345b1ab330a4f4cc18b','[\"*\"]',NULL,NULL,'2026-07-10 02:40:21','2026-07-10 02:40:21'),
(21,'App\\Models\\User',1,'api-token','0478f0659d14ead0311f569d90c93a68b054d7438a831880b83c557c32fc39fb','[\"*\"]','2026-07-10 06:15:31',NULL,'2026-07-10 05:10:18','2026-07-10 06:15:31'),
(22,'App\\Models\\User',1,'api-token','7d19dae7f328d22b9a3b7e981c500975fbec75c6884172a6cb8b223d64faabbe','[\"*\"]','2026-07-10 09:53:36',NULL,'2026-07-10 07:16:41','2026-07-10 09:53:36'),
(23,'App\\Models\\User',1,'api-token','7c3e01c034b9b2aa4da4bde814897e1da91bb448e70ac85d8484883801d8b9cd','[\"*\"]','2026-07-10 10:23:14',NULL,'2026-07-10 07:52:45','2026-07-10 10:23:14'),
(24,'App\\Models\\User',1,'api-token','f47f1ca1fa4886d4bd7c4be03bd7e30c672b4c7d9b809fe5c025f6df943f3ac0','[\"*\"]','2026-07-10 10:40:00',NULL,'2026-07-10 09:53:47','2026-07-10 10:40:00'),
(25,'App\\Models\\User',1,'api-token','98d5ceb2557871709f6084098e26e68bb85764513197bceff7d524222c392531','[\"*\"]','2026-07-10 23:01:36',NULL,'2026-07-10 21:59:40','2026-07-10 23:01:36'),
(26,'App\\Models\\User',1,'api-token','188b4ae00d3c62ae725393cdd341cc5cff38744bb84cebb3cf10645e1b7c2b9a','[\"*\"]','2026-07-11 00:55:32',NULL,'2026-07-10 23:02:21','2026-07-11 00:55:32'),
(27,'App\\Models\\User',1,'api-token','64e75164760bed3ce822d2cf7b1b7bc4cb22eb03e34409fd33ad71828cbec2b8','[\"*\"]','2026-07-11 01:56:12',NULL,'2026-07-11 00:55:41','2026-07-11 01:56:12'),
(28,'App\\Models\\User',1,'api-token','ef1a0c9dc66474675b3d0ae67e43c73543b9d7ffa29dd1a307bc7616467b31a9','[\"*\"]','2026-07-11 05:02:13',NULL,'2026-07-11 01:56:17','2026-07-11 05:02:13'),
(29,'App\\Models\\User',1,'api-token','7fb53614d803533f9009912a633f55d70f4dd885ed0dd78c3f26106c6ef3e0c3','[\"*\"]','2026-07-11 06:55:43',NULL,'2026-07-11 05:03:11','2026-07-11 06:55:43'),
(30,'App\\Models\\User',1,'api-token','e6beadae4d3b5b2b2b0eaaaf842ea2a5fe7ce916f3abfcdd2f27285bcac93514','[\"*\"]',NULL,NULL,'2026-07-11 06:55:51','2026-07-11 06:55:51');
/*!40000 ALTER TABLE `personal_access_tokens` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `role_has_permissions`
--

DROP TABLE IF EXISTS `role_has_permissions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `role_has_permissions` (
  `permission_id` bigint(20) unsigned NOT NULL,
  `role_id` bigint(20) unsigned NOT NULL,
  PRIMARY KEY (`permission_id`,`role_id`),
  KEY `role_has_permissions_role_id_foreign` (`role_id`),
  CONSTRAINT `role_has_permissions_permission_id_foreign` FOREIGN KEY (`permission_id`) REFERENCES `permissions` (`id`) ON DELETE CASCADE,
  CONSTRAINT `role_has_permissions_role_id_foreign` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `role_has_permissions`
--

LOCK TABLES `role_has_permissions` WRITE;
/*!40000 ALTER TABLE `role_has_permissions` DISABLE KEYS */;
INSERT INTO `role_has_permissions` VALUES
(1,2),
(1,3),
(2,2),
(3,2),
(4,2),
(5,2),
(5,3),
(6,2),
(7,2),
(8,2),
(9,2),
(9,3),
(10,2),
(11,2),
(12,2),
(13,2),
(13,3),
(14,2),
(15,2),
(16,2),
(17,2),
(17,3),
(18,2),
(19,2),
(20,2),
(21,2),
(21,3),
(22,2),
(23,2),
(24,2),
(25,2),
(25,3),
(26,2),
(27,2),
(28,2),
(29,2),
(29,3),
(30,2),
(31,2),
(32,2),
(33,2),
(33,3),
(34,2),
(35,2),
(36,2),
(37,2),
(37,3),
(38,2),
(39,2),
(40,2),
(41,2),
(41,3),
(42,2),
(43,2),
(44,2),
(45,2),
(45,3),
(46,2),
(47,2),
(48,2),
(49,2),
(49,3),
(50,2),
(51,2),
(52,2),
(53,2),
(53,3),
(54,2),
(55,2),
(56,2),
(57,2),
(57,3),
(58,2),
(59,2),
(60,2),
(61,2),
(61,3),
(62,2),
(63,2),
(64,2),
(65,2),
(65,3),
(66,2),
(67,2),
(68,2),
(69,2),
(69,3),
(70,2),
(71,2),
(72,2),
(73,2),
(73,3),
(74,2),
(75,2),
(76,2),
(77,2),
(77,3),
(78,2),
(79,2),
(80,2),
(81,2),
(82,2),
(82,3),
(83,2),
(84,2),
(85,2),
(86,2),
(87,2),
(88,2),
(89,2),
(89,3),
(90,2),
(90,3),
(91,2),
(92,2),
(93,2),
(94,2),
(94,3),
(95,2),
(96,2),
(97,2),
(98,2),
(99,2),
(99,3);
/*!40000 ALTER TABLE `role_has_permissions` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `roles`
--

DROP TABLE IF EXISTS `roles`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `roles` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `guard_name` varchar(255) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `roles_name_guard_name_unique` (`name`,`guard_name`)
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `roles`
--

LOCK TABLES `roles` WRITE;
/*!40000 ALTER TABLE `roles` DISABLE KEYS */;
INSERT INTO `roles` VALUES
(1,'super_admin','web','2026-06-25 21:14:29','2026-06-25 21:14:29'),
(2,'admin','web','2026-06-25 21:14:29','2026-06-25 21:14:29'),
(3,'staff','web','2026-06-25 21:14:29','2026-06-25 21:14:29');
/*!40000 ALTER TABLE `roles` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `sessions`
--

DROP TABLE IF EXISTS `sessions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `sessions` (
  `id` varchar(255) NOT NULL,
  `user_id` bigint(20) unsigned DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` text DEFAULT NULL,
  `payload` longtext NOT NULL,
  `last_activity` int(11) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `sessions_user_id_index` (`user_id`),
  KEY `sessions_last_activity_index` (`last_activity`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `sessions`
--

LOCK TABLES `sessions` WRITE;
/*!40000 ALTER TABLE `sessions` DISABLE KEYS */;
/*!40000 ALTER TABLE `sessions` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `users`
--

DROP TABLE IF EXISTS `users`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `users` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `email` varchar(255) NOT NULL,
  `role` varchar(20) NOT NULL DEFAULT 'staff',
  `email_verified_at` timestamp NULL DEFAULT NULL,
  `password` varchar(255) NOT NULL,
  `active_modules` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`active_modules`)),
  `remember_token` varchar(100) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `users_email_unique` (`email`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `users`
--

LOCK TABLES `users` WRITE;
/*!40000 ALTER TABLE `users` DISABLE KEYS */;
INSERT INTO `users` VALUES
(1,'Admin User','admin@erp.local','super_admin',NULL,'$2y$12$rzPUB3bX0ycYwNFUZgWbmui/zeKlnfXl7MECA/Uh4GzNi1ReIFx3m','[\"inventory\"]',NULL,'2026-06-25 21:14:30','2026-06-25 21:14:30');
/*!40000 ALTER TABLE `users` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Dumping events for database 'erp_production'
--

--
-- Dumping routines for database 'erp_production'
--
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

-- Dump completed on 2026-07-11 22:04:46
