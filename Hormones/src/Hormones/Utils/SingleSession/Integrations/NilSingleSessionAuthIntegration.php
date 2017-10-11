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

namespace Hormones\Utils\SingleSession\Integrations;

use Hormones\Utils\SingleSession\SingleSessionAuthIntegration;
use Hormones\Utils\SingleSession\SingleSessionModule;
use pocketmine\event\player\PlayerLoginEvent;

class NilSingleSessionAuthIntegration extends SingleSessionAuthIntegration{
	private $plugin;

	public function __construct(SingleSessionModule $module){
		$this->plugin = $module->getPlugin();
		parent::__construct($module);
	}

	/**
	 * @param PlayerLoginEvent $event
	 *
	 * @priority        MONITOR
	 * @ignoreCancelled true
	 */
	public function e_onLogin(PlayerLoginEvent $event) : void{
		$this->onLoginImpl($event->getPlayer());
	}

	public function isLoggedIn(string $name) : bool{
		return $this->plugin->getServer()->getPlayerExact($name) !== null;
	}
}
