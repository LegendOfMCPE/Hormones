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

namespace Hormones\Utils\Balancer;

use Hormones\Commands\FindOrganicTissueTask;
use libasynql\result\MysqlErrorResult;
use libasynql\result\MysqlResult;
use libasynql\result\MysqlSelectResult;
use pocketmine\Player;

class FindLastOrganicTissueTask extends FindOrganicTissueTask{
	private $fallbackId;
	private $fallbackName;
	private $playerName;

	public function __construct(BalancerModule $module, Player $player, string $cause){
		$this->fallbackId = $module->getAlwaysFallbackDestination();
		$this->fallbackName = $module->getAlwaysFallbackName();
		$this->playerName = $player->getName();
		parent::__construct($module->getPlugin(), $player, $cause, "", null, function() use ($player){
			$player->kick("All $this->fallbackName servers are full/offline!");
		});
	}

	protected function execute() : void{
		$db = $this->getMysqli();
		$result = MysqlResult::executeQuery($db, "SELECT hormones_organs.name, hormones_organs.organId FROM hormones_accstate
				INNER JOIN hormones_organs ON hormones_accstate.lastOrgan = hormones_organs.organId
				WHERE hormones_accstate.username = ?", [["s", strtolower($this->playerName)]]);
		if(!($result instanceof MysqlSelectResult)){
			assert($result instanceof MysqlErrorResult);
			throw $result->getException();
		}
		if(isset($result->rows[0])){
			$this->organId = (int)$result->rows[0]["organId"];
			$this->organName = $result->rows[0]["name"];
			parent::execute();
			$result = $this->getResult();
			if(!($result instanceof MysqlSelectResult and count($result->rows) === 0)){
				return;
			}
			// all tissues in last organ are full, go to fallback
		}
		// else no last organ, also go to fallback
		$this->organId = $this->fallbackId;
		$this->organName = $this->fallbackName;
		parent::execute();
	}
}
