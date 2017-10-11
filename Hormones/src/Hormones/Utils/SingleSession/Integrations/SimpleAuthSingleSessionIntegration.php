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
use SimpleAuth\event\PlayerAuthenticateEvent;
use SimpleAuth\SimpleAuth;

class SimpleAuthSingleSessionIntegration extends SingleSessionAuthIntegration{
	private $int;

	public function __construct(SingleSessionModule $module){
		$int = $module->getPlugin()->getServer()->getPluginManager()->getPlugin("SimpleAuth");
		if(!($int instanceof SimpleAuth) || !$int->isEnabled()){
			throw new \ClassNotFoundException();
		}
		$this->int = $int;
		parent::__construct($module);
	}

	/**
	 * @param PlayerAuthenticateEvent $event
	 *
	 * @priority        MONITOR
	 * @ignoreCancelled true
	 */
	public function e_onAuth(PlayerAuthenticateEvent $event) : void{
		$this->onLoginImpl($event->getPlayer());
	}

	public function isLoggedIn(string $name) : bool{
		$player = $this->int->getServer()->getPlayerExact($name);
		return $player !== null && $this->int->isPlayerAuthenticated($player);
	}
}
