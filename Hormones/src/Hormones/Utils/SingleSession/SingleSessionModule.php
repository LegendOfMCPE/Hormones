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

use Hormones\HormonesPlugin;
use Hormones\Utils\SingleSession\Hormones\NotifyJoinHormone;
use Hormones\Utils\SingleSession\Integrations\HereAuthSingleSessionIntegration;
use Hormones\Utils\SingleSession\Integrations\NilSingleSessionAuthIntegration;
use Hormones\Utils\SingleSession\Integrations\PiggyAuthSingleSessionIntegration;
use Hormones\Utils\SingleSession\Integrations\ServerAuthSingleSessionIntegration;
use Hormones\Utils\SingleSession\Integrations\SimpleAuthSingleSessionIntegration;
use pocketmine\event\Listener;
use pocketmine\event\player\PlayerLoginEvent;

class SingleSessionModule implements Listener{
	const MODE_OFF = 0;
	const MODE_PUSH = 1;
	const MODE_BUMP = 2;
	const MODE_IP_PUSH = SingleSessionModule::MODE_PUSH | SingleSessionModule::MODE_BUMP;

	/** @var HormonesPlugin */
	private $plugin;
	/** @var SingleSessionAuthIntegration */
	private $integration;

	/** @var int */
	private $mode;

	public function __construct(HormonesPlugin $plugin){
		$this->plugin = $plugin;

		static $modeMap = [
			"" => SingleSessionModule::MODE_OFF,
			"off" => SingleSessionModule::MODE_OFF,
			"push" => SingleSessionModule::MODE_PUSH,
			"bump" => SingleSessionModule::MODE_BUMP,
			"ip-push" => SingleSessionModule::MODE_IP_PUSH
		];
		if(isset($modeMap[strtolower($modeStr = (string) $plugin->getConfig()->getNested("singleSession.mode", false))])){
			$this->mode = $modeMap[strtolower($modeStr)];
		}else{
			$plugin->getLogger()->warning("Unknown singleSession mode \"$modeStr\", using default value (\"off\")");
			$this->mode = SingleSessionModule::MODE_OFF;
		}

		$authIntegration = $plugin->getConfig()->getNested("singleSession.authIntegration", "none");
		try{
			switch(strtolower($authIntegration)){
				case "simpleauth":
					$this->integration = new SimpleAuthSingleSessionIntegration($this);
					break;
				case "serverauth":
					$this->integration = new ServerAuthSingleSessionIntegration($this);
					break;
				case "hereauth":
					$this->integration = new HereAuthSingleSessionIntegration($this);
					break;
				case "piggyauth":
					$this->integration = new PiggyAuthSingleSessionIntegration($this);
					break;
				case "none":
				case "nil":
					throw new \RuntimeException("nil");
				default:
					throw new \RuntimeException;
			}
		}catch(\RuntimeException $e){
			if($e->getMessage() !== "nil"){
				$plugin->getLogger()->error("Failed to use authIntegration \"$authIntegration\". " .
					"Using default auth integration (\"none\") instead.");
			}
			$this->integration = new NilSingleSessionAuthIntegration($this);
		}
		$plugin->getServer()->getPluginManager()->registerEvents($this->integration, $plugin);

		if(($this->mode & SingleSessionModule::MODE_BUMP) !== 0){
			$this->getPlugin()->getServer()->getPluginManager()->registerEvents($this, $plugin);
		}
	}

	/**
	 * @param PlayerLoginEvent $event
	 *
	 * @priority        MONITOR
	 * @ignoreCancelled true
	 */
	public function e_onLogin(PlayerLoginEvent $event) : void{
		$hormone = new NotifyJoinHormone();
		$hormone->username = $event->getPlayer()->getName();
		$hormone->ip = $event->getPlayer()->getAddress();
		$hormone->tissueId = $this->plugin->getTissueId();
		$hormone->release($this->plugin);
	}

	public function getPlugin() : HormonesPlugin{
		return $this->plugin;
	}

	public function getIntegration() : SingleSessionAuthIntegration{
		return $this->integration;
	}

	public function getMode() : int{
		return $this->mode;
	}
}
