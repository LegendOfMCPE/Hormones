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

use Hormones\Hormone\Defaults\StopServerHormone;
use Hormones\HormonesPlugin;
use pocketmine\command\CommandSender;

class StopNetworkCommand extends HormonesCommand{
	public function __construct(HormonesPlugin $plugin){
		parent::__construct($plugin, "stop-all", "Stop all currently-online servers in the network (may automatically restart)", "/nstop", ["nstop"]);
		$this->setPermission("hormones.admin.stop");
	}

	public function execute(CommandSender $sender, string $commandLabel, array $args) : bool{
		if(!$this->testPermission($sender)){
			return false;
		}
		$hormone = new StopServerHormone();
		$hormone->release($this->getPlugin());
		return true;
	}
}
