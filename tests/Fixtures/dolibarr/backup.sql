-- MySQL dump 10.13  Distrib 10.11.6-MariaDB, for debian-linux-gnu (x86_64)
--
-- Host: localhost    Database: dolibarr
-- ------------------------------------------------------

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET NAMES utf8mb4 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;

--
-- Table structure for table `llx_accounting_account`
--

DROP TABLE IF EXISTS `llx_accounting_account`;
CREATE TABLE `llx_accounting_account` (
  `rowid` int(11) NOT NULL AUTO_INCREMENT,
  `label` varchar(255) DEFAULT NULL COMMENT 'it''s a column',
  PRIMARY KEY (`rowid`),
  KEY `idx_test` (`rowid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci;

LOCK TABLES `llx_accounting_account` WRITE;
/*!40000 ALTER TABLE `llx_accounting_account` DISABLE KEYS */;
INSERT INTO `llx_accounting_account` VALUES (1,'Vendite'),(2,'Acquisti; con punto e virgola\nsu due righe');
/*!40000 ALTER TABLE `llx_accounting_account` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `llx_c_country`
--

DROP TABLE IF EXISTS `llx_c_country`;
CREATE TABLE `llx_c_country` (
  `rowid` int(11) NOT NULL AUTO_INCREMENT,
  `code` varchar(255) DEFAULT NULL COMMENT 'it''s a column',
  `label` varchar(255) DEFAULT NULL COMMENT 'it''s a column',
  PRIMARY KEY (`rowid`),
  KEY `idx_test` (`rowid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci;

LOCK TABLES `llx_c_country` WRITE;
/*!40000 ALTER TABLE `llx_c_country` DISABLE KEYS */;
INSERT INTO `llx_c_country` VALUES (1,'FR','France'),(3,'IT','Italy');
/*!40000 ALTER TABLE `llx_c_country` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `llx_c_departements`
--

DROP TABLE IF EXISTS `llx_c_departements`;
CREATE TABLE `llx_c_departements` (
  `rowid` int(11) NOT NULL AUTO_INCREMENT,
  `code_departement` varchar(255) DEFAULT NULL COMMENT 'it''s a column',
  `nom` varchar(255) DEFAULT NULL COMMENT 'it''s a column',
  PRIMARY KEY (`rowid`),
  KEY `idx_test` (`rowid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci;

LOCK TABLES `llx_c_departements` WRITE;
/*!40000 ALTER TABLE `llx_c_departements` DISABLE KEYS */;
INSERT INTO `llx_c_departements` VALUES (101,'MI','Milano');
/*!40000 ALTER TABLE `llx_c_departements` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `llx_c_paiement`
--

DROP TABLE IF EXISTS `llx_c_paiement`;
CREATE TABLE `llx_c_paiement` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `code` varchar(255) DEFAULT NULL COMMENT 'it''s a column',
  `libelle` varchar(255) DEFAULT NULL COMMENT 'it''s a column',
  `type` varchar(255) DEFAULT NULL COMMENT 'it''s a column',
  `active` varchar(255) DEFAULT NULL COMMENT 'it''s a column',
  PRIMARY KEY (`id`),
  KEY `idx_test` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci;

LOCK TABLES `llx_c_paiement` WRITE;
/*!40000 ALTER TABLE `llx_c_paiement` DISABLE KEYS */;
INSERT INTO `llx_c_paiement` (`id`, `code`, `libelle`, `type`, `active`) VALUES (2,'VIR','Bonifico',2,1);
INSERT INTO `llx_c_paiement` (`id`, `code`, `libelle`, `type`, `active`) VALUES (4,'LIQ','Contanti',2,1);
INSERT INTO `llx_c_paiement` (`id`, `code`, `libelle`, `type`, `active`) VALUES (6,'CB','Carta',2,1);
INSERT INTO `llx_c_paiement` (`id`, `code`, `libelle`, `type`, `active`) VALUES (13,'RIBA','Ri.Ba.',2,1);
/*!40000 ALTER TABLE `llx_c_paiement` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `llx_c_payment_term`
--

DROP TABLE IF EXISTS `llx_c_payment_term`;
CREATE TABLE `llx_c_payment_term` (
  `rowid` int(11) NOT NULL AUTO_INCREMENT,
  `code` varchar(255) DEFAULT NULL COMMENT 'it''s a column',
  `libelle` varchar(255) DEFAULT NULL COMMENT 'it''s a column',
  `nbjour` varchar(255) DEFAULT NULL COMMENT 'it''s a column',
  `type_cdr` varchar(255) DEFAULT NULL COMMENT 'it''s a column',
  PRIMARY KEY (`rowid`),
  KEY `idx_test` (`rowid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci;

LOCK TABLES `llx_c_payment_term` WRITE;
/*!40000 ALTER TABLE `llx_c_payment_term` DISABLE KEYS */;
INSERT INTO `llx_c_payment_term` VALUES (1,'RECEP','Alla ricezione',0,0),(2,'30D','30 giorni',30,0),(3,'30DENDMONTH','30 giorni fine mese',30,1);
/*!40000 ALTER TABLE `llx_c_payment_term` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `llx_c_typent`
--

DROP TABLE IF EXISTS `llx_c_typent`;
CREATE TABLE `llx_c_typent` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `code` varchar(255) DEFAULT NULL COMMENT 'it''s a column',
  `libelle` varchar(255) DEFAULT NULL COMMENT 'it''s a column',
  PRIMARY KEY (`id`),
  KEY `idx_test` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci;

LOCK TABLES `llx_c_typent` WRITE;
/*!40000 ALTER TABLE `llx_c_typent` DISABLE KEYS */;
INSERT INTO `llx_c_typent` VALUES (1,'TE_STARTUP','Startup'),(5,'TE_ADMIN','Pubblica amministrazione'),(8,'TE_PRIVATE','Privato');
/*!40000 ALTER TABLE `llx_c_typent` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `llx_c_units`
--

DROP TABLE IF EXISTS `llx_c_units`;
CREATE TABLE `llx_c_units` (
  `rowid` int(11) NOT NULL AUTO_INCREMENT,
  `code` varchar(255) DEFAULT NULL COMMENT 'it''s a column',
  `label` varchar(255) DEFAULT NULL COMMENT 'it''s a column',
  `short_label` varchar(255) DEFAULT NULL COMMENT 'it''s a column',
  `unit_type` varchar(255) DEFAULT NULL COMMENT 'it''s a column',
  PRIMARY KEY (`rowid`),
  KEY `idx_test` (`rowid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci;

LOCK TABLES `llx_c_units` WRITE;
/*!40000 ALTER TABLE `llx_c_units` DISABLE KEYS */;
INSERT INTO `llx_c_units` VALUES (1,'P','Piece','p','qty'),(7,'H','Hour','h','time');
/*!40000 ALTER TABLE `llx_c_units` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `llx_categorie`
--

DROP TABLE IF EXISTS `llx_categorie`;
CREATE TABLE `llx_categorie` (
  `rowid` int(11) NOT NULL AUTO_INCREMENT,
  `label` varchar(255) DEFAULT NULL COMMENT 'it''s a column',
  `type` varchar(255) DEFAULT NULL COMMENT 'it''s a column',
  PRIMARY KEY (`rowid`),
  KEY `idx_test` (`rowid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci;

LOCK TABLES `llx_categorie` WRITE;
/*!40000 ALTER TABLE `llx_categorie` DISABLE KEYS */;
INSERT INTO `llx_categorie` VALUES (30,'Hardware',0),(31,'VIP',2);
/*!40000 ALTER TABLE `llx_categorie` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `llx_categorie_product`
--

DROP TABLE IF EXISTS `llx_categorie_product`;
CREATE TABLE `llx_categorie_product` (
  `fk_categorie` varchar(255) DEFAULT NULL COMMENT 'it''s a column',
  `fk_product` varchar(255) DEFAULT NULL COMMENT 'it''s a column',
  KEY `idx_test` (`fk_categorie`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci;

LOCK TABLES `llx_categorie_product` WRITE;
/*!40000 ALTER TABLE `llx_categorie_product` DISABLE KEYS */;
INSERT INTO `llx_categorie_product` VALUES (30,40);
/*!40000 ALTER TABLE `llx_categorie_product` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `llx_commande`
--

DROP TABLE IF EXISTS `llx_commande`;
CREATE TABLE `llx_commande` (
  `rowid` int(11) NOT NULL AUTO_INCREMENT,
  `ref` varchar(255) DEFAULT NULL COMMENT 'it''s a column',
  `fk_soc` varchar(255) DEFAULT NULL COMMENT 'it''s a column',
  `date_creation` varchar(255) DEFAULT NULL COMMENT 'it''s a column',
  `date_commande` varchar(255) DEFAULT NULL COMMENT 'it''s a column',
  `date_livraison` varchar(255) DEFAULT NULL COMMENT 'it''s a column',
  `fk_user_author` varchar(255) DEFAULT NULL COMMENT 'it''s a column',
  `fk_statut` varchar(255) DEFAULT NULL COMMENT 'it''s a column',
  `facture` varchar(255) DEFAULT NULL COMMENT 'it''s a column',
  `total_ht` varchar(255) DEFAULT NULL COMMENT 'it''s a column',
  `total_tva` varchar(255) DEFAULT NULL COMMENT 'it''s a column',
  `total_ttc` varchar(255) DEFAULT NULL COMMENT 'it''s a column',
  `ref_client` varchar(255) DEFAULT NULL COMMENT 'it''s a column',
  `note_private` varchar(255) DEFAULT NULL COMMENT 'it''s a column',
  `note_public` varchar(255) DEFAULT NULL COMMENT 'it''s a column',
  `fk_cond_reglement` varchar(255) DEFAULT NULL COMMENT 'it''s a column',
  `fk_mode_reglement` varchar(255) DEFAULT NULL COMMENT 'it''s a column',
  PRIMARY KEY (`rowid`),
  KEY `idx_test` (`rowid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci;

LOCK TABLES `llx_commande` WRITE;
/*!40000 ALTER TABLE `llx_commande` DISABLE KEYS */;
INSERT INTO `llx_commande` VALUES (70,'CO2601-0001',10,'2026-01-15 09:00:00','2026-01-15',NULL,1,3,1,'125.00000000','27.50000000','152.50000000','ORD-ALFA-7',NULL,NULL,3,2);
/*!40000 ALTER TABLE `llx_commande` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `llx_commande_fournisseur`
--

DROP TABLE IF EXISTS `llx_commande_fournisseur`;
CREATE TABLE `llx_commande_fournisseur` (
  `rowid` int(11) NOT NULL AUTO_INCREMENT,
  `ref` varchar(255) DEFAULT NULL COMMENT 'it''s a column',
  `ref_supplier` varchar(255) DEFAULT NULL COMMENT 'it''s a column',
  `fk_soc` varchar(255) DEFAULT NULL COMMENT 'it''s a column',
  `date_creation` varchar(255) DEFAULT NULL COMMENT 'it''s a column',
  `date_commande` varchar(255) DEFAULT NULL COMMENT 'it''s a column',
  `date_livraison` varchar(255) DEFAULT NULL COMMENT 'it''s a column',
  `fk_user_author` varchar(255) DEFAULT NULL COMMENT 'it''s a column',
  `fk_statut` varchar(255) DEFAULT NULL COMMENT 'it''s a column',
  `total_ht` varchar(255) DEFAULT NULL COMMENT 'it''s a column',
  `total_tva` varchar(255) DEFAULT NULL COMMENT 'it''s a column',
  `total_ttc` varchar(255) DEFAULT NULL COMMENT 'it''s a column',
  `note_private` varchar(255) DEFAULT NULL COMMENT 'it''s a column',
  `note_public` varchar(255) DEFAULT NULL COMMENT 'it''s a column',
  `fk_cond_reglement` varchar(255) DEFAULT NULL COMMENT 'it''s a column',
  `fk_mode_reglement` varchar(255) DEFAULT NULL COMMENT 'it''s a column',
  PRIMARY KEY (`rowid`),
  KEY `idx_test` (`rowid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci;

LOCK TABLES `llx_commande_fournisseur` WRITE;
/*!40000 ALTER TABLE `llx_commande_fournisseur` DISABLE KEYS */;
INSERT INTO `llx_commande_fournisseur` VALUES (120,'CF2601-0001','BETA-55',11,'2026-01-16 10:00:00','2026-01-16',NULL,2,5,'13.00000000','2.86000000','15.86000000',NULL,NULL,2,2);
/*!40000 ALTER TABLE `llx_commande_fournisseur` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `llx_commande_fournisseurdet`
--

DROP TABLE IF EXISTS `llx_commande_fournisseurdet`;
CREATE TABLE `llx_commande_fournisseurdet` (
  `rowid` int(11) NOT NULL AUTO_INCREMENT,
  `fk_commande` varchar(255) DEFAULT NULL COMMENT 'it''s a column',
  `fk_product` varchar(255) DEFAULT NULL COMMENT 'it''s a column',
  `ref` varchar(255) DEFAULT NULL COMMENT 'it''s a column',
  `label` varchar(255) DEFAULT NULL COMMENT 'it''s a column',
  `description` varchar(255) DEFAULT NULL COMMENT 'it''s a column',
  `product_type` varchar(255) DEFAULT NULL COMMENT 'it''s a column',
  `qty` varchar(255) DEFAULT NULL COMMENT 'it''s a column',
  `subprice` varchar(255) DEFAULT NULL COMMENT 'it''s a column',
  `remise_percent` varchar(255) DEFAULT NULL COMMENT 'it''s a column',
  `tva_tx` varchar(255) DEFAULT NULL COMMENT 'it''s a column',
  `vat_src_code` varchar(255) DEFAULT NULL COMMENT 'it''s a column',
  `total_ht` varchar(255) DEFAULT NULL COMMENT 'it''s a column',
  `rang` varchar(255) DEFAULT NULL COMMENT 'it''s a column',
  `fk_unit` varchar(255) DEFAULT NULL COMMENT 'it''s a column',
  PRIMARY KEY (`rowid`),
  KEY `idx_test` (`rowid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci;

LOCK TABLES `llx_commande_fournisseurdet` WRITE;
/*!40000 ALTER TABLE `llx_commande_fournisseurdet` DISABLE KEYS */;
INSERT INTO `llx_commande_fournisseurdet` VALUES (121,120,40,'B-MOUSE',NULL,'Mouse',0,2,'6.50000000',0,'22.0000','','13.00000000',1,NULL);
/*!40000 ALTER TABLE `llx_commande_fournisseurdet` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `llx_commandedet`
--

DROP TABLE IF EXISTS `llx_commandedet`;
CREATE TABLE `llx_commandedet` (
  `rowid` int(11) NOT NULL AUTO_INCREMENT,
  `fk_commande` varchar(255) DEFAULT NULL COMMENT 'it''s a column',
  `fk_product` varchar(255) DEFAULT NULL COMMENT 'it''s a column',
  `label` varchar(255) DEFAULT NULL COMMENT 'it''s a column',
  `description` varchar(255) DEFAULT NULL COMMENT 'it''s a column',
  `product_type` varchar(255) DEFAULT NULL COMMENT 'it''s a column',
  `qty` varchar(255) DEFAULT NULL COMMENT 'it''s a column',
  `subprice` varchar(255) DEFAULT NULL COMMENT 'it''s a column',
  `remise_percent` varchar(255) DEFAULT NULL COMMENT 'it''s a column',
  `tva_tx` varchar(255) DEFAULT NULL COMMENT 'it''s a column',
  `vat_src_code` varchar(255) DEFAULT NULL COMMENT 'it''s a column',
  `total_ht` varchar(255) DEFAULT NULL COMMENT 'it''s a column',
  `rang` varchar(255) DEFAULT NULL COMMENT 'it''s a column',
  `fk_unit` varchar(255) DEFAULT NULL COMMENT 'it''s a column',
  `special_code` varchar(255) DEFAULT NULL COMMENT 'it''s a column',
  PRIMARY KEY (`rowid`),
  KEY `idx_test` (`rowid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci;

LOCK TABLES `llx_commandedet` WRITE;
/*!40000 ALTER TABLE `llx_commandedet` DISABLE KEYS */;
INSERT INTO `llx_commandedet` VALUES (71,70,40,NULL,'Mouse USB',0,2,'12.50000000',0,'22.0000','','25.00000000',1,NULL,0),(72,70,41,NULL,'Configurazione',1,2,'50.00000000',0,'22.0000','','100.00000000',2,7,0);
/*!40000 ALTER TABLE `llx_commandedet` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `llx_const`
--

DROP TABLE IF EXISTS `llx_const`;
CREATE TABLE `llx_const` (
  `rowid` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(255) DEFAULT NULL COMMENT 'it''s a column',
  `entity` varchar(255) DEFAULT NULL COMMENT 'it''s a column',
  `value` varchar(255) DEFAULT NULL COMMENT 'it''s a column',
  `type` varchar(255) DEFAULT NULL COMMENT 'it''s a column',
  PRIMARY KEY (`rowid`),
  KEY `idx_test` (`rowid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci;

LOCK TABLES `llx_const` WRITE;
/*!40000 ALTER TABLE `llx_const` DISABLE KEYS */;
INSERT INTO `llx_const` VALUES (1,'MAIN_VERSION_LAST_UPGRADE',0,'24.0.1','chaine'),(2,'MAIN_INFO_SOCIETE_NOM',1,'Reimg S.r.l.','chaine');
/*!40000 ALTER TABLE `llx_const` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `llx_contrat`
--

DROP TABLE IF EXISTS `llx_contrat`;
CREATE TABLE `llx_contrat` (
  `rowid` int(11) NOT NULL AUTO_INCREMENT,
  `ref` varchar(255) DEFAULT NULL COMMENT 'it''s a column',
  `fk_soc` varchar(255) DEFAULT NULL COMMENT 'it''s a column',
  `datec` varchar(255) DEFAULT NULL COMMENT 'it''s a column',
  `date_contrat` varchar(255) DEFAULT NULL COMMENT 'it''s a column',
  `statut` varchar(255) DEFAULT NULL COMMENT 'it''s a column',
  `fk_commercial_suivi` varchar(255) DEFAULT NULL COMMENT 'it''s a column',
  `fk_user_author` varchar(255) DEFAULT NULL COMMENT 'it''s a column',
  `total_ht` varchar(255) DEFAULT NULL COMMENT 'it''s a column',
  `note_private` varchar(255) DEFAULT NULL COMMENT 'it''s a column',
  `note_public` varchar(255) DEFAULT NULL COMMENT 'it''s a column',
  PRIMARY KEY (`rowid`),
  KEY `idx_test` (`rowid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci;

LOCK TABLES `llx_contrat` WRITE;
/*!40000 ALTER TABLE `llx_contrat` DISABLE KEYS */;
INSERT INTO `llx_contrat` VALUES (140,'CT2601-0001',10,'2026-01-01 08:00:00','2026-01-01 00:00:00',1,1,1,'450.00000000',NULL,NULL),(141,'CT2601-0002',13,'2026-01-01 08:00:00','2026-01-01 00:00:00',1,1,1,'100.00000000',NULL,NULL);
/*!40000 ALTER TABLE `llx_contrat` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `llx_contratdet`
--

DROP TABLE IF EXISTS `llx_contratdet`;
CREATE TABLE `llx_contratdet` (
  `rowid` int(11) NOT NULL AUTO_INCREMENT,
  `fk_contrat` varchar(255) DEFAULT NULL COMMENT 'it''s a column',
  `fk_product` varchar(255) DEFAULT NULL COMMENT 'it''s a column',
  `label` varchar(255) DEFAULT NULL COMMENT 'it''s a column',
  `description` varchar(255) DEFAULT NULL COMMENT 'it''s a column',
  `qty` varchar(255) DEFAULT NULL COMMENT 'it''s a column',
  `subprice` varchar(255) DEFAULT NULL COMMENT 'it''s a column',
  `tva_tx` varchar(255) DEFAULT NULL COMMENT 'it''s a column',
  `vat_src_code` varchar(255) DEFAULT NULL COMMENT 'it''s a column',
  `total_ht` varchar(255) DEFAULT NULL COMMENT 'it''s a column',
  `fk_unit` varchar(255) DEFAULT NULL COMMENT 'it''s a column',
  `date_fin_validite` varchar(255) DEFAULT NULL COMMENT 'it''s a column',
  `statut` varchar(255) DEFAULT NULL COMMENT 'it''s a column',
  PRIMARY KEY (`rowid`),
  KEY `idx_test` (`rowid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci;

LOCK TABLES `llx_contratdet` WRITE;
/*!40000 ALTER TABLE `llx_contratdet` DISABLE KEYS */;
INSERT INTO `llx_contratdet` VALUES (142,140,41,'Pacchetto 10 ore assistenza',NULL,10,'45.00000000','22.0000','','450.00000000',7,'2099-12-31 00:00:00',4),(143,141,42,'Canone',NULL,1,'100.00000000','22.0000','','100.00000000',NULL,NULL,4);
/*!40000 ALTER TABLE `llx_contratdet` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `llx_element_element`
--

DROP TABLE IF EXISTS `llx_element_element`;
CREATE TABLE `llx_element_element` (
  `rowid` int(11) NOT NULL AUTO_INCREMENT,
  `fk_source` varchar(255) DEFAULT NULL COMMENT 'it''s a column',
  `sourcetype` varchar(255) DEFAULT NULL COMMENT 'it''s a column',
  `fk_target` varchar(255) DEFAULT NULL COMMENT 'it''s a column',
  `targettype` varchar(255) DEFAULT NULL COMMENT 'it''s a column',
  PRIMARY KEY (`rowid`),
  KEY `idx_test` (`rowid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci;

LOCK TABLES `llx_element_element` WRITE;
/*!40000 ALTER TABLE `llx_element_element` DISABLE KEYS */;
INSERT INTO `llx_element_element` VALUES (110,60,'propal',70,'commande'),(111,70,'commande',80,'facture'),(112,60,'propal',80,'facture'),(113,150,'fichinter',80,'facture'),(114,70,'commande',120,'order_supplier');
/*!40000 ALTER TABLE `llx_element_element` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `llx_facture`
--

DROP TABLE IF EXISTS `llx_facture`;
CREATE TABLE `llx_facture` (
  `rowid` int(11) NOT NULL AUTO_INCREMENT,
  `ref` varchar(255) DEFAULT NULL COMMENT 'it''s a column',
  `type` varchar(255) DEFAULT NULL COMMENT 'it''s a column',
  `fk_soc` varchar(255) DEFAULT NULL COMMENT 'it''s a column',
  `datec` varchar(255) DEFAULT NULL COMMENT 'it''s a column',
  `datef` varchar(255) DEFAULT NULL COMMENT 'it''s a column',
  `date_valid` varchar(255) DEFAULT NULL COMMENT 'it''s a column',
  `date_lim_reglement` varchar(255) DEFAULT NULL COMMENT 'it''s a column',
  `paye` varchar(255) DEFAULT NULL COMMENT 'it''s a column',
  `fk_statut` varchar(255) DEFAULT NULL COMMENT 'it''s a column',
  `fk_user_author` varchar(255) DEFAULT NULL COMMENT 'it''s a column',
  `total_ht` varchar(255) DEFAULT NULL COMMENT 'it''s a column',
  `total_tva` varchar(255) DEFAULT NULL COMMENT 'it''s a column',
  `total_ttc` varchar(255) DEFAULT NULL COMMENT 'it''s a column',
  `revenuestamp` varchar(255) DEFAULT NULL COMMENT 'it''s a column',
  `localtax1` varchar(255) DEFAULT NULL COMMENT 'it''s a column',
  `localtax2` varchar(255) DEFAULT NULL COMMENT 'it''s a column',
  `fk_facture_source` varchar(255) DEFAULT NULL COMMENT 'it''s a column',
  `fk_cond_reglement` varchar(255) DEFAULT NULL COMMENT 'it''s a column',
  `fk_mode_reglement` varchar(255) DEFAULT NULL COMMENT 'it''s a column',
  `note_private` varchar(255) DEFAULT NULL COMMENT 'it''s a column',
  `note_public` varchar(255) DEFAULT NULL COMMENT 'it''s a column',
  `ref_client` varchar(255) DEFAULT NULL COMMENT 'it''s a column',
  `close_code` varchar(255) DEFAULT NULL COMMENT 'it''s a column',
  `close_note` varchar(255) DEFAULT NULL COMMENT 'it''s a column',
  `multicurrency_code` varchar(255) DEFAULT NULL COMMENT 'it''s a column',
  PRIMARY KEY (`rowid`),
  KEY `idx_test` (`rowid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci;

LOCK TABLES `llx_facture` WRITE;
/*!40000 ALTER TABLE `llx_facture` DISABLE KEYS */;
INSERT INTO `llx_facture` VALUES (80,'FA2601-0005',0,10,'2026-01-20 11:00:00','2026-01-20','2026-01-20 11:05:00','2026-02-28',1,2,1,'125.00000000','27.50000000','152.50000000','0.00000000',0,0,NULL,3,2,NULL,'Grazie!','ORD-ALFA-7',NULL,NULL,'EUR'),(81,'AV2602-0002',2,10,'2026-02-01 09:00:00','2026-02-01','2026-02-01 09:01:00',NULL,0,1,1,'-12.50000000','-2.75000000','-15.25000000','0.00000000',0,0,80,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'EUR'),(82,'(PROV82)',0,12,'2026-03-01 09:00:00','2026-03-01',NULL,NULL,0,0,1,'50.00000000','0.00000000','50.00000000','0.00000000',0,0,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'EUR'),(83,'FA2512-0099',0,13,'2025-12-15 09:00:00','2025-12-15','2025-12-15 09:00:00','2026-01-15',0,1,2,'100.00000000','22.00000000','122.00000000','0.00000000',0,0,NULL,2,2,NULL,NULL,NULL,NULL,NULL,'EUR');
/*!40000 ALTER TABLE `llx_facture` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `llx_facture_fourn`
--

DROP TABLE IF EXISTS `llx_facture_fourn`;
CREATE TABLE `llx_facture_fourn` (
  `rowid` int(11) NOT NULL AUTO_INCREMENT,
  `ref` varchar(255) DEFAULT NULL COMMENT 'it''s a column',
  `ref_supplier` varchar(255) DEFAULT NULL COMMENT 'it''s a column',
  `type` varchar(255) DEFAULT NULL COMMENT 'it''s a column',
  `fk_soc` varchar(255) DEFAULT NULL COMMENT 'it''s a column',
  `datec` varchar(255) DEFAULT NULL COMMENT 'it''s a column',
  `datef` varchar(255) DEFAULT NULL COMMENT 'it''s a column',
  `libelle` varchar(255) DEFAULT NULL COMMENT 'it''s a column',
  `paye` varchar(255) DEFAULT NULL COMMENT 'it''s a column',
  `fk_statut` varchar(255) DEFAULT NULL COMMENT 'it''s a column',
  `fk_user_author` varchar(255) DEFAULT NULL COMMENT 'it''s a column',
  `total_ht` varchar(255) DEFAULT NULL COMMENT 'it''s a column',
  `total_tva` varchar(255) DEFAULT NULL COMMENT 'it''s a column',
  `total_ttc` varchar(255) DEFAULT NULL COMMENT 'it''s a column',
  `date_lim_reglement` varchar(255) DEFAULT NULL COMMENT 'it''s a column',
  `date_closing` varchar(255) DEFAULT NULL COMMENT 'it''s a column',
  `fk_mode_reglement` varchar(255) DEFAULT NULL COMMENT 'it''s a column',
  `note_private` varchar(255) DEFAULT NULL COMMENT 'it''s a column',
  `note_public` varchar(255) DEFAULT NULL COMMENT 'it''s a column',
  PRIMARY KEY (`rowid`),
  KEY `idx_test` (`rowid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci;

LOCK TABLES `llx_facture_fourn` WRITE;
/*!40000 ALTER TABLE `llx_facture_fourn` DISABLE KEYS */;
INSERT INTO `llx_facture_fourn` VALUES (130,'SI2601-0001','FT/2026/55',0,11,'2026-01-18 10:00:00','2026-01-18','Acquisto mouse',1,2,1,'13.00000000','2.86000000','15.86000000','2026-02-18',NULL,2,NULL,NULL),(131,'SI2601-0002','A-77',0,12,'2026-01-25 10:00:00','2026-01-25','Servizi Gamma',0,1,1,'200.00000000','44.00000000','244.00000000','2026-02-25',NULL,13,NULL,NULL);
/*!40000 ALTER TABLE `llx_facture_fourn` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `llx_facture_fourn_det`
--

DROP TABLE IF EXISTS `llx_facture_fourn_det`;
CREATE TABLE `llx_facture_fourn_det` (
  `rowid` int(11) NOT NULL AUTO_INCREMENT,
  `fk_facture_fourn` varchar(255) DEFAULT NULL COMMENT 'it''s a column',
  `fk_product` varchar(255) DEFAULT NULL COMMENT 'it''s a column',
  `ref` varchar(255) DEFAULT NULL COMMENT 'it''s a column',
  `label` varchar(255) DEFAULT NULL COMMENT 'it''s a column',
  `description` varchar(255) DEFAULT NULL COMMENT 'it''s a column',
  `pu_ht` varchar(255) DEFAULT NULL COMMENT 'it''s a column',
  `qty` varchar(255) DEFAULT NULL COMMENT 'it''s a column',
  `remise_percent` varchar(255) DEFAULT NULL COMMENT 'it''s a column',
  `tva_tx` varchar(255) DEFAULT NULL COMMENT 'it''s a column',
  `vat_src_code` varchar(255) DEFAULT NULL COMMENT 'it''s a column',
  `total_ht` varchar(255) DEFAULT NULL COMMENT 'it''s a column',
  `product_type` varchar(255) DEFAULT NULL COMMENT 'it''s a column',
  `rang` varchar(255) DEFAULT NULL COMMENT 'it''s a column',
  `fk_unit` varchar(255) DEFAULT NULL COMMENT 'it''s a column',
  PRIMARY KEY (`rowid`),
  KEY `idx_test` (`rowid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci;

LOCK TABLES `llx_facture_fourn_det` WRITE;
/*!40000 ALTER TABLE `llx_facture_fourn_det` DISABLE KEYS */;
INSERT INTO `llx_facture_fourn_det` VALUES (132,130,40,'B-MOUSE',NULL,'Mouse','6.50000000',2,0,'22.0000','','13.00000000',0,1,NULL),(133,131,NULL,NULL,'Consulenza',NULL,'200.00000000',1,0,'22.0000','','200.00000000',1,1,NULL);
/*!40000 ALTER TABLE `llx_facture_fourn_det` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `llx_facturedet`
--

DROP TABLE IF EXISTS `llx_facturedet`;
CREATE TABLE `llx_facturedet` (
  `rowid` int(11) NOT NULL AUTO_INCREMENT,
  `fk_facture` varchar(255) DEFAULT NULL COMMENT 'it''s a column',
  `fk_product` varchar(255) DEFAULT NULL COMMENT 'it''s a column',
  `label` varchar(255) DEFAULT NULL COMMENT 'it''s a column',
  `description` varchar(255) DEFAULT NULL COMMENT 'it''s a column',
  `product_type` varchar(255) DEFAULT NULL COMMENT 'it''s a column',
  `qty` varchar(255) DEFAULT NULL COMMENT 'it''s a column',
  `subprice` varchar(255) DEFAULT NULL COMMENT 'it''s a column',
  `remise_percent` varchar(255) DEFAULT NULL COMMENT 'it''s a column',
  `tva_tx` varchar(255) DEFAULT NULL COMMENT 'it''s a column',
  `vat_src_code` varchar(255) DEFAULT NULL COMMENT 'it''s a column',
  `total_ht` varchar(255) DEFAULT NULL COMMENT 'it''s a column',
  `rang` varchar(255) DEFAULT NULL COMMENT 'it''s a column',
  `fk_unit` varchar(255) DEFAULT NULL COMMENT 'it''s a column',
  `special_code` varchar(255) DEFAULT NULL COMMENT 'it''s a column',
  PRIMARY KEY (`rowid`),
  KEY `idx_test` (`rowid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci;

LOCK TABLES `llx_facturedet` WRITE;
/*!40000 ALTER TABLE `llx_facturedet` DISABLE KEYS */;
INSERT INTO `llx_facturedet` VALUES (90,80,40,NULL,'Mouse USB',0,2,'12.50000000',0,'22.0000','','25.00000000',1,NULL,0),(91,80,41,NULL,'Configurazione',1,2,'50.00000000',0,'22.0000','','100.00000000',2,7,0),(92,81,40,NULL,'Reso mouse',0,1,'-12.50000000',0,'22.0000','','-12.50000000',1,NULL,0),(93,82,NULL,'Consulenza','<p>Analisi&nbsp;iniziale<br>e report</p>',1,1,'50.00000000',0,'0.0000','N2.2','50.00000000',1,NULL,0),(94,83,42,NULL,NULL,1,1,'100.00000000',0,'22.0000','','100.00000000',1,NULL,0);
/*!40000 ALTER TABLE `llx_facturedet` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `llx_fichinter`
--

DROP TABLE IF EXISTS `llx_fichinter`;
CREATE TABLE `llx_fichinter` (
  `rowid` int(11) NOT NULL AUTO_INCREMENT,
  `ref` varchar(255) DEFAULT NULL COMMENT 'it''s a column',
  `fk_soc` varchar(255) DEFAULT NULL COMMENT 'it''s a column',
  `fk_contrat` varchar(255) DEFAULT NULL COMMENT 'it''s a column',
  `datec` varchar(255) DEFAULT NULL COMMENT 'it''s a column',
  `dateo` varchar(255) DEFAULT NULL COMMENT 'it''s a column',
  `datee` varchar(255) DEFAULT NULL COMMENT 'it''s a column',
  `fk_user_author` varchar(255) DEFAULT NULL COMMENT 'it''s a column',
  `fk_statut` varchar(255) DEFAULT NULL COMMENT 'it''s a column',
  `duree` varchar(255) DEFAULT NULL COMMENT 'it''s a column',
  `description` varchar(255) DEFAULT NULL COMMENT 'it''s a column',
  `note_private` varchar(255) DEFAULT NULL COMMENT 'it''s a column',
  `note_public` varchar(255) DEFAULT NULL COMMENT 'it''s a column',
  `online_sign_name` varchar(255) DEFAULT NULL COMMENT 'it''s a column',
  PRIMARY KEY (`rowid`),
  KEY `idx_test` (`rowid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci;

LOCK TABLES `llx_fichinter` WRITE;
/*!40000 ALTER TABLE `llx_fichinter` DISABLE KEYS */;
INSERT INTO `llx_fichinter` VALUES (150,'FI2601-0001',10,NULL,'2026-01-12 08:00:00',NULL,NULL,1,2,7200,'Installazione',NULL,NULL,'Luca Bianchi'),(151,'FI2601-0002',10,140,'2026-01-20 08:00:00',NULL,NULL,2,1,5400,'Manutenzione',NULL,NULL,NULL),(152,'FI2601-0003',12,NULL,'2026-01-22 08:00:00',NULL,NULL,1,3,1800,'Verifica',NULL,NULL,NULL);
/*!40000 ALTER TABLE `llx_fichinter` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `llx_fichinterdet`
--

DROP TABLE IF EXISTS `llx_fichinterdet`;
CREATE TABLE `llx_fichinterdet` (
  `rowid` int(11) NOT NULL AUTO_INCREMENT,
  `fk_fichinter` varchar(255) DEFAULT NULL COMMENT 'it''s a column',
  `date` varchar(255) DEFAULT NULL COMMENT 'it''s a column',
  `description` varchar(255) DEFAULT NULL COMMENT 'it''s a column',
  `duree` varchar(255) DEFAULT NULL COMMENT 'it''s a column',
  `rang` varchar(255) DEFAULT NULL COMMENT 'it''s a column',
  PRIMARY KEY (`rowid`),
  KEY `idx_test` (`rowid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci;

LOCK TABLES `llx_fichinterdet` WRITE;
/*!40000 ALTER TABLE `llx_fichinterdet` DISABLE KEYS */;
INSERT INTO `llx_fichinterdet` VALUES (153,150,'2026-01-12 09:00:00','Installazione postazioni',7200,1),(154,151,'2026-01-20 14:30:00','Pulizia server',5400,1),(155,152,'2026-01-22 10:00:00','Controllo',1800,1);
/*!40000 ALTER TABLE `llx_fichinterdet` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `llx_paiement`
--

DROP TABLE IF EXISTS `llx_paiement`;
CREATE TABLE `llx_paiement` (
  `rowid` int(11) NOT NULL AUTO_INCREMENT,
  `ref` varchar(255) DEFAULT NULL COMMENT 'it''s a column',
  `datep` varchar(255) DEFAULT NULL COMMENT 'it''s a column',
  `amount` varchar(255) DEFAULT NULL COMMENT 'it''s a column',
  `fk_paiement` varchar(255) DEFAULT NULL COMMENT 'it''s a column',
  `num_paiement` varchar(255) DEFAULT NULL COMMENT 'it''s a column',
  PRIMARY KEY (`rowid`),
  KEY `idx_test` (`rowid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci;

LOCK TABLES `llx_paiement` WRITE;
/*!40000 ALTER TABLE `llx_paiement` DISABLE KEYS */;
INSERT INTO `llx_paiement` VALUES (100,'PAY2602-0001','2026-02-10 00:00:00','152.50000000',2,'CRO123');
/*!40000 ALTER TABLE `llx_paiement` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `llx_paiement_facture`
--

DROP TABLE IF EXISTS `llx_paiement_facture`;
CREATE TABLE `llx_paiement_facture` (
  `rowid` int(11) NOT NULL AUTO_INCREMENT,
  `fk_paiement` varchar(255) DEFAULT NULL COMMENT 'it''s a column',
  `fk_facture` varchar(255) DEFAULT NULL COMMENT 'it''s a column',
  `amount` varchar(255) DEFAULT NULL COMMENT 'it''s a column',
  PRIMARY KEY (`rowid`),
  KEY `idx_test` (`rowid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci;

LOCK TABLES `llx_paiement_facture` WRITE;
/*!40000 ALTER TABLE `llx_paiement_facture` DISABLE KEYS */;
INSERT INTO `llx_paiement_facture` VALUES (101,100,80,'152.50000000');
/*!40000 ALTER TABLE `llx_paiement_facture` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `llx_paiementfourn`
--

DROP TABLE IF EXISTS `llx_paiementfourn`;
CREATE TABLE `llx_paiementfourn` (
  `rowid` int(11) NOT NULL AUTO_INCREMENT,
  `ref` varchar(255) DEFAULT NULL COMMENT 'it''s a column',
  `datep` varchar(255) DEFAULT NULL COMMENT 'it''s a column',
  `amount` varchar(255) DEFAULT NULL COMMENT 'it''s a column',
  `fk_paiement` varchar(255) DEFAULT NULL COMMENT 'it''s a column',
  `num_paiement` varchar(255) DEFAULT NULL COMMENT 'it''s a column',
  PRIMARY KEY (`rowid`),
  KEY `idx_test` (`rowid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci;

LOCK TABLES `llx_paiementfourn` WRITE;
/*!40000 ALTER TABLE `llx_paiementfourn` DISABLE KEYS */;
INSERT INTO `llx_paiementfourn` VALUES (134,'SPAY1','2026-02-05 00:00:00','15.86000000',2,NULL);
/*!40000 ALTER TABLE `llx_paiementfourn` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `llx_paiementfourn_facturefourn`
--

DROP TABLE IF EXISTS `llx_paiementfourn_facturefourn`;
CREATE TABLE `llx_paiementfourn_facturefourn` (
  `rowid` int(11) NOT NULL AUTO_INCREMENT,
  `fk_paiementfourn` varchar(255) DEFAULT NULL COMMENT 'it''s a column',
  `fk_facturefourn` varchar(255) DEFAULT NULL COMMENT 'it''s a column',
  `amount` varchar(255) DEFAULT NULL COMMENT 'it''s a column',
  PRIMARY KEY (`rowid`),
  KEY `idx_test` (`rowid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci;

LOCK TABLES `llx_paiementfourn_facturefourn` WRITE;
/*!40000 ALTER TABLE `llx_paiementfourn_facturefourn` DISABLE KEYS */;
INSERT INTO `llx_paiementfourn_facturefourn` VALUES (135,134,130,'15.86000000');
/*!40000 ALTER TABLE `llx_paiementfourn_facturefourn` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `llx_product`
--

DROP TABLE IF EXISTS `llx_product`;
CREATE TABLE `llx_product` (
  `rowid` int(11) NOT NULL AUTO_INCREMENT,
  `ref` varchar(255) DEFAULT NULL COMMENT 'it''s a column',
  `label` varchar(255) DEFAULT NULL COMMENT 'it''s a column',
  `description` varchar(255) DEFAULT NULL COMMENT 'it''s a column',
  `fk_product_type` varchar(255) DEFAULT NULL COMMENT 'it''s a column',
  `price` varchar(255) DEFAULT NULL COMMENT 'it''s a column',
  `price_base_type` varchar(255) DEFAULT NULL COMMENT 'it''s a column',
  `cost_price` varchar(255) DEFAULT NULL COMMENT 'it''s a column',
  `pmp` varchar(255) DEFAULT NULL COMMENT 'it''s a column',
  `tva_tx` varchar(255) DEFAULT NULL COMMENT 'it''s a column',
  `default_vat_code` varchar(255) DEFAULT NULL COMMENT 'it''s a column',
  `tosell` varchar(255) DEFAULT NULL COMMENT 'it''s a column',
  `tobuy` varchar(255) DEFAULT NULL COMMENT 'it''s a column',
  `fk_unit` varchar(255) DEFAULT NULL COMMENT 'it''s a column',
  `duration` varchar(255) DEFAULT NULL COMMENT 'it''s a column',
  `note` varchar(255) DEFAULT NULL COMMENT 'it''s a column',
  `note_public` varchar(255) DEFAULT NULL COMMENT 'it''s a column',
  `datec` varchar(255) DEFAULT NULL COMMENT 'it''s a column',
  PRIMARY KEY (`rowid`),
  KEY `idx_test` (`rowid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci;

LOCK TABLES `llx_product` WRITE;
/*!40000 ALTER TABLE `llx_product` DISABLE KEYS */;
INSERT INTO `llx_product` VALUES (40,'MOUSE01','Mouse ottico','Mouse USB',0,'12.50000000','HT','6.00000000','0.00000000','22.0000',NULL,1,1,1,NULL,NULL,NULL,'2025-01-01 10:00:00'),(41,'ASS-ORA','Assistenza tecnica oraria',NULL,1,'50.00000000','HT',NULL,'0.00000000','22.0000',NULL,1,0,7,NULL,NULL,NULL,'2025-01-01 10:00:00'),(42,'CANONE','Canone manutenzione',NULL,1,'100.00000000','HT',NULL,'0.00000000','22.0000',NULL,1,0,NULL,'1m',NULL,NULL,'2025-01-01 10:00:00');
/*!40000 ALTER TABLE `llx_product` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `llx_product_customer_price`
--

DROP TABLE IF EXISTS `llx_product_customer_price`;
CREATE TABLE `llx_product_customer_price` (
  `rowid` int(11) NOT NULL AUTO_INCREMENT,
  `fk_product` varchar(255) DEFAULT NULL COMMENT 'it''s a column',
  `fk_soc` varchar(255) DEFAULT NULL COMMENT 'it''s a column',
  `price` varchar(255) DEFAULT NULL COMMENT 'it''s a column',
  `discount_percent` varchar(255) DEFAULT NULL COMMENT 'it''s a column',
  `date_begin` varchar(255) DEFAULT NULL COMMENT 'it''s a column',
  `date_end` varchar(255) DEFAULT NULL COMMENT 'it''s a column',
  `ref_customer` varchar(255) DEFAULT NULL COMMENT 'it''s a column',
  PRIMARY KEY (`rowid`),
  KEY `idx_test` (`rowid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci;

LOCK TABLES `llx_product_customer_price` WRITE;
/*!40000 ALTER TABLE `llx_product_customer_price` DISABLE KEYS */;
INSERT INTO `llx_product_customer_price` VALUES (50,41,10,'45.00000000',0,'2025-01-01',NULL,NULL);
/*!40000 ALTER TABLE `llx_product_customer_price` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `llx_projet`
--

DROP TABLE IF EXISTS `llx_projet`;
CREATE TABLE `llx_projet` (
  `rowid` int(11) NOT NULL AUTO_INCREMENT,
  `ref` varchar(255) DEFAULT NULL COMMENT 'it''s a column',
  `title` varchar(255) DEFAULT NULL COMMENT 'it''s a column',
  PRIMARY KEY (`rowid`),
  KEY `idx_test` (`rowid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci;

LOCK TABLES `llx_projet` WRITE;
/*!40000 ALTER TABLE `llx_projet` DISABLE KEYS */;
INSERT INTO `llx_projet` VALUES (1,'PJ2601-0001','Progetto A'),(2,'PJ2601-0002','Progetto B');
/*!40000 ALTER TABLE `llx_projet` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `llx_propal`
--

DROP TABLE IF EXISTS `llx_propal`;
CREATE TABLE `llx_propal` (
  `rowid` int(11) NOT NULL AUTO_INCREMENT,
  `ref` varchar(255) DEFAULT NULL COMMENT 'it''s a column',
  `fk_soc` varchar(255) DEFAULT NULL COMMENT 'it''s a column',
  `datec` varchar(255) DEFAULT NULL COMMENT 'it''s a column',
  `datep` varchar(255) DEFAULT NULL COMMENT 'it''s a column',
  `fin_validite` varchar(255) DEFAULT NULL COMMENT 'it''s a column',
  `date_valid` varchar(255) DEFAULT NULL COMMENT 'it''s a column',
  `date_signature` varchar(255) DEFAULT NULL COMMENT 'it''s a column',
  `date_cloture` varchar(255) DEFAULT NULL COMMENT 'it''s a column',
  `fk_user_author` varchar(255) DEFAULT NULL COMMENT 'it''s a column',
  `fk_statut` varchar(255) DEFAULT NULL COMMENT 'it''s a column',
  `total_ht` varchar(255) DEFAULT NULL COMMENT 'it''s a column',
  `total_tva` varchar(255) DEFAULT NULL COMMENT 'it''s a column',
  `total_ttc` varchar(255) DEFAULT NULL COMMENT 'it''s a column',
  `fk_cond_reglement` varchar(255) DEFAULT NULL COMMENT 'it''s a column',
  `fk_mode_reglement` varchar(255) DEFAULT NULL COMMENT 'it''s a column',
  `note_private` varchar(255) DEFAULT NULL COMMENT 'it''s a column',
  `note_public` varchar(255) DEFAULT NULL COMMENT 'it''s a column',
  `ref_client` varchar(255) DEFAULT NULL COMMENT 'it''s a column',
  `online_sign_name` varchar(255) DEFAULT NULL COMMENT 'it''s a column',
  PRIMARY KEY (`rowid`),
  KEY `idx_test` (`rowid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci;

LOCK TABLES `llx_propal` WRITE;
/*!40000 ALTER TABLE `llx_propal` DISABLE KEYS */;
INSERT INTO `llx_propal` VALUES (60,'PR2601-0001',10,'2026-01-10 10:00:00','2026-01-10','2026-02-10 00:00:00','2026-01-10 10:05:00','2026-01-12 15:00:00',NULL,1,2,'125.00000000','27.50000000','152.50000000',3,2,NULL,'Grazie per la richiesta','ORD-ALFA-7',NULL);
/*!40000 ALTER TABLE `llx_propal` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `llx_propaldet`
--

DROP TABLE IF EXISTS `llx_propaldet`;
CREATE TABLE `llx_propaldet` (
  `rowid` int(11) NOT NULL AUTO_INCREMENT,
  `fk_propal` varchar(255) DEFAULT NULL COMMENT 'it''s a column',
  `fk_product` varchar(255) DEFAULT NULL COMMENT 'it''s a column',
  `label` varchar(255) DEFAULT NULL COMMENT 'it''s a column',
  `description` varchar(255) DEFAULT NULL COMMENT 'it''s a column',
  `product_type` varchar(255) DEFAULT NULL COMMENT 'it''s a column',
  `qty` varchar(255) DEFAULT NULL COMMENT 'it''s a column',
  `subprice` varchar(255) DEFAULT NULL COMMENT 'it''s a column',
  `remise_percent` varchar(255) DEFAULT NULL COMMENT 'it''s a column',
  `tva_tx` varchar(255) DEFAULT NULL COMMENT 'it''s a column',
  `vat_src_code` varchar(255) DEFAULT NULL COMMENT 'it''s a column',
  `total_ht` varchar(255) DEFAULT NULL COMMENT 'it''s a column',
  `rang` varchar(255) DEFAULT NULL COMMENT 'it''s a column',
  `fk_unit` varchar(255) DEFAULT NULL COMMENT 'it''s a column',
  `special_code` varchar(255) DEFAULT NULL COMMENT 'it''s a column',
  PRIMARY KEY (`rowid`),
  KEY `idx_test` (`rowid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci;

LOCK TABLES `llx_propaldet` WRITE;
/*!40000 ALTER TABLE `llx_propaldet` DISABLE KEYS */;
INSERT INTO `llx_propaldet` VALUES (61,60,NULL,'Hardware',NULL,9,1,'0.00000000',0,'0.0000','','0.00000000',1,NULL,104777),(62,60,40,NULL,'Mouse USB',0,2,'12.50000000',0,'22.0000','','25.00000000',2,NULL,0),(63,60,41,NULL,'Configurazione',1,2,'50.00000000',0,'22.0000','','100.00000000',3,7,0),(64,60,NULL,'Subtotale',NULL,9,99,'0.00000000',0,'0.0000','','0.00000000',4,NULL,104777);
/*!40000 ALTER TABLE `llx_propaldet` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `llx_societe`
--

DROP TABLE IF EXISTS `llx_societe`;
CREATE TABLE `llx_societe` (
  `rowid` int(11) NOT NULL AUTO_INCREMENT,
  `nom` varchar(255) DEFAULT NULL COMMENT 'it''s a column',
  `name_alias` varchar(255) DEFAULT NULL COMMENT 'it''s a column',
  `entity` varchar(255) DEFAULT NULL COMMENT 'it''s a column',
  `status` varchar(255) DEFAULT NULL COMMENT 'it''s a column',
  `client` varchar(255) DEFAULT NULL COMMENT 'it''s a column',
  `fournisseur` varchar(255) DEFAULT NULL COMMENT 'it''s a column',
  `code_client` varchar(255) DEFAULT NULL COMMENT 'it''s a column',
  `address` varchar(255) DEFAULT NULL COMMENT 'it''s a column',
  `zip` varchar(255) DEFAULT NULL COMMENT 'it''s a column',
  `town` varchar(255) DEFAULT NULL COMMENT 'it''s a column',
  `fk_departement` varchar(255) DEFAULT NULL COMMENT 'it''s a column',
  `fk_pays` varchar(255) DEFAULT NULL COMMENT 'it''s a column',
  `phone` varchar(255) DEFAULT NULL COMMENT 'it''s a column',
  `phone_mobile` varchar(255) DEFAULT NULL COMMENT 'it''s a column',
  `email` varchar(255) DEFAULT NULL COMMENT 'it''s a column',
  `url` varchar(255) DEFAULT NULL COMMENT 'it''s a column',
  `fk_typent` varchar(255) DEFAULT NULL COMMENT 'it''s a column',
  `siren` varchar(255) DEFAULT NULL COMMENT 'it''s a column',
  `siret` varchar(255) DEFAULT NULL COMMENT 'it''s a column',
  `ape` varchar(255) DEFAULT NULL COMMENT 'it''s a column',
  `idprof4` varchar(255) DEFAULT NULL COMMENT 'it''s a column',
  `idprof5` varchar(255) DEFAULT NULL COMMENT 'it''s a column',
  `idprof6` varchar(255) DEFAULT NULL COMMENT 'it''s a column',
  `tva_intra` varchar(255) DEFAULT NULL COMMENT 'it''s a column',
  `note_private` varchar(255) DEFAULT NULL COMMENT 'it''s a column',
  `note_public` varchar(255) DEFAULT NULL COMMENT 'it''s a column',
  `cond_reglement` varchar(255) DEFAULT NULL COMMENT 'it''s a column',
  `mode_reglement` varchar(255) DEFAULT NULL COMMENT 'it''s a column',
  `cond_reglement_supplier` varchar(255) DEFAULT NULL COMMENT 'it''s a column',
  `mode_reglement_supplier` varchar(255) DEFAULT NULL COMMENT 'it''s a column',
  `datec` varchar(255) DEFAULT NULL COMMENT 'it''s a column',
  `fk_user_creat` varchar(255) DEFAULT NULL COMMENT 'it''s a column',
  PRIMARY KEY (`rowid`),
  KEY `idx_test` (`rowid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci;

LOCK TABLES `llx_societe` WRITE;
/*!40000 ALTER TABLE `llx_societe` DISABLE KEYS */;
INSERT INTO `llx_societe` VALUES (10,'Alfa S.r.l.',NULL,1,1,1,0,'CU2601-0001','Via Roma 1','20100','Milano',101,3,'02 1234567',NULL,'info@alfa.it','https://alfa.it',1,NULL,NULL,NULL,NULL,NULL,NULL,'IT01114601006','<p>Cliente <b>storico</b></p>','L\'azienda; "top"\nsecondo rigo',3,2,NULL,NULL,'2025-01-01 10:00:00',1),(11,'Beta Forniture',NULL,1,1,0,1,NULL,'Via Po 2','10100','Torino',NULL,3,NULL,NULL,'amministrazione@pec.beta.it',NULL,1,NULL,NULL,NULL,NULL,NULL,NULL,'IT02000000001',NULL,NULL,NULL,NULL,2,2,'2025-01-02 10:00:00',1),(12,'Gamma & Figli',NULL,1,1,3,1,NULL,'Piazza Duomo 3','20121','Milano',101,3,NULL,NULL,'info@gamma.it',NULL,1,NULL,NULL,NULL,NULL,NULL,NULL,'IT03000000002',NULL,NULL,2,2,2,13,'2025-01-03 10:00:00',2),(13,'Comune di Delta',NULL,1,1,1,0,NULL,'Piazza Municipio 1','20010','Delta',101,3,NULL,NULL,NULL,NULL,5,NULL,NULL,NULL,'80012345678',NULL,NULL,NULL,NULL,NULL,2,2,NULL,NULL,'2025-01-04 10:00:00',1),(14,'Rossi Mario',NULL,1,0,2,0,NULL,NULL,NULL,NULL,NULL,3,NULL,'333 7654321','mario@example.it',NULL,8,NULL,NULL,NULL,'RSSMRA80A01F205X',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'2025-01-05 10:00:00',1);
/*!40000 ALTER TABLE `llx_societe` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `llx_societe_extrafields`
--

DROP TABLE IF EXISTS `llx_societe_extrafields`;
CREATE TABLE `llx_societe_extrafields` (
  `rowid` int(11) NOT NULL AUTO_INCREMENT,
  `fk_object` varchar(255) DEFAULT NULL COMMENT 'it''s a column',
  `codice_destinatario` varchar(255) DEFAULT NULL COMMENT 'it''s a column',
  PRIMARY KEY (`rowid`),
  KEY `idx_test` (`rowid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci;

LOCK TABLES `llx_societe_extrafields` WRITE;
/*!40000 ALTER TABLE `llx_societe_extrafields` DISABLE KEYS */;
INSERT INTO `llx_societe_extrafields` VALUES (1,10,'M5UXCR1'),(2,13,'UF1234');
/*!40000 ALTER TABLE `llx_societe_extrafields` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `llx_socpeople`
--

DROP TABLE IF EXISTS `llx_socpeople`;
CREATE TABLE `llx_socpeople` (
  `rowid` int(11) NOT NULL AUTO_INCREMENT,
  `fk_soc` varchar(255) DEFAULT NULL COMMENT 'it''s a column',
  `lastname` varchar(255) DEFAULT NULL COMMENT 'it''s a column',
  `firstname` varchar(255) DEFAULT NULL COMMENT 'it''s a column',
  `poste` varchar(255) DEFAULT NULL COMMENT 'it''s a column',
  `email` varchar(255) DEFAULT NULL COMMENT 'it''s a column',
  `phone` varchar(255) DEFAULT NULL COMMENT 'it''s a column',
  `phone_perso` varchar(255) DEFAULT NULL COMMENT 'it''s a column',
  `phone_mobile` varchar(255) DEFAULT NULL COMMENT 'it''s a column',
  `statut` varchar(255) DEFAULT NULL COMMENT 'it''s a column',
  `address` varchar(255) DEFAULT NULL COMMENT 'it''s a column',
  `zip` varchar(255) DEFAULT NULL COMMENT 'it''s a column',
  `town` varchar(255) DEFAULT NULL COMMENT 'it''s a column',
  `note_private` varchar(255) DEFAULT NULL COMMENT 'it''s a column',
  `note_public` varchar(255) DEFAULT NULL COMMENT 'it''s a column',
  PRIMARY KEY (`rowid`),
  KEY `idx_test` (`rowid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci;

LOCK TABLES `llx_socpeople` WRITE;
/*!40000 ALTER TABLE `llx_socpeople` DISABLE KEYS */;
INSERT INTO `llx_socpeople` VALUES (20,10,'Bianchi','Luca','Responsabile IT','luca@alfa.it','02 1234567',NULL,'333 1234567',1,NULL,NULL,NULL,NULL,NULL),(21,12,'Verdi','Anna',NULL,'anna@gamma.it',NULL,NULL,NULL,1,NULL,NULL,NULL,NULL,NULL),(22,NULL,'Senza','Azienda',NULL,NULL,NULL,NULL,NULL,1,NULL,NULL,NULL,NULL,NULL);
/*!40000 ALTER TABLE `llx_socpeople` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `llx_user`
--

DROP TABLE IF EXISTS `llx_user`;
CREATE TABLE `llx_user` (
  `rowid` int(11) NOT NULL AUTO_INCREMENT,
  `login` varchar(255) DEFAULT NULL COMMENT 'it''s a column',
  `lastname` varchar(255) DEFAULT NULL COMMENT 'it''s a column',
  `firstname` varchar(255) DEFAULT NULL COMMENT 'it''s a column',
  `email` varchar(255) DEFAULT NULL COMMENT 'it''s a column',
  `statut` varchar(255) DEFAULT NULL COMMENT 'it''s a column',
  `admin` varchar(255) DEFAULT NULL COMMENT 'it''s a column',
  `pass_crypted` varchar(255) DEFAULT NULL COMMENT 'it''s a column',
  PRIMARY KEY (`rowid`),
  KEY `idx_test` (`rowid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci;

LOCK TABLES `llx_user` WRITE;
/*!40000 ALTER TABLE `llx_user` DISABLE KEYS */;
INSERT INTO `llx_user` VALUES (1,'admin','Rossi','Marco','marco@reimg.test',1,1,'$2y$10$secret'),(2,'tecnico','Neri','Paolo','paolo@altrove.test',1,0,'$2y$10$secret');
/*!40000 ALTER TABLE `llx_user` ENABLE KEYS */;
UNLOCK TABLES;

/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
-- Dump completed on 2026-11-05 10:00:00
