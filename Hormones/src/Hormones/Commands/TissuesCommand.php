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
use pocketmine\command\CommandSender;

class TissuesCommand extends HormonesCommand{
	public function __construct(HormonesPlugin $plugin){
		parent::__construct($plugin, "tissues", "See all online servers", "/servers", ["servers"]);
	}

	public function execute(CommandSender $sender, string $commandLabel, array $args) : void{
		$this->getPlugin()->getServer()->getScheduler()->scheduleAsyncTask(new TissueListTask($this->getPlugin(), $sender));
	}
}
