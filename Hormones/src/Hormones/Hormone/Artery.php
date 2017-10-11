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

namespace Hormones\Hormone;

use Hormones\HormonesPlugin;
use libasynql\MysqlCredentials;
use libasynql\QueryMysqlTask;
use libasynql\result\MysqlErrorResult;
use libasynql\result\MysqlResult;
use libasynql\result\MysqlSelectResult;
use pocketmine\Server;

class Artery extends QueryMysqlTask{
	const STARTUP_ID = -2;

	private $hormonesAfter;
	private $organId;
	private $objectCreated;

	private $virtualMax;

	public function __construct(MysqlCredentials $credentials, int $hormonesAfter, int $organId){
		parent::__construct($credentials);
		$this->hormonesAfter = $hormonesAfter;
		$this->organId = $organId;
		$this->objectCreated = microtime(true);
	}

	protected function execute() : void{
		$bitmask = HormonesPlugin::setNthBit($this->organId, 8);
		if($this->hormonesAfter !== Artery::STARTUP_ID){
			$after = "hormoneId > ?";
			$args = [["i", $this->hormonesAfter], ["s", bin2hex($bitmask)]];
		}else{
			$after = "UNIX_TIMESTAMP(expiry) > UNIX_TIMESTAMP()";
			$args = [["s", bin2hex($bitmask)]];
		}

		$this->setResult(MysqlResult::executeQuery($db = $this->getMysqli(), $query = "
			SELECT hormoneId, type, receptors, UNIX_TIMESTAMP(creation) creationTime, UNIX_TIMESTAMP(expiry) expiryTime, json
				FROM hormones_blood WHERE $after AND ((receptors & CONV(?, 16, 10)) > 0)",
			$args));

		if($this->hormonesAfter === Artery::STARTUP_ID){
			$result = MysqlResult::executeQuery($db, "SELECT IFNULL(MAX(hormoneId), -1) max FROM hormones_blood", []);
			if($result instanceof MysqlSelectResult){
				$this->virtualMax = (int) $result->rows[0]["max"];
			}else{
				assert($result instanceof MysqlErrorResult);
				throw $result->getException();
			}
		}
	}

	public function onCompletion(Server $server) : void{
		$plugin = HormonesPlugin::getInstance($server);
		if(!$plugin->isEnabled()){
			return;
		}
		$lastHormoneId = $this->hormonesAfter;
		$result = $this->getResult();
		if(!$plugin->isEnabled()){
			return;
		}
		if($result instanceof MysqlErrorResult){
			$plugin->getLogger()->logException($result->getException());
			return;
		}elseif($result instanceof MysqlSelectResult){
			$result->fixTypes([
				"hormoneId" => MysqlSelectResult::TYPE_INT,
				"type" => MysqlSelectResult::TYPE_STRING,
				"receptors" => MysqlSelectResult::TYPE_STRING,
				"creationTime" => MysqlSelectResult::TYPE_INT,
				"expiryTime" => MysqlSelectResult::TYPE_INT,
				"json" => MysqlSelectResult::TYPE_STRING
			]);
			foreach($result->rows as $row){
				Hormone::handleRow($plugin, $row);
				$lastHormoneId = $row["hormoneId"];
			}
			$plugin->onArteryDiastole();
			$plugin->getTimers()->arteryNet->addDatum($result->getTiming());
		}
		if($this->hormonesAfter === Artery::STARTUP_ID){
			$lastHormoneId = $this->virtualMax;
		}
		$plugin->getTimers()->arteryCycle->addDatum(microtime(true) - $this->objectCreated);
		$plugin->setLastArterialHormoneId($lastHormoneId);
		$server->getScheduler()->scheduleAsyncTask(new Artery($this->getCredentials(), $lastHormoneId, $this->organId));
	}
}
