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

namespace Hormones\Commands;

use Hormones\HormonesPlugin;
use libasynql\QueryMysqlTask;
use libasynql\result\MysqlErrorResult;
use libasynql\result\MysqlResult;
use libasynql\result\MysqlSelectResult;
use pocketmine\command\CommandSender;
use pocketmine\Server;
use pocketmine\utils\TextFormat;

class TissueListTask extends QueryMysqlTask{
	public function __construct(HormonesPlugin $plugin, CommandSender $sender){
		parent::__construct($plugin->getCredentials(), [$plugin, $sender]);
	}

	protected function execute() : void{
		$this->setResult(MysqlResult::executeQuery($this->getMysqli(), "SELECT
				hormones_organs.name organ, displayName, ip, port, usedSlots, maxSlots
			FROM hormones_tissues INNER JOIN hormones_organs ON hormones_tissues.organId = hormones_organs.organId
			WHERE UNIX_TIMESTAMP() - UNIX_TIMESTAMP(lastOnline) < 10
			ORDER BY hormones_tissues.organId", []));
	}

	public function onCompletion(Server $server) : void{
		/**
		 * @var HormonesPlugin $plugin
		 * @var CommandSender  $sender
		 */
		list($plugin, $sender) = $this->fetchLocal($server);
		if(!$plugin->isEnabled()){
			return;
		}

		$result = $this->getResult();
		if(!($result instanceof MysqlSelectResult)){
			assert($result instanceof MysqlErrorResult);
			throw $result->getException();
		}
		$result->fixTypes([
			"organ" => MysqlSelectResult::TYPE_STRING,
			"displayName" => MysqlSelectResult::TYPE_STRING,
			"ip" => MysqlSelectResult::TYPE_STRING,
			"port" => MysqlSelectResult::TYPE_INT,
			"usedSlots" => MysqlSelectResult::TYPE_INT,
			"maxSlots" => MysqlSelectResult::TYPE_INT,
		]);

		$lastOrgan = null;
		foreach($result->rows as $row){
			$row = (object) $row;
			$organ = $row->organ;
			if($organ !== $lastOrgan){
				$lastOrgan = $organ;
				$sender->sendMessage(TextFormat::AQUA . $organ . ":");
			}
			$addr = "$row->ip:$row->port";
			$sender->sendMessage(" " . TextFormat::GREEN . $row->displayName .
				($row->displayName !== $addr ? " ($addr) " : " ") .
				TextFormat::GOLD . "$row->usedSlots / $row->maxSlots");
		}
	}
}
