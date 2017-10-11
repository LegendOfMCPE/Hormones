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

namespace Hormones\Lymph;

use Exception;
use Hormones\HormonesPlugin;
use libasynql\MysqlCredentials;
use libasynql\QueryMysqlTask;
use libasynql\result\MysqlErrorResult;
use libasynql\result\MysqlResult;
use libasynql\result\MysqlSelectResult;
use pocketmine\Server;

class LymphVessel extends QueryMysqlTask{
	private $tissueId;
	private $organId;
	private $usedSlots;
	private $maxSlots;
	private $ip;
	private $port;
	private $hormonesVersion;
	private $displayName;
	private $processId;

	private $objectCreated;

	public function __construct(MysqlCredentials $credentials, HormonesPlugin $plugin){
		parent::__construct($credentials);
		$this->tissueId = $plugin->getTissueId();
		$this->organId = $plugin->getOrganId();
		$this->usedSlots = count($plugin->getServer()->getOnlinePlayers());
		$this->maxSlots = $plugin->getSoftSlotsLimit();
		$this->ip = $plugin->getVisibleAddress();
		$this->port = $plugin->getServer()->getPort();
		$this->hormonesVersion = HormonesPlugin::DATABASE_VERSION;
		$this->displayName = $plugin->getServerDisplayName();
		$this->processId = getmypid(); // make sure this is called from the main thread

		$this->objectCreated = microtime(true);
	}

	protected function execute() : void{
		$mysqli = $this->getMysqli();
		$stmt = $mysqli->prepare(/** @lang MySQL */
			"INSERT INTO hormones_tissues
			(tissueId, organId, usedSlots, maxSlots, ip, port, hormonesVersion, displayName, processId) VALUES 
			(?       , ?      , ?        , ?       , ? , ?   , ?              , ?          , ?) ON DUPLICATE KEY UPDATE
			organId = ?, usedSlots = ?, maxSlots = ?, ip = ?, port = ?, hormonesVersion = ?, displayName = ?, processId = ?,
			lastOnline = CURRENT_TIMESTAMP");
		$stmt->bind_param(str_replace(" ", "",
			"s         i        i          i         s   i     i                s            i" .
			"          i        i          i         s   i     i                s            i"),
			$this->tissueId,
			$this->organId, $this->usedSlots, $this->maxSlots, $this->ip, $this->port, $this->hormonesVersion, $this->displayName, $this->processId,
			$this->organId, $this->usedSlots, $this->maxSlots, $this->ip, $this->port, $this->hormonesVersion, $this->displayName, $this->processId);
		$stmt->execute();
		$stmt->close();

		$statResult = MysqlResult::executeQuery($mysqli, /** @lang MySQL */
			"SELECT tissues, online, total, organId IS NULL isRollup FROM
			(SELECT organId, COUNT(*) tissues, IFNULL(SUM(usedSlots), 0) online, IFNULL(SUM(maxSlots), 0) total
				FROM hormones_tissues WHERE UNIX_TIMESTAMP() - UNIX_TIMESTAMP(lastOnline) < 10 GROUP BY organId WITH ROLLUP) t
			WHERE organId IS NULL OR organId = ?",
			[["i", $this->organId]]);
		$altResult = MysqlResult::executeQuery($mysqli, /** @lang MySQL */
			"SELECT t2.ip, t2.port, t2.displayName FROM (
				SELECT GREATEST(0, MAX(maxSlots - usedSlots)) maxAvail FROM hormones_tissues
					WHERE organId = ? AND UNIX_TIMESTAMP() - UNIX_TIMESTAMP(lastOnline) < 10 AND tissueId <> ?
			) t INNER JOIN hormones_tissues t2 ON t.maxAvail = t2.maxSlots - t2.usedSlots
				AND organId = ? AND UNIX_TIMESTAMP() - UNIX_TIMESTAMP(lastOnline) < 10 AND tissueId <> ? LIMIT 1", [
				["i", $this->organId], ["s", $this->tissueId],
				["i", $this->organId], ["s", $this->tissueId]
			]);
		// maxSlots - usedSlots = available slots
		// ORDER BY maxSlots => minimum percentage load

		if($statResult instanceof MysqlSelectResult){
			$statResult->fixTypes([
				"isRollup" => MysqlSelectResult::TYPE_BOOL,
				"tissues" => MysqlSelectResult::TYPE_INT,
				"online" => MysqlSelectResult::TYPE_INT,
				"total" => MysqlSelectResult::TYPE_INT,
			]);
			if(count($statResult->rows) !== 2){
				var_dump($statResult->rows);
				$this->setResult(new Exception("Lymph query returns no rows"));
			}else{
				if($altResult instanceof MysqlSelectResult and count($altResult->rows) === 1){
					$altResult->fixTypes([
						"ip" => MysqlSelectResult::TYPE_STRING,
						"port" => MysqlSelectResult::TYPE_INT,
						"displayName" => MysqlSelectResult::TYPE_STRING,
					]);
					$row2 = $altResult->rows[0];
					$altServer = new AltServerObject();
					$altServer->address = $row2["ip"];
					$altServer->port = $row2["port"];
					$altServer->displayName = $row2["displayName"];
				}else{
					$altServer = null;
				}
				foreach($statResult->rows as $row){
					if($row["isRollup"]){
						$rollupRow = $row;
					}else{
						$organicRow = $row;
					}
				}
				if(isset($organicRow, $rollupRow)){
					$lr = new LymphResult();
					$lr->netTime = $statResult->getTiming();
					$lr->organicTissueCount = $organicRow["tissues"];
					$lr->organicOnlineSlots = $organicRow["online"];
					$lr->organicTotalSlots = $organicRow["total"];
					$lr->networkTissueCount = $rollupRow["tissues"];
					$lr->networkOnlineSlots = $rollupRow["online"];
					$lr->networkTotalSlots = $rollupRow["total"];
					$lr->altServer = $altServer;
					$this->setResult($lr);
				}else{
					var_dump($statResult->rows, $organicRow ?? null, $rollupRow ?? null);
					$this->setResult(new Exception("Lymph query returns no rows"));
				}
			}

		}else{
			assert($statResult instanceof MysqlErrorResult);
			$this->setResult($statResult->getException());
		}
	}

	public function onCompletion(Server $server) : void{
		$plugin = HormonesPlugin::getInstance($server);
		if(!$plugin->isEnabled()){
			return;
		}
		$result = $this->getResult();
		if($result instanceof Exception){
			$plugin->getLogger()->logException($result);
		}elseif($result instanceof LymphResult){
			$plugin->setLymphResult($result);
			$plugin->getTimers()->lymphNet->addDatum($result->netTime);
		}

		$plugin->getTimers()->lymphCycle->addDatum(microtime(true) - $this->objectCreated);
		$server->getScheduler()->scheduleAsyncTask(new LymphVessel($this->getCredentials(), $plugin));
	}
}
