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

use Hormones\HormonesPlugin;
use libasynql\DirectQueryMysqlTask;
use pocketmine\scheduler\PluginTask;

class UpdateAccountStateTask extends PluginTask{
	public function onRun(int $ticks) : void{
		/** @var HormonesPlugin $plugin */
		$plugin = $this->getOwner();
		foreach($plugin->getServer()->getOnlinePlayers() as $player){
			if(!$player->isOnline()){
				continue;
			}
			$plugin->getServer()->getScheduler()->scheduleAsyncTask(new DirectQueryMysqlTask($plugin->getCredentials(),
				"INSERT INTO hormones_accstate (username, lastOrgan, lastTissue, lastOnline)
				VALUES (?, ?, ?, CURRENT_TIMESTAMP)
			ON DUPLICATE KEY UPDATE lastOrgan = ?, lastTissue = ?, lastOnline = CURRENT_TIMESTAMP", [
					["s", strtolower($player->getName())],
					["i", $plugin->getOrganId()],
					["s", $plugin->getTissueId()],
					["i", $plugin->getOrganId()],
					["s", $plugin->getTissueId()],
				]));
		}
	}
}
