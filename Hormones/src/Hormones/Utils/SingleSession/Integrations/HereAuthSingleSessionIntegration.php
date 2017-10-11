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

use HereAuth\Event\HereAuthAuthenticationEvent;
use HereAuth\HereAuth;
use Hormones\Utils\SingleSession\SingleSessionAuthIntegration;
use Hormones\Utils\SingleSession\SingleSessionModule;

class HereAuthSingleSessionIntegration extends SingleSessionAuthIntegration{
	private $int;

	public function __construct(SingleSessionModule $module){
		try{
			$int = HereAuth::getInstance($module->getPlugin()->getServer());
			if($int === null){
				throw new \ClassNotFoundException();
			}
		}catch(\ClassNotFoundException $e){
			throw new \RuntimeException;
		}
		$this->int = $int;
		parent::__construct($module);
	}

	/**
	 * @param HereAuthAuthenticationEvent $event
	 *
	 * @priority        MONITOR
	 * @ignoreCancelled true
	 */
	public function e_onAuth(HereAuthAuthenticationEvent $event) : void{
		$this->onLoginImpl($event->getPlayer());
	}

	public function isLoggedIn(string $name) : bool{
		$user = $this->int->getUserByExactName($name);
		return $user !== null && $user->isPlaying();
	}
}
