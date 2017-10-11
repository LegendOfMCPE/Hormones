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

namespace Hormones\Utils\SingleSession;

use Hormones\Utils\SingleSession\Hormones\PushPlayersHormone;
use pocketmine\event\Listener;
use pocketmine\Player;

abstract class SingleSessionAuthIntegration implements Listener{
	/** @var SingleSessionModule */
	private $module;

	protected function __construct(SingleSessionModule $module){
		$this->module = $module;
	}

	public function onLoginImpl(Player $player) : void{
		if(($this->module->getMode() & SingleSessionModule::MODE_PUSH) !== 0){
			$hormone = new PushPlayersHormone();
			$hormone->username = $player->getName();
			$hormone->sourceTissueId = $this->module->getPlugin()->getTissueId();
			$hormone->sourceTissueName = $this->module->getPlugin()->getServerDisplayName();
			$hormone->ip = $player->getAddress();
			$hormone->release($this->module->getPlugin());
		}
	}

	public abstract function isLoggedIn(string $name) : bool;
}
