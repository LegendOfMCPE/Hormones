<?php

/*
 *
 * Hormones
 *
 * Copyright (C) 2017 SOFe
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
*/

declare(strict_types=1);

namespace Hormones;

use Hormones\Hormone\Defaults\VerifyDatabaseVersionHormone;
use libasynql\MysqlCredentials;
use libasynql\PingMysqlTask;
use libasynql\result\MysqlResult;
use libasynql\result\MysqlSelectResult;
use Logger;

class DatabaseSetup{
	/**
	 * @internal Only to be called from HormonesPlugin.php
	 *
	 * @param MysqlCredentials $cred
	 * @param HormonesPlugin   $plugin
	 * @param int              &$organId
	 * @param \mysqli|null     $mysqli
	 *
	 * @return bool
	 */
	public static function setupDatabase(MysqlCredentials $cred, HormonesPlugin $plugin, &$organId, \mysqli $mysqli) : bool{
		$plugin->getLogger()->debug("Checking database...");
		$mysqli->query(/** @lang MySQL */
			"CREATE TABLE IF NOT EXISTS hormones_metadata (name VARCHAR(20) PRIMARY KEY, val VARCHAR(20))");
		$afterUnlocks = [];
		$mysqli->query(/** @lang MySQL */
			"LOCK TABLES hormones_metadata WRITE, hormones_organs WRITE, hormones_organs AS organs_2 WRITE, hormones_blood WRITE, hormones_tissues WRITE, hormones_accstate WRITE"); // this should lock all startup operations by Hormones

		$result = MysqlResult::executeQuery($mysqli, "SELECT val FROM hormones_metadata WHERE name = ?", [["s", "version"]]);
		if($result instanceof MysqlSelectResult and count($result->rows) > 0){
			$version = (int) $result->rows[0]["val"];
			if($version < HormonesPlugin::DATABASE_VERSION){
				$major = $version >> 16;
				$minor = $version & 0xFFFF;
				$plugin->getLogger()->notice("Updating the database from $major.$minor to " . HormonesPlugin::DATABASE_MAJOR_VERSION . "." .
					HormonesPlugin::DATABASE_MINOR_VERSION . "! Other servers in the network might become incompatible and require updating.");


				if($major === HormonesPlugin::DATABASE_MAJOR_VERSION){
					if($minor <= 0){
						foreach([
							        /** @lang MySQL */
							        "SET FOREIGN_KEY_CHECKS=0",
							        "ALTER TABLE hormones_organs MODIFY organId TINYINT UNSIGNED NOT NULL",
							        /** @lang MySQL */
							        "SET FOREIGN_KEY_CHECKS=1",
							        "CREATE TABLE hormones_accstate (
										username   VARCHAR(20) PRIMARY KEY,
										lastOrgan  TINYINT UNSIGNED DEFAULT NULL,
										lastTissue CHAR(32)         DEFAULT NULL,
										lastOnline TIMESTAMP        DEFAULT CURRENT_TIMESTAMP,
										FOREIGN KEY (lastOrgan) REFERENCES hormones_organs (organId)
											ON UPDATE CASCADE
											ON DELETE SET NULL,
										FOREIGN KEY (lastTissue) REFERENCES hormones_tissues (tissueId)
											ON UPDATE CASCADE
											ON DELETE SET NULL
									)"
						        ] as $query){
							$plugin->getLogger()->debug($query);
							$mysqli->query($query);
						}
						$afterUnlocks[] = /** @lang MySQL */
							"CREATE FUNCTION organ_name_to_id(inName VARCHAR(64))
								RETURNS TINYINT
							DETERMINISTIC
								BEGIN
									DECLARE id TINYINT UNSIGNED;
									DECLARE empty_id TINYINT UNSIGNED;
									SELECT organId
									INTO @id
									FROM hormones_organs
									WHERE hormones_organs.name = inName;
									IF ROW_COUNT() = 1
									THEN
										-- just select, no need to change stuff
										RETURN @id;
									ELSE
										IF (SELECT COUNT(*)
										    FROM hormones_organs) = 64
										THEN
											-- table full, try to empty some rows
											DELETE FROM hormones_organs
											WHERE NOT EXISTS(SELECT tissueId
											                 FROM hormones_tissues
											                 WHERE hormones_tissues.organId = hormones_organs.organId);
											IF ROW_COUNT() = 0
											THEN
												SIGNAL SQLSTATE '45000'
												SET MESSAGE_TEXT = 'Too many organs; consider deleting unused ones';
											END IF;
										END IF;
										-- find the first empty row
										IF NOT EXISTS(SELECT name
										              FROM hormones_organs
										              WHERE hormones_organs.organId = 0)
										THEN
											-- our gap-finding query doesn't work if 0 i
											INSERT INTO hormones_organs (organId, name) VALUES (0, inName);
											RETURN 0;
										ELSE
											-- detect gaps
											SELECT (hormones_organs.organId + 1)
											INTO @empty_id
											FROM hormones_organs LEFT JOIN hormones_organs organs_2 ON organs_2.organId = hormones_organs.organId + 1
											WHERE organs_2.organId IS NULL
											ORDER BY hormones_organs.organId ASC
											LIMIT 1;
											IF ROW_COUNT() = 1
											THEN
												INSERT INTO hormones_organs (organId, name) VALUES (@empty_id, inName);
												RETURN @empty_id;
											ELSE
												SIGNAL SQLSTATE '45000'
												SET MESSAGE_TEXT = 'Assertion error: organ count is not 64, but no gaps found and organId=0 is not null';
											END IF;
										END IF;
									END IF;
								END";
					}

					MysqlResult::executeQuery($mysqli, /** @lang MySQL */
						"UPDATE hormones_metadata SET val = ? WHERE name = ?", [["s", HormonesPlugin::DATABASE_VERSION], ["s", "version"]]);
				}else{
					// TODO update
					$hormone = new VerifyDatabaseVersionHormone;
					$hormone->pluginVersion = $plugin->getDescription()->getVersion();
					$hormone->dbVersion = HormonesPlugin::DATABASE_VERSION;
					$hormone->release($plugin);
				}
			}elseif(($version >> 16) > (HormonesPlugin::DATABASE_VERSION >> 16)){
				$plugin->getLogger()->critical("Please update the plugin! (You already updated it on some other servers)");
				$plugin->getServer()->getPluginManager()->disablePlugin($plugin);
				return false;
			}else{
				$plugin->getLogger()->debug("Database OK");
			}
		}else{
			$plugin->getLogger()->info("Thanks for using Hormones the first time. Setting up database tables...");
			DatabaseSetup::initialSetup($mysqli, $plugin->getLogger());

			$op = $mysqli->prepare("INSERT INTO hormones_metadata (val, name) VALUES (?, ?)");
			$value = HormonesPlugin::DATABASE_VERSION;
			$name = "version";
			$op->bind_param("ss", $value, $name);
			$op->execute();
		}

		$organName = $plugin->getConfig()->getNested("localize.organ");

		$mysqli->query(/** @lang MySQL */
			"UNLOCK TABLES");
		foreach($afterUnlocks as $query){
			$plugin->getLogger()->debug($query);
			$mysqli->query($query);
		}

		$result = MysqlResult::executeQuery($mysqli, /** @lang MySQL */
			"SELECT organ_name_to_id(?) organId", [["s", $organName]]);
		if($result instanceof MysqlSelectResult and isset($result->rows[0])){
			$organId = (int) $result->rows[0]["organId"];
			$plugin->getLogger()->debug("Evaluated organ ID for $organName: $organId");
		}else{
			throw new \RuntimeException("Failed to retrieve organ ID");
		}


		PingMysqlTask::init($plugin, $cred);

		return true;
	}

	private static function initialSetup(\mysqli $mysqli, Logger $logger) : void{
		$queries = [
			"CREATE TABLE hormones_organs (
				organId TINYINT UNSIGNED PRIMARY KEY,
				name VARCHAR(64) UNIQUE
			) AUTO_INCREMENT = 0",
			/** @lang MySQL */
			"CREATE TRIGGER organs_organId_limit BEFORE INSERT ON hormones_organs FOR EACH ROW
			BEGIN
				IF (NEW.organId < 0 OR NEW.organId > 63) THEN
					SIGNAL SQLSTATE '45000'
						SET MESSAGE_TEXT = 'organ flag is beyond range';
				END IF;
			END",
			/** @lang MySQL */
			"CREATE FUNCTION organ_name_to_id(inName VARCHAR(64))
				RETURNS TINYINT
			DETERMINISTIC
				BEGIN
					DECLARE id TINYINT UNSIGNED;
					DECLARE empty_id TINYINT UNSIGNED;
					SELECT organId
					INTO @id
					FROM hormones_organs
					WHERE hormones_organs.name = inName;
					IF ROW_COUNT() = 1
					THEN
						-- just select, no need to change stuff
						RETURN @id;
					ELSE
						IF (SELECT COUNT(*)
						    FROM hormones_organs) = 64
						THEN
							-- table full, try to empty some rows
							DELETE FROM hormones_organs
							WHERE NOT EXISTS(SELECT tissueId
							                 FROM hormones_tissues
							                 WHERE hormones_tissues.organId = hormones_organs.organId);
							IF ROW_COUNT() = 0
							THEN
								SIGNAL SQLSTATE '45000'
								SET MESSAGE_TEXT = 'Too many organs; consider deleting unused ones';
							END IF;
						END IF;
						-- find the first empty row
						IF NOT EXISTS(SELECT name
						              FROM hormones_organs
						              WHERE hormones_organs.organId = 0)
						THEN
							-- our gap-finding query doesn't work if 0 i
							INSERT INTO hormones_organs (organId, name) VALUES (0, inName);
							RETURN 0;
						ELSE
							-- detect gaps
							SELECT (hormones_organs.organId + 1)
							INTO @empty_id
							FROM hormones_organs LEFT JOIN hormones_organs organs_2 ON organs_2.organId = hormones_organs.organId + 1
							WHERE organs_2.organId IS NULL
							ORDER BY hormones_organs.organId ASC
							LIMIT 1;
							IF ROW_COUNT() = 1
							THEN
								INSERT INTO hormones_organs (organId, name) VALUES (@empty_id, inName);
								RETURN @empty_id;
							ELSE
								SIGNAL SQLSTATE '45000'
								SET MESSAGE_TEXT = 'Assertion error: organ count is not 64, but no gaps found and organId=0 is not null';
							END IF;
						END IF;
					END IF;
				END",
			"CREATE TABLE hormones_blood (
				hormoneId BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
				type VARCHAR(64) NOT NULL,
				receptors BIT(64) DEFAULT x'FFFFFFFFFFFFFFFF',
				creation TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
				expiry TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
				json TEXT
			)",
			"CREATE TABLE hormones_tissues (
				tissueId CHAR(32) PRIMARY KEY,
				organId TINYINT UNSIGNED NOT NULL,
				lastOnline TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
				usedSlots SMALLINT UNSIGNED,
				maxSlots SMALLINT UNSIGNED,
				ip VARCHAR(68),
				port SMALLINT UNSIGNED,
				hormonesVersion MEDIUMINT,
				displayName VARCHAR(100),
				processId SMALLINT UNSIGNED,
				FOREIGN KEY (organId) REFERENCES hormones_organs(organId) ON UPDATE CASCADE ON DELETE RESTRICT
			)",
			"CREATE TABLE hormones_accstate (
				username   VARCHAR(20) PRIMARY KEY,
				lastOrgan  TINYINT UNSIGNED DEFAULT NULL,
				lastTissue CHAR(32)         DEFAULT NULL,
				lastOnline TIMESTAMP        DEFAULT CURRENT_TIMESTAMP,
				FOREIGN KEY (lastOrgan) REFERENCES hormones_organs (organId)
					ON UPDATE CASCADE
					ON DELETE SET NULL,
				FOREIGN KEY (lastTissue) REFERENCES hormones_tissues (tissueId)
					ON UPDATE CASCADE
					ON DELETE SET NULL
			)",
		];
		foreach($queries as $query){
			$logger->debug(substr($query, 0, 30));
			$result = $mysqli->query($query);
			if($result !== true){
				$logger->error("Failed to execute database setup query: $mysqli->error\n$query");
			}
		}
	}
}
