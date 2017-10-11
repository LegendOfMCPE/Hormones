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
use pocketmine\Player;
use pocketmine\Server;
use pocketmine\utils\TextFormat;

class FindOrganicTissueTask extends QueryMysqlTask{
	/** @var string */
	protected $organName;
	/** @var int|null */
	protected $organId;
	/** @var string */
	protected $tissueId;
	/** @var string */
	protected $cause;

	public function __construct(HormonesPlugin $plugin, Player $player, string $cause, string $organName, int $organId = null, callable $onUnknownOrgan = null, callable $onServersFull = null){
		parent::__construct($plugin->getCredentials(), [$plugin, $player, $onUnknownOrgan, $onServersFull]);
		$this->organName = $organName;
		$this->organId = $organId;
		$this->tissueId = $plugin->getTissueId();
		$this->cause = $cause;
	}

	protected function execute() : void{
		$db = $this->getMysqli();
		if($this->organId === null){
			$result = MysqlResult::executeQuery($db, "SELECT organId FROM hormones_organs WHERE name = ?", [["s", $this->organName]]);
			if($result instanceof MysqlSelectResult){
				if(count($result->rows) === 0){
					$this->setResult(true);
					return;
				}
				$this->organId = (int) $result->rows[0]["organId"];
			}else{
				assert($result instanceof MysqlErrorResult);
				throw $result->getException();
			}
		}
		$this->setResult(MysqlResult::executeQuery($db, "SELECT ip, port, displayName FROM hormones_tissues
				WHERE organId = ? AND UNIX_TIMESTAMP() - UNIX_TIMESTAMP(lastOnline) < 5 AND maxSlots > usedSlots AND tissueId <> ?
				ORDER BY (maxSlots - usedSlots) DESC, maxSlots ASC LIMIT 1",
			[["i", $this->organId], ["s", $this->tissueId]]));
	}

	public function onCompletion(Server $server) : void{
		/** @var HormonesPlugin $plugin */
		/** @var Player $player */
		/** @var callable|null $onUnknownOrgan */
		/** @var callable|null $onServersFull */
		list($plugin, $player, $onUnknownOrgan, $onServersFull) = $this->fetchLocal($server);
		if(!$plugin->isEnabled() || !$player->isOnline()){
			return;
		}

		$result = $this->getResult();
		if($result === true){
			assert(is_callable($onUnknownOrgan));
			$onUnknownOrgan();
			return;
		}elseif($result instanceof MysqlSelectResult){
			if(count($result->rows) === 1){
				$player->transfer($result->rows[0]["ip"], $result->rows[0]["port"],
					"$this->cause: Transferring you to the least full $this->organName server: " . $result->rows[0]["displayName"]);
			}else{
				if(is_callable($onServersFull)){
					$onServersFull();
				}else{
					$player->sendMessage(TextFormat::YELLOW . "All $this->organName servers are full/offline!");
				}
			}
		}else{
			assert($result instanceof MysqlErrorResult);
			throw $result->getException();
		}
	}
}
