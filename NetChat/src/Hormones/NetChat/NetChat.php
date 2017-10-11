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

namespace Hormones\NetChat;

use Hormones\Event\UnknownHormoneEvent;
use Hormones\HormonesPlugin;
use Hormones\NetChat\Hormones\ChatEventHormone;
use libasynql\DirectQueryMysqlTask;
use libasynql\result\MysqlErrorResult;
use libasynql\result\MysqlResult;
use libasynql\result\MysqlSelectResult;
use pocketmine\event\Listener;
use pocketmine\event\player\PlayerLoginEvent;
use pocketmine\event\player\PlayerQuitEvent;
use pocketmine\plugin\PluginBase;

class NetChat extends PluginBase implements Listener{
	/** @var HormonesPlugin */
	private $hormones;
	/** @var NetChatSession[] */
	private $sessions = [];
	/** @var NetChatChannel[] */
	private $loadedChannels = []; // TODO clean loaded channels periodically

	public function onEnable() : void{
		$this->hormones = HormonesPlugin::getInstance($this->getServer()) or assert(false, "Hormones not loaded");
		// TODO check version

		$this->hormones->getServer()->getPluginManager()->registerEvents($this, $this);
	}

	/**
	 * @param PlayerLoginEvent $event
	 * @priority        MONITOR
	 * @ignoreCancelled true
	 */
	public function e_onLogin(PlayerLoginEvent $event) : void{
		$session = new NetChatSession($this, $event->getPlayer());
		$this->sessions[$event->getPlayer()->getId()] = $session;
	}

	public function e_onQuit(PlayerQuitEvent $event) : void{
		if(isset($this->sessions[$event->getPlayer()->getId()])){
			$this->sessions[$event->getPlayer()->getId()]->finalize();
			unset($this->sessions[$event->getPlayer()->getId()]);
		}
	}

	public function e_identifyHormone(UnknownHormoneEvent $event) : void{
		if($event->getType() === ChatEventHormone::TYPE){
			$event->setHormone(new ChatEventHormone($event->getReceptors()));
			$event->setRespondArgs([$this]);
		}
	}

	public function getLoadedChannel(string $name) : ?NetChatChannel{
		return $this->loadedChannels[mb_strtolower($name)] ?? null;
	}

	public function lazyGetChannel(string $name, callable $return, callable $notFound = null) : void{
		$lowName = mb_strtolower($name);
		if(isset($lowName)){
			$return($this->loadedChannels[$lowName]);
		}else{
			$task = new DirectQueryMysqlTask($this->hormones->getCredentials(),
				"SELECT name, visible, invite, passphrase, permission, defaultPerm FROM hormones_netchat_channels WHERE name = ?", [
					["s", $name]
				], function(MysqlResult $result) use ($lowName, $return, $notFound){
					if(!($result instanceof MysqlSelectResult)){
						assert($result instanceof MysqlErrorResult);
						throw $result->getException();
					}
					if(count($result->rows) === 0){
						if($notFound !== null){
							$notFound();
						}
						return;
					}
					$result->fixTypes([
						"name" => MysqlSelectResult::TYPE_STRING,
						"visible" => MysqlSelectResult::TYPE_BOOL,
						"invite" => MysqlSelectResult::TYPE_BOOL,
						"defaultPerm" => MysqlSelectResult::TYPE_INT,
						// passphrase and permission nullable
					]);
					$row = $result->rows[0];
					$channel = new NetChatChannel($row["name"], $row["visible"], $row["invite"],
						$row["passphrase"] ?? null, $row["permission"] ?? null, $row["defaultPerm"]);
					$return($channel);
				});
			$this->hormones->getServer()->getScheduler()->scheduleAsyncTask($task);
		}
	}

	public function getHormones() : HormonesPlugin{
		return $this->hormones;
	}

	/**
	 * @return NetChatSession[]
	 */
	public function getSessions() : array{
		return $this->sessions;
	}
}
