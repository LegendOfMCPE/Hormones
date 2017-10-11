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
use PiggyAuth\Events\PlayerLoginEvent as PiggyAuthLoginEvent;
use PiggyAuth\Main as PiggyAuth;
use PiggyAuth\Sessions\PiggyAuthSession;

class PiggyAuthSingleSessionIntegration extends SingleSessionAuthIntegration{
	private $int;

	public function __construct(SingleSessionModule $module){
		$int = $module->getPlugin()->getServer()->getPluginManager()->getPlugin("PiggyAuth");
		if(!($int instanceof PiggyAuth) || !$int->isEnabled()){
			throw new \RuntimeException;
		}
		$this->int = $int;
		parent::__construct($module);
	}

	/**
	 * @param \PiggyAuth\Events\PlayerLoginEvent $event
	 *
	 * @priority        MONITOR
	 * @ignoreCancelled true
	 */
	public function e_onAuth(PiggyAuthLoginEvent $event) : void{
		$this->onLoginImpl($event->getPlayer());
	}

	public function isLoggedIn(string $name) : bool{
		$player = $this->int->getServer()->getPlayerExact($name);
		if($player === null){
			return false;
		}
		$session = $this->int->getSessionManager()->getSession($player);
		return $session instanceof PiggyAuthSession && $session->isAuthenticated();
	}
}
