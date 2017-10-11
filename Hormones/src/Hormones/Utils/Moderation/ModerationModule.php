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

namespace Hormones\Utils\Moderation;

use Hormones\Commands\FindOrganicTissueTask;
use Hormones\HormonesPlugin;
use Hormones\Utils\Moderation\Commands\BroadcastCommand;
use Hormones\Utils\Moderation\Commands\PenaltyCommand;
use pocketmine\event\Listener;
use pocketmine\event\player\PlayerChatEvent;
use pocketmine\event\player\PlayerJoinEvent;
use pocketmine\event\player\PlayerPreLoginEvent;

class ModerationModule implements Listener{
	/** @var HormonesPlugin */
	private $plugin;

	/** @var Penalty[][] */
	private $penaltyGroups = [Penalty::TYPE_BAN => [], Penalty::TYPE_MUTE => []];

	public function __construct(HormonesPlugin $plugin){
		$this->plugin = $plugin;
		$this->plugin->getServer()->getPluginManager()->registerEvents($this, $plugin);
		$this->plugin->getServer()->getCommandMap()->registerAll("hormones", [
			new BroadcastCommand($plugin, false),
			new BroadcastCommand($plugin, true),
			new PenaltyCommand($plugin, Penalty::TYPE_BAN, "nban", "Ban"),
			new PenaltyCommand($plugin, Penalty::TYPE_MUTE, "nmute", "Mute"),
		]);
	}

	/**
	 * @param PlayerChatEvent $event
	 *
	 * @priority        LOW
	 * @ignoreCancelled true
	 */
	public function e_onChat(PlayerChatEvent $event) : void{
		foreach($this->penaltyGroups[Penalty::TYPE_MUTE] as $k => $penalty){
			if($penalty->hasExpired()){
				unset($this->penaltyGroups[Penalty::TYPE_MUTE][$k]);
			}elseif($penalty->target->matchesPlayer($event->getPlayer())){
				$event->setCancelled();
				$event->getPlayer()->sendMessage($penalty->getNotifyMessage());
				break;
			}
		}
	}

	/**
	 * @param PlayerPreLoginEvent $event
	 *
	 * @priority        HIGH
	 * @ignoreCancelled true
	 */
	public function e_onPreLogin(PlayerPreLoginEvent $event) : void{
		foreach($this->penaltyGroups[Penalty::TYPE_BAN] as $k => $penalty){
			if($penalty->hasExpired()){
				unset($this->penaltyGroups[Penalty::TYPE_BAN][$k]);
			}elseif($penalty->target->matchesPlayer($player = $event->getPlayer())){
				if($organName = $this->plugin->getConfig()->getNested("moderation.banTransfer", false)){
					$this->plugin->getServer()->getScheduler()->scheduleAsyncTask(new FindOrganicTissueTask(
						$this->plugin, $event->getPlayer(), "BanTransfer", $organName, null, function() use ($organName){
						$this->plugin->getLogger()->critical("Unknown hormone $organName as defined in config.yml:moderation.banTransfer");
					}, function() use ($player, $penalty){
						$player->kick($penalty->getNotifyMessage());
					}
					));
				}
				$event->setCancelled();
				$event->setKickMessage($penalty->getNotifyMessage());
				break;
			}
		}
	}

	public function e_onJoin(PlayerJoinEvent $event) : void{
		foreach($this->penaltyGroups as $type => $penalties){
			foreach($penalties as $k => $penalty){
				if($penalty->hasExpired()){
					unset($this->penaltyGroups[$type][$k]);
				}elseif($penalty->target->matchesPlayer($event->getPlayer())){
					$event->getPlayer()->sendMessage($penalty->getNotifyMessage());
				}
			}
		}
	}

	public function addPenaltySession(Penalty $session) : void{
		$this->penaltyGroups[$session->type][spl_object_hash($session)] = $session;
	}
}
