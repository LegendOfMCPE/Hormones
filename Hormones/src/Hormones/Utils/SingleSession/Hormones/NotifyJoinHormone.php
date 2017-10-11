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

namespace Hormones\Utils\SingleSession\Hormones;

use Hormones\Hormone\Hormone;
use Hormones\HormonesPlugin;
use Hormones\Utils\SingleSession\SingleSessionModule;

class NotifyJoinHormone extends Hormone{
	const TYPE = "Hormones.SingleSession.NotifyJoin";

	public $username;
	public $ip;
	public $tissueId;

	// TODO estimate what happens if servers have different singleSession.mode values

	public function getType() : string{
		return NotifyJoinHormone::TYPE;
	}

	public function getData() : array{
		return [
			"username" => $this->username,
			"ip" => $this->ip,
			"tissueId" => $this->tissueId
		];
	}

	public function respond(array $args) : void{
		/** @var HormonesPlugin $plugin */
		list($plugin) = $args;

		if((($mode = $plugin->getSingleSessionModule()->getMode()) & SingleSessionModule::MODE_BUMP) !== 0){
			$player = $plugin->getServer()->getPlayerExact($this->username);
			if($plugin->getSingleSessionModule()->getIntegration()->isLoggedIn($this->username)){
				if($mode === SingleSessionModule::MODE_BUMP or $player->getAddress() !== $this->ip){
					// TODO check login timestamp
					// do bump!
					$hormone = new SpecificBumpHormone();
					$hormone->username = $this->username;
					$hormone->receptingTissueId = $this->tissueId;
					$hormone->bumpedFromTissueName = $plugin->getServerDisplayName();
					$hormone->release($plugin);
				}
			}
		}
	}
}
