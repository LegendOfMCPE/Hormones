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

namespace Hormones\Utils\TransferOnly;

use Hormones\Hormone\Vein;
use Hormones\HormonesPlugin;
use libasynql\result\MysqlErrorResult;
use libasynql\result\MysqlResult;
use libasynql\result\MysqlSelectResult;
use pocketmine\event\Listener;
use pocketmine\event\player\PlayerJoinEvent;
use pocketmine\event\player\PlayerLoginEvent;
use pocketmine\event\player\PlayerTransferEvent;
use pocketmine\Player;
use pocketmine\scheduler\PluginTask;

class TransferOnlyModule implements Listener{
	const MODE_OFF = 0;
	const MODE_LOGIN = 1;
	const MODE_LOGIN_SYNC = 2;
	const MODE_SINGLE_DIASTOLE = 3;
	const MODE_DOUBLE_DIASTOLE = 4;
	const MODE_JOIN = 5;

	const HORMONE_TIMEOUT = 120;
	const KICK_MESSAGE = "You cannot join this server without being transferred from another server in this network";

	/** @var HormonesPlugin */
	private $plugin;
	/** @var int */
	private $mode;
	/** @var bool */
	private $doesAsyncDeclaration = false;
	/** @var string[] */
	private $aka = [];

	/** @var PreTransferDeclaration[] */
	private $decls = [];

	public function __construct(HormonesPlugin $plugin){
		$this->plugin = $plugin;
		$plugin->getServer()->getPluginManager()->registerEvents($this, $plugin);
		static $modeMap = [
			"off" => TransferOnlyModule::MODE_OFF,
			"login" => TransferOnlyModule::MODE_LOGIN,
			"login.sync" => TransferOnlyModule::MODE_LOGIN_SYNC,
			"diastole.single" => TransferOnlyModule::MODE_SINGLE_DIASTOLE,
			"diastole.double" => TransferOnlyModule::MODE_DOUBLE_DIASTOLE,
			"join" => TransferOnlyModule::MODE_JOIN,
		];
		$this->mode = $modeMap[$plugin->getConfig()->getNested("transferOnly.mode", "off")] ?? TransferOnlyModule::MODE_OFF;
		$this->doesAsyncDeclaration = $plugin->getConfig()->getNested("transferOnly.asyncDeclaration", false);
		foreach($plugin->getConfig()->getNested("transferOnly.aka", []) as $item){
			$this->aka[] = strtolower(strpos($item, ":") === false ? ($item . ":" . $plugin->getServer()->getPort()) : $item);
		}
	}

	public function isEnabled() : bool{
		return $this->mode !== TransferOnlyModule::MODE_OFF;
	}

	public function getPlugin() : HormonesPlugin{
		return $this->plugin;
	}

	/**
	 * @param PlayerTransferEvent $event
	 *
	 * @priority        MONITOR
	 * @ignoreCancelled true
	 */
	public function e_onTransfer(PlayerTransferEvent $event) : void{
		$hormone = new DeclareTransferHormone(null, TransferOnlyModule::HORMONE_TIMEOUT);
		$hormone->username = $event->getPlayer()->getName();
		$hormone->userIp = $event->getPlayer()->getAddress();
		$hormone->destIp = $event->getAddress();
		$hormone->destPort = $event->getPort();
		if($this->doesAsyncDeclaration){
			$hormone->release($this->plugin);
		}else{
			$vein = new Vein($this->getPlugin()->getCredentials(), $hormone, $this->plugin);
			$vein->execute();
			$vein->onCompletion($this->plugin->getServer());
		}
	}

	/**
	 * @param PlayerLoginEvent $event
	 *
	 * @priority        LOW
	 * @ignoreCancelled false
	 *                  (Yes, false, to force memory clearing)
	 */
	public function e_onLogin(PlayerLoginEvent $event) : void{
		if($this->mode === TransferOnlyModule::MODE_LOGIN || $this->mode === TransferOnlyModule::MODE_LOGIN_SYNC){
			$player = $event->getPlayer();
			if($this->confirmTransfer($player)){
				return; // passed
			}
			if($this->mode === TransferOnlyModule::MODE_LOGIN_SYNC){
				$result = MysqlResult::executeQuery($this->plugin->getCredentials()->newMysqli(),
					"SELECT json FROM hormones_blood WHERE type = ? AND UNIX_TIMESTAMP() - UNIX_TIMESTAMP(creation) < ?",
					[["s", DeclareTransferHormone::class], ["i", TransferOnlyModule::HORMONE_TIMEOUT]]);
				if($result instanceof MysqlSelectResult){
					foreach($result->rows as $row){
						$json = $row["json"];
						/** @var \stdClass|DeclareTransferHormone $data */
						$data = json_decode($json);
						if($this->matchesAddress($data->destIp, $data->destPort) and
							$player->getName() === $data->username && $player->getName() === $data->userIp
						){
							return; // passed
						}
					}
				}else{
					assert($result instanceof MysqlErrorResult);
					throw $result->getException();
				}
			}
			$player->kick(TransferOnlyModule::KICK_MESSAGE, false);
		}elseif($this->mode === TransferOnlyModule::MODE_SINGLE_DIASTOLE){
			$player = $event->getPlayer();
			$this->getPlugin()->addDiastoleListener(function() use ($player){
				$this->confirmTransferOrKick($player);
			});
		}elseif($this->mode === TransferOnlyModule::MODE_DOUBLE_DIASTOLE){
			$player = $event->getPlayer();
			$this->getPlugin()->addDiastoleListener(function() use ($player){
				$this->getPlugin()->addDiastoleListener(function() use ($player){
					$this->confirmTransferOrKick($player);
				});
			});
		}
	}

	/**
	 * @param PlayerJoinEvent $event
	 */
	public function e_onJoin(PlayerJoinEvent $event) : void{
		if($this->mode === TransferOnlyModule::MODE_JOIN){
			$plugin = $this->getPlugin();
			$plugin->getServer()->getScheduler()->scheduleDelayedTask(new class($this, $event->getPlayer()) extends PluginTask{
				/** @var TransferOnlyModule */
				private $module;
				/** @var Player */
				private $player;

				public function __construct(TransferOnlyModule $module, Player $player){
					parent::__construct($module->getPlugin());
					$this->module = $module;
					$this->player = $player;
				}

				public function onRun(int $currentTick){
					$this->module->confirmTransferOrKick($this->player);
				}
			}, 1);
		}
	}

	public function confirmTransfer(Player $player) : bool{
		if(in_array($player->getAddress(), $this->getPlugin()->getConfig()->getNested("transferOnly.exemptAddresses", []))){
			return true;
		}
		foreach($this->decls as $i => $decl){
			if(time() - $decl->time > TransferOnlyModule::HORMONE_TIMEOUT){
				unset($this->decls[$i]);
				continue;
			}
			if($decl->username === $player->getName() and $decl->ip === $player->getAddress()){
				return true;
			}
		}
		return false;
	}

	public function confirmTransferOrKick(Player $player) : void{
		if(!$this->confirmTransfer($player)){
			$player->kick(TransferOnlyModule::KICK_MESSAGE, false);
		}
	}

	public function matchesAddress(string $destIp, int $destPort) : bool{
		return in_array(strtolower("$destIp:$destPort"), $this->aka);
	}

	public function declareTransfer(PreTransferDeclaration $decl) : void{
		$this->decls[spl_object_hash($decl)] = $decl;
	}
}
