-- --------------------------------------------------------
-- Host:                         127.0.0.1
-- Server version:               10.6.22-MariaDB-0ubuntu0.22.04.1 - Ubuntu 22.04
-- Server OS:                    debian-linux-gnu
-- HeidiSQL Version:             12.9.0.6999
-- --------------------------------------------------------

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET NAMES utf8mb4 */;
/*!50503 SET NAMES utf8mb4 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;


-- Dumping database structure for rm_tracker
CREATE DATABASE IF NOT EXISTS `rm_tracker` /*!40100 DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci */;
USE `rm_tracker`;

-- Dumping structure for table rm_tracker.Data
CREATE TABLE IF NOT EXISTS `Data` (
  `Id` int(11) NOT NULL AUTO_INCREMENT,
  `Input` varchar(500) NOT NULL,
  `Timestamp` timestamp NOT NULL DEFAULT current_timestamp(),
  `Processed` enum('1','0') NOT NULL DEFAULT '0',
  `Info` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`Id`),
  KEY `IX_Data_Processed_Id` (`Processed`,`Id`)
) ENGINE=Aria AUTO_INCREMENT=470449 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci PAGE_CHECKSUM=1;

-- Data exporting was unselected.

-- Dumping structure for table rm_tracker.DataArchive
CREATE TABLE IF NOT EXISTS `DataArchive` (
  `Id` int(11) NOT NULL AUTO_INCREMENT,
  `DataStaging_Id` int(11) NOT NULL,
  `Imei` varchar(20) NOT NULL,
  `Lat` float(8,6) NOT NULL,
  `Lon` float(9,6) NOT NULL,
  `Timestamp` datetime NOT NULL,
  `Distance` decimal(10,1) unsigned DEFAULT 0.0,
  `Bearing` decimal(3,0) unsigned DEFAULT NULL,
  `Course` decimal(5,2) DEFAULT NULL,
  `Speed_avg` decimal(18,2) DEFAULT 0.00,
  `Speed` decimal(5,2) DEFAULT NULL,
  `Path_Id` int(11) DEFAULT NULL,
  `Path_Info` enum('Start','End') DEFAULT NULL,
  PRIMARY KEY (`Id`),
  UNIQUE KEY `DataStaging_Id` (`DataStaging_Id`),
  KEY `IX_DataArchive_Imei_Timestamp_Id` (`Imei`,`Timestamp`,`Id`),
  KEY `IX_DataArchive_PathId_Timestamp` (`Path_Id`,`Timestamp`),
  KEY `FK_DataArchive_Imei_Devices_Imei` (`Imei`),
  KEY `Path_Id` (`Path_Id`),
  CONSTRAINT `FK_DataArchive_Imei_Devices_Imei` FOREIGN KEY (`Imei`) REFERENCES `Devices` (`Imei`),
  CONSTRAINT `FK_DataArchive_Path_Id_Path_Id` FOREIGN KEY (`Path_Id`) REFERENCES `Path` (`Id`)
) ENGINE=InnoDB AUTO_INCREMENT=17594205 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Data exporting was unselected.

-- Dumping structure for table rm_tracker.DataStaging
CREATE TABLE IF NOT EXISTS `DataStaging` (
  `Id` int(11) NOT NULL AUTO_INCREMENT,
  `Data_Id` int(11) NOT NULL,
  `Imei` varchar(20) NOT NULL,
  `Lat` float(8,6) NOT NULL,
  `Lon` float(9,6) NOT NULL,
  `Lat_DMS` varchar(11) NOT NULL,
  `Lon_DMS` varchar(11) NOT NULL,
  `Timestamp` datetime NOT NULL,
  `Speed` decimal(5,2) DEFAULT 0.00,
  `Processed` enum('1','0') NOT NULL DEFAULT '0',
  PRIMARY KEY (`Id`),
  UNIQUE KEY `Data_Id` (`Data_Id`),
  KEY `IX_DataStaging_Processed_Imei_Timestamp_Id` (`Processed`,`Imei`,`Timestamp`,`Id`),
  KEY `FK_DataStaging_Imei_Devices_Imei` (`Imei`),
  CONSTRAINT `FK_DataStaging_Imei_Devices_Imei` FOREIGN KEY (`Imei`) REFERENCES `Devices` (`Imei`)
) ENGINE=InnoDB AUTO_INCREMENT=523270 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Data exporting was unselected.

-- Dumping structure for table rm_tracker.Devices
CREATE TABLE IF NOT EXISTS `Devices` (
  `Imei` varchar(20) NOT NULL,
  `Name` varchar(50) NOT NULL,
  `Url` varchar(50) DEFAULT NULL,
  `LastUpdated` datetime DEFAULT NULL,
  `DeleteNewer` enum('1','0') NOT NULL DEFAULT '0',
  `Guid` varchar(36) DEFAULT NULL,
  `LastPosition_Id` int(11) DEFAULT NULL,
  `Disabled` enum('1','0') NOT NULL DEFAULT '0',
  PRIMARY KEY (`Imei`),
  KEY `LastUpdated` (`LastUpdated`),
  KEY `DeleteNewer` (`DeleteNewer`),
  KEY `Disabled` (`Disabled`),
  KEY `FK_Devices_LastPosition_Id_DataArchive_Id` (`LastPosition_Id`),
  KEY `Url` (`Url`),
  CONSTRAINT `FK_Devices_LastPosition_Id_DataArchive_Id` FOREIGN KEY (`LastPosition_Id`) REFERENCES `DataArchive` (`Id`) ON DELETE SET NULL ON UPDATE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Data exporting was unselected.

-- Dumping structure for table rm_tracker.Events
CREATE TABLE IF NOT EXISTS `Events` (
  `Id` int(11) NOT NULL AUTO_INCREMENT,
  `Imei` varchar(20) NOT NULL,
  `Timestamp` datetime NOT NULL,
  `Guid` varchar(36) DEFAULT uuid(),
  `NameUrl` varchar(255) DEFAULT '',
  `Info` varchar(100) NOT NULL,
  `Description` varchar(255) DEFAULT NULL,
  `Amount` decimal(5,1) DEFAULT NULL,
  `Price` decimal(5,2) DEFAULT NULL,
  `Type` enum('Fuel','Gas','EngineHours','Other') NOT NULL,
  `Lat` float(8,6) DEFAULT NULL,
  `Lon` float(9,6) DEFAULT NULL,
  `Place_Id` int(11) DEFAULT NULL,
  `Kml` text DEFAULT NULL,
  `EngineHourMeter` decimal(10,1) DEFAULT NULL,
  PRIMARY KEY (`Id`) USING BTREE,
  KEY `Timestamp` (`Timestamp`,`Imei`) USING BTREE,
  KEY `FK_Events_Imei_Devices_Imei` (`Imei`) USING BTREE,
  KEY `Place_Id` (`Place_Id`),
  KEY `Guid` (`Guid`),
  KEY `NameUrl` (`NameUrl`),
  CONSTRAINT `FK_Events_Imei_Devices_Imei` FOREIGN KEY (`Imei`) REFERENCES `Devices` (`Imei`),
  CONSTRAINT `FK_Events_Place_Id_Places_Id` FOREIGN KEY (`Place_Id`) REFERENCES `Places` (`Id`)
) ENGINE=InnoDB AUTO_INCREMENT=15 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Data exporting was unselected.

-- Dumping structure for table rm_tracker.Path
CREATE TABLE IF NOT EXISTS `Path` (
  `Id` int(11) NOT NULL AUTO_INCREMENT,
  `Name` varchar(100) DEFAULT NULL,
  `Description` varchar(100) DEFAULT NULL,
  `NameUrl` varchar(100) DEFAULT NULL,
  `Guid` varchar(36) DEFAULT NULL,
  `Imei` varchar(20) NOT NULL,
  `Start` datetime NOT NULL,
  `StartPlace_Id` int(11) DEFAULT NULL,
  `End` datetime DEFAULT NULL,
  `EndPlace_Id` int(11) DEFAULT NULL,
  `Kml` longtext DEFAULT NULL,
  `KmlPoints` longtext DEFAULT NULL,
  `Ready` tinyint(1) NOT NULL DEFAULT 0,
  `Rename` tinyint(1) NOT NULL DEFAULT 1,
  `Visible` tinyint(1) NOT NULL DEFAULT 1,
  `Group` tinyint(1) NOT NULL DEFAULT 0,
  `Duration` time DEFAULT NULL,
  `Distance` decimal(10,1) DEFAULT NULL,
  `Speed_Avg` decimal(18,2) DEFAULT NULL,
  `EngineHourMeter` decimal(10,1) DEFAULT NULL,
  `EngineHours` decimal(10,1) DEFAULT NULL,
  PRIMARY KEY (`Id`),
  KEY `Ready` (`Ready`),
  KEY `Start_End` (`Start`,`End`),
  KEY `Rename` (`Rename`),
  KEY `Visible` (`Visible`),
  KEY `Guid` (`Guid`),
  KEY `NimiUrl` (`NameUrl`),
  KEY `Imei` (`Imei`),
  KEY `FK_Path_StartPlace_Id_Places_id` (`StartPlace_Id`),
  KEY `FK_Path_EndPlace_Id_Places_id` (`EndPlace_Id`),
  CONSTRAINT `FK_Path_EndPlace_Id_Places_id` FOREIGN KEY (`EndPlace_Id`) REFERENCES `Places` (`Id`),
  CONSTRAINT `FK_Path_Imei_Devices_Imei` FOREIGN KEY (`Imei`) REFERENCES `Devices` (`Imei`),
  CONSTRAINT `FK_Path_StartPlace_Id_Places_id` FOREIGN KEY (`StartPlace_Id`) REFERENCES `Places` (`Id`)
) ENGINE=InnoDB AUTO_INCREMENT=21101 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Data exporting was unselected.

-- Dumping structure for table rm_tracker.Places
CREATE TABLE IF NOT EXISTS `Places` (
  `Id` int(11) NOT NULL AUTO_INCREMENT,
  `Name` varchar(100) NOT NULL,
  `Description` varchar(100) DEFAULT NULL,
  `NameUrl` varchar(100) DEFAULT NULL,
  `Lat` float(8,6) NOT NULL,
  `Lon` float(9,6) NOT NULL,
  `Radius` decimal(5,0) NOT NULL DEFAULT 1000,
  `Kml` text DEFAULT NULL,
  `Rename` tinyint(1) NOT NULL DEFAULT 1,
  `Owner` varchar(20) DEFAULT NULL,
  `Public` tinyint(1) NOT NULL DEFAULT 1,
  `Visible` tinyint(1) NOT NULL DEFAULT 1,
  `Group` enum('Summary','0') DEFAULT '0',
  `Group_Imei` varchar(20) DEFAULT NULL,
  `LastEdited` datetime DEFAULT NULL,
  `LastEdited_Imei` varchar(20) DEFAULT NULL,
  PRIMARY KEY (`Id`),
  UNIQUE KEY `Name` (`Name`),
  KEY `Owner` (`Owner`),
  KEY `Public` (`Public`),
  KEY `NameUrl` (`NameUrl`),
  KEY `GroupId` (`Group_Imei`),
  KEY `Group_Type` (`Group`)
) ENGINE=InnoDB AUTO_INCREMENT=603 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Data exporting was unselected.

-- Dumping structure for table rm_tracker.Settings
CREATE TABLE IF NOT EXISTS `Settings` (
  `Setting` varchar(50) NOT NULL,
  `Value` varchar(50) NOT NULL,
  PRIMARY KEY (`Setting`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Data exporting was unselected.

-- Dumping structure for trigger rm_tracker.DataArchive_Update_LastUpdated
SET @OLDTMP_SQL_MODE=@@SQL_MODE, SQL_MODE='STRICT_TRANS_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_AUTO_CREATE_USER,NO_ENGINE_SUBSTITUTION';
DELIMITER //
CREATE DEFINER=`opiadmin`@`%` TRIGGER `DataArchive_Update_LastUpdated` AFTER INSERT ON `DataArchive` FOR EACH ROW BEGIN

	-- PÃ¤ivitetÃ¤Ã¤n laitteen viimeisin pÃ¤ivitysajankohta ja nollataan poistotieto
	UPDATE Devices 
	SET LastUpdated = NEW.Timestamp, DeleteNewer = '0', LastPosition_Id = NEW.Id 
	WHERE Imei = NEW.Imei;

END//
DELIMITER ;
SET SQL_MODE=@OLDTMP_SQL_MODE;

-- Dumping structure for trigger rm_tracker.DataStaging_Update_LastUpdated
SET @OLDTMP_SQL_MODE=@@SQL_MODE, SQL_MODE='STRICT_TRANS_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_AUTO_CREATE_USER,NO_ENGINE_SUBSTITUTION';
DELIMITER //
CREATE DEFINER=`opiadmin`@`%` TRIGGER `DataStaging_Update_LastUpdated` BEFORE INSERT ON `DataStaging` FOR EACH ROW BEGIN

	IF NEW.Timestamp < (SELECT LastUpdated FROM Devices WHERE Imei = NEW.Imei) THEN
	
		-- Poistetaan arkistosta epÃ¤validi data
		DELETE FROM DataArchive
		WHERE Timestamp > NEW.Timestamp
			AND Imei = NEW.Imei; 

		-- Poistetaan matkat, joita ei enÃ¤Ã¤ ole
		DELETE p FROM Path p
		WHERE p.Imei = NEW.Imei
			AND p.`Group` = 0
			AND NOT EXISTS (
				SELECT * FROM DataArchive 
				WHERE Imei = NEW.Imei AND Path_Id = p.Id
				); 
		
		-- PÃ¤ivitetÃ¤Ã¤n viimeisin pÃ¤ivitysajankohta ja merkataan poistettavat tiedot
		UPDATE Devices 
		SET LastUpdated = NEW.Timestamp, DeleteNewer = '1' 
		WHERE Imei = NEW.Imei;
	
	END IF;

END//
DELIMITER ;
SET SQL_MODE=@OLDTMP_SQL_MODE;

/*!40103 SET TIME_ZONE=IFNULL(@OLD_TIME_ZONE, 'system') */;
/*!40101 SET SQL_MODE=IFNULL(@OLD_SQL_MODE, '') */;
/*!40014 SET FOREIGN_KEY_CHECKS=IFNULL(@OLD_FOREIGN_KEY_CHECKS, 1) */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40111 SET SQL_NOTES=IFNULL(@OLD_SQL_NOTES, 1) */;
