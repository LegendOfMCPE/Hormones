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

namespace Hormones\Utils\Balancer;

use Hormones\Commands\FindOrganicTissueTask;
use Hormones\HormonesPlugin;
use Hormones\Utils\Balancer\Event\PlayerBalancedEvent;
use libasynql\result\MysqlErrorResult;
use libasynql\result\MysqlResult;
use libasynql\result\MysqlSelectResult;
use pocketmine\event\Listener;
use pocketmine\event\player\PlayerLoginEvent;
use pocketmine\event\server\QueryRegenerateEvent;
use pocketmine\Player;

class BalancerModule implements Listener{
	const ALWAYS_TRANSFER_NONE = -1;
	const ALWAYS_TRANSFER_LAST = -2;

	private $plugin;

	/** @var string */
	private $queryPlayerCount;

	/** @var bool */
	private $fullTransfer;
	/** @var int */
	private $fullLimit;
	/** @var string[] */
	private $fullExempts;

	/** @var bool */
	private $stopTransfer;

	/** @var string|null */
	private $alwaysTransferName;
	/** @var int */
	private $alwaysTransferOrgan;
	/** @var bool */
	private $alwaysKick;
	/** @var string[] */
	private $alwaysExempts;
	/** @var string|null */
	private $alwaysFallbackName;
	/** @var int */
	private $alwaysFallbackOrgan;
	/** @var bool */
	private $logLast;

	public function __construct(HormonesPlugin $plugin){
		$this->plugin = $plugin;

		$this->queryPlayerCount = strtolower($this->getPlugin()->getConfig()->getNested("balancer.queryPlayerCount", "organic"));

		$this->fullTransfer = (bool) $this->getPlugin()->getConfig()->getNested("balancer.fullTransfer", $this->getPlugin()->getConfig()->getNested("balancer.enabled", true));
		$this->fullLimit = (int) $this->getPlugin()->getConfig()->getNested("balancer.playerSoftLimit", 18);
		$this->fullExempts = array_fill_keys(array_map("strtolower",
			$this->getPlugin()->getConfig()->getNested("balancer.fullExemptPlayers", $this->getPlugin()->getConfig()->getNested("balancer.exemptPlayers", []))), true);

		$this->stopTransfer = (bool) $this->getPlugin()->getConfig()->getNested("balancer.stopTransfer", false);

		$this->alwaysTransferName = $this->getPlugin()->getConfig()->getNested("balancer.alwaysTransfer", false);
		if(!$this->alwaysTransferName){
			$this->alwaysTransferName = null;
		}else{
			$this->alwaysTransferName = strtolower((string) $this->alwaysTransferName);
		}
		if($this->alwaysTransferName === null){
			$this->alwaysTransferOrgan = self::ALWAYS_TRANSFER_NONE;
		}elseif($this->alwaysTransferName === "last"){
			$this->alwaysTransferOrgan = self::ALWAYS_TRANSFER_LAST;
		}else{
			$result = MysqlResult::executeQuery($this->getPlugin()->connectMainThreadMysql(), /** @lang MySQL */
				"SELECT organId FROM hormones_organs WHERE name = ?", [["s", $this->alwaysTransferName]]);
			if(!($result instanceof MysqlSelectResult)){
				assert($result instanceof MysqlErrorResult);
				throw $result->getException();
			}
			if(!isset($result->rows[0])){
				throw new \RuntimeException("Unknown organ in balancer.alwaysTransfer");
			}
			$this->alwaysTransferOrgan = (int) $result->rows[0]["organId"];
		}
		$this->alwaysKick = (bool) $this->getPlugin()->getConfig()->getNested("balancer.alwaysKick", false);
		$this->alwaysExempts = array_fill_keys(array_map("strtolower",
			$this->getPlugin()->getConfig()->getNested("balancer.alwaysExemptPlayers", [])), true);
		$this->alwaysFallbackName = $this->getPlugin()->getConfig()->getNested("balancer.alwaysFallback", false) ?: null;
		if($this->alwaysFallbackName === null){
			$this->alwaysFallbackOrgan = -1;
		}else{
			$result = MysqlResult::executeQuery($this->getPlugin()->connectMainThreadMysql(), /** @lang MySQL */
				"SELECT organId FROM hormones_organs WHERE name = ?", [["s", $this->alwaysFallbackName]]);
			if(!($result instanceof MysqlSelectResult)){
				assert($result instanceof MysqlErrorResult);
				throw $result->getException();
			}
			if(!isset($result->rows[0])){
				throw new \RuntimeException("Unknown organ in balancer.alwaysFallback");
			}
		}
		$this->logLast = (bool) $this->getPlugin()->getConfig()->getNested("balancer.logLast", true);
		if($this->logsLast()){
			$this->getPlugin()->getServer()->getScheduler()->scheduleRepeatingTask(new UpdateAccountStateTask($this->getPlugin()), 10);
		}

		$this->getPlugin()->getServer()->getPluginManager()->registerEvents($this, $plugin);
	}

	/**
	 * @param QueryRegenerateEvent $event
	 *
	 * @priority LOW
	 */
	public function e_onQueryRegen(QueryRegenerateEvent $event) : void{
		switch($this->getQueryPlayerCountMode()){
			case "tissue":
				break;
			case "organic":
				$event->setPlayerCount($this->plugin->getLymphResult()->organicOnlineSlots);
				$event->setMaxPlayerCount($this->plugin->getLymphResult()->organicTotalSlots);
				break;
			case "network":
				$event->setPlayerCount($this->plugin->getLymphResult()->networkOnlineSlots);
				$event->setMaxPlayerCount($this->plugin->getLymphResult()->networkTotalSlots);
		}
	}

	/**
	 * @param PlayerLoginEvent $event
	 *
	 * @priority        HIGH
	 * @ignoreCancelled true
	 */
	public function e_onLogin(PlayerLoginEvent $event) : void{
		// We can't check permissions here because permission plugins have not defined them yet

		$player = $event->getPlayer();
		if($this->getAlwaysTransferDestination() >= 0 && !$this->hasAlwaysExempt($player->getName())){
			$task = new FindOrganicTissueTask($this->getPlugin(), $player, "AlwaysTransfer", $this->getAlwaysTransferDestinationName(), $this->getAlwaysTransferDestination());
			$this->getPlugin()->getServer()->getScheduler()->scheduleAsyncTask($task);
		}elseif($this->getAlwaysTransferDestination() === self::ALWAYS_TRANSFER_LAST && !$this->hasAlwaysExempt($player->getName())){ // last
			$task = new FindLastOrganicTissueTask($this, $player, "AlwaysTransfer (Last)");
			$this->getPlugin()->getServer()->getScheduler()->scheduleAsyncTask($task);
		}

		if($this->isTransferUponFull()){
			if(count($this->getPlugin()->getServer()->getOnlinePlayers()) >= $this->getFullLimit()){ // getOnlinePlayers() doesn't include the current player
				if($this->getPlugin()->getLymphResult()->altServer === null){
					$event->setCancelled();
					$event->setKickMessage("All {$this->getPlugin()->getOrganName()} servers are full!");
					return;
				}
				$balEv = new PlayerBalancedEvent($this->getPlugin(), $player, $this->getPlugin()->getLymphResult()->altServer);
				if($this->hasFullExempt($player->getName())){
					$balEv->setCancelled();
				}
				$this->getPlugin()->getServer()->getPluginManager()->callEvent($balEv);

				if(!$balEv->isCancelled()){
					$this->getPlugin()->getLogger()->info("Transferring {$player->getName()} to alt server: {$balEv->getTargetServer()->displayName}");
					$player->transfer($balEv->getTargetServer()->address, $balEv->getTargetServer()->port,
						"Server full! Transferring you to {$balEv->getTargetServer()->displayName}");
					$event->setCancelled();
				}else{
					$this->getPlugin()->getLogger()->debug("Event cancelled");
				}
			}
		}
	}

	public function onDisable() : void{
		$result = MysqlResult::executeQuery($this->getPlugin()->connectMainThreadMysql(), /** @lang MySQL */
			"SELECT ip, port, maxSlots, maxSlots - usedSlots availSlots FROM hormones_tissues
				WHERE organId = ? AND tissueId = ? AND maxSlots - usedSlots > 0 AND UNIX_TIMESTAMP() - UNIX_TIMESTAMP(lastOnline) < 5
				ORDER BY availSlots DESC, maxSlots ASC",
			[["i", $this->getPlugin()->getOrganId()], ["s", $this->getPlugin()->getTissueId()]]);
		if(!($result instanceof MysqlSelectResult)){
			assert($result instanceof MysqlErrorResult);
			throw  $result->getException();
		}
		$result->fixTypes([
			"ip" => MysqlSelectResult::TYPE_STRING,
			"port" => MysqlSelectResult::TYPE_INT,
			"maxSlots" => MysqlSelectResult::TYPE_INT,
			"availSlots" => MysqlSelectResult::TYPE_INT
		]);
		/** @var Player[] $players */
		$players = array_reverse($this->getPlugin()->getServer()->getOnlinePlayers());
		foreach($players as $player){
			$player->transfer($result->rows[0]["ip"], $result->rows[0]["port"], "Server stop transfer");
			if((--$result->rows[0]["availSlots"]) === 0){
				array_shift($result->rows);
				if(count($result->rows) === 0){
					break;
				}
			}
		}
	}

	public function getQueryPlayerCountMode() : string{
		return $this->queryPlayerCount;
	}

	public function isTransferUponFull() : bool{
		return $this->fullTransfer;
	}

	public function getFullLimit() : int{
		return $this->fullLimit;
	}

	/**
	 * @return string[]
	 */
	public function getFullExempts() : array{
		return $this->fullExempts;
	}

	public function hasFullExempt(string $name) : bool{
		return isset($this->fullExempts[strtolower($name)]);
	}

	public function isTransferUponStop() : bool{
		return $this->stopTransfer;
	}

	public function getAlwaysTransferDestination() : int{
		return $this->alwaysTransferOrgan;
	}

	/**
	 * @return string|null
	 */
	public function getAlwaysTransferDestinationName() : ?string{
		return $this->alwaysTransferName;
	}

	/**
	 * @return string[]
	 */
	public function getAlwaysExempts() : array{
		return $this->alwaysExempts;
	}

	public function hasAlwaysExempt(string $name) : bool{
		return isset($this->alwaysExempts[strtolower($name)]);
	}

	public function getAlwaysFallbackDestination() : int{
		return $this->alwaysFallbackOrgan;
	}

	/**
	 * @return string|null
	 */
	public function getAlwaysFallbackName() : ?string{
		return $this->alwaysFallbackName;
	}

	public function logsLast() : bool{
		return $this->logLast;
	}

	public function getPlugin() : HormonesPlugin{
		return $this->plugin;
	}
}
