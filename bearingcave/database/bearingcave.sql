-- =====================================================================
-- BearingCave: clean installation database (MySQL 8.0+)
-- Generated from the CodeIgniter 4 migrations 000001-000013 + Shield,
-- then DatabaseSeeder. Contains SCHEMA + REFERENCE/CONFIGURATION DATA ONLY:
--   249 countries, 8 currencies, 63 platform settings (with approval
--   status), 4 membership plans + entitlements, categories, brands,
--   CMS page drafts, the 86 "Suppiler list" master-data fields.
-- Contains NO users, companies, products, orders, demo data or secrets.
--
-- Import (empty database, utf8mb4):
--   CREATE DATABASE bearingcave CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
--   mysql -u USER -p bearingcave < bearingcave.sql     (or phpMyAdmin → Import)
-- Then create the first administrator:
--   php spark bearingcave:create-admin you@company.com
-- The `migrations` table is included, so later `php spark migrate --all`
-- runs only new migrations.
--
-- WARNING: this file DROPS and recreates every BearingCave table.
-- Never import it into a database that holds real records; to upgrade an
-- existing installation use `php spark migrate --all` (docs/DATABASE_GUIDE.md).
-- =====================================================================

-- MySQL dump 10.13  Distrib 8.0.46, for Linux (x86_64)
--
-- Host: localhost    Database: bc_export
-- ------------------------------------------------------
-- Server version	8.0.46-0ubuntu0.24.04.4

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!50503 SET NAMES utf8mb4 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;

--
-- Table structure for table `approved_vendors`
--

DROP TABLE IF EXISTS `approved_vendors`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `approved_vendors` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `company_id` int unsigned NOT NULL,
  `scope_type` enum('general','category','brand','product') COLLATE utf8mb4_unicode_ci NOT NULL,
  `scope_id` int unsigned DEFAULT NULL,
  `status` enum('approved','suspended','revoked') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'approved',
  `valid_until` date DEFAULT NULL,
  `notes` text COLLATE utf8mb4_unicode_ci,
  `approved_by` int unsigned DEFAULT NULL,
  `approved_at` datetime DEFAULT NULL,
  `created_at` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `company_id_scope_type_scope_id` (`company_id`,`scope_type`,`scope_id`),
  KEY `approved_vendors_approved_by_foreign` (`approved_by`),
  KEY `status` (`status`),
  KEY `scope_type_scope_id` (`scope_type`,`scope_id`),
  CONSTRAINT `approved_vendors_approved_by_foreign` FOREIGN KEY (`approved_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `approved_vendors_company_id_foreign` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `approved_vendors`
--

LOCK TABLES `approved_vendors` WRITE;
/*!40000 ALTER TABLE `approved_vendors` DISABLE KEYS */;
/*!40000 ALTER TABLE `approved_vendors` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `audit_logs`
--

DROP TABLE IF EXISTS `audit_logs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `audit_logs` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int unsigned DEFAULT NULL,
  `company_id` int unsigned DEFAULT NULL,
  `event` varchar(80) COLLATE utf8mb4_unicode_ci NOT NULL,
  `severity` enum('info','warning','security') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'info',
  `entity_type` varchar(40) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `entity_id` int unsigned DEFAULT NULL,
  `description` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `old_values` json DEFAULT NULL,
  `new_values` json DEFAULT NULL,
  `ip_address` varchar(45) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `user_agent` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `event` (`event`),
  KEY `severity` (`severity`),
  KEY `entity_type_entity_id` (`entity_type`,`entity_id`),
  KEY `user_id` (`user_id`),
  KEY `created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `audit_logs`
--

LOCK TABLES `audit_logs` WRITE;
/*!40000 ALTER TABLE `audit_logs` DISABLE KEYS */;
/*!40000 ALTER TABLE `audit_logs` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `auth_groups_users`
--

DROP TABLE IF EXISTS `auth_groups_users`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `auth_groups_users` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int unsigned NOT NULL,
  `group` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `auth_groups_users_user_id_foreign` (`user_id`),
  CONSTRAINT `auth_groups_users_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `auth_groups_users`
--

LOCK TABLES `auth_groups_users` WRITE;
/*!40000 ALTER TABLE `auth_groups_users` DISABLE KEYS */;
/*!40000 ALTER TABLE `auth_groups_users` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `auth_identities`
--

DROP TABLE IF EXISTS `auth_identities`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `auth_identities` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int unsigned NOT NULL,
  `type` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `secret` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `secret2` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `expires` datetime DEFAULT NULL,
  `extra` text COLLATE utf8mb4_unicode_ci,
  `force_reset` tinyint(1) NOT NULL DEFAULT '0',
  `last_used_at` datetime DEFAULT NULL,
  `created_at` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `type_secret` (`type`,`secret`),
  KEY `user_id` (`user_id`),
  CONSTRAINT `auth_identities_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `auth_identities`
--

LOCK TABLES `auth_identities` WRITE;
/*!40000 ALTER TABLE `auth_identities` DISABLE KEYS */;
/*!40000 ALTER TABLE `auth_identities` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `auth_logins`
--

DROP TABLE IF EXISTS `auth_logins`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `auth_logins` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `ip_address` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `user_agent` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `id_type` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `identifier` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `user_id` int unsigned DEFAULT NULL,
  `date` datetime NOT NULL,
  `success` tinyint(1) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `id_type_identifier` (`id_type`,`identifier`),
  KEY `user_id` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `auth_logins`
--

LOCK TABLES `auth_logins` WRITE;
/*!40000 ALTER TABLE `auth_logins` DISABLE KEYS */;
/*!40000 ALTER TABLE `auth_logins` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `auth_permissions_users`
--

DROP TABLE IF EXISTS `auth_permissions_users`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `auth_permissions_users` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int unsigned NOT NULL,
  `permission` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `auth_permissions_users_user_id_foreign` (`user_id`),
  CONSTRAINT `auth_permissions_users_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `auth_permissions_users`
--

LOCK TABLES `auth_permissions_users` WRITE;
/*!40000 ALTER TABLE `auth_permissions_users` DISABLE KEYS */;
/*!40000 ALTER TABLE `auth_permissions_users` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `auth_remember_tokens`
--

DROP TABLE IF EXISTS `auth_remember_tokens`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `auth_remember_tokens` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `selector` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `hashedValidator` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `user_id` int unsigned NOT NULL,
  `expires` datetime NOT NULL,
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `selector` (`selector`),
  KEY `auth_remember_tokens_user_id_foreign` (`user_id`),
  CONSTRAINT `auth_remember_tokens_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `auth_remember_tokens`
--

LOCK TABLES `auth_remember_tokens` WRITE;
/*!40000 ALTER TABLE `auth_remember_tokens` DISABLE KEYS */;
/*!40000 ALTER TABLE `auth_remember_tokens` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `auth_token_logins`
--

DROP TABLE IF EXISTS `auth_token_logins`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `auth_token_logins` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `ip_address` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `user_agent` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `id_type` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `identifier` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `user_id` int unsigned DEFAULT NULL,
  `date` datetime NOT NULL,
  `success` tinyint(1) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `id_type_identifier` (`id_type`,`identifier`),
  KEY `user_id` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `auth_token_logins`
--

LOCK TABLES `auth_token_logins` WRITE;
/*!40000 ALTER TABLE `auth_token_logins` DISABLE KEYS */;
/*!40000 ALTER TABLE `auth_token_logins` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `brands`
--

DROP TABLE IF EXISTS `brands`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `brands` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(120) COLLATE utf8mb4_unicode_ci NOT NULL,
  `slug` varchar(140) COLLATE utf8mb4_unicode_ci NOT NULL,
  `country_code` char(2) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `brand_type` enum('oem','aftermarket','both') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'both',
  `logo_path` varchar(191) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `is_active` tinyint unsigned NOT NULL DEFAULT '1',
  `created_at` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `slug` (`slug`)
) ENGINE=InnoDB AUTO_INCREMENT=31 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `brands`
--

LOCK TABLES `brands` WRITE;
/*!40000 ALTER TABLE `brands` DISABLE KEYS */;
INSERT INTO `brands` VALUES (1,'SKF','skf','SE','both',NULL,1,'2026-10-13 11:03:03','2026-10-13 11:03:03'),(2,'Schaeffler (FAG / INA / LuK)','schaeffler-fag-ina-luk','DE','both',NULL,1,'2026-10-13 11:03:03','2026-10-13 11:03:03'),(3,'NSK','nsk','JP','both',NULL,1,'2026-10-13 11:03:03','2026-10-13 11:03:03'),(4,'NTN','ntn','JP','both',NULL,1,'2026-10-13 11:03:03','2026-10-13 11:03:03'),(5,'Timken','timken','US','both',NULL,1,'2026-10-13 11:03:03','2026-10-13 11:03:03'),(6,'JTEKT (Koyo)','jtekt-koyo','JP','both',NULL,1,'2026-10-13 11:03:03','2026-10-13 11:03:03'),(7,'NBC','nbc','IN','both',NULL,1,'2026-10-13 11:03:03','2026-10-13 11:03:03'),(8,'Bosch','bosch','DE','both',NULL,1,'2026-10-13 11:03:03','2026-10-13 11:03:03'),(9,'Denso','denso','JP','both',NULL,1,'2026-10-13 11:03:03','2026-10-13 11:03:03'),(10,'Continental','continental','DE','both',NULL,1,'2026-10-13 11:03:03','2026-10-13 11:03:03'),(11,'ZF','zf','DE','both',NULL,1,'2026-10-13 11:03:03','2026-10-13 11:03:03'),(12,'Valeo','valeo','FR','both',NULL,1,'2026-10-13 11:03:03','2026-10-13 11:03:03'),(13,'MAHLE','mahle','DE','both',NULL,1,'2026-10-13 11:03:03','2026-10-13 11:03:03'),(14,'Brembo','brembo','IT','both',NULL,1,'2026-10-13 11:03:03','2026-10-13 11:03:03'),(15,'Delphi','delphi','GB','both',NULL,1,'2026-10-13 11:03:03','2026-10-13 11:03:03'),(16,'Gates','gates','US','both',NULL,1,'2026-10-13 11:03:03','2026-10-13 11:03:03'),(17,'Aisin','aisin','JP','both',NULL,1,'2026-10-13 11:03:03','2026-10-13 11:03:03'),(18,'HELLA','hella','DE','both',NULL,1,'2026-10-13 11:03:03','2026-10-13 11:03:03'),(19,'NGK','ngk','JP','both',NULL,1,'2026-10-13 11:03:03','2026-10-13 11:03:03'),(20,'MANN-FILTER','mann-filter','DE','both',NULL,1,'2026-10-13 11:03:03','2026-10-13 11:03:03'),(21,'Lucas TVS','lucas-tvs','IN','both',NULL,1,'2026-10-13 11:03:03','2026-10-13 11:03:03'),(22,'Minda','minda','IN','both',NULL,1,'2026-10-13 11:03:03','2026-10-13 11:03:03'),(23,'Rane','rane','IN','both',NULL,1,'2026-10-13 11:03:03','2026-10-13 11:03:03'),(24,'Uno Minda','uno-minda','IN','both',NULL,1,'2026-10-13 11:03:03','2026-10-13 11:03:03'),(25,'Federal-Mogul','federal-mogul','US','both',NULL,1,'2026-10-13 11:03:03','2026-10-13 11:03:03'),(26,'Dayco','dayco','US','both',NULL,1,'2026-10-13 11:03:03','2026-10-13 11:03:03'),(27,'Sachs','sachs','DE','both',NULL,1,'2026-10-13 11:03:03','2026-10-13 11:03:03'),(28,'Monroe','monroe','US','both',NULL,1,'2026-10-13 11:03:03','2026-10-13 11:03:03'),(29,'Exide','exide','IN','both',NULL,1,'2026-10-13 11:03:03','2026-10-13 11:03:03'),(30,'Other / Unbranded','other-unbranded',NULL,'both',NULL,1,'2026-10-13 11:03:03','2026-10-13 11:03:03');
/*!40000 ALTER TABLE `brands` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `buyer_profiles`
--

DROP TABLE IF EXISTS `buyer_profiles`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `buyer_profiles` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `company_id` int unsigned NOT NULL,
  `buyer_category` varchar(80) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `annual_purchase_volume` varchar(60) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `interested_categories` text COLLATE utf8mb4_unicode_ci,
  `preferred_currency` char(3) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `engagement_score` decimal(5,2) NOT NULL DEFAULT '0.00',
  `rfq_genuineness_score` decimal(5,2) NOT NULL DEFAULT '0.00',
  `transaction_score` decimal(5,2) NOT NULL DEFAULT '0.00',
  `scores_computed_at` datetime DEFAULT NULL,
  `restricted_inventory_access` tinyint unsigned NOT NULL DEFAULT '0',
  `created_at` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  `services_bonus_score` decimal(5,2) DEFAULT NULL,
  `overall_score` decimal(5,2) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `company_id` (`company_id`),
  CONSTRAINT `buyer_profiles_company_id_foreign` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `buyer_profiles`
--

LOCK TABLES `buyer_profiles` WRITE;
/*!40000 ALTER TABLE `buyer_profiles` DISABLE KEYS */;
/*!40000 ALTER TABLE `buyer_profiles` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `cart_items`
--

DROP TABLE IF EXISTS `cart_items`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `cart_items` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `cart_id` int unsigned NOT NULL,
  `product_id` int unsigned NOT NULL,
  `quantity` int unsigned NOT NULL DEFAULT '1',
  `created_at` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `cart_id_product_id` (`cart_id`,`product_id`),
  KEY `cart_items_product_id_foreign` (`product_id`),
  CONSTRAINT `cart_items_cart_id_foreign` FOREIGN KEY (`cart_id`) REFERENCES `carts` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `cart_items_product_id_foreign` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `cart_items`
--

LOCK TABLES `cart_items` WRITE;
/*!40000 ALTER TABLE `cart_items` DISABLE KEYS */;
/*!40000 ALTER TABLE `cart_items` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `carts`
--

DROP TABLE IF EXISTS `carts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `carts` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int unsigned NOT NULL,
  `company_id` int unsigned NOT NULL,
  `status` enum('active','converted','abandoned') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'active',
  `created_at` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `carts_company_id_foreign` (`company_id`),
  KEY `user_id_status` (`user_id`,`status`),
  CONSTRAINT `carts_company_id_foreign` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `carts_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `carts`
--

LOCK TABLES `carts` WRITE;
/*!40000 ALTER TABLE `carts` DISABLE KEYS */;
/*!40000 ALTER TABLE `carts` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `categories`
--

DROP TABLE IF EXISTS `categories`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `categories` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `parent_id` int unsigned DEFAULT NULL,
  `name` varchar(120) COLLATE utf8mb4_unicode_ci NOT NULL,
  `slug` varchar(140) COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` text COLLATE utf8mb4_unicode_ci,
  `icon` varchar(60) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `sort_order` int unsigned NOT NULL DEFAULT '0',
  `is_active` tinyint unsigned NOT NULL DEFAULT '1',
  `meta_title` varchar(191) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `meta_description` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `slug` (`slug`),
  KEY `parent_id` (`parent_id`),
  CONSTRAINT `categories_parent_id_foreign` FOREIGN KEY (`parent_id`) REFERENCES `categories` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=63 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `categories`
--

LOCK TABLES `categories` WRITE;
/*!40000 ALTER TABLE `categories` DISABLE KEYS */;
INSERT INTO `categories` VALUES (1,NULL,'Bearings','bearings',NULL,'bi-gear-wide-connected',0,1,NULL,NULL,'2026-10-13 11:03:03','2026-10-13 11:03:03'),(2,1,'Ball Bearings','ball-bearings',NULL,NULL,0,1,NULL,NULL,'2026-10-13 11:03:03','2026-10-13 11:03:03'),(3,1,'Tapered Roller Bearings','tapered-roller-bearings',NULL,NULL,1,1,NULL,NULL,'2026-10-13 11:03:03','2026-10-13 11:03:03'),(4,1,'Cylindrical Roller Bearings','cylindrical-roller-bearings',NULL,NULL,2,1,NULL,NULL,'2026-10-13 11:03:03','2026-10-13 11:03:03'),(5,1,'Needle Roller Bearings','needle-roller-bearings',NULL,NULL,3,1,NULL,NULL,'2026-10-13 11:03:03','2026-10-13 11:03:03'),(6,1,'Wheel Hub Bearings & Units','wheel-hub-bearings-units',NULL,NULL,4,1,NULL,NULL,'2026-10-13 11:03:03','2026-10-13 11:03:03'),(7,1,'Clutch Release Bearings','clutch-release-bearings',NULL,NULL,5,1,NULL,NULL,'2026-10-13 11:03:03','2026-10-13 11:03:03'),(8,1,'Spherical & Thrust Bearings','spherical-thrust-bearings',NULL,NULL,6,1,NULL,NULL,'2026-10-13 11:03:03','2026-10-13 11:03:03'),(9,NULL,'Engine Components','engine-components',NULL,'bi-cpu',1,1,NULL,NULL,'2026-10-13 11:03:03','2026-10-13 11:03:03'),(10,9,'Pistons & Rings','pistons-rings',NULL,NULL,0,1,NULL,NULL,'2026-10-13 11:03:03','2026-10-13 11:03:03'),(11,9,'Valves & Valve Train','valves-valve-train',NULL,NULL,1,1,NULL,NULL,'2026-10-13 11:03:03','2026-10-13 11:03:03'),(12,9,'Gaskets & Seals','gaskets-seals',NULL,NULL,2,1,NULL,NULL,'2026-10-13 11:03:03','2026-10-13 11:03:03'),(13,9,'Timing Components','timing-components',NULL,NULL,3,1,NULL,NULL,'2026-10-13 11:03:03','2026-10-13 11:03:03'),(14,9,'Engine Bearings & Bushes','engine-bearings-bushes',NULL,NULL,4,1,NULL,NULL,'2026-10-13 11:03:03','2026-10-13 11:03:03'),(15,NULL,'Transmission & Driveline','transmission-driveline',NULL,'bi-diagram-3',2,1,NULL,NULL,'2026-10-13 11:03:03','2026-10-13 11:03:03'),(16,15,'Gears & Shafts','gears-shafts',NULL,NULL,0,1,NULL,NULL,'2026-10-13 11:03:03','2026-10-13 11:03:03'),(17,15,'CV Joints & Drive Shafts','cv-joints-drive-shafts',NULL,NULL,1,1,NULL,NULL,'2026-10-13 11:03:03','2026-10-13 11:03:03'),(18,15,'Clutch Components','clutch-components',NULL,NULL,2,1,NULL,NULL,'2026-10-13 11:03:03','2026-10-13 11:03:03'),(19,15,'Differential Parts','differential-parts',NULL,NULL,3,1,NULL,NULL,'2026-10-13 11:03:03','2026-10-13 11:03:03'),(20,NULL,'Brake System','brake-system',NULL,'bi-disc',3,1,NULL,NULL,'2026-10-13 11:03:03','2026-10-13 11:03:03'),(21,20,'Brake Pads & Shoes','brake-pads-shoes',NULL,NULL,0,1,NULL,NULL,'2026-10-13 11:03:03','2026-10-13 11:03:03'),(22,20,'Discs & Drums','discs-drums',NULL,NULL,1,1,NULL,NULL,'2026-10-13 11:03:03','2026-10-13 11:03:03'),(23,20,'Calipers & Cylinders','calipers-cylinders',NULL,NULL,2,1,NULL,NULL,'2026-10-13 11:03:03','2026-10-13 11:03:03'),(24,20,'Brake Hoses & Lines','brake-hoses-lines',NULL,NULL,3,1,NULL,NULL,'2026-10-13 11:03:03','2026-10-13 11:03:03'),(25,NULL,'Suspension & Steering','suspension-steering',NULL,'bi-arrows-collapse',4,1,NULL,NULL,'2026-10-13 11:03:03','2026-10-13 11:03:03'),(26,25,'Shock Absorbers & Struts','shock-absorbers-struts',NULL,NULL,0,1,NULL,NULL,'2026-10-13 11:03:03','2026-10-13 11:03:03'),(27,25,'Control Arms & Bushes','control-arms-bushes',NULL,NULL,1,1,NULL,NULL,'2026-10-13 11:03:03','2026-10-13 11:03:03'),(28,25,'Tie Rods & Ball Joints','tie-rods-ball-joints',NULL,NULL,2,1,NULL,NULL,'2026-10-13 11:03:03','2026-10-13 11:03:03'),(29,25,'Steering Gears & Pumps','steering-gears-pumps',NULL,NULL,3,1,NULL,NULL,'2026-10-13 11:03:03','2026-10-13 11:03:03'),(30,NULL,'Electrical & Electronics','electrical-electronics',NULL,'bi-lightning-charge',5,1,NULL,NULL,'2026-10-13 11:03:03','2026-10-13 11:03:03'),(31,30,'Starters & Alternators','starters-alternators',NULL,NULL,0,1,NULL,NULL,'2026-10-13 11:03:03','2026-10-13 11:03:03'),(32,30,'Sensors','sensors',NULL,NULL,1,1,NULL,NULL,'2026-10-13 11:03:03','2026-10-13 11:03:03'),(33,30,'Wiring & Connectors','wiring-connectors',NULL,NULL,2,1,NULL,NULL,'2026-10-13 11:03:03','2026-10-13 11:03:03'),(34,30,'Switches & Relays','switches-relays',NULL,NULL,3,1,NULL,NULL,'2026-10-13 11:03:03','2026-10-13 11:03:03'),(35,30,'ECUs & Modules','ecus-modules',NULL,NULL,4,1,NULL,NULL,'2026-10-13 11:03:03','2026-10-13 11:03:03'),(36,NULL,'Filters','filters',NULL,'bi-funnel',6,1,NULL,NULL,'2026-10-13 11:03:03','2026-10-13 11:03:03'),(37,36,'Oil Filters','oil-filters',NULL,NULL,0,1,NULL,NULL,'2026-10-13 11:03:03','2026-10-13 11:03:03'),(38,36,'Air Filters','air-filters',NULL,NULL,1,1,NULL,NULL,'2026-10-13 11:03:03','2026-10-13 11:03:03'),(39,36,'Fuel Filters','fuel-filters',NULL,NULL,2,1,NULL,NULL,'2026-10-13 11:03:03','2026-10-13 11:03:03'),(40,36,'Cabin Filters','cabin-filters',NULL,NULL,3,1,NULL,NULL,'2026-10-13 11:03:03','2026-10-13 11:03:03'),(41,NULL,'Cooling & Heating','cooling-heating',NULL,'bi-thermometer-half',7,1,NULL,NULL,'2026-10-13 11:03:03','2026-10-13 11:03:03'),(42,41,'Radiators','radiators',NULL,NULL,0,1,NULL,NULL,'2026-10-13 11:03:03','2026-10-13 11:03:03'),(43,41,'Water Pumps','water-pumps',NULL,NULL,1,1,NULL,NULL,'2026-10-13 11:03:03','2026-10-13 11:03:03'),(44,41,'Thermostats','thermostats',NULL,NULL,2,1,NULL,NULL,'2026-10-13 11:03:03','2026-10-13 11:03:03'),(45,41,'Fans & Blowers','fans-blowers',NULL,NULL,3,1,NULL,NULL,'2026-10-13 11:03:03','2026-10-13 11:03:03'),(46,NULL,'Fuel & Exhaust','fuel-exhaust',NULL,'bi-fuel-pump',8,1,NULL,NULL,'2026-10-13 11:03:03','2026-10-13 11:03:03'),(47,46,'Fuel Pumps & Injectors','fuel-pumps-injectors',NULL,NULL,0,1,NULL,NULL,'2026-10-13 11:03:03','2026-10-13 11:03:03'),(48,46,'Exhaust Components','exhaust-components',NULL,NULL,1,1,NULL,NULL,'2026-10-13 11:03:03','2026-10-13 11:03:03'),(49,46,'Turbochargers','turbochargers',NULL,NULL,2,1,NULL,NULL,'2026-10-13 11:03:03','2026-10-13 11:03:03'),(50,NULL,'Body, Lighting & Exterior','body-lighting-exterior',NULL,'bi-lightbulb',9,1,NULL,NULL,'2026-10-13 11:03:03','2026-10-13 11:03:03'),(51,50,'Lamps & Lighting','lamps-lighting',NULL,NULL,0,1,NULL,NULL,'2026-10-13 11:03:03','2026-10-13 11:03:03'),(52,50,'Mirrors','mirrors',NULL,NULL,1,1,NULL,NULL,'2026-10-13 11:03:03','2026-10-13 11:03:03'),(53,50,'Body Panels & Trim','body-panels-trim',NULL,NULL,2,1,NULL,NULL,'2026-10-13 11:03:03','2026-10-13 11:03:03'),(54,NULL,'Fasteners & Hardware','fasteners-hardware',NULL,'bi-nut',10,1,NULL,NULL,'2026-10-13 11:03:03','2026-10-13 11:03:03'),(55,54,'Bolts, Nuts & Studs','bolts-nuts-studs',NULL,NULL,0,1,NULL,NULL,'2026-10-13 11:03:03','2026-10-13 11:03:03'),(56,54,'Clips & Fixings','clips-fixings',NULL,NULL,1,1,NULL,NULL,'2026-10-13 11:03:03','2026-10-13 11:03:03'),(57,54,'Springs & Washers','springs-washers',NULL,NULL,2,1,NULL,NULL,'2026-10-13 11:03:03','2026-10-13 11:03:03'),(58,NULL,'Rubber & Polymer Parts','rubber-polymer-parts',NULL,'bi-circle',11,1,NULL,NULL,'2026-10-13 11:03:03','2026-10-13 11:03:03'),(59,58,'Hoses','hoses',NULL,NULL,0,1,NULL,NULL,'2026-10-13 11:03:03','2026-10-13 11:03:03'),(60,58,'Belts','belts',NULL,NULL,1,1,NULL,NULL,'2026-10-13 11:03:03','2026-10-13 11:03:03'),(61,58,'Mountings','mountings',NULL,NULL,2,1,NULL,NULL,'2026-10-13 11:03:03','2026-10-13 11:03:03'),(62,58,'O-Rings & Oil Seals','o-rings-oil-seals',NULL,NULL,3,1,NULL,NULL,'2026-10-13 11:03:03','2026-10-13 11:03:03');
/*!40000 ALTER TABLE `categories` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `cms_pages`
--

DROP TABLE IF EXISTS `cms_pages`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `cms_pages` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `slug` varchar(120) COLLATE utf8mb4_unicode_ci NOT NULL,
  `title` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `body` mediumtext COLLATE utf8mb4_unicode_ci,
  `meta_title` varchar(191) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `meta_description` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `status` enum('draft','published') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'draft',
  `show_in_footer` tinyint unsigned NOT NULL DEFAULT '0',
  `approval_status` enum('approved','pending_approval') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending_approval',
  `updated_by` int unsigned DEFAULT NULL,
  `created_at` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `slug` (`slug`)
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `cms_pages`
--

LOCK TABLES `cms_pages` WRITE;
/*!40000 ALTER TABLE `cms_pages` DISABLE KEYS */;
INSERT INTO `cms_pages` VALUES (1,'terms','Terms of Use','<p>These Terms of Use will govern access to the BearingCave marketplace. The final text must be prepared and approved by the client\'s legal advisers.</p><h2>Points the final terms must cover</h2><ul><li>Account eligibility and business verification</li><li>Supplier membership plans, fees, renewal and suspension</li><li>Buyer and supplier responsibilities for product quality, warranty and returns</li><li>RFQ confidentiality and use of platform data</li><li>Inspection and logistics service terms</li><li>Payments, escrow partner terms (once selected), cancellations and refunds</li><li>Dispute handling process</li><li>Country restrictions, export controls and sanctions compliance</li><li>Limitation of liability and governing law</li></ul>','Terms of Use | BearingCave',NULL,'published',1,'pending_approval',NULL,'2026-10-13 11:03:03','2026-10-13 11:03:03'),(2,'privacy','Privacy Policy','<p>This page will explain how BearingCave collects, uses and protects personal and business data, including KYC documents, bank details and cross-border data transfers. Final text pending legal review (e.g. India DPDP Act 2023, GDPR for EU users where applicable).</p>','Privacy Policy | BearingCave',NULL,'published',1,'pending_approval',NULL,'2026-10-13 11:03:03','2026-10-13 11:03:03'),(3,'refund-policy','Cancellation & Refund Policy','<p>Cancellation and refund rules for memberships, inspection fees, logistics charges and orders are pending client approval.</p>','Cancellation & Refund Policy | BearingCave',NULL,'published',1,'pending_approval',NULL,'2026-10-13 11:03:03','2026-10-13 11:03:03'),(4,'disclaimer','Disclaimer','<p>Part numbers and cross references shown on BearingCave are provided by suppliers. Unless explicitly marked as verified by BearingCave, a cross reference does not confirm interchangeability. Buyers should confirm specifications before ordering.</p>','Disclaimer | BearingCave',NULL,'published',1,'pending_approval',NULL,'2026-10-13 11:03:03','2026-10-13 11:03:03');
/*!40000 ALTER TABLE `cms_pages` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `companies`
--

DROP TABLE IF EXISTS `companies`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `companies` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `uuid` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `company_type` enum('buyer','supplier') COLLATE utf8mb4_unicode_ci NOT NULL,
  `legal_name` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `trade_name` varchar(191) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `public_alias` varchar(60) COLLATE utf8mb4_unicode_ci NOT NULL,
  `slug` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `registration_number` varchar(80) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `business_type` varchar(60) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `year_established` smallint unsigned DEFAULT NULL,
  `employee_count` varchar(30) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `country_code` char(2) COLLATE utf8mb4_unicode_ci NOT NULL,
  `state` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `city` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `address_line1` varchar(191) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `address_line2` varchar(191) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `postal_code` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `phone` varchar(40) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `email` varchar(191) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `website` varchar(191) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `description` text COLLATE utf8mb4_unicode_ci,
  `logo_path` varchar(191) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `verification_status` enum('unverified','in_review','verified','rejected','suspended') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'unverified',
  `verified_at` datetime DEFAULT NULL,
  `status` enum('active','suspended','closed') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'active',
  `suspension_reason` text COLLATE utf8mb4_unicode_ci,
  `public_profile_enabled` tinyint unsigned NOT NULL DEFAULT '0',
  `show_contact_public` tinyint unsigned NOT NULL DEFAULT '0',
  `account_manager_user_id` int unsigned DEFAULT NULL,
  `is_sample` tinyint unsigned NOT NULL DEFAULT '0',
  `created_at` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  `deleted_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uuid` (`uuid`),
  UNIQUE KEY `slug` (`slug`),
  KEY `companies_account_manager_user_id_foreign` (`account_manager_user_id`),
  KEY `company_type` (`company_type`),
  KEY `verification_status` (`verification_status`),
  KEY `country_code` (`country_code`),
  KEY `status` (`status`),
  CONSTRAINT `companies_account_manager_user_id_foreign` FOREIGN KEY (`account_manager_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `companies_country_code_foreign` FOREIGN KEY (`country_code`) REFERENCES `countries` (`iso2`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `companies`
--

LOCK TABLES `companies` WRITE;
/*!40000 ALTER TABLE `companies` DISABLE KEYS */;
/*!40000 ALTER TABLE `companies` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `company_country_rules`
--

DROP TABLE IF EXISTS `company_country_rules`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `company_country_rules` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `company_id` int unsigned NOT NULL,
  `country_code` char(2) COLLATE utf8mb4_unicode_ci NOT NULL,
  `rule` enum('allow','deny') COLLATE utf8mb4_unicode_ci NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `company_id_country_code` (`company_id`,`country_code`),
  KEY `company_country_rules_country_code_foreign` (`country_code`),
  CONSTRAINT `company_country_rules_company_id_foreign` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `company_country_rules_country_code_foreign` FOREIGN KEY (`country_code`) REFERENCES `countries` (`iso2`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `company_country_rules`
--

LOCK TABLES `company_country_rules` WRITE;
/*!40000 ALTER TABLE `company_country_rules` DISABLE KEYS */;
/*!40000 ALTER TABLE `company_country_rules` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `company_directors`
--

DROP TABLE IF EXISTS `company_directors`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `company_directors` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `company_id` int unsigned NOT NULL,
  `full_name` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `designation` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `nationality` char(2) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `id_document_type` varchar(60) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `id_document_number_enc` text COLLATE utf8mb4_unicode_ci,
  `ownership_percent` decimal(5,2) DEFAULT NULL,
  `is_ubo` tinyint unsigned NOT NULL DEFAULT '0',
  `is_pep_declared` tinyint unsigned NOT NULL DEFAULT '0',
  `verification_status` enum('pending','verified','rejected') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `created_at` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `company_id` (`company_id`),
  CONSTRAINT `company_directors_company_id_foreign` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `company_directors`
--

LOCK TABLES `company_directors` WRITE;
/*!40000 ALTER TABLE `company_directors` DISABLE KEYS */;
/*!40000 ALTER TABLE `company_directors` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `company_member_permissions`
--

DROP TABLE IF EXISTS `company_member_permissions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `company_member_permissions` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `company_member_id` int unsigned NOT NULL,
  `permission_key` varchar(80) COLLATE utf8mb4_unicode_ci NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `company_member_id_permission_key` (`company_member_id`,`permission_key`),
  CONSTRAINT `company_member_permissions_company_member_id_foreign` FOREIGN KEY (`company_member_id`) REFERENCES `company_members` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `company_member_permissions`
--

LOCK TABLES `company_member_permissions` WRITE;
/*!40000 ALTER TABLE `company_member_permissions` DISABLE KEYS */;
/*!40000 ALTER TABLE `company_member_permissions` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `company_members`
--

DROP TABLE IF EXISTS `company_members`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `company_members` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `company_id` int unsigned NOT NULL,
  `user_id` int unsigned NOT NULL,
  `member_role` enum('owner','admin','employee') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'employee',
  `job_title` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `status` enum('active','invited','disabled') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'active',
  `created_at` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `company_id_user_id` (`company_id`,`user_id`),
  UNIQUE KEY `user_id` (`user_id`),
  CONSTRAINT `company_members_company_id_foreign` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `company_members_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `company_members`
--

LOCK TABLES `company_members` WRITE;
/*!40000 ALTER TABLE `company_members` DISABLE KEYS */;
/*!40000 ALTER TABLE `company_members` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `compliance_certifications`
--

DROP TABLE IF EXISTS `compliance_certifications`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `compliance_certifications` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `company_id` int unsigned NOT NULL,
  `cert_type` enum('ISO9001','IATF16949','AS9100','ISO14001','ISO45001','RoHS','REACH','ESG','INSURANCE','LABOR','OTHER') COLLATE utf8mb4_unicode_ci NOT NULL,
  `cert_name` varchar(191) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `cert_number` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `issuer` varchar(191) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `issued_on` date DEFAULT NULL,
  `expires_on` date DEFAULT NULL,
  `document_id` int unsigned DEFAULT NULL,
  `status` enum('pending','verified','rejected','expired') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `reviewer_notes` text COLLATE utf8mb4_unicode_ci,
  `reviewed_by` int unsigned DEFAULT NULL,
  `reviewed_at` datetime DEFAULT NULL,
  `created_at` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `compliance_certifications_document_id_foreign` (`document_id`),
  KEY `compliance_certifications_reviewed_by_foreign` (`reviewed_by`),
  KEY `company_id` (`company_id`),
  KEY `expires_on` (`expires_on`),
  KEY `status` (`status`),
  CONSTRAINT `compliance_certifications_company_id_foreign` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `compliance_certifications_document_id_foreign` FOREIGN KEY (`document_id`) REFERENCES `documents` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `compliance_certifications_reviewed_by_foreign` FOREIGN KEY (`reviewed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `compliance_certifications`
--

LOCK TABLES `compliance_certifications` WRITE;
/*!40000 ALTER TABLE `compliance_certifications` DISABLE KEYS */;
/*!40000 ALTER TABLE `compliance_certifications` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `contact_leads`
--

DROP TABLE IF EXISTS `contact_leads`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `contact_leads` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(120) COLLATE utf8mb4_unicode_ci NOT NULL,
  `company_name` varchar(191) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `email` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `phone` varchar(40) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `country_code` char(2) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `enquiry_type` enum('general','buyer','supplier','membership','inspection','logistics','partnership') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'general',
  `message` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `status` enum('new','contacted','closed') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'new',
  `ip_address` varchar(45) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `contact_leads`
--

LOCK TABLES `contact_leads` WRITE;
/*!40000 ALTER TABLE `contact_leads` DISABLE KEYS */;
/*!40000 ALTER TABLE `contact_leads` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `countries`
--

DROP TABLE IF EXISTS `countries`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `countries` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `iso2` char(2) COLLATE utf8mb4_unicode_ci NOT NULL,
  `name` varchar(120) COLLATE utf8mb4_unicode_ci NOT NULL,
  `region` varchar(60) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `is_enabled` tinyint unsigned NOT NULL DEFAULT '1',
  PRIMARY KEY (`id`),
  UNIQUE KEY `iso2` (`iso2`)
) ENGINE=InnoDB AUTO_INCREMENT=250 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `countries`
--

LOCK TABLES `countries` WRITE;
/*!40000 ALTER TABLE `countries` DISABLE KEYS */;
INSERT INTO `countries` VALUES (1,'AD','Andorra',NULL,1),(2,'AE','United Arab Emirates',NULL,1),(3,'AF','Afghanistan',NULL,1),(4,'AG','Antigua & Barbuda',NULL,1),(5,'AI','Anguilla',NULL,1),(6,'AL','Albania',NULL,1),(7,'AM','Armenia',NULL,1),(8,'AO','Angola',NULL,1),(9,'AQ','Antarctica',NULL,1),(10,'AR','Argentina',NULL,1),(11,'AS','American Samoa',NULL,1),(12,'AT','Austria',NULL,1),(13,'AU','Australia',NULL,1),(14,'AW','Aruba',NULL,1),(15,'AX','Åland Islands',NULL,1),(16,'AZ','Azerbaijan',NULL,1),(17,'BA','Bosnia & Herzegovina',NULL,1),(18,'BB','Barbados',NULL,1),(19,'BD','Bangladesh',NULL,1),(20,'BE','Belgium',NULL,1),(21,'BF','Burkina Faso',NULL,1),(22,'BG','Bulgaria',NULL,1),(23,'BH','Bahrain',NULL,1),(24,'BI','Burundi',NULL,1),(25,'BJ','Benin',NULL,1),(26,'BL','St. Barthélemy',NULL,1),(27,'BM','Bermuda',NULL,1),(28,'BN','Brunei',NULL,1),(29,'BO','Bolivia',NULL,1),(30,'BQ','Caribbean Netherlands',NULL,1),(31,'BR','Brazil',NULL,1),(32,'BS','Bahamas',NULL,1),(33,'BT','Bhutan',NULL,1),(34,'BV','Bouvet Island',NULL,1),(35,'BW','Botswana',NULL,1),(36,'BY','Belarus',NULL,1),(37,'BZ','Belize',NULL,1),(38,'CA','Canada',NULL,1),(39,'CC','Cocos (Keeling) Islands',NULL,1),(40,'CD','Congo - Kinshasa',NULL,1),(41,'CF','Central African Republic',NULL,1),(42,'CG','Congo - Brazzaville',NULL,1),(43,'CH','Switzerland',NULL,1),(44,'CI','Côte d’Ivoire',NULL,1),(45,'CK','Cook Islands',NULL,1),(46,'CL','Chile',NULL,1),(47,'CM','Cameroon',NULL,1),(48,'CN','China',NULL,1),(49,'CO','Colombia',NULL,1),(50,'CR','Costa Rica',NULL,1),(51,'CU','Cuba',NULL,1),(52,'CV','Cape Verde',NULL,1),(53,'CW','Curaçao',NULL,1),(54,'CX','Christmas Island',NULL,1),(55,'CY','Cyprus',NULL,1),(56,'CZ','Czechia',NULL,1),(57,'DE','Germany',NULL,1),(58,'DJ','Djibouti',NULL,1),(59,'DK','Denmark',NULL,1),(60,'DM','Dominica',NULL,1),(61,'DO','Dominican Republic',NULL,1),(62,'DZ','Algeria',NULL,1),(63,'EC','Ecuador',NULL,1),(64,'EE','Estonia',NULL,1),(65,'EG','Egypt',NULL,1),(66,'EH','Western Sahara',NULL,1),(67,'ER','Eritrea',NULL,1),(68,'ES','Spain',NULL,1),(69,'ET','Ethiopia',NULL,1),(70,'FI','Finland',NULL,1),(71,'FJ','Fiji',NULL,1),(72,'FK','Falkland Islands',NULL,1),(73,'FM','Micronesia',NULL,1),(74,'FO','Faroe Islands',NULL,1),(75,'FR','France',NULL,1),(76,'GA','Gabon',NULL,1),(77,'GB','United Kingdom',NULL,1),(78,'GD','Grenada',NULL,1),(79,'GE','Georgia',NULL,1),(80,'GF','French Guiana',NULL,1),(81,'GG','Guernsey',NULL,1),(82,'GH','Ghana',NULL,1),(83,'GI','Gibraltar',NULL,1),(84,'GL','Greenland',NULL,1),(85,'GM','Gambia',NULL,1),(86,'GN','Guinea',NULL,1),(87,'GP','Guadeloupe',NULL,1),(88,'GQ','Equatorial Guinea',NULL,1),(89,'GR','Greece',NULL,1),(90,'GS','South Georgia & South Sandwich Islands',NULL,1),(91,'GT','Guatemala',NULL,1),(92,'GU','Guam',NULL,1),(93,'GW','Guinea-Bissau',NULL,1),(94,'GY','Guyana',NULL,1),(95,'HK','Hong Kong SAR China',NULL,1),(96,'HM','Heard & McDonald Islands',NULL,1),(97,'HN','Honduras',NULL,1),(98,'HR','Croatia',NULL,1),(99,'HT','Haiti',NULL,1),(100,'HU','Hungary',NULL,1),(101,'ID','Indonesia',NULL,1),(102,'IE','Ireland',NULL,1),(103,'IL','Israel',NULL,1),(104,'IM','Isle of Man',NULL,1),(105,'IN','India',NULL,1),(106,'IO','British Indian Ocean Territory',NULL,1),(107,'IQ','Iraq',NULL,1),(108,'IR','Iran',NULL,1),(109,'IS','Iceland',NULL,1),(110,'IT','Italy',NULL,1),(111,'JE','Jersey',NULL,1),(112,'JM','Jamaica',NULL,1),(113,'JO','Jordan',NULL,1),(114,'JP','Japan',NULL,1),(115,'KE','Kenya',NULL,1),(116,'KG','Kyrgyzstan',NULL,1),(117,'KH','Cambodia',NULL,1),(118,'KI','Kiribati',NULL,1),(119,'KM','Comoros',NULL,1),(120,'KN','St. Kitts & Nevis',NULL,1),(121,'KP','North Korea',NULL,1),(122,'KR','South Korea',NULL,1),(123,'KW','Kuwait',NULL,1),(124,'KY','Cayman Islands',NULL,1),(125,'KZ','Kazakhstan',NULL,1),(126,'LA','Laos',NULL,1),(127,'LB','Lebanon',NULL,1),(128,'LC','St. Lucia',NULL,1),(129,'LI','Liechtenstein',NULL,1),(130,'LK','Sri Lanka',NULL,1),(131,'LR','Liberia',NULL,1),(132,'LS','Lesotho',NULL,1),(133,'LT','Lithuania',NULL,1),(134,'LU','Luxembourg',NULL,1),(135,'LV','Latvia',NULL,1),(136,'LY','Libya',NULL,1),(137,'MA','Morocco',NULL,1),(138,'MC','Monaco',NULL,1),(139,'MD','Moldova',NULL,1),(140,'ME','Montenegro',NULL,1),(141,'MF','St. Martin',NULL,1),(142,'MG','Madagascar',NULL,1),(143,'MH','Marshall Islands',NULL,1),(144,'MK','North Macedonia',NULL,1),(145,'ML','Mali',NULL,1),(146,'MM','Myanmar (Burma)',NULL,1),(147,'MN','Mongolia',NULL,1),(148,'MO','Macao SAR China',NULL,1),(149,'MP','Northern Mariana Islands',NULL,1),(150,'MQ','Martinique',NULL,1),(151,'MR','Mauritania',NULL,1),(152,'MS','Montserrat',NULL,1),(153,'MT','Malta',NULL,1),(154,'MU','Mauritius',NULL,1),(155,'MV','Maldives',NULL,1),(156,'MW','Malawi',NULL,1),(157,'MX','Mexico',NULL,1),(158,'MY','Malaysia',NULL,1),(159,'MZ','Mozambique',NULL,1),(160,'NA','Namibia',NULL,1),(161,'NC','New Caledonia',NULL,1),(162,'NE','Niger',NULL,1),(163,'NF','Norfolk Island',NULL,1),(164,'NG','Nigeria',NULL,1),(165,'NI','Nicaragua',NULL,1),(166,'NL','Netherlands',NULL,1),(167,'NO','Norway',NULL,1),(168,'NP','Nepal',NULL,1),(169,'NR','Nauru',NULL,1),(170,'NU','Niue',NULL,1),(171,'NZ','New Zealand',NULL,1),(172,'OM','Oman',NULL,1),(173,'PA','Panama',NULL,1),(174,'PE','Peru',NULL,1),(175,'PF','French Polynesia',NULL,1),(176,'PG','Papua New Guinea',NULL,1),(177,'PH','Philippines',NULL,1),(178,'PK','Pakistan',NULL,1),(179,'PL','Poland',NULL,1),(180,'PM','St. Pierre & Miquelon',NULL,1),(181,'PN','Pitcairn Islands',NULL,1),(182,'PR','Puerto Rico',NULL,1),(183,'PS','Palestinian Territories',NULL,1),(184,'PT','Portugal',NULL,1),(185,'PW','Palau',NULL,1),(186,'PY','Paraguay',NULL,1),(187,'QA','Qatar',NULL,1),(188,'RE','Réunion',NULL,1),(189,'RO','Romania',NULL,1),(190,'RS','Serbia',NULL,1),(191,'RU','Russia',NULL,1),(192,'RW','Rwanda',NULL,1),(193,'SA','Saudi Arabia',NULL,1),(194,'SB','Solomon Islands',NULL,1),(195,'SC','Seychelles',NULL,1),(196,'SD','Sudan',NULL,1),(197,'SE','Sweden',NULL,1),(198,'SG','Singapore',NULL,1),(199,'SH','St. Helena',NULL,1),(200,'SI','Slovenia',NULL,1),(201,'SJ','Svalbard & Jan Mayen',NULL,1),(202,'SK','Slovakia',NULL,1),(203,'SL','Sierra Leone',NULL,1),(204,'SM','San Marino',NULL,1),(205,'SN','Senegal',NULL,1),(206,'SO','Somalia',NULL,1),(207,'SR','Suriname',NULL,1),(208,'SS','South Sudan',NULL,1),(209,'ST','São Tomé & Príncipe',NULL,1),(210,'SV','El Salvador',NULL,1),(211,'SX','Sint Maarten',NULL,1),(212,'SY','Syria',NULL,1),(213,'SZ','Eswatini',NULL,1),(214,'TC','Turks & Caicos Islands',NULL,1),(215,'TD','Chad',NULL,1),(216,'TF','French Southern Territories',NULL,1),(217,'TG','Togo',NULL,1),(218,'TH','Thailand',NULL,1),(219,'TJ','Tajikistan',NULL,1),(220,'TK','Tokelau',NULL,1),(221,'TL','Timor-Leste',NULL,1),(222,'TM','Turkmenistan',NULL,1),(223,'TN','Tunisia',NULL,1),(224,'TO','Tonga',NULL,1),(225,'TR','Türkiye',NULL,1),(226,'TT','Trinidad & Tobago',NULL,1),(227,'TV','Tuvalu',NULL,1),(228,'TW','Taiwan',NULL,1),(229,'TZ','Tanzania',NULL,1),(230,'UA','Ukraine',NULL,1),(231,'UG','Uganda',NULL,1),(232,'UM','U.S. Outlying Islands',NULL,1),(233,'US','United States',NULL,1),(234,'UY','Uruguay',NULL,1),(235,'UZ','Uzbekistan',NULL,1),(236,'VA','Vatican City',NULL,1),(237,'VC','St. Vincent & Grenadines',NULL,1),(238,'VE','Venezuela',NULL,1),(239,'VG','British Virgin Islands',NULL,1),(240,'VI','U.S. Virgin Islands',NULL,1),(241,'VN','Vietnam',NULL,1),(242,'VU','Vanuatu',NULL,1),(243,'WF','Wallis & Futuna',NULL,1),(244,'WS','Samoa',NULL,1),(245,'YE','Yemen',NULL,1),(246,'YT','Mayotte',NULL,1),(247,'ZA','South Africa',NULL,1),(248,'ZM','Zambia',NULL,1),(249,'ZW','Zimbabwe',NULL,1);
/*!40000 ALTER TABLE `countries` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `cross_references`
--

DROP TABLE IF EXISTS `cross_references`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `cross_references` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `product_id` int unsigned NOT NULL,
  `ref_brand` varchar(120) COLLATE utf8mb4_unicode_ci NOT NULL,
  `ref_part_number` varchar(80) COLLATE utf8mb4_unicode_ci NOT NULL,
  `ref_part_number_norm` varchar(80) COLLATE utf8mb4_unicode_ci NOT NULL,
  `relation` enum('declared_equivalent','verified_interchange','supersedes','superseded_by') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'declared_equivalent',
  `verified_by` int unsigned DEFAULT NULL,
  `verified_at` datetime DEFAULT NULL,
  `notes` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `product_id_ref_brand_ref_part_number_norm` (`product_id`,`ref_brand`,`ref_part_number_norm`),
  KEY `cross_references_verified_by_foreign` (`verified_by`),
  KEY `ref_part_number_norm` (`ref_part_number_norm`),
  CONSTRAINT `cross_references_product_id_foreign` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `cross_references_verified_by_foreign` FOREIGN KEY (`verified_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `cross_references`
--

LOCK TABLES `cross_references` WRITE;
/*!40000 ALTER TABLE `cross_references` DISABLE KEYS */;
/*!40000 ALTER TABLE `cross_references` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `currencies`
--

DROP TABLE IF EXISTS `currencies`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `currencies` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `code` char(3) COLLATE utf8mb4_unicode_ci NOT NULL,
  `name` varchar(80) COLLATE utf8mb4_unicode_ci NOT NULL,
  `symbol` varchar(8) COLLATE utf8mb4_unicode_ci NOT NULL,
  `is_enabled` tinyint unsigned NOT NULL DEFAULT '1',
  `is_base` tinyint unsigned NOT NULL DEFAULT '0',
  `rate_to_base` decimal(18,6) DEFAULT NULL,
  `rate_updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `code` (`code`)
) ENGINE=InnoDB AUTO_INCREMENT=9 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `currencies`
--

LOCK TABLES `currencies` WRITE;
/*!40000 ALTER TABLE `currencies` DISABLE KEYS */;
INSERT INTO `currencies` VALUES (1,'INR','Indian Rupee','₹',1,1,NULL,NULL),(2,'USD','US Dollar','$',1,0,NULL,NULL),(3,'EUR','Euro','€',1,0,NULL,NULL),(4,'GBP','Pound Sterling','£',1,0,NULL,NULL),(5,'AED','UAE Dirham','AED',1,0,NULL,NULL),(6,'SGD','Singapore Dollar','S$',1,0,NULL,NULL),(7,'JPY','Japanese Yen','¥',1,0,NULL,NULL),(8,'CNY','Chinese Yuan','CN¥',1,0,NULL,NULL);
/*!40000 ALTER TABLE `currencies` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `dispute_messages`
--

DROP TABLE IF EXISTS `dispute_messages`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `dispute_messages` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `dispute_id` int unsigned NOT NULL,
  `user_id` int unsigned DEFAULT NULL,
  `sender_side` enum('buyer','supplier','admin') COLLATE utf8mb4_unicode_ci NOT NULL,
  `message` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `is_internal` tinyint unsigned NOT NULL DEFAULT '0',
  `created_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `dispute_messages_user_id_foreign` (`user_id`),
  KEY `dispute_id` (`dispute_id`),
  CONSTRAINT `dispute_messages_dispute_id_foreign` FOREIGN KEY (`dispute_id`) REFERENCES `disputes` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `dispute_messages_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `dispute_messages`
--

LOCK TABLES `dispute_messages` WRITE;
/*!40000 ALTER TABLE `dispute_messages` DISABLE KEYS */;
/*!40000 ALTER TABLE `dispute_messages` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `disputes`
--

DROP TABLE IF EXISTS `disputes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `disputes` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `dispute_number` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL,
  `order_id` int unsigned NOT NULL,
  `raised_by_company_id` int unsigned NOT NULL,
  `against_company_id` int unsigned NOT NULL,
  `raised_by` int unsigned DEFAULT NULL,
  `category` enum('quality','quantity_shortfall','wrong_item','damaged_goods','delayed_delivery','documentation','payment','inspection_service','platform_service','other') COLLATE utf8mb4_unicode_ci NOT NULL,
  `subject` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `desired_resolution` text COLLATE utf8mb4_unicode_ci,
  `status` enum('open','awaiting_response','under_review','resolved','rejected','closed') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'open',
  `assigned_to` int unsigned DEFAULT NULL,
  `resolution_notes` text COLLATE utf8mb4_unicode_ci,
  `resolved_at` datetime DEFAULT NULL,
  `closed_at` datetime DEFAULT NULL,
  `created_at` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  `bc_intervention_eligible` tinyint unsigned DEFAULT '0',
  `inspection_request_id` int unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `dispute_number` (`dispute_number`),
  KEY `disputes_raised_by_company_id_foreign` (`raised_by_company_id`),
  KEY `disputes_against_company_id_foreign` (`against_company_id`),
  KEY `disputes_raised_by_foreign` (`raised_by`),
  KEY `disputes_assigned_to_foreign` (`assigned_to`),
  KEY `status` (`status`),
  KEY `order_id` (`order_id`),
  CONSTRAINT `disputes_against_company_id_foreign` FOREIGN KEY (`against_company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `disputes_assigned_to_foreign` FOREIGN KEY (`assigned_to`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `disputes_order_id_foreign` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `disputes_raised_by_company_id_foreign` FOREIGN KEY (`raised_by_company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `disputes_raised_by_foreign` FOREIGN KEY (`raised_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `disputes`
--

LOCK TABLES `disputes` WRITE;
/*!40000 ALTER TABLE `disputes` DISABLE KEYS */;
/*!40000 ALTER TABLE `disputes` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `documents`
--

DROP TABLE IF EXISTS `documents`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `documents` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `uuid` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `company_id` int unsigned DEFAULT NULL,
  `uploaded_by` int unsigned DEFAULT NULL,
  `entity_type` varchar(40) COLLATE utf8mb4_unicode_ci NOT NULL,
  `entity_id` int unsigned DEFAULT NULL,
  `doc_type` varchar(60) COLLATE utf8mb4_unicode_ci NOT NULL,
  `title` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `original_name` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `stored_path` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `mime_type` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `size_bytes` int unsigned NOT NULL DEFAULT '0',
  `sha256` char(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `version` smallint unsigned NOT NULL DEFAULT '1',
  `previous_version_id` int unsigned DEFAULT NULL,
  `is_current` tinyint unsigned NOT NULL DEFAULT '1',
  `issued_on` date DEFAULT NULL,
  `expires_on` date DEFAULT NULL,
  `expiry_reminder_sent_at` datetime DEFAULT NULL,
  `visibility` enum('private','platform','verified_buyers','public') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'private',
  `review_status` enum('pending','approved','rejected') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `review_comment` text COLLATE utf8mb4_unicode_ci,
  `reviewed_by` int unsigned DEFAULT NULL,
  `reviewed_at` datetime DEFAULT NULL,
  `created_at` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  `deleted_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uuid` (`uuid`),
  KEY `documents_company_id_foreign` (`company_id`),
  KEY `documents_uploaded_by_foreign` (`uploaded_by`),
  KEY `documents_reviewed_by_foreign` (`reviewed_by`),
  KEY `documents_previous_version_id_foreign` (`previous_version_id`),
  KEY `entity_type_entity_id` (`entity_type`,`entity_id`),
  KEY `expires_on` (`expires_on`),
  KEY `review_status` (`review_status`),
  CONSTRAINT `documents_company_id_foreign` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `documents_previous_version_id_foreign` FOREIGN KEY (`previous_version_id`) REFERENCES `documents` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `documents_reviewed_by_foreign` FOREIGN KEY (`reviewed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `documents_uploaded_by_foreign` FOREIGN KEY (`uploaded_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `documents`
--

LOCK TABLES `documents` WRITE;
/*!40000 ALTER TABLE `documents` DISABLE KEYS */;
/*!40000 ALTER TABLE `documents` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `email_outbox`
--

DROP TABLE IF EXISTS `email_outbox`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `email_outbox` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int unsigned DEFAULT NULL,
  `to_email` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `subject` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `body_html` mediumtext COLLATE utf8mb4_unicode_ci,
  `status` enum('queued','sent','failed','disabled') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'queued',
  `attempts` tinyint unsigned NOT NULL DEFAULT '0',
  `last_error` text COLLATE utf8mb4_unicode_ci,
  `sent_at` datetime DEFAULT NULL,
  `created_at` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `email_outbox_user_id_foreign` (`user_id`),
  KEY `status` (`status`),
  CONSTRAINT `email_outbox_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `email_outbox`
--

LOCK TABLES `email_outbox` WRITE;
/*!40000 ALTER TABLE `email_outbox` DISABLE KEYS */;
/*!40000 ALTER TABLE `email_outbox` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `financial_assessments`
--

DROP TABLE IF EXISTS `financial_assessments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `financial_assessments` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `company_id` int unsigned NOT NULL,
  `fiscal_year` varchar(9) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `currency` char(3) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'INR',
  `annual_turnover` decimal(18,2) DEFAULT NULL,
  `net_profit` decimal(18,2) DEFAULT NULL,
  `total_assets` decimal(18,2) DEFAULT NULL,
  `total_liabilities` decimal(18,2) DEFAULT NULL,
  `credit_rating` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `credit_agency` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `banking_reference` text COLLATE utf8mb4_unicode_ci,
  `legal_cases_count` int unsigned DEFAULT NULL,
  `legal_case_notes` text COLLATE utf8mb4_unicode_ci,
  `bankruptcy_history` tinyint unsigned NOT NULL DEFAULT '0',
  `status` enum('pending','verified','rejected') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `reviewer_notes` text COLLATE utf8mb4_unicode_ci,
  `reviewed_by` int unsigned DEFAULT NULL,
  `reviewed_at` datetime DEFAULT NULL,
  `created_at` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `financial_assessments_reviewed_by_foreign` (`reviewed_by`),
  KEY `company_id` (`company_id`),
  CONSTRAINT `financial_assessments_company_id_foreign` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `financial_assessments_reviewed_by_foreign` FOREIGN KEY (`reviewed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `financial_assessments`
--

LOCK TABLES `financial_assessments` WRITE;
/*!40000 ALTER TABLE `financial_assessments` DISABLE KEYS */;
/*!40000 ALTER TABLE `financial_assessments` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `fitments`
--

DROP TABLE IF EXISTS `fitments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `fitments` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `product_id` int unsigned NOT NULL,
  `make` varchar(80) COLLATE utf8mb4_unicode_ci NOT NULL,
  `model` varchar(80) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `variant` varchar(120) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `engine` varchar(80) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `year_from` smallint unsigned DEFAULT NULL,
  `year_to` smallint unsigned DEFAULT NULL,
  `application` varchar(191) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `product_id` (`product_id`),
  KEY `make_model` (`make`,`model`),
  CONSTRAINT `fitments_product_id_foreign` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `fitments`
--

LOCK TABLES `fitments` WRITE;
/*!40000 ALTER TABLE `fitments` DISABLE KEYS */;
/*!40000 ALTER TABLE `fitments` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `import_job_rows`
--

DROP TABLE IF EXISTS `import_job_rows`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `import_job_rows` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `import_job_id` int unsigned NOT NULL,
  `row_number` int unsigned NOT NULL DEFAULT '0',
  `data` json DEFAULT NULL,
  `errors` json DEFAULT NULL,
  `status` enum('valid','error','duplicate','imported','skipped') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'valid',
  `entity_id` int unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `import_job_id_status` (`import_job_id`,`status`),
  CONSTRAINT `import_job_rows_import_job_id_foreign` FOREIGN KEY (`import_job_id`) REFERENCES `import_jobs` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `import_job_rows`
--

LOCK TABLES `import_job_rows` WRITE;
/*!40000 ALTER TABLE `import_job_rows` DISABLE KEYS */;
/*!40000 ALTER TABLE `import_job_rows` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `import_jobs`
--

DROP TABLE IF EXISTS `import_jobs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `import_jobs` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `uuid` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `import_type` enum('products','supplier_master') COLLATE utf8mb4_unicode_ci NOT NULL,
  `company_id` int unsigned DEFAULT NULL,
  `created_by` int unsigned DEFAULT NULL,
  `original_name` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `stored_path` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `status` enum('uploaded','mapped','validated','completed','failed','cancelled') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'uploaded',
  `headers` json DEFAULT NULL,
  `column_map` json DEFAULT NULL,
  `total_rows` int unsigned NOT NULL DEFAULT '0',
  `valid_rows` int unsigned NOT NULL DEFAULT '0',
  `error_rows` int unsigned NOT NULL DEFAULT '0',
  `duplicate_rows` int unsigned NOT NULL DEFAULT '0',
  `imported_rows` int unsigned NOT NULL DEFAULT '0',
  `error_message` text COLLATE utf8mb4_unicode_ci,
  `completed_at` datetime DEFAULT NULL,
  `created_at` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uuid` (`uuid`),
  KEY `import_jobs_company_id_foreign` (`company_id`),
  KEY `import_jobs_created_by_foreign` (`created_by`),
  KEY `status` (`status`),
  CONSTRAINT `import_jobs_company_id_foreign` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `import_jobs_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `import_jobs`
--

LOCK TABLES `import_jobs` WRITE;
/*!40000 ALTER TABLE `import_jobs` DISABLE KEYS */;
/*!40000 ALTER TABLE `import_jobs` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `inspection_reports`
--

DROP TABLE IF EXISTS `inspection_reports`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `inspection_reports` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `inspection_request_id` int unsigned NOT NULL,
  `result` enum('pass','fail','conditional') COLLATE utf8mb4_unicode_ci NOT NULL,
  `inspected_on` date NOT NULL,
  `quantity_expected` int unsigned DEFAULT NULL,
  `quantity_verified` int unsigned DEFAULT NULL,
  `packaging_ok` tinyint unsigned NOT NULL DEFAULT '0',
  `authenticity_notes` text COLLATE utf8mb4_unicode_ci,
  `summary` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `findings` text COLLATE utf8mb4_unicode_ci,
  `document_id` int unsigned DEFAULT NULL,
  `uploaded_by` int unsigned DEFAULT NULL,
  `created_at` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `inspection_request_id` (`inspection_request_id`),
  KEY `inspection_reports_document_id_foreign` (`document_id`),
  KEY `inspection_reports_uploaded_by_foreign` (`uploaded_by`),
  CONSTRAINT `inspection_reports_document_id_foreign` FOREIGN KEY (`document_id`) REFERENCES `documents` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `inspection_reports_inspection_request_id_foreign` FOREIGN KEY (`inspection_request_id`) REFERENCES `inspection_requests` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `inspection_reports_uploaded_by_foreign` FOREIGN KEY (`uploaded_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `inspection_reports`
--

LOCK TABLES `inspection_reports` WRITE;
/*!40000 ALTER TABLE `inspection_reports` DISABLE KEYS */;
/*!40000 ALTER TABLE `inspection_reports` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `inspection_requests`
--

DROP TABLE IF EXISTS `inspection_requests`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `inspection_requests` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `request_number` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL,
  `company_id` int unsigned NOT NULL,
  `requested_by` int unsigned DEFAULT NULL,
  `order_id` int unsigned DEFAULT NULL,
  `product_id` int unsigned DEFAULT NULL,
  `inspection_type` enum('in_house','third_party') COLLATE utf8mb4_unicode_ci NOT NULL,
  `scope` json DEFAULT NULL,
  `location_country` char(2) COLLATE utf8mb4_unicode_ci NOT NULL,
  `location_city` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `invoice_value` decimal(15,2) DEFAULT NULL,
  `shipment_volume_cbm` decimal(10,3) DEFAULT NULL,
  `currency` char(3) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'INR',
  `estimated_fee` decimal(15,2) DEFAULT NULL,
  `quoted_fee` decimal(15,2) DEFAULT NULL,
  `fee_basis` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `status` enum('requested','quoted','accepted','declined','assigned','in_progress','report_uploaded','completed','cancelled') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'requested',
  `inspector_user_id` int unsigned DEFAULT NULL,
  `third_party_agency` varchar(191) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `scheduled_on` date DEFAULT NULL,
  `quote_valid_until` date DEFAULT NULL,
  `invoice_id` int unsigned DEFAULT NULL,
  `notes` text COLLATE utf8mb4_unicode_ci,
  `admin_notes` text COLLATE utf8mb4_unicode_ci,
  `created_at` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  `requested_at_checkout` tinyint unsigned DEFAULT '0',
  PRIMARY KEY (`id`),
  UNIQUE KEY `request_number` (`request_number`),
  KEY `inspection_requests_requested_by_foreign` (`requested_by`),
  KEY `inspection_requests_order_id_foreign` (`order_id`),
  KEY `inspection_requests_product_id_foreign` (`product_id`),
  KEY `inspection_requests_inspector_user_id_foreign` (`inspector_user_id`),
  KEY `inspection_requests_invoice_id_foreign` (`invoice_id`),
  KEY `status` (`status`),
  KEY `company_id` (`company_id`),
  CONSTRAINT `inspection_requests_company_id_foreign` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `inspection_requests_inspector_user_id_foreign` FOREIGN KEY (`inspector_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `inspection_requests_invoice_id_foreign` FOREIGN KEY (`invoice_id`) REFERENCES `invoices` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `inspection_requests_order_id_foreign` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `inspection_requests_product_id_foreign` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `inspection_requests_requested_by_foreign` FOREIGN KEY (`requested_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `inspection_requests`
--

LOCK TABLES `inspection_requests` WRITE;
/*!40000 ALTER TABLE `inspection_requests` DISABLE KEYS */;
/*!40000 ALTER TABLE `inspection_requests` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `inventory_lots`
--

DROP TABLE IF EXISTS `inventory_lots`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `inventory_lots` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `product_id` int unsigned NOT NULL,
  `lot_code` varchar(60) COLLATE utf8mb4_unicode_ci NOT NULL,
  `quantity` int unsigned NOT NULL DEFAULT '0',
  `received_on` date NOT NULL,
  `manufactured_on` date DEFAULT NULL,
  `warehouse_location` varchar(191) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `notes` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `product_id_lot_code` (`product_id`,`lot_code`),
  KEY `received_on` (`received_on`),
  CONSTRAINT `inventory_lots_product_id_foreign` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `chk_lots_qty` CHECK ((`quantity` >= 0))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `inventory_lots`
--

LOCK TABLES `inventory_lots` WRITE;
/*!40000 ALTER TABLE `inventory_lots` DISABLE KEYS */;
/*!40000 ALTER TABLE `inventory_lots` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `inventory_movements`
--

DROP TABLE IF EXISTS `inventory_movements`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `inventory_movements` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `product_id` int unsigned NOT NULL,
  `lot_id` int unsigned DEFAULT NULL,
  `movement_type` enum('receipt','adjustment','reservation','release','dispatch','import','return') COLLATE utf8mb4_unicode_ci NOT NULL,
  `quantity` int NOT NULL,
  `on_hand_after` int unsigned NOT NULL DEFAULT '0',
  `reserved_after` int unsigned NOT NULL DEFAULT '0',
  `reference_type` varchar(40) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `reference_id` int unsigned DEFAULT NULL,
  `user_id` int unsigned DEFAULT NULL,
  `note` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `inventory_movements_lot_id_foreign` (`lot_id`),
  KEY `inventory_movements_user_id_foreign` (`user_id`),
  KEY `product_id` (`product_id`),
  KEY `reference_type_reference_id` (`reference_type`,`reference_id`),
  CONSTRAINT `inventory_movements_lot_id_foreign` FOREIGN KEY (`lot_id`) REFERENCES `inventory_lots` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `inventory_movements_product_id_foreign` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `inventory_movements_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `inventory_movements`
--

LOCK TABLES `inventory_movements` WRITE;
/*!40000 ALTER TABLE `inventory_movements` DISABLE KEYS */;
/*!40000 ALTER TABLE `inventory_movements` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `invoices`
--

DROP TABLE IF EXISTS `invoices`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `invoices` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `invoice_number` varchar(40) COLLATE utf8mb4_unicode_ci NOT NULL,
  `company_id` int unsigned NOT NULL,
  `invoice_type` enum('subscription','inspection','logistics','proforma') COLLATE utf8mb4_unicode_ci NOT NULL,
  `reference_type` varchar(40) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `reference_id` int unsigned DEFAULT NULL,
  `currency` char(3) COLLATE utf8mb4_unicode_ci NOT NULL,
  `subtotal` decimal(15,2) NOT NULL DEFAULT '0.00',
  `tax_rate` decimal(5,2) NOT NULL DEFAULT '0.00',
  `tax_amount` decimal(15,2) NOT NULL DEFAULT '0.00',
  `total` decimal(15,2) NOT NULL DEFAULT '0.00',
  `tax_note` varchar(191) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `status` enum('draft','issued','paid','void') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'issued',
  `issued_at` datetime DEFAULT NULL,
  `due_at` datetime DEFAULT NULL,
  `paid_at` datetime DEFAULT NULL,
  `notes` text COLLATE utf8mb4_unicode_ci,
  `created_by` int unsigned DEFAULT NULL,
  `created_at` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `invoice_number` (`invoice_number`),
  KEY `invoices_company_id_foreign` (`company_id`),
  KEY `invoices_created_by_foreign` (`created_by`),
  KEY `status` (`status`),
  KEY `reference_type_reference_id` (`reference_type`,`reference_id`),
  CONSTRAINT `invoices_company_id_foreign` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `invoices_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `invoices`
--

LOCK TABLES `invoices` WRITE;
/*!40000 ALTER TABLE `invoices` DISABLE KEYS */;
/*!40000 ALTER TABLE `invoices` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `kyc_records`
--

DROP TABLE IF EXISTS `kyc_records`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `kyc_records` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `company_id` int unsigned NOT NULL,
  `gst_number` varchar(30) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `pan_number` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `cin_number` varchar(30) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `vat_number` varchar(40) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `tax_id` varchar(60) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `bank_account_name` varchar(191) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `bank_account_number_enc` text COLLATE utf8mb4_unicode_ci,
  `bank_account_last4` varchar(4) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `bank_name` varchar(191) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `bank_ifsc_swift` varchar(30) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `bank_country` char(2) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `bank_validated` enum('pending','validated','failed') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `address_verified` enum('pending','verified','failed') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `owner_verified` enum('pending','verified','failed') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `ubo_verified` enum('pending','verified','failed') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `tax_verified` enum('pending','verified','failed') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `reviewer_notes` text COLLATE utf8mb4_unicode_ci,
  `reviewed_by` int unsigned DEFAULT NULL,
  `reviewed_at` datetime DEFAULT NULL,
  `created_at` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `company_id` (`company_id`),
  KEY `kyc_records_reviewed_by_foreign` (`reviewed_by`),
  CONSTRAINT `kyc_records_company_id_foreign` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `kyc_records_reviewed_by_foreign` FOREIGN KEY (`reviewed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `kyc_records`
--

LOCK TABLES `kyc_records` WRITE;
/*!40000 ALTER TABLE `kyc_records` DISABLE KEYS */;
/*!40000 ALTER TABLE `kyc_records` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `logistics_requests`
--

DROP TABLE IF EXISTS `logistics_requests`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `logistics_requests` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `request_number` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL,
  `company_id` int unsigned NOT NULL,
  `requested_by` int unsigned DEFAULT NULL,
  `order_id` int unsigned DEFAULT NULL,
  `mode` enum('supplier_direct','platform_managed','confidential','consolidation') COLLATE utf8mb4_unicode_ci NOT NULL,
  `consolidation_ref` varchar(40) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `pickup_country` char(2) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `pickup_city` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `pickup_address` text COLLATE utf8mb4_unicode_ci,
  `pickup_contact` varchar(191) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `destination_country` char(2) COLLATE utf8mb4_unicode_ci NOT NULL,
  `destination_city` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `destination_address` text COLLATE utf8mb4_unicode_ci,
  `destination_contact` varchar(191) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `incoterm` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `cargo_description` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `weight_kg` decimal(12,3) DEFAULT NULL,
  `volume_cbm` decimal(10,3) DEFAULT NULL,
  `packages` int unsigned DEFAULT NULL,
  `goods_value` decimal(15,2) DEFAULT NULL,
  `currency` char(3) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'INR',
  `estimated_fee` decimal(15,2) DEFAULT NULL,
  `quoted_fee` decimal(15,2) DEFAULT NULL,
  `fee_basis` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `status` enum('requested','quoted','accepted','booked','in_transit','delivered','cancelled') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'requested',
  `assigned_to` int unsigned DEFAULT NULL,
  `invoice_id` int unsigned DEFAULT NULL,
  `notes` text COLLATE utf8mb4_unicode_ci,
  `admin_notes` text COLLATE utf8mb4_unicode_ci,
  `created_at` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `request_number` (`request_number`),
  KEY `logistics_requests_requested_by_foreign` (`requested_by`),
  KEY `logistics_requests_order_id_foreign` (`order_id`),
  KEY `logistics_requests_assigned_to_foreign` (`assigned_to`),
  KEY `logistics_requests_invoice_id_foreign` (`invoice_id`),
  KEY `status` (`status`),
  KEY `company_id` (`company_id`),
  CONSTRAINT `logistics_requests_assigned_to_foreign` FOREIGN KEY (`assigned_to`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `logistics_requests_company_id_foreign` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `logistics_requests_invoice_id_foreign` FOREIGN KEY (`invoice_id`) REFERENCES `invoices` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `logistics_requests_order_id_foreign` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `logistics_requests_requested_by_foreign` FOREIGN KEY (`requested_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `logistics_requests`
--

LOCK TABLES `logistics_requests` WRITE;
/*!40000 ALTER TABLE `logistics_requests` DISABLE KEYS */;
/*!40000 ALTER TABLE `logistics_requests` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `membership_plans`
--

DROP TABLE IF EXISTS `membership_plans`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `membership_plans` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `code` varchar(40) COLLATE utf8mb4_unicode_ci NOT NULL,
  `name` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `audience` enum('supplier','buyer') COLLATE utf8mb4_unicode_ci NOT NULL,
  `annual_fee` decimal(12,2) NOT NULL DEFAULT '0.00',
  `currency` char(3) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'INR',
  `duration_months` smallint unsigned NOT NULL DEFAULT '12',
  `requires_verification` tinyint unsigned NOT NULL DEFAULT '0',
  `is_default` tinyint unsigned NOT NULL DEFAULT '0',
  `is_active` tinyint unsigned NOT NULL DEFAULT '1',
  `description` text COLLATE utf8mb4_unicode_ci,
  `sort_order` int unsigned NOT NULL DEFAULT '0',
  `approval_status` enum('approved','pending_approval') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending_approval',
  `created_at` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `code` (`code`),
  KEY `audience` (`audience`)
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `membership_plans`
--

LOCK TABLES `membership_plans` WRITE;
/*!40000 ALTER TABLE `membership_plans` DISABLE KEYS */;
INSERT INTO `membership_plans` VALUES (1,'supplier_free','Unverified Supplier','supplier',0.00,'INR',12,0,1,1,'Free registration: private profile, lot-based listings, BearingCave-managed shipping, basic enquiry management.',1,'approved','2026-10-13 11:03:03','2026-10-13 11:03:03'),(2,'supplier_verified','Verified Supplier','supplier',50000.00,'INR',12,1,0,1,'Verified badge, public profile & branding, priority ranking, premium RFQ leads, bidding, per-piece pricing, country visibility, bulk upload, analytics and a dedicated account manager.',2,'pending_approval','2026-10-13 11:03:03','2026-10-13 11:03:03'),(3,'buyer_standard','Buyer','buyer',0.00,'INR',12,0,1,1,'Free registration: browse, search, standard RFQs, quotations, orders, inspection and logistics requests, disputes.',3,'approved','2026-10-13 11:03:03','2026-10-13 11:03:03'),(4,'buyer_verified','Verified Buyer','buyer',0.00,'INR',12,1,0,1,'Verified badge, eligibility for approved restricted inventory, advanced comparison, consolidation services and analytics.',4,'approved','2026-10-13 11:03:03','2026-10-13 11:03:03');
/*!40000 ALTER TABLE `membership_plans` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `migrations`
--

DROP TABLE IF EXISTS `migrations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `migrations` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `version` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `class` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `group` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `namespace` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `time` int NOT NULL,
  `batch` int unsigned NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=18 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `migrations`
--

LOCK TABLES `migrations` WRITE;
/*!40000 ALTER TABLE `migrations` DISABLE KEYS */;
INSERT INTO `migrations` VALUES (1,'2020-12-28-223112','CodeIgniter\\Shield\\Database\\Migrations\\CreateAuthTables','default','CodeIgniter\\Shield',1791869579,1),(2,'2021-07-04-041948','CodeIgniter\\Settings\\Database\\Migrations\\CreateSettingsTable','default','CodeIgniter\\Settings',1791869579,1),(3,'2021-11-14-143905','CodeIgniter\\Settings\\Database\\Migrations\\AddContextColumn','default','CodeIgniter\\Settings',1791869579,1),(4,'2026-07-20-181246','CodeIgniter\\Settings\\Database\\Migrations\\ConvertSqlsrvValueColumn','default','CodeIgniter\\Settings',1791869579,1),(5,'2026-10-01-000001','App\\Database\\Migrations\\CreateReferenceTables','default','App',1791869579,1),(6,'2026-10-01-000002','App\\Database\\Migrations\\CreateCompanyTables','default','App',1791869579,1),(7,'2026-10-01-000003','App\\Database\\Migrations\\CreateVerificationTables','default','App',1791869580,1),(8,'2026-10-01-000004','App\\Database\\Migrations\\CreateMembershipBillingTables','default','App',1791869580,1),(9,'2026-10-01-000005','App\\Database\\Migrations\\CreateCatalogTables','default','App',1791869581,1),(10,'2026-10-01-000006','App\\Database\\Migrations\\CreateProcurementTables','default','App',1791869581,1),(11,'2026-10-01-000007','App\\Database\\Migrations\\CreateServiceTables','default','App',1791869581,1),(12,'2026-10-01-000008','App\\Database\\Migrations\\CreatePlatformTables','default','App',1791869582,1),(13,'2026-10-01-000009','App\\Database\\Migrations\\CreateNumberSequences','default','App',1791869582,1),(14,'2026-10-13-000010','App\\Database\\Migrations\\AddSheetAlignmentColumns','default','App',1791869582,1),(15,'2026-10-13-000011','App\\Database\\Migrations\\AlignDataWithClientWorkbook','default','App',1791869582,1),(16,'2026-10-13-000012','App\\Database\\Migrations\\AddWorkbookScoringSettings','default','App',1791869582,1),(17,'2026-10-13-000013','App\\Database\\Migrations\\AddSiteAuditOutputs','default','App',1791869582,1);
/*!40000 ALTER TABLE `migrations` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `notifications`
--

DROP TABLE IF EXISTS `notifications`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `notifications` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int unsigned NOT NULL,
  `type` varchar(60) COLLATE utf8mb4_unicode_ci NOT NULL,
  `title` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `body` text COLLATE utf8mb4_unicode_ci,
  `link` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `read_at` datetime DEFAULT NULL,
  `created_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `user_id_read_at` (`user_id`,`read_at`),
  CONSTRAINT `notifications_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `notifications`
--

LOCK TABLES `notifications` WRITE;
/*!40000 ALTER TABLE `notifications` DISABLE KEYS */;
/*!40000 ALTER TABLE `notifications` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `number_sequences`
--

DROP TABLE IF EXISTS `number_sequences`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `number_sequences` (
  `seq_key` varchar(40) COLLATE utf8mb4_unicode_ci NOT NULL,
  `seq_year` smallint unsigned NOT NULL,
  `counter` int unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`seq_key`,`seq_year`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `number_sequences`
--

LOCK TABLES `number_sequences` WRITE;
/*!40000 ALTER TABLE `number_sequences` DISABLE KEYS */;
/*!40000 ALTER TABLE `number_sequences` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `order_items`
--

DROP TABLE IF EXISTS `order_items`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `order_items` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `order_id` int unsigned NOT NULL,
  `product_id` int unsigned DEFAULT NULL,
  `quotation_item_id` int unsigned DEFAULT NULL,
  `description` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `part_number` varchar(80) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `quantity` int unsigned NOT NULL DEFAULT '1',
  `unit_price` decimal(15,4) NOT NULL,
  `line_total` decimal(15,2) NOT NULL,
  `stock_reserved` tinyint unsigned NOT NULL DEFAULT '0',
  `created_at` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `order_items_product_id_foreign` (`product_id`),
  KEY `order_items_quotation_item_id_foreign` (`quotation_item_id`),
  KEY `order_id` (`order_id`),
  CONSTRAINT `order_items_order_id_foreign` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `order_items_product_id_foreign` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `order_items_quotation_item_id_foreign` FOREIGN KEY (`quotation_item_id`) REFERENCES `quotation_items` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `order_items`
--

LOCK TABLES `order_items` WRITE;
/*!40000 ALTER TABLE `order_items` DISABLE KEYS */;
/*!40000 ALTER TABLE `order_items` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `order_status_history`
--

DROP TABLE IF EXISTS `order_status_history`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `order_status_history` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `order_id` int unsigned NOT NULL,
  `from_status` varchar(40) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `to_status` varchar(40) COLLATE utf8mb4_unicode_ci NOT NULL,
  `user_id` int unsigned DEFAULT NULL,
  `note` text COLLATE utf8mb4_unicode_ci,
  `created_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `order_status_history_user_id_foreign` (`user_id`),
  KEY `order_id` (`order_id`),
  CONSTRAINT `order_status_history_order_id_foreign` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `order_status_history_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `order_status_history`
--

LOCK TABLES `order_status_history` WRITE;
/*!40000 ALTER TABLE `order_status_history` DISABLE KEYS */;
/*!40000 ALTER TABLE `order_status_history` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `orders`
--

DROP TABLE IF EXISTS `orders`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `orders` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `order_number` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL,
  `buyer_company_id` int unsigned NOT NULL,
  `supplier_company_id` int unsigned NOT NULL,
  `quotation_id` int unsigned DEFAULT NULL,
  `rfq_id` int unsigned DEFAULT NULL,
  `source` enum('quotation','cart') COLLATE utf8mb4_unicode_ci NOT NULL,
  `status` enum('pending_supplier_confirmation','confirmed','awaiting_payment','paid','in_fulfilment','shipped','delivered','completed','cancelled','disputed') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending_supplier_confirmation',
  `payment_status` enum('unpaid','pending','partially_paid','paid','refunded') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'unpaid',
  `shipment_status` enum('not_started','requested','booked','in_transit','delivered') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'not_started',
  `currency` char(3) COLLATE utf8mb4_unicode_ci NOT NULL,
  `subtotal` decimal(15,2) NOT NULL DEFAULT '0.00',
  `inspection_fee` decimal(15,2) NOT NULL DEFAULT '0.00',
  `logistics_fee` decimal(15,2) NOT NULL DEFAULT '0.00',
  `tax_amount` decimal(15,2) NOT NULL DEFAULT '0.00',
  `total` decimal(15,2) NOT NULL DEFAULT '0.00',
  `incoterm` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `payment_terms` varchar(191) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `delivery_country` char(2) COLLATE utf8mb4_unicode_ci NOT NULL,
  `delivery_address` text COLLATE utf8mb4_unicode_ci,
  `logistics_mode` enum('platform_managed','supplier_direct','confidential','buyer_arranged') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'platform_managed',
  `commercial_terms` text COLLATE utf8mb4_unicode_ci,
  `buyer_po_reference` varchar(80) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `buyer_confirmed_at` datetime DEFAULT NULL,
  `supplier_confirmed_at` datetime DEFAULT NULL,
  `cancelled_reason` text COLLATE utf8mb4_unicode_ci,
  `created_by` int unsigned DEFAULT NULL,
  `created_at` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `order_number` (`order_number`),
  KEY `orders_quotation_id_foreign` (`quotation_id`),
  KEY `orders_rfq_id_foreign` (`rfq_id`),
  KEY `orders_created_by_foreign` (`created_by`),
  KEY `status` (`status`),
  KEY `buyer_company_id` (`buyer_company_id`),
  KEY `supplier_company_id` (`supplier_company_id`),
  CONSTRAINT `orders_buyer_company_id_foreign` FOREIGN KEY (`buyer_company_id`) REFERENCES `companies` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `orders_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `orders_quotation_id_foreign` FOREIGN KEY (`quotation_id`) REFERENCES `quotations` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `orders_rfq_id_foreign` FOREIGN KEY (`rfq_id`) REFERENCES `rfqs` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `orders_supplier_company_id_foreign` FOREIGN KEY (`supplier_company_id`) REFERENCES `companies` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `orders`
--

LOCK TABLES `orders` WRITE;
/*!40000 ALTER TABLE `orders` DISABLE KEYS */;
/*!40000 ALTER TABLE `orders` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `part_numbers`
--

DROP TABLE IF EXISTS `part_numbers`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `part_numbers` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `product_id` int unsigned NOT NULL,
  `number_type` enum('manufacturer','oem','alternate','supersession') COLLATE utf8mb4_unicode_ci NOT NULL,
  `part_number` varchar(80) COLLATE utf8mb4_unicode_ci NOT NULL,
  `part_number_norm` varchar(80) COLLATE utf8mb4_unicode_ci NOT NULL,
  `brand_name` varchar(120) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `product_id_number_type_part_number_norm` (`product_id`,`number_type`,`part_number_norm`),
  KEY `part_number_norm` (`part_number_norm`),
  CONSTRAINT `part_numbers_product_id_foreign` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `part_numbers`
--

LOCK TABLES `part_numbers` WRITE;
/*!40000 ALTER TABLE `part_numbers` DISABLE KEYS */;
/*!40000 ALTER TABLE `part_numbers` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `payment_webhook_events`
--

DROP TABLE IF EXISTS `payment_webhook_events`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `payment_webhook_events` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `provider` varchar(40) COLLATE utf8mb4_unicode_ci NOT NULL,
  `event_id` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `event_type` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `payload` json DEFAULT NULL,
  `signature_valid` tinyint unsigned NOT NULL DEFAULT '0',
  `status` enum('received','processed','ignored','failed') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'received',
  `error` text COLLATE utf8mb4_unicode_ci,
  `processed_at` datetime DEFAULT NULL,
  `created_at` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `provider_event_id` (`provider`,`event_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `payment_webhook_events`
--

LOCK TABLES `payment_webhook_events` WRITE;
/*!40000 ALTER TABLE `payment_webhook_events` DISABLE KEYS */;
/*!40000 ALTER TABLE `payment_webhook_events` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `payments`
--

DROP TABLE IF EXISTS `payments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `payments` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `company_id` int unsigned NOT NULL,
  `invoice_id` int unsigned DEFAULT NULL,
  `order_id` int unsigned DEFAULT NULL,
  `provider` varchar(40) COLLATE utf8mb4_unicode_ci NOT NULL,
  `provider_reference` varchar(191) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `idempotency_key` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `method` varchar(40) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `amount` decimal(15,2) NOT NULL,
  `currency` char(3) COLLATE utf8mb4_unicode_ci NOT NULL,
  `status` enum('initiated','pending','succeeded','failed','refunded','cancelled') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'initiated',
  `is_sandbox` tinyint unsigned NOT NULL DEFAULT '0',
  `is_escrow` tinyint unsigned NOT NULL DEFAULT '0',
  `escrow_status` enum('none','held_by_provider','released','refunded') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'none',
  `recorded_by` int unsigned DEFAULT NULL,
  `paid_at` datetime DEFAULT NULL,
  `notes` text COLLATE utf8mb4_unicode_ci,
  `raw_payload` json DEFAULT NULL,
  `created_at` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `idempotency_key` (`idempotency_key`),
  KEY `payments_company_id_foreign` (`company_id`),
  KEY `payments_recorded_by_foreign` (`recorded_by`),
  KEY `status` (`status`),
  KEY `order_id` (`order_id`),
  KEY `invoice_id` (`invoice_id`),
  CONSTRAINT `payments_company_id_foreign` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `payments_invoice_id_foreign` FOREIGN KEY (`invoice_id`) REFERENCES `invoices` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `payments_recorded_by_foreign` FOREIGN KEY (`recorded_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `payments`
--

LOCK TABLES `payments` WRITE;
/*!40000 ALTER TABLE `payments` DISABLE KEYS */;
/*!40000 ALTER TABLE `payments` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `plan_entitlements`
--

DROP TABLE IF EXISTS `plan_entitlements`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `plan_entitlements` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `plan_id` int unsigned NOT NULL,
  `feature_key` varchar(80) COLLATE utf8mb4_unicode_ci NOT NULL,
  `is_enabled` tinyint unsigned NOT NULL DEFAULT '0',
  `limit_value` int unsigned DEFAULT NULL,
  `created_at` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `plan_id_feature_key` (`plan_id`,`feature_key`),
  CONSTRAINT `plan_entitlements_plan_id_foreign` FOREIGN KEY (`plan_id`) REFERENCES `membership_plans` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=47 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `plan_entitlements`
--

LOCK TABLES `plan_entitlements` WRITE;
/*!40000 ALTER TABLE `plan_entitlements` DISABLE KEYS */;
INSERT INTO `plan_entitlements` VALUES (1,1,'verified_badge',0,NULL,'2026-10-13 11:03:03','2026-10-13 11:03:03'),(2,1,'public_profile',0,NULL,'2026-10-13 11:03:03','2026-10-13 11:03:03'),(3,1,'public_contact',0,NULL,'2026-10-13 11:03:03','2026-10-13 11:03:03'),(4,1,'priority_ranking',0,NULL,'2026-10-13 11:03:03','2026-10-13 11:03:03'),(5,1,'direct_enquiries',0,NULL,'2026-10-13 11:03:03','2026-10-13 11:03:03'),(6,1,'premium_rfq_leads',0,NULL,'2026-10-13 11:03:03','2026-10-13 11:03:03'),(7,1,'supplier_bidding',0,NULL,'2026-10-13 11:03:03','2026-10-13 11:03:03'),(8,1,'per_piece_pricing',0,NULL,'2026-10-13 11:03:03','2026-10-13 11:03:03'),(9,1,'country_visibility_control',0,NULL,'2026-10-13 11:03:03','2026-10-13 11:03:03'),(10,1,'bulk_import',0,NULL,'2026-10-13 11:03:03','2026-10-13 11:03:03'),(11,1,'advanced_inventory',0,NULL,'2026-10-13 11:03:03','2026-10-13 11:03:03'),(12,1,'advanced_analytics',0,NULL,'2026-10-13 11:03:03','2026-10-13 11:03:03'),(13,1,'demand_insights',0,NULL,'2026-10-13 11:03:03','2026-10-13 11:03:03'),(14,1,'dedicated_account_manager',0,NULL,'2026-10-13 11:03:03','2026-10-13 11:03:03'),(15,1,'direct_logistics',0,NULL,'2026-10-13 11:03:03','2026-10-13 11:03:03'),(16,1,'max_active_products',1,NULL,'2026-10-13 11:03:03','2026-10-13 11:03:03'),(17,2,'verified_badge',1,NULL,'2026-10-13 11:03:03','2026-10-13 11:03:03'),(18,2,'public_profile',1,NULL,'2026-10-13 11:03:03','2026-10-13 11:03:03'),(19,2,'public_contact',1,NULL,'2026-10-13 11:03:03','2026-10-13 11:03:03'),(20,2,'priority_ranking',1,50,'2026-10-13 11:03:03','2026-10-13 11:03:03'),(21,2,'direct_enquiries',1,NULL,'2026-10-13 11:03:03','2026-10-13 11:03:03'),(22,2,'premium_rfq_leads',1,NULL,'2026-10-13 11:03:03','2026-10-13 11:03:03'),(23,2,'supplier_bidding',1,NULL,'2026-10-13 11:03:03','2026-10-13 11:03:03'),(24,2,'per_piece_pricing',1,NULL,'2026-10-13 11:03:03','2026-10-13 11:03:03'),(25,2,'country_visibility_control',1,NULL,'2026-10-13 11:03:03','2026-10-13 11:03:03'),(26,2,'bulk_import',1,NULL,'2026-10-13 11:03:03','2026-10-13 11:03:03'),(27,2,'advanced_inventory',1,NULL,'2026-10-13 11:03:03','2026-10-13 11:03:03'),(28,2,'advanced_analytics',1,NULL,'2026-10-13 11:03:03','2026-10-13 11:03:03'),(29,2,'demand_insights',1,NULL,'2026-10-13 11:03:03','2026-10-13 11:03:03'),(30,2,'dedicated_account_manager',1,NULL,'2026-10-13 11:03:03','2026-10-13 11:03:03'),(31,2,'direct_logistics',1,NULL,'2026-10-13 11:03:03','2026-10-13 11:03:03'),(32,2,'max_active_products',1,NULL,'2026-10-13 11:03:03','2026-10-13 11:03:03'),(33,3,'buyer_badge',0,NULL,'2026-10-13 11:03:03','2026-10-13 11:03:03'),(34,3,'rfq_submission',1,NULL,'2026-10-13 11:03:03','2026-10-13 11:03:03'),(35,3,'max_open_rfqs',1,5,'2026-10-13 11:03:03','2026-10-13 11:03:03'),(36,3,'restricted_inventory_access',0,NULL,'2026-10-13 11:03:03','2026-10-13 11:03:03'),(37,3,'advanced_comparison',0,NULL,'2026-10-13 11:03:03','2026-10-13 11:03:03'),(38,3,'consolidation_services',0,NULL,'2026-10-13 11:03:03','2026-10-13 11:03:03'),(39,3,'buyer_analytics',0,NULL,'2026-10-13 11:03:03','2026-10-13 11:03:03'),(40,4,'buyer_badge',1,NULL,'2026-10-13 11:03:03','2026-10-13 11:03:03'),(41,4,'rfq_submission',1,NULL,'2026-10-13 11:03:03','2026-10-13 11:03:03'),(42,4,'max_open_rfqs',1,NULL,'2026-10-13 11:03:03','2026-10-13 11:03:03'),(43,4,'restricted_inventory_access',1,NULL,'2026-10-13 11:03:03','2026-10-13 11:03:03'),(44,4,'advanced_comparison',1,NULL,'2026-10-13 11:03:03','2026-10-13 11:03:03'),(45,4,'consolidation_services',1,NULL,'2026-10-13 11:03:03','2026-10-13 11:03:03'),(46,4,'buyer_analytics',1,NULL,'2026-10-13 11:03:03','2026-10-13 11:03:03');
/*!40000 ALTER TABLE `plan_entitlements` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `platform_settings`
--

DROP TABLE IF EXISTS `platform_settings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `platform_settings` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `setting_key` varchar(120) COLLATE utf8mb4_unicode_ci NOT NULL,
  `setting_value` text COLLATE utf8mb4_unicode_ci,
  `value_type` enum('string','int','decimal','bool','json','text') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'string',
  `setting_group` varchar(60) COLLATE utf8mb4_unicode_ci NOT NULL,
  `label` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` text COLLATE utf8mb4_unicode_ci,
  `approval_status` enum('approved','pending_approval') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending_approval',
  `is_sensitive` tinyint unsigned NOT NULL DEFAULT '0',
  `updated_by` int unsigned DEFAULT NULL,
  `created_at` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `setting_key` (`setting_key`),
  KEY `setting_group` (`setting_group`)
) ENGINE=InnoDB AUTO_INCREMENT=64 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `platform_settings`
--

LOCK TABLES `platform_settings` WRITE;
/*!40000 ALTER TABLE `platform_settings` DISABLE KEYS */;
INSERT INTO `platform_settings` VALUES (1,'platform.name','BearingCave','string','platform','Platform name','Shown in titles and emails.','approved',0,NULL,'2026-10-13 11:03:02','2026-10-13 11:03:02'),(2,'platform.support_email','support@bearingcave.com','string','platform','Support email','Public support address (confirm mailbox exists).','pending_approval',0,NULL,'2026-10-13 11:03:02','2026-10-13 11:03:02'),(3,'platform.support_phone','','string','platform','Support phone','Public support phone number.','pending_approval',0,NULL,'2026-10-13 11:03:02','2026-10-13 11:03:02'),(4,'platform.default_currency','INR','string','platform','Default currency','Base currency for fees and reports.','approved',0,NULL,'2026-10-13 11:03:02','2026-10-13 11:03:02'),(5,'platform.blocked_countries','[]','json','platform','Blocked registration countries','ISO-2 codes that cannot register (sanctions / legal). JSON list.','pending_approval',0,NULL,'2026-10-13 11:03:02','2026-10-13 11:03:02'),(6,'platform.settlement_currency_rules','Orders are invoiced in the currency of the accepted quotation or listing. No automatic FX conversion.','text','platform','Commercial settlement currency rules','Displayed on order terms.','pending_approval',0,NULL,'2026-10-13 11:03:02','2026-10-13 11:03:02'),(7,'membership.verified_plan_code','supplier_verified','string','membership','Verified supplier plan code','Plan offered after verification approval.','approved',0,NULL,'2026-10-13 11:03:02','2026-10-13 11:03:02'),(8,'membership.reminder_days','[30,7]','json','membership','Renewal reminder days','Days before expiry to remind suppliers. JSON list.','approved',0,NULL,'2026-10-13 11:03:02','2026-10-13 11:03:02'),(9,'buyer.verified_plan_code','buyer_verified','string','membership','Verified buyer plan code','Entitlement plan applied to verified buyers.','approved',0,NULL,'2026-10-13 11:03:02','2026-10-13 11:03:02'),(10,'tax.subscription_rate_pct','18','decimal','tax','Tax % on membership fee','GST/VAT treatment of the ₹50,000 annual verification fee — confirm with tax advisor.','pending_approval',0,NULL,'2026-10-13 11:03:02','2026-10-13 11:03:02'),(11,'tax.inspection_rate_pct','18','decimal','tax','Tax % on inspection fees','Confirm with tax advisor.','pending_approval',0,NULL,'2026-10-13 11:03:02','2026-10-13 11:03:02'),(12,'tax.logistics_rate_pct','18','decimal','tax','Tax % on logistics fees','Confirm with tax advisor.','pending_approval',0,NULL,'2026-10-13 11:03:02','2026-10-13 11:03:02'),(13,'tax.proforma_rate_pct','0','decimal','tax','Tax % on proforma (goods) invoices','Goods tax depends on supplier, HSN code and destination; 0 until rules are approved.','pending_approval',0,NULL,'2026-10-13 11:03:02','2026-10-13 11:03:02'),(14,'billing.invoice_due_days','15','int','billing','Invoice due days','Payment terms for platform invoices.','pending_approval',0,NULL,'2026-10-13 11:03:02','2026-10-13 11:03:02'),(15,'billing.refund_policy','Refunds and cancellations are handled case-by-case by BearingCave finance until a policy is approved.','text','billing','Cancellation & refund rules','Shown on invoices and checkout.','pending_approval',0,NULL,'2026-10-13 11:03:02','2026-10-13 11:03:02'),(16,'verification.supplier_stages','[\"registration\",\"documents\",\"kyc\",\"compliance\",\"financial\",\"risk\",\"quality\",\"site_audit\",\"sanctions\",\"management_approval\"]','json','verification','Supplier verification stages','Stages required before management approval.','pending_approval',0,NULL,'2026-10-13 11:03:02','2026-10-13 11:03:02'),(17,'verification.buyer_stages','[\"registration\",\"documents\",\"kyc\",\"sanctions\",\"management_approval\"]','json','verification','Buyer verification stages','Stages required for verified buyer status.','pending_approval',0,NULL,'2026-10-13 11:03:02','2026-10-13 11:03:02'),(18,'verification.document_reminder_days','[30,7]','json','verification','Document expiry reminder days','JSON list of days before expiry.','approved',0,NULL,'2026-10-13 11:03:02','2026-10-13 11:03:02'),(19,'verification.suspend_on_expired_docs','0','bool','verification','Auto-suspend on expired mandatory documents','Supplier suspension policy (pending).','pending_approval',0,NULL,'2026-10-13 11:03:02','2026-10-13 11:03:02'),(20,'risk.weights','{\"financial_risk\":1,\"operational_risk\":1,\"compliance_risk\":1,\"geographic_risk\":1,\"supply_risk\":1,\"esg_risk\":1}','json','risk','Risk factor weights','Relative weight of each 1–5 factor score.','pending_approval',0,NULL,'2026-10-13 11:03:02','2026-10-13 11:03:02'),(21,'risk.thresholds','{\"low_max\":2.0,\"medium_max\":3.5}','json','risk','Risk level thresholds','Weighted score ≤ low_max = LOW, ≤ medium_max = MEDIUM, else HIGH.','pending_approval',0,NULL,'2026-10-13 11:03:02','2026-10-13 11:03:02'),(22,'sanctions.provider','none','string','risk','Sanctions screening provider','none = manual screening recorded by officers. Integrate an authorised provider before automated checks.','pending_approval',0,NULL,'2026-10-13 11:03:02','2026-10-13 11:03:02'),(23,'catalog.review_policy','all','string','catalog','Listing review policy','all | unverified | none — which supplier listings need admin approval.','pending_approval',0,NULL,'2026-10-13 11:03:02','2026-10-13 11:03:02'),(24,'catalog.guest_can_see_prices','0','bool','catalog','Guests can see prices','If off, visitors must sign in to see prices.','pending_approval',0,NULL,'2026-10-13 11:03:02','2026-10-13 11:03:02'),(25,'catalog.max_compare','4','int','catalog','Max products in comparison','','approved',0,NULL,'2026-10-13 11:03:02','2026-10-13 11:03:02'),(26,'buyer.restricted_access_policy','Verified buyers are granted restricted inventory access individually by a Verification Officer.','text','catalog','Restricted inventory access policy','Shown to buyers.','pending_approval',0,NULL,'2026-10-13 11:03:02','2026-10-13 11:03:02'),(27,'supplier.contact_disclosure_policy','Supplier contact details are shown only for verified suppliers who opt in, to signed-in buyers, and never on confidential listings.','text','catalog','Supplier contact disclosure conditions','','pending_approval',0,NULL,'2026-10-13 11:03:02','2026-10-13 11:03:02'),(28,'inspection.in_house_rate_pct','10','decimal','services','In-house inspection % of invoice value','From the requirements spreadsheet (10%).','approved',0,NULL,'2026-10-13 11:03:02','2026-10-13 11:03:02'),(29,'inspection.in_house_min_fee','0','decimal','services','In-house inspection minimum fee','0 = no minimum. Pending client decision.','pending_approval',0,NULL,'2026-10-13 11:03:02','2026-10-13 11:03:02'),(30,'logistics.platform_fee_pct','10','decimal','services','BearingCave-managed logistics charge %','From the spreadsheet (optional additional 10%).','approved',0,NULL,'2026-10-13 11:03:02','2026-10-13 11:03:02'),(31,'logistics.platform_fee_basis','manual_quote','string','services','Logistics charge calculation base','manual_quote | goods_value | freight_cost. Base not defined in spreadsheet — pending.','pending_approval',0,NULL,'2026-10-13 11:03:02','2026-10-13 11:03:02'),(32,'logistics.consolidation_policy','Consolidation is available to verified buyers on request; pricing quoted per shipment.','text','services','Shipping/consolidation privileges for verified buyers','','pending_approval',0,NULL,'2026-10-13 11:03:02','2026-10-13 11:03:02'),(33,'rfq.auto_match_on_submit','1','bool','rfq','Auto-run supplier matching on submission','Matching only suggests suppliers; distribution stays admin-controlled.','approved',0,NULL,'2026-10-13 11:03:02','2026-10-13 11:03:02'),(34,'rfq.auto_distribute','0','bool','rfq','Auto-distribute to top matches','Off = BearingCave admin reviews and distributes each RFQ.','pending_approval',0,NULL,'2026-10-13 11:03:02','2026-10-13 11:03:02'),(35,'rfq.min_match_score','20','decimal','rfq','Minimum match score','Suppliers below this score are not suggested.','pending_approval',0,NULL,'2026-10-13 11:03:02','2026-10-13 11:03:02'),(36,'rfq.max_auto_invites','10','int','rfq','Max suppliers per auto distribution','','pending_approval',0,NULL,'2026-10-13 11:03:02','2026-10-13 11:03:02'),(37,'rfq.matching_weights','{\"part_number\":50,\"oem_number\":40,\"cross_reference\":25,\"brand\":15,\"category\":10,\"stock_sufficient\":10,\"avl\":10,\"performance_max\":10}','json','rfq','Matching weights','Points per matching signal.','pending_approval',0,NULL,'2026-10-13 11:03:02','2026-10-13 11:03:02'),(38,'rfq.max_revisions','5','int','rfq','Max quotation revisions','','pending_approval',0,NULL,'2026-10-13 11:03:02','2026-10-13 11:03:02'),(39,'rfq.default_deadline_days','7','int','rfq','Default RFQ deadline (days)','','approved',0,NULL,'2026-10-13 11:03:02','2026-10-13 11:03:02'),(40,'rfq.distribution_criteria','RFQs go to verified suppliers with active membership whose listed stock, brands, categories or AVL approvals match.','text','rfq','RFQ distribution criteria','','pending_approval',0,NULL,'2026-10-13 11:03:02','2026-10-13 11:03:02'),(41,'ranking.performance_weight','1','decimal','ranking','Performance weight in ranking','Ranking = performance × weight + plan boost.','pending_approval',0,NULL,'2026-10-13 11:03:02','2026-10-13 11:03:02'),(42,'performance.window_days','365','int','ranking','Performance window (days)','','approved',0,NULL,'2026-10-13 11:03:02','2026-10-13 11:03:02'),(43,'buyer.score_weights','{\"engagement\":40,\"transaction\":40,\"services_bonus\":10,\"genuine_rfq_bonus\":10}','json','ranking','Client score weights','Workbook: client scoring = engagement history + transaction behaviour, with bonuses for using BearingCave logistics/inspection and for genuine purchase requirements. Weights (sum 100) are not specified by the client.','pending_approval',0,NULL,'2026-10-13 11:03:02','2026-10-13 11:03:02'),(44,'buyer.services_bonus_per_use','25','int','ranking','Client bonus per BearingCave service used','Points (max 100) per logistics or inspection request in the scoring window.','pending_approval',0,NULL,'2026-10-13 11:03:02','2026-10-13 11:03:02'),(45,'payments.bank_transfer_enabled','1','bool','payments','Bank transfer enabled','Finance confirms receipt manually.','approved',0,NULL,'2026-10-13 11:03:02','2026-10-13 11:03:02'),(46,'payments.bank_instructions','','text','payments','Bank remittance instructions','Account details shown to payers. Leave empty until finance confirms.','pending_approval',0,NULL,'2026-10-13 11:03:02','2026-10-13 11:03:02'),(47,'payments.sandbox_enabled','1','bool','payments','Sandbox gateway enabled (non-production only)','Test payments that move no money.','approved',0,NULL,'2026-10-13 11:03:02','2026-10-13 11:03:02'),(48,'payments.escrow_provider','none','string','payments','Escrow / payment partner','No escrow is simulated. Integrate a licensed provider once selected.','pending_approval',0,NULL,'2026-10-13 11:03:02','2026-10-13 11:03:02'),(49,'email.delivery_enabled','0','bool','notifications','Email delivery enabled','Enable after SMTP is configured in .env. Until then emails stay in the outbox as \"disabled\".','pending_approval',0,NULL,'2026-10-13 11:03:02','2026-10-13 11:03:02'),(50,'email.send_immediately','1','bool','notifications','Send emails immediately','Otherwise run: php spark bearingcave:outbox','approved',0,NULL,'2026-10-13 11:03:02','2026-10-13 11:03:02'),(51,'notifications.email_disabled_types','[]','json','notifications','Notification types without email','JSON list of notification types.','approved',0,NULL,'2026-10-13 11:03:02','2026-10-13 11:03:02'),(52,'notifications.sms_provider','none','string','notifications','SMS provider','Integration point only; no SMS is sent.','pending_approval',0,NULL,'2026-10-13 11:03:02','2026-10-13 11:03:02'),(53,'notifications.whatsapp_provider','none','string','notifications','WhatsApp provider','Integration point only; no WhatsApp messages are sent.','pending_approval',0,NULL,'2026-10-13 11:03:02','2026-10-13 11:03:02'),(54,'documents.max_upload_mb','10','int','platform','Max upload size (MB)','','approved',0,NULL,'2026-10-13 11:03:02','2026-10-13 11:03:02'),(55,'imports.max_rows','5000','int','platform','Max rows per import','','approved',0,NULL,'2026-10-13 11:03:02','2026-10-13 11:03:02'),(56,'cart.max_items','50','int','platform','Max basket lines','','approved',0,NULL,'2026-10-13 11:03:02','2026-10-13 11:03:02'),(57,'seo.default_title','BearingCave — Global B2B Automotive Surplus Marketplace','string','seo','Default page title','','pending_approval',0,NULL,'2026-10-13 11:03:02','2026-10-13 11:03:02'),(58,'seo.default_description','Buy and sell genuine surplus, obsolete and slow-moving automotive inventory with verified manufacturers and suppliers worldwide.','string','seo','Default meta description','','pending_approval',0,NULL,'2026-10-13 11:03:02','2026-10-13 11:03:02'),(59,'seo.indexing_enabled','0','bool','seo','Allow search engine indexing','Keep off on staging. When off, robots.txt disallows everything and pages send noindex.','pending_approval',0,NULL,'2026-10-13 11:03:02','2026-10-13 11:03:02'),(60,'seo.google_site_verification','','string','seo','Google Search Console verification token','','approved',0,NULL,'2026-10-13 11:03:02','2026-10-13 11:03:02'),(61,'branding.primary_color','#0B1F3A','string','branding','Primary colour (provisional)','Deep navy until official brand assets are approved.','pending_approval',0,NULL,'2026-10-13 11:03:02','2026-10-13 11:03:02'),(62,'branding.accent_color','#1F5FBF','string','branding','Accent colour (provisional)','Premium blue.','pending_approval',0,NULL,'2026-10-13 11:03:02','2026-10-13 11:03:02'),(63,'legal.warranty_terms','Product quality and warranty obligations remain with the supplier and buyer under their agreed terms.','text','legal','Warranty & returns terms','','pending_approval',0,NULL,'2026-10-13 11:03:02','2026-10-13 11:03:02');
/*!40000 ALTER TABLE `platform_settings` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `pricing_tiers`
--

DROP TABLE IF EXISTS `pricing_tiers`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `pricing_tiers` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `product_id` int unsigned NOT NULL,
  `min_qty` int unsigned NOT NULL DEFAULT '1',
  `max_qty` int unsigned DEFAULT NULL,
  `unit_price` decimal(15,4) NOT NULL,
  `created_at` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `product_id_min_qty` (`product_id`,`min_qty`),
  CONSTRAINT `pricing_tiers_product_id_foreign` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `pricing_tiers`
--

LOCK TABLES `pricing_tiers` WRITE;
/*!40000 ALTER TABLE `pricing_tiers` DISABLE KEYS */;
/*!40000 ALTER TABLE `pricing_tiers` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `product_country_rules`
--

DROP TABLE IF EXISTS `product_country_rules`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `product_country_rules` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `product_id` int unsigned NOT NULL,
  `country_code` char(2) COLLATE utf8mb4_unicode_ci NOT NULL,
  `rule` enum('allow','deny') COLLATE utf8mb4_unicode_ci NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `product_id_country_code` (`product_id`,`country_code`),
  KEY `country_code` (`country_code`),
  CONSTRAINT `product_country_rules_country_code_foreign` FOREIGN KEY (`country_code`) REFERENCES `countries` (`iso2`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `product_country_rules_product_id_foreign` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `product_country_rules`
--

LOCK TABLES `product_country_rules` WRITE;
/*!40000 ALTER TABLE `product_country_rules` DISABLE KEYS */;
/*!40000 ALTER TABLE `product_country_rules` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `product_enquiries`
--

DROP TABLE IF EXISTS `product_enquiries`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `product_enquiries` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `product_id` int unsigned NOT NULL,
  `buyer_company_id` int unsigned NOT NULL,
  `user_id` int unsigned DEFAULT NULL,
  `quantity` int unsigned DEFAULT NULL,
  `message` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `status` enum('open','responded','closed','converted') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'open',
  `response` text COLLATE utf8mb4_unicode_ci,
  `responded_by` int unsigned DEFAULT NULL,
  `responded_at` datetime DEFAULT NULL,
  `created_at` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  `routing` enum('direct','platform') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'direct',
  `forwarded_at` datetime DEFAULT NULL,
  `forwarded_by` int unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `product_enquiries_product_id_foreign` (`product_id`),
  KEY `product_enquiries_buyer_company_id_foreign` (`buyer_company_id`),
  KEY `product_enquiries_user_id_foreign` (`user_id`),
  KEY `product_enquiries_responded_by_foreign` (`responded_by`),
  KEY `status` (`status`),
  KEY `idx_enquiries_routing` (`routing`,`status`),
  CONSTRAINT `product_enquiries_buyer_company_id_foreign` FOREIGN KEY (`buyer_company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `product_enquiries_product_id_foreign` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `product_enquiries_responded_by_foreign` FOREIGN KEY (`responded_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `product_enquiries_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `product_enquiries`
--

LOCK TABLES `product_enquiries` WRITE;
/*!40000 ALTER TABLE `product_enquiries` DISABLE KEYS */;
/*!40000 ALTER TABLE `product_enquiries` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `product_images`
--

DROP TABLE IF EXISTS `product_images`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `product_images` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `product_id` int unsigned NOT NULL,
  `path` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `alt_text` varchar(191) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `sort_order` int unsigned NOT NULL DEFAULT '0',
  `is_primary` tinyint unsigned NOT NULL DEFAULT '0',
  `created_at` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `product_id` (`product_id`),
  CONSTRAINT `product_images_product_id_foreign` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `product_images`
--

LOCK TABLES `product_images` WRITE;
/*!40000 ALTER TABLE `product_images` DISABLE KEYS */;
/*!40000 ALTER TABLE `product_images` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `product_specifications`
--

DROP TABLE IF EXISTS `product_specifications`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `product_specifications` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `product_id` int unsigned NOT NULL,
  `spec_name` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `spec_value` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `unit` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `sort_order` int unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  KEY `product_id` (`product_id`),
  CONSTRAINT `product_specifications_product_id_foreign` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `product_specifications`
--

LOCK TABLES `product_specifications` WRITE;
/*!40000 ALTER TABLE `product_specifications` DISABLE KEYS */;
/*!40000 ALTER TABLE `product_specifications` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `products`
--

DROP TABLE IF EXISTS `products`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `products` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `uuid` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `company_id` int unsigned NOT NULL,
  `category_id` int unsigned NOT NULL,
  `brand_id` int unsigned DEFAULT NULL,
  `sku` varchar(80) COLLATE utf8mb4_unicode_ci NOT NULL,
  `name` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `slug` varchar(220) COLLATE utf8mb4_unicode_ci NOT NULL,
  `part_number` varchar(80) COLLATE utf8mb4_unicode_ci NOT NULL,
  `part_number_norm` varchar(80) COLLATE utf8mb4_unicode_ci NOT NULL,
  `oem_part_number` varchar(80) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `oem_part_number_norm` varchar(80) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `description` text COLLATE utf8mb4_unicode_ci,
  `search_keywords` text COLLATE utf8mb4_unicode_ci,
  `item_condition` enum('new','new_old_stock','new_open_box','refurbished','used') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'new',
  `unit_of_measure` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pcs',
  `length_mm` decimal(10,2) DEFAULT NULL,
  `width_mm` decimal(10,2) DEFAULT NULL,
  `height_mm` decimal(10,2) DEFAULT NULL,
  `inner_diameter_mm` decimal(10,3) DEFAULT NULL,
  `outer_diameter_mm` decimal(10,3) DEFAULT NULL,
  `weight_kg` decimal(10,3) DEFAULT NULL,
  `currency` char(3) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'INR',
  `sale_mode` enum('lot','piece','both') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'lot',
  `unit_price` decimal(15,4) DEFAULT NULL,
  `lot_price` decimal(15,2) DEFAULT NULL,
  `lot_quantity` int unsigned DEFAULT NULL,
  `moq` int unsigned NOT NULL DEFAULT '1',
  `price_visibility` enum('show','on_request') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'show',
  `stock_on_hand` int unsigned NOT NULL DEFAULT '0',
  `stock_reserved` int unsigned NOT NULL DEFAULT '0',
  `inventory_age_date` date DEFAULT NULL,
  `warehouse_city` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `warehouse_country` char(2) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `visibility` enum('public','verified_buyers','hidden') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'public',
  `country_mode` enum('all','allow_list','deny_list') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'all',
  `hide_supplier_identity` tinyint unsigned NOT NULL DEFAULT '1',
  `shipping_options` varchar(120) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'platform_managed',
  `inspection_status` enum('none','requested','passed','failed') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'none',
  `status` enum('draft','pending_review','published','rejected','unpublished','archived') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'draft',
  `review_notes` text COLLATE utf8mb4_unicode_ci,
  `approved_by` int unsigned DEFAULT NULL,
  `approved_at` datetime DEFAULT NULL,
  `published_at` datetime DEFAULT NULL,
  `view_count` int unsigned NOT NULL DEFAULT '0',
  `is_sample` tinyint unsigned NOT NULL DEFAULT '0',
  `created_at` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  `deleted_at` datetime DEFAULT NULL,
  `clearance_pct` decimal(5,2) DEFAULT NULL,
  `clearance_started_at` datetime DEFAULT NULL,
  `pre_clearance_unit_price` decimal(15,4) DEFAULT NULL,
  `pre_clearance_lot_price` decimal(15,2) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uuid` (`uuid`),
  UNIQUE KEY `slug` (`slug`),
  UNIQUE KEY `company_id_sku` (`company_id`,`sku`),
  KEY `products_approved_by_foreign` (`approved_by`),
  KEY `part_number_norm` (`part_number_norm`),
  KEY `oem_part_number_norm` (`oem_part_number_norm`),
  KEY `status_visibility` (`status`,`visibility`),
  KEY `category_id` (`category_id`),
  KEY `brand_id` (`brand_id`),
  KEY `company_id` (`company_id`),
  KEY `inventory_age_date` (`inventory_age_date`),
  KEY `warehouse_country` (`warehouse_country`),
  KEY `idx_products_clearance` (`clearance_started_at`),
  FULLTEXT KEY `ft_products_search` (`name`,`part_number`,`search_keywords`),
  CONSTRAINT `products_approved_by_foreign` FOREIGN KEY (`approved_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `products_brand_id_foreign` FOREIGN KEY (`brand_id`) REFERENCES `brands` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `products_category_id_foreign` FOREIGN KEY (`category_id`) REFERENCES `categories` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `products_company_id_foreign` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `chk_products_stock` CHECK (((`stock_on_hand` >= 0) and (`stock_reserved` >= 0) and (`stock_reserved` <= `stock_on_hand`)))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `products`
--

LOCK TABLES `products` WRITE;
/*!40000 ALTER TABLE `products` DISABLE KEYS */;
/*!40000 ALTER TABLE `products` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `quality_assessments`
--

DROP TABLE IF EXISTS `quality_assessments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `quality_assessments` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `company_id` int unsigned NOT NULL,
  `has_inspection_system` tinyint unsigned NOT NULL DEFAULT '0',
  `ppap` enum('yes','no','partial','na') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'na',
  `apqp` enum('yes','no','partial','na') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'na',
  `fmea` enum('yes','no','partial','na') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'na',
  `capa` enum('yes','no','partial','na') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'na',
  `qc_process_notes` text COLLATE utf8mb4_unicode_ci,
  `rejection_rate_pct` decimal(5,2) DEFAULT NULL,
  `quality_certificates` text COLLATE utf8mb4_unicode_ci,
  `score` decimal(5,2) DEFAULT NULL,
  `status` enum('pending','passed','failed') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `reviewer_notes` text COLLATE utf8mb4_unicode_ci,
  `reviewed_by` int unsigned DEFAULT NULL,
  `reviewed_at` datetime DEFAULT NULL,
  `created_at` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `quality_assessments_reviewed_by_foreign` (`reviewed_by`),
  KEY `company_id` (`company_id`),
  CONSTRAINT `quality_assessments_company_id_foreign` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `quality_assessments_reviewed_by_foreign` FOREIGN KEY (`reviewed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `quality_assessments`
--

LOCK TABLES `quality_assessments` WRITE;
/*!40000 ALTER TABLE `quality_assessments` DISABLE KEYS */;
/*!40000 ALTER TABLE `quality_assessments` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `quotation_items`
--

DROP TABLE IF EXISTS `quotation_items`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `quotation_items` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `quotation_id` int unsigned NOT NULL,
  `rfq_item_id` int unsigned DEFAULT NULL,
  `product_id` int unsigned DEFAULT NULL,
  `description` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `part_number` varchar(80) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `quantity` int unsigned NOT NULL DEFAULT '1',
  `unit_price` decimal(15,4) NOT NULL,
  `line_total` decimal(15,2) NOT NULL,
  `lead_time_days` int unsigned DEFAULT NULL,
  `notes` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `quotation_items_rfq_item_id_foreign` (`rfq_item_id`),
  KEY `quotation_items_product_id_foreign` (`product_id`),
  KEY `quotation_id` (`quotation_id`),
  CONSTRAINT `quotation_items_product_id_foreign` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `quotation_items_quotation_id_foreign` FOREIGN KEY (`quotation_id`) REFERENCES `quotations` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `quotation_items_rfq_item_id_foreign` FOREIGN KEY (`rfq_item_id`) REFERENCES `rfq_items` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `quotation_items`
--

LOCK TABLES `quotation_items` WRITE;
/*!40000 ALTER TABLE `quotation_items` DISABLE KEYS */;
/*!40000 ALTER TABLE `quotation_items` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `quotation_messages`
--

DROP TABLE IF EXISTS `quotation_messages`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `quotation_messages` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `quotation_id` int unsigned NOT NULL,
  `sender_side` enum('buyer','supplier','admin') COLLATE utf8mb4_unicode_ci NOT NULL,
  `user_id` int unsigned DEFAULT NULL,
  `message` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `proposed_total` decimal(15,2) DEFAULT NULL,
  `created_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `quotation_messages_user_id_foreign` (`user_id`),
  KEY `quotation_id` (`quotation_id`),
  CONSTRAINT `quotation_messages_quotation_id_foreign` FOREIGN KEY (`quotation_id`) REFERENCES `quotations` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `quotation_messages_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `quotation_messages`
--

LOCK TABLES `quotation_messages` WRITE;
/*!40000 ALTER TABLE `quotation_messages` DISABLE KEYS */;
/*!40000 ALTER TABLE `quotation_messages` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `quotations`
--

DROP TABLE IF EXISTS `quotations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `quotations` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `quotation_number` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL,
  `rfq_id` int unsigned NOT NULL,
  `supplier_company_id` int unsigned NOT NULL,
  `rfq_match_id` int unsigned DEFAULT NULL,
  `revision` smallint unsigned NOT NULL DEFAULT '1',
  `previous_id` int unsigned DEFAULT NULL,
  `is_current` tinyint unsigned NOT NULL DEFAULT '1',
  `status` enum('draft','submitted','superseded','withdrawn','shortlisted','accepted','rejected','expired') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'submitted',
  `currency` char(3) COLLATE utf8mb4_unicode_ci NOT NULL,
  `total_amount` decimal(15,2) NOT NULL DEFAULT '0.00',
  `valid_until` date DEFAULT NULL,
  `delivery_terms` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `lead_time_days` int unsigned DEFAULT NULL,
  `payment_terms` varchar(191) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `inspection_offered` tinyint unsigned NOT NULL DEFAULT '0',
  `logistics_option` enum('platform_managed','supplier_direct','confidential','buyer_arranged') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'platform_managed',
  `notes` text COLLATE utf8mb4_unicode_ci,
  `submitted_by` int unsigned DEFAULT NULL,
  `submitted_at` datetime DEFAULT NULL,
  `buyer_decision_at` datetime DEFAULT NULL,
  `buyer_decision_note` text COLLATE utf8mb4_unicode_ci,
  `created_at` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `quotation_number` (`quotation_number`),
  KEY `quotations_rfq_match_id_foreign` (`rfq_match_id`),
  KEY `quotations_previous_id_foreign` (`previous_id`),
  KEY `quotations_submitted_by_foreign` (`submitted_by`),
  KEY `rfq_id_is_current` (`rfq_id`,`is_current`),
  KEY `supplier_company_id` (`supplier_company_id`),
  KEY `status` (`status`),
  CONSTRAINT `quotations_previous_id_foreign` FOREIGN KEY (`previous_id`) REFERENCES `quotations` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `quotations_rfq_id_foreign` FOREIGN KEY (`rfq_id`) REFERENCES `rfqs` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `quotations_rfq_match_id_foreign` FOREIGN KEY (`rfq_match_id`) REFERENCES `rfq_matches` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `quotations_submitted_by_foreign` FOREIGN KEY (`submitted_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `quotations_supplier_company_id_foreign` FOREIGN KEY (`supplier_company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `quotations`
--

LOCK TABLES `quotations` WRITE;
/*!40000 ALTER TABLE `quotations` DISABLE KEYS */;
/*!40000 ALTER TABLE `quotations` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `rfq_items`
--

DROP TABLE IF EXISTS `rfq_items`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `rfq_items` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `rfq_id` int unsigned NOT NULL,
  `product_id` int unsigned DEFAULT NULL,
  `category_id` int unsigned DEFAULT NULL,
  `brand_id` int unsigned DEFAULT NULL,
  `brand_text` varchar(120) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `part_number` varchar(80) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `part_number_norm` varchar(80) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `description` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `specifications` text COLLATE utf8mb4_unicode_ci,
  `quantity` int unsigned NOT NULL DEFAULT '1',
  `unit` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pcs',
  `target_unit_price` decimal(15,4) DEFAULT NULL,
  `created_at` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `rfq_items_product_id_foreign` (`product_id`),
  KEY `rfq_items_category_id_foreign` (`category_id`),
  KEY `rfq_items_brand_id_foreign` (`brand_id`),
  KEY `rfq_id` (`rfq_id`),
  KEY `part_number_norm` (`part_number_norm`),
  CONSTRAINT `rfq_items_brand_id_foreign` FOREIGN KEY (`brand_id`) REFERENCES `brands` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `rfq_items_category_id_foreign` FOREIGN KEY (`category_id`) REFERENCES `categories` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `rfq_items_product_id_foreign` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `rfq_items_rfq_id_foreign` FOREIGN KEY (`rfq_id`) REFERENCES `rfqs` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `rfq_items`
--

LOCK TABLES `rfq_items` WRITE;
/*!40000 ALTER TABLE `rfq_items` DISABLE KEYS */;
/*!40000 ALTER TABLE `rfq_items` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `rfq_matches`
--

DROP TABLE IF EXISTS `rfq_matches`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `rfq_matches` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `rfq_id` int unsigned NOT NULL,
  `supplier_company_id` int unsigned NOT NULL,
  `match_score` decimal(6,2) NOT NULL DEFAULT '0.00',
  `match_reasons` json DEFAULT NULL,
  `matched_product_ids` json DEFAULT NULL,
  `status` enum('suggested','invited','excluded','viewed','declined','quoted') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'suggested',
  `invited_by` int unsigned DEFAULT NULL,
  `invited_at` datetime DEFAULT NULL,
  `viewed_at` datetime DEFAULT NULL,
  `responded_at` datetime DEFAULT NULL,
  `decline_reason` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `rfq_id_supplier_company_id` (`rfq_id`,`supplier_company_id`),
  KEY `rfq_matches_invited_by_foreign` (`invited_by`),
  KEY `status` (`status`),
  KEY `supplier_company_id` (`supplier_company_id`),
  CONSTRAINT `rfq_matches_invited_by_foreign` FOREIGN KEY (`invited_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `rfq_matches_rfq_id_foreign` FOREIGN KEY (`rfq_id`) REFERENCES `rfqs` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `rfq_matches_supplier_company_id_foreign` FOREIGN KEY (`supplier_company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `rfq_matches`
--

LOCK TABLES `rfq_matches` WRITE;
/*!40000 ALTER TABLE `rfq_matches` DISABLE KEYS */;
/*!40000 ALTER TABLE `rfq_matches` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `rfqs`
--

DROP TABLE IF EXISTS `rfqs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `rfqs` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `rfq_number` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL,
  `buyer_company_id` int unsigned NOT NULL,
  `created_by` int unsigned DEFAULT NULL,
  `title` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `destination_country` char(2) COLLATE utf8mb4_unicode_ci NOT NULL,
  `delivery_terms` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `delivery_requirements` text COLLATE utf8mb4_unicode_ci,
  `required_by` date DEFAULT NULL,
  `deadline_at` datetime NOT NULL,
  `currency` char(3) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'INR',
  `inspection_required` tinyint unsigned NOT NULL DEFAULT '0',
  `logistics_required` tinyint unsigned NOT NULL DEFAULT '0',
  `comments` text COLLATE utf8mb4_unicode_ci,
  `status` enum('draft','submitted','under_review','distributed','evaluation','awarded','closed','cancelled','rejected') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'submitted',
  `source` enum('form','cart','product','enquiry') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'form',
  `admin_notes` text COLLATE utf8mb4_unicode_ci,
  `reviewed_by` int unsigned DEFAULT NULL,
  `reviewed_at` datetime DEFAULT NULL,
  `distributed_at` datetime DEFAULT NULL,
  `awarded_quotation_id` int unsigned DEFAULT NULL,
  `created_at` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `rfq_number` (`rfq_number`),
  KEY `rfqs_created_by_foreign` (`created_by`),
  KEY `rfqs_reviewed_by_foreign` (`reviewed_by`),
  KEY `rfqs_destination_country_foreign` (`destination_country`),
  KEY `status` (`status`),
  KEY `buyer_company_id` (`buyer_company_id`),
  KEY `deadline_at` (`deadline_at`),
  CONSTRAINT `rfqs_buyer_company_id_foreign` FOREIGN KEY (`buyer_company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `rfqs_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `rfqs_destination_country_foreign` FOREIGN KEY (`destination_country`) REFERENCES `countries` (`iso2`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `rfqs_reviewed_by_foreign` FOREIGN KEY (`reviewed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `rfqs`
--

LOCK TABLES `rfqs` WRITE;
/*!40000 ALTER TABLE `rfqs` DISABLE KEYS */;
/*!40000 ALTER TABLE `rfqs` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `risk_assessments`
--

DROP TABLE IF EXISTS `risk_assessments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `risk_assessments` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `company_id` int unsigned NOT NULL,
  `application_id` int unsigned DEFAULT NULL,
  `financial_risk` tinyint unsigned DEFAULT NULL,
  `operational_risk` tinyint unsigned DEFAULT NULL,
  `compliance_risk` tinyint unsigned DEFAULT NULL,
  `geographic_risk` tinyint unsigned DEFAULT NULL,
  `supply_risk` tinyint unsigned DEFAULT NULL,
  `esg_risk` tinyint unsigned DEFAULT NULL,
  `weighted_score` decimal(5,2) DEFAULT NULL,
  `risk_level` enum('LOW','MEDIUM','HIGH') COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `evidence_notes` text COLLATE utf8mb4_unicode_ci,
  `assessed_by` int unsigned DEFAULT NULL,
  `assessed_at` datetime DEFAULT NULL,
  `created_at` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `risk_assessments_application_id_foreign` (`application_id`),
  KEY `risk_assessments_assessed_by_foreign` (`assessed_by`),
  KEY `company_id` (`company_id`),
  KEY `risk_level` (`risk_level`),
  CONSTRAINT `risk_assessments_application_id_foreign` FOREIGN KEY (`application_id`) REFERENCES `verification_applications` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `risk_assessments_assessed_by_foreign` FOREIGN KEY (`assessed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `risk_assessments_company_id_foreign` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `risk_assessments`
--

LOCK TABLES `risk_assessments` WRITE;
/*!40000 ALTER TABLE `risk_assessments` DISABLE KEYS */;
/*!40000 ALTER TABLE `risk_assessments` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `sanctions_screenings`
--

DROP TABLE IF EXISTS `sanctions_screenings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `sanctions_screenings` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `company_id` int unsigned NOT NULL,
  `subject_type` enum('company','director') COLLATE utf8mb4_unicode_ci NOT NULL,
  `subject_name` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `provider` varchar(60) COLLATE utf8mb4_unicode_ci NOT NULL,
  `lists_checked` varchar(191) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `result` enum('clear','potential_match','confirmed_match','error') COLLATE utf8mb4_unicode_ci NOT NULL,
  `provider_reference` varchar(191) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `notes` text COLLATE utf8mb4_unicode_ci,
  `screened_by` int unsigned DEFAULT NULL,
  `screened_at` datetime NOT NULL,
  `created_at` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `sanctions_screenings_screened_by_foreign` (`screened_by`),
  KEY `company_id` (`company_id`),
  KEY `result` (`result`),
  CONSTRAINT `sanctions_screenings_company_id_foreign` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `sanctions_screenings_screened_by_foreign` FOREIGN KEY (`screened_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `sanctions_screenings`
--

LOCK TABLES `sanctions_screenings` WRITE;
/*!40000 ALTER TABLE `sanctions_screenings` DISABLE KEYS */;
/*!40000 ALTER TABLE `sanctions_screenings` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `saved_products`
--

DROP TABLE IF EXISTS `saved_products`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `saved_products` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int unsigned NOT NULL,
  `product_id` int unsigned NOT NULL,
  `created_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `user_id_product_id` (`user_id`,`product_id`),
  KEY `saved_products_product_id_foreign` (`product_id`),
  CONSTRAINT `saved_products_product_id_foreign` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `saved_products_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `saved_products`
--

LOCK TABLES `saved_products` WRITE;
/*!40000 ALTER TABLE `saved_products` DISABLE KEYS */;
/*!40000 ALTER TABLE `saved_products` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `search_logs`
--

DROP TABLE IF EXISTS `search_logs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `search_logs` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int unsigned DEFAULT NULL,
  `company_id` int unsigned DEFAULT NULL,
  `query` varchar(191) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `filters` json DEFAULT NULL,
  `results_count` int unsigned NOT NULL DEFAULT '0',
  `country_code` char(2) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `created_at` (`created_at`),
  KEY `company_id` (`company_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `search_logs`
--

LOCK TABLES `search_logs` WRITE;
/*!40000 ALTER TABLE `search_logs` DISABLE KEYS */;
/*!40000 ALTER TABLE `search_logs` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `settings`
--

DROP TABLE IF EXISTS `settings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `settings` (
  `id` int NOT NULL AUTO_INCREMENT,
  `class` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `key` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `value` text COLLATE utf8mb4_unicode_ci,
  `type` varchar(31) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'string',
  `context` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `settings`
--

LOCK TABLES `settings` WRITE;
/*!40000 ALTER TABLE `settings` DISABLE KEYS */;
/*!40000 ALTER TABLE `settings` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `shipment_events`
--

DROP TABLE IF EXISTS `shipment_events`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `shipment_events` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `shipment_id` int unsigned NOT NULL,
  `status` varchar(40) COLLATE utf8mb4_unicode_ci NOT NULL,
  `location` varchar(191) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `description` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `event_at` datetime NOT NULL,
  `created_by` int unsigned DEFAULT NULL,
  `created_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `shipment_events_created_by_foreign` (`created_by`),
  KEY `shipment_id` (`shipment_id`),
  CONSTRAINT `shipment_events_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `shipment_events_shipment_id_foreign` FOREIGN KEY (`shipment_id`) REFERENCES `shipments` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `shipment_events`
--

LOCK TABLES `shipment_events` WRITE;
/*!40000 ALTER TABLE `shipment_events` DISABLE KEYS */;
/*!40000 ALTER TABLE `shipment_events` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `shipments`
--

DROP TABLE IF EXISTS `shipments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `shipments` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `logistics_request_id` int unsigned DEFAULT NULL,
  `order_id` int unsigned DEFAULT NULL,
  `carrier` varchar(120) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `tracking_number` varchar(120) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `transport_mode` enum('air','sea','road','rail','courier') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'road',
  `status` enum('booked','picked_up','in_transit','customs','out_for_delivery','delivered','exception') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'booked',
  `shipped_at` datetime DEFAULT NULL,
  `eta` date DEFAULT NULL,
  `delivered_at` datetime DEFAULT NULL,
  `delivered_on_time` tinyint(1) DEFAULT NULL,
  `created_by` int unsigned DEFAULT NULL,
  `created_at` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `shipments_logistics_request_id_foreign` (`logistics_request_id`),
  KEY `shipments_created_by_foreign` (`created_by`),
  KEY `status` (`status`),
  KEY `order_id` (`order_id`),
  CONSTRAINT `shipments_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `shipments_logistics_request_id_foreign` FOREIGN KEY (`logistics_request_id`) REFERENCES `logistics_requests` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `shipments_order_id_foreign` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `shipments`
--

LOCK TABLES `shipments` WRITE;
/*!40000 ALTER TABLE `shipments` DISABLE KEYS */;
/*!40000 ALTER TABLE `shipments` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `site_audits`
--

DROP TABLE IF EXISTS `site_audits`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `site_audits` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `company_id` int unsigned NOT NULL,
  `scheduled_on` date DEFAULT NULL,
  `conducted_on` date DEFAULT NULL,
  `auditor_user_id` int unsigned DEFAULT NULL,
  `external_auditor` varchar(191) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `site_address` text COLLATE utf8mb4_unicode_ci,
  `manufacturing_capability` tinyint unsigned DEFAULT NULL,
  `production_capacity` tinyint unsigned DEFAULT NULL,
  `machinery_condition` tinyint unsigned DEFAULT NULL,
  `warehouse_management` tinyint unsigned DEFAULT NULL,
  `calibration` tinyint unsigned DEFAULT NULL,
  `workforce_capability` tinyint unsigned DEFAULT NULL,
  `quality_processes` tinyint unsigned DEFAULT NULL,
  `findings` text COLLATE utf8mb4_unicode_ci,
  `result` enum('scheduled','pass','conditional','fail','cancelled') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'scheduled',
  `report_document_id` int unsigned DEFAULT NULL,
  `created_at` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  `audit_score` decimal(5,2) DEFAULT NULL,
  `corrective_actions` text COLLATE utf8mb4_unicode_ci,
  `recommendation` enum('approve','approve_with_conditions','reject') COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `site_audits_auditor_user_id_foreign` (`auditor_user_id`),
  KEY `site_audits_report_document_id_foreign` (`report_document_id`),
  KEY `company_id` (`company_id`),
  CONSTRAINT `site_audits_auditor_user_id_foreign` FOREIGN KEY (`auditor_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `site_audits_company_id_foreign` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `site_audits_report_document_id_foreign` FOREIGN KEY (`report_document_id`) REFERENCES `documents` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `site_audits`
--

LOCK TABLES `site_audits` WRITE;
/*!40000 ALTER TABLE `site_audits` DISABLE KEYS */;
/*!40000 ALTER TABLE `site_audits` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `subscriptions`
--

DROP TABLE IF EXISTS `subscriptions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `subscriptions` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `company_id` int unsigned NOT NULL,
  `plan_id` int unsigned NOT NULL,
  `status` enum('pending_payment','active','expired','cancelled','suspended') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending_payment',
  `starts_on` date DEFAULT NULL,
  `ends_on` date DEFAULT NULL,
  `invoice_id` int unsigned DEFAULT NULL,
  `renewal_of_id` int unsigned DEFAULT NULL,
  `reminder_sent_at` datetime DEFAULT NULL,
  `notes` text COLLATE utf8mb4_unicode_ci,
  `created_by` int unsigned DEFAULT NULL,
  `created_at` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `subscriptions_plan_id_foreign` (`plan_id`),
  KEY `subscriptions_created_by_foreign` (`created_by`),
  KEY `subscriptions_renewal_of_id_foreign` (`renewal_of_id`),
  KEY `status` (`status`),
  KEY `ends_on` (`ends_on`),
  KEY `company_id` (`company_id`),
  CONSTRAINT `subscriptions_company_id_foreign` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `subscriptions_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `subscriptions_plan_id_foreign` FOREIGN KEY (`plan_id`) REFERENCES `membership_plans` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `subscriptions_renewal_of_id_foreign` FOREIGN KEY (`renewal_of_id`) REFERENCES `subscriptions` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `subscriptions`
--

LOCK TABLES `subscriptions` WRITE;
/*!40000 ALTER TABLE `subscriptions` DISABLE KEYS */;
/*!40000 ALTER TABLE `subscriptions` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `supplier_master_attributes`
--

DROP TABLE IF EXISTS `supplier_master_attributes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `supplier_master_attributes` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `company_id` int unsigned NOT NULL,
  `field_key` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `value` text COLLATE utf8mb4_unicode_ci,
  `import_job_id` int unsigned DEFAULT NULL,
  `created_at` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `company_id_field_key` (`company_id`,`field_key`),
  CONSTRAINT `supplier_master_attributes_company_id_foreign` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `supplier_master_attributes`
--

LOCK TABLES `supplier_master_attributes` WRITE;
/*!40000 ALTER TABLE `supplier_master_attributes` DISABLE KEYS */;
/*!40000 ALTER TABLE `supplier_master_attributes` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `supplier_master_fields`
--

DROP TABLE IF EXISTS `supplier_master_fields`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `supplier_master_fields` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `source_heading` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `field_key` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `field_group` varchar(60) COLLATE utf8mb4_unicode_ci NOT NULL,
  `target` varchar(120) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `data_type` enum('string','text','number','decimal','date','bool','email','url') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'string',
  `sort_order` int unsigned NOT NULL DEFAULT '0',
  `is_confirmed` tinyint unsigned NOT NULL DEFAULT '0',
  `created_at` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  `is_active` tinyint unsigned DEFAULT '1',
  PRIMARY KEY (`id`),
  UNIQUE KEY `field_key` (`field_key`),
  KEY `field_group` (`field_group`)
) ENGINE=InnoDB AUTO_INCREMENT=87 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `supplier_master_fields`
--

LOCK TABLES `supplier_master_fields` WRITE;
/*!40000 ALTER TABLE `supplier_master_fields` DISABLE KEYS */;
INSERT INTO `supplier_master_fields` VALUES (1,'SKU','sku','contact',NULL,'string',0,1,'2026-10-13 11:03:02','2026-10-13 11:03:03',1),(2,'Company Name','company_name','contact','companies.legal_name','string',1,1,'2026-10-13 11:03:02','2026-10-13 11:03:03',1),(3,'Contact Person','contact_person','contact',NULL,'string',2,1,'2026-10-13 11:03:02','2026-10-13 11:03:03',1),(4,'Designation','designation','contact',NULL,'string',3,1,'2026-10-13 11:03:02','2026-10-13 11:03:03',1),(5,'Manufacturing Catagory','manufacturing_catagory','contact','supplier_profiles.main_categories','string',4,1,'2026-10-13 11:03:02','2026-10-13 11:03:03',1),(6,'Address 1','address_1','contact','companies.address_line1','string',5,1,'2026-10-13 11:03:02','2026-10-13 11:03:03',1),(7,'State','state','contact','companies.state','string',6,1,'2026-10-13 11:03:02','2026-10-13 11:03:03',1),(8,'Country','country','contact','companies.country_code','string',7,1,'2026-10-13 11:03:02','2026-10-13 11:03:03',1),(9,'Land Line No.','land_line_no','contact','companies.phone','string',8,1,'2026-10-13 11:03:02','2026-10-13 11:03:03',1),(10,'Mobile no.','mobile_no','contact',NULL,'string',9,1,'2026-10-13 11:03:02','2026-10-13 11:03:03',1),(11,'Fax No.','fax_no','contact',NULL,'string',10,1,'2026-10-13 11:03:02','2026-10-13 11:03:03',1),(12,'Email','email','contact','companies.email','string',11,1,'2026-10-13 11:03:02','2026-10-13 11:03:03',1),(13,'Website','website','contact','companies.website','string',12,1,'2026-10-13 11:03:02','2026-10-13 11:03:03',1),(14,'Instagram','instagram','contact',NULL,'string',13,1,'2026-10-13 11:03:02','2026-10-13 11:03:03',1),(15,'Facebook','facebook','contact',NULL,'string',14,1,'2026-10-13 11:03:02','2026-10-13 11:03:03',1),(16,'Linkedin','linkedin','contact',NULL,'string',15,1,'2026-10-13 11:03:02','2026-10-13 11:03:03',1),(17,'GST Registration','gst_registration','registration_kyc','kyc_records.gst_number','string',16,1,'2026-10-13 11:03:02','2026-10-13 11:03:03',1),(18,'PAN Card','pan_card','registration_kyc','kyc_records.pan_number','string',17,1,'2026-10-13 11:03:02','2026-10-13 11:03:03',1),(19,'CIN','cin','registration_kyc','kyc_records.cin_number','string',18,1,'2026-10-13 11:03:02','2026-10-13 11:03:03',1),(20,'VAT','vat','registration_kyc','kyc_records.vat_number','string',19,1,'2026-10-13 11:03:02','2026-10-13 11:03:03',1),(21,'Tax ID validation','tax_id_validation','registration_kyc','kyc_records.tax_id','string',20,1,'2026-10-13 11:03:02','2026-10-13 11:03:03',1),(22,'Bank account','bank_account','registration_kyc','kyc_records.bank_account_number','string',21,1,'2026-10-13 11:03:02','2026-10-13 11:03:03',1),(23,'Certificate of Incorporation','certificate_of_incorporation','registration_kyc',NULL,'string',22,1,'2026-10-13 11:03:02','2026-10-13 11:03:03',1),(24,'MSME Certificate','msme_certificate','registration_kyc',NULL,'string',23,1,'2026-10-13 11:03:02','2026-10-13 11:03:03',1),(25,'Import Export Code (IEC)','import_export_code_iec','registration_kyc',NULL,'string',24,1,'2026-10-13 11:03:02','2026-10-13 11:03:03',1),(26,'Director/Owner verification','director_owner_verification','registration_kyc',NULL,'string',25,1,'2026-10-13 11:03:02','2026-10-13 11:03:03',1),(27,'ISO certifications','iso_certifications','compliance',NULL,'string',26,1,'2026-10-13 11:03:02','2026-10-13 11:03:03',1),(28,'IATF 16949 (Automotive)','iatf_16949_automotive','compliance',NULL,'string',27,1,'2026-10-13 11:03:02','2026-10-13 11:03:03',1),(29,'AS9100 (Aerospace)','as9100_aerospace','compliance',NULL,'string',28,1,'2026-10-13 11:03:02','2026-10-13 11:03:03',1),(30,'RoHS & REACH compliance','rohs_reach_compliance','compliance',NULL,'string',29,1,'2026-10-13 11:03:02','2026-10-13 11:03:03',1),(31,'ESG compliance','esg_compliance','compliance',NULL,'string',30,1,'2026-10-13 11:03:02','2026-10-13 11:03:03',1),(32,'Insurance certificates','insurance_certificates','compliance',NULL,'string',31,1,'2026-10-13 11:03:02','2026-10-13 11:03:03',1),(33,'Labor law compliance','labor_law_compliance','compliance',NULL,'string',32,1,'2026-10-13 11:03:02','2026-10-13 11:03:03',1),(34,'Credit rating','credit_rating','financial',NULL,'string',33,1,'2026-10-13 11:03:02','2026-10-13 11:03:03',1),(35,'Annual turnover','annual_turnover','financial',NULL,'string',34,1,'2026-10-13 11:03:02','2026-10-13 11:03:03',1),(36,'Profitability','profitability','financial',NULL,'string',35,1,'2026-10-13 11:03:02','2026-10-13 11:03:03',1),(37,'Balance sheet review','balance_sheet_review','financial',NULL,'string',36,1,'2026-10-13 11:03:02','2026-10-13 11:03:03',1),(38,'Banking references','banking_references','financial',NULL,'string',37,1,'2026-10-13 11:03:02','2026-10-13 11:03:03',1),(39,'Outstanding legal cases','outstanding_legal_cases','financial',NULL,'string',38,1,'2026-10-13 11:03:02','2026-10-13 11:03:03',1),(40,'Bankruptcy checks','bankruptcy_checks','financial',NULL,'string',39,1,'2026-10-13 11:03:02','2026-10-13 11:03:03',1),(41,'Risk outputs','risk_outputs','risk',NULL,'string',40,1,'2026-10-13 11:03:02','2026-10-13 11:03:03',1),(42,'Financial Risk','financial_risk','risk',NULL,'string',41,1,'2026-10-13 11:03:02','2026-10-13 11:03:03',1),(43,'Operational Risk','operational_risk','risk',NULL,'string',42,1,'2026-10-13 11:03:02','2026-10-13 11:03:03',1),(44,'Compliance Risk','compliance_risk','risk',NULL,'string',43,1,'2026-10-13 11:03:02','2026-10-13 11:03:03',1),(45,'Geographic Risk','geographic_risk','risk',NULL,'string',44,1,'2026-10-13 11:03:02','2026-10-13 11:03:03',1),(46,'Supply Risk','supply_risk','risk',NULL,'string',45,1,'2026-10-13 11:03:02','2026-10-13 11:03:03',1),(47,'ESG Risk','esg_risk','risk',NULL,'string',46,1,'2026-10-13 11:03:02','2026-10-13 11:03:03',1),(48,'Quality certifications','quality_certifications','quality',NULL,'string',47,1,'2026-10-13 11:03:02','2026-10-13 11:03:03',1),(49,'Inspection systems','inspection_systems','quality',NULL,'string',48,1,'2026-10-13 11:03:02','2026-10-13 11:03:03',1),(50,'PPAP capability','ppap_capability','quality',NULL,'string',49,1,'2026-10-13 11:03:02','2026-10-13 11:03:03',1),(51,'Incoming and outgoing QC procedures','incoming_and_outgoing_qc_procedures','quality',NULL,'string',50,1,'2026-10-13 11:03:02','2026-10-13 11:03:03',1),(52,'CAPA process','capa_process','quality',NULL,'string',51,1,'2026-10-13 11:03:02','2026-10-13 11:03:03',1),(53,'Rejection history','rejection_history','quality',NULL,'string',52,1,'2026-10-13 11:03:02','2026-10-13 11:03:03',1),(54,'IATF 16949','iatf_16949','quality',NULL,'string',53,1,'2026-10-13 11:03:02','2026-10-13 11:03:03',1),(55,'PPAP','ppap','quality',NULL,'string',54,1,'2026-10-13 11:03:02','2026-10-13 11:03:03',1),(56,'APQP','apqp','quality',NULL,'string',55,1,'2026-10-13 11:03:02','2026-10-13 11:03:03',1),(57,'FMEA capability','fmea_capability','quality',NULL,'string',56,1,'2026-10-13 11:03:02','2026-10-13 11:03:03',1),(58,'Manufacturing capability','manufacturing_capability','site_audit',NULL,'string',57,1,'2026-10-13 11:03:02','2026-10-13 11:03:03',1),(59,'Production capacity','production_capacity','site_audit','supplier_profiles.production_capacity','string',58,1,'2026-10-13 11:03:02','2026-10-13 11:03:03',1),(60,'Machinery condition','machinery_condition','site_audit',NULL,'string',59,1,'2026-10-13 11:03:02','2026-10-13 11:03:03',1),(61,'Warehouse management','warehouse_management','site_audit',NULL,'string',60,1,'2026-10-13 11:03:02','2026-10-13 11:03:03',1),(62,'Calibration systems','calibration_systems','site_audit',NULL,'string',61,1,'2026-10-13 11:03:02','2026-10-13 11:03:03',1),(63,'Employee competency','employee_competency','site_audit',NULL,'string',62,1,'2026-10-13 11:03:02','2026-10-13 11:03:03',1),(64,'Quality systems','quality_systems','site_audit',NULL,'string',63,1,'2026-10-13 11:03:02','2026-10-13 11:03:03',1),(65,'Outputs','outputs','site_audit',NULL,'string',64,1,'2026-10-13 11:03:02','2026-10-13 11:03:03',1),(66,'ISO certificates','iso_certificates','documents',NULL,'string',65,1,'2026-10-13 11:03:02','2026-10-13 11:03:03',1),(67,'Insurance policies','insurance_policies','documents',NULL,'string',66,1,'2026-10-13 11:03:02','2026-10-13 11:03:03',1),(68,'NDAs','ndas','documents',NULL,'string',67,1,'2026-10-13 11:03:02','2026-10-13 11:03:03',1),(69,'Contracts','contracts','documents',NULL,'string',68,1,'2026-10-13 11:03:02','2026-10-13 11:03:03',1),(70,'Quality certificates','quality_certificates','documents',NULL,'string',69,1,'2026-10-13 11:03:02','2026-10-13 11:03:03',1),(71,'Test reports','test_reports','documents',NULL,'string',70,1,'2026-10-13 11:03:02','2026-10-13 11:03:03',1),(72,'Features','features','documents',NULL,'string',71,1,'2026-10-13 11:03:02','2026-10-13 11:03:03',1),(73,'UN sanctions lists','un_sanctions_lists','sanctions',NULL,'string',72,1,'2026-10-13 11:03:02','2026-10-13 11:03:03',1),(74,'OFAC sanctions','ofac_sanctions','sanctions',NULL,'string',73,1,'2026-10-13 11:03:02','2026-10-13 11:03:03',1),(75,'Politically Exposed Persons (PEP)','politically_exposed_persons_pep','sanctions',NULL,'string',74,1,'2026-10-13 11:03:02','2026-10-13 11:03:03',1),(76,'Anti-money laundering databases','anti_money_laundering_databases','sanctions',NULL,'string',75,1,'2026-10-13 11:03:02','2026-10-13 11:03:03',1),(77,'On-time delivery %','on_time_delivery','performance',NULL,'string',76,1,'2026-10-13 11:03:02','2026-10-13 11:03:03',1),(78,'Quality rejection %','quality_rejection','performance',NULL,'string',77,1,'2026-10-13 11:03:02','2026-10-13 11:03:03',1),(79,'Cost competitiveness','cost_competitiveness','performance',NULL,'string',78,1,'2026-10-13 11:03:02','2026-10-13 11:03:03',1),(80,'Response time','response_time','performance',NULL,'string',79,1,'2026-10-13 11:03:02','2026-10-13 11:03:03',1),(81,'Service level','service_level','performance',NULL,'string',80,1,'2026-10-13 11:03:02','2026-10-13 11:03:03',1),(82,'Category-wise supplier approval','category_wise_supplier_approval','avl',NULL,'string',81,1,'2026-10-13 11:03:02','2026-10-13 11:03:03',1),(83,'Approved brands','approved_brands','avl',NULL,'string',82,1,'2026-10-13 11:03:02','2026-10-13 11:03:03',1),(84,'Product-specific approvals','product_specific_approvals','avl',NULL,'string',83,1,'2026-10-13 11:03:02','2026-10-13 11:03:03',1),(85,'Supplier status tracking','supplier_status_tracking','avl',NULL,'string',84,1,'2026-10-13 11:03:02','2026-10-13 11:03:03',1),(86,'Suspension and reactivation','suspension_and_reactivation','avl',NULL,'string',85,1,'2026-10-13 11:03:02','2026-10-13 11:03:03',1);
/*!40000 ALTER TABLE `supplier_master_fields` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `supplier_performance`
--

DROP TABLE IF EXISTS `supplier_performance`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `supplier_performance` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `company_id` int unsigned NOT NULL,
  `period_start` date NOT NULL,
  `period_end` date NOT NULL,
  `orders_count` int unsigned NOT NULL DEFAULT '0',
  `on_time_delivery_pct` decimal(5,2) DEFAULT NULL,
  `quality_rejection_pct` decimal(5,2) DEFAULT NULL,
  `cost_competitiveness` decimal(5,2) DEFAULT NULL,
  `avg_response_hours` decimal(8,2) DEFAULT NULL,
  `rfq_response_rate_pct` decimal(5,2) DEFAULT NULL,
  `service_level` decimal(5,2) DEFAULT NULL,
  `overall_score` decimal(5,2) DEFAULT NULL,
  `source` enum('computed','manual') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'computed',
  `notes` text COLLATE utf8mb4_unicode_ci,
  `computed_at` datetime DEFAULT NULL,
  `created_at` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  `compliance_score` decimal(5,2) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `company_id_period_start_period_end` (`company_id`,`period_start`,`period_end`),
  CONSTRAINT `supplier_performance_company_id_foreign` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `supplier_performance`
--

LOCK TABLES `supplier_performance` WRITE;
/*!40000 ALTER TABLE `supplier_performance` DISABLE KEYS */;
/*!40000 ALTER TABLE `supplier_performance` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `supplier_profiles`
--

DROP TABLE IF EXISTS `supplier_profiles`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `supplier_profiles` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `company_id` int unsigned NOT NULL,
  `supplier_type` enum('manufacturer','oem_supplier','aftermarket_supplier','distributor','trader','stockist','other') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'manufacturer',
  `main_categories` text COLLATE utf8mb4_unicode_ci,
  `brands_handled` text COLLATE utf8mb4_unicode_ci,
  `production_capacity` varchar(191) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `warehouse_count` int unsigned DEFAULT NULL,
  `export_markets` text COLLATE utf8mb4_unicode_ci,
  `logistics_preference` enum('platform_managed','supplier_direct','both') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'platform_managed',
  `default_confidential` tinyint unsigned NOT NULL DEFAULT '1',
  `ranking_score` decimal(6,2) NOT NULL DEFAULT '0.00',
  `performance_score` decimal(5,2) DEFAULT NULL,
  `risk_level` enum('LOW','MEDIUM','HIGH') COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `company_id` (`company_id`),
  KEY `ranking_score` (`ranking_score`),
  CONSTRAINT `supplier_profiles_company_id_foreign` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `supplier_profiles`
--

LOCK TABLES `supplier_profiles` WRITE;
/*!40000 ALTER TABLE `supplier_profiles` DISABLE KEYS */;
/*!40000 ALTER TABLE `supplier_profiles` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `users`
--

DROP TABLE IF EXISTS `users`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `users` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `username` varchar(30) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `status` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `status_message` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `active` tinyint(1) NOT NULL DEFAULT '0',
  `last_active` datetime DEFAULT NULL,
  `created_at` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  `deleted_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `username` (`username`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `users`
--

LOCK TABLES `users` WRITE;
/*!40000 ALTER TABLE `users` DISABLE KEYS */;
/*!40000 ALTER TABLE `users` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `verification_applications`
--

DROP TABLE IF EXISTS `verification_applications`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `verification_applications` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `company_id` int unsigned NOT NULL,
  `application_type` enum('supplier','buyer') COLLATE utf8mb4_unicode_ci NOT NULL,
  `cycle` smallint unsigned NOT NULL DEFAULT '1',
  `status` enum('draft','submitted','in_review','approved','rejected','changes_requested','withdrawn') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'draft',
  `current_stage` varchar(40) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `submitted_at` datetime DEFAULT NULL,
  `decided_at` datetime DEFAULT NULL,
  `decided_by` int unsigned DEFAULT NULL,
  `decision_notes` text COLLATE utf8mb4_unicode_ci,
  `created_at` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `verification_applications_decided_by_foreign` (`decided_by`),
  KEY `status` (`status`),
  KEY `company_id` (`company_id`),
  CONSTRAINT `verification_applications_company_id_foreign` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `verification_applications_decided_by_foreign` FOREIGN KEY (`decided_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `verification_applications`
--

LOCK TABLES `verification_applications` WRITE;
/*!40000 ALTER TABLE `verification_applications` DISABLE KEYS */;
/*!40000 ALTER TABLE `verification_applications` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `verification_events`
--

DROP TABLE IF EXISTS `verification_events`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `verification_events` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `application_id` int unsigned NOT NULL,
  `stage_key` varchar(40) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `action` varchar(60) COLLATE utf8mb4_unicode_ci NOT NULL,
  `user_id` int unsigned DEFAULT NULL,
  `notes` text COLLATE utf8mb4_unicode_ci,
  `created_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `verification_events_user_id_foreign` (`user_id`),
  KEY `application_id` (`application_id`),
  CONSTRAINT `verification_events_application_id_foreign` FOREIGN KEY (`application_id`) REFERENCES `verification_applications` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `verification_events_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `verification_events`
--

LOCK TABLES `verification_events` WRITE;
/*!40000 ALTER TABLE `verification_events` DISABLE KEYS */;
/*!40000 ALTER TABLE `verification_events` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `verification_stages`
--

DROP TABLE IF EXISTS `verification_stages`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `verification_stages` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `application_id` int unsigned NOT NULL,
  `stage_key` varchar(40) COLLATE utf8mb4_unicode_ci NOT NULL,
  `sort_order` smallint unsigned NOT NULL DEFAULT '0',
  `status` enum('pending','in_review','passed','failed','waived','not_applicable') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `assigned_to` int unsigned DEFAULT NULL,
  `reviewed_by` int unsigned DEFAULT NULL,
  `reviewed_at` datetime DEFAULT NULL,
  `comments` text COLLATE utf8mb4_unicode_ci,
  `created_at` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `application_id_stage_key` (`application_id`,`stage_key`),
  KEY `verification_stages_reviewed_by_foreign` (`reviewed_by`),
  KEY `status` (`status`),
  KEY `assigned_to` (`assigned_to`),
  CONSTRAINT `verification_stages_application_id_foreign` FOREIGN KEY (`application_id`) REFERENCES `verification_applications` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `verification_stages_assigned_to_foreign` FOREIGN KEY (`assigned_to`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `verification_stages_reviewed_by_foreign` FOREIGN KEY (`reviewed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `verification_stages`
--

LOCK TABLES `verification_stages` WRITE;
/*!40000 ALTER TABLE `verification_stages` DISABLE KEYS */;
/*!40000 ALTER TABLE `verification_stages` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Dumping routines for database 'bc_export'
--
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

-- Dump completed
