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
use libasynql\result\MysqlSuccessResult;
use pocketmine\Server;

class Vein extends QueryMysqlTask{
	private $hormone;

	public function __construct(MysqlCredentials $credentials, Hormone $hormone, HormonesPlugin $plugin){
		parent::__construct($credentials, [$hormone, $plugin]);
		$this->hormone = serialize([
			"type" => $hormone->getType(),
			"receptors" => $hormone->getReceptors(),
			"creationTime" => $hormone->getCreationTime(),
			"expiryTime" => $hormone->getExpiryTime(),
			"data" => $hormone->getData()
		]);
	}

	public function execute() : void{
		$hormone = unserialize($this->hormone);

		$this->setResult(MysqlResult::executeQuery($this->getMysqli(), "INSERT INTO hormones_blood 
				(type, receptors, creation, expiry, json) VALUES (?, ?, FROM_UNIXTIME(?), FROM_UNIXTIME(?), ?)", [
			["s", $hormone["type"]],
			["s", $hormone["receptors"]],
			["i", $hormone["creationTime"]],
			["i", $hormone["expiryTime"]],
			["s", json_encode($hormone["data"])]
		]));
	}

	public function onCompletion(Server $server) : void{
		$result = $this->getResult();
		/** @var Hormone $hormone */
		/** @var HormonesPlugin $plugin */
		list($hormone, $plugin) = $this->fetchLocal($server);
		if($result instanceof MysqlErrorResult){
			$plugin = HormonesPlugin::getInstance($server);
			$plugin->getLogger()->logException($result->getException());
		}elseif($result instanceof MysqlSuccessResult){
			$hormone->setHormoneId($result->insertId);
			$plugin->getTimers()->veinUp->addDatum($result->getTiming());
		}
	}
}
