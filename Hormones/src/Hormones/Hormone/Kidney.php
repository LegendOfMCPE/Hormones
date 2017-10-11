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
use libasynql\DirectQueryMysqlTask;
use libasynql\result\MysqlErrorResult;
use libasynql\result\MysqlSuccessResult;
use pocketmine\scheduler\PluginTask;

/**
 * Deletes old hormones
 */
class Kidney extends PluginTask{
	private $expiry;

	public static function init(HormonesPlugin $plugin) : void{
		if($plugin->getConfig()->getNested("kidney.enabled", true)){
			$kidney = new Kidney($plugin);
			$kidney->expiry = $plugin->getConfig()->getNested("kidney.expiry", 600);
			$plugin->getServer()->getScheduler()->scheduleRepeatingTask($kidney, $plugin->getConfig()->getNested("kidney.interval", 600) * 20);
		}
	}

	public function onRun(int $currentTick) : void{
		/** @var HormonesPlugin $plugin */
		$plugin = $this->getOwner();
		$plugin->getServer()->getScheduler()->scheduleAsyncTask(new DirectQueryMysqlTask($plugin->getCredentials(),
			"DELETE FROM hormones_blood WHERE UNIX_TIMESTAMP(expiry) < UNIX_TIMESTAMP() - ?", [["i", $this->expiry]],
			function($result) use ($plugin){
				if($result instanceof MysqlErrorResult){
					$plugin->getLogger()->logException($result->getException());
				}elseif($result instanceof MysqlSuccessResult){
					$plugin->getLogger()->info("[Kidney] Cleaned {$result->affectedRows} expired hormones");
				}
			}));
	}
}
