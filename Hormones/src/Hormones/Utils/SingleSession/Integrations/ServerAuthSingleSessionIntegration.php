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
use ServerAuth\Events\ServerAuthAuthenticateEvent;
use ServerAuth\ServerAuth;

class ServerAuthSingleSessionIntegration extends SingleSessionAuthIntegration{
	private $int;

	public function __construct(SingleSessionModule $module){
		$int = $module->getPlugin()->getServer()->getPluginManager()->getPlugin("ServerAuth");
		if(!($int instanceof ServerAuth) || !$int->isEnabled()){
			throw new \ClassNotFoundException();
		}
		$this->int = $int;
		parent::__construct($module);
	}

	/**
	 * @param ServerAuthAuthenticateEvent $event
	 *
	 * @priority        MONITOR
	 * @ignoreCancelled true
	 */
	public function e_onAuth(ServerAuthAuthenticateEvent $event) : void{
		$this->onLoginImpl($event->getPlayer());
	}

	public function isLoggedIn(string $name) : bool{
		$player = $this->int->getServer()->getPlayerExact($name);
		return $player !== null && $this->int->isPlayerAuthenticated($player);
	}
}
