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

namespace Hormones;

use Hormones\Commands\HormonesStatusCommand;
use Hormones\Commands\StopNetworkCommand;
use Hormones\Commands\SwitchOrganCommand;
use Hormones\Commands\TissuesCommand;
use Hormones\Hormone\Artery;
use Hormones\Hormone\Kidney;
use Hormones\Lymph\LymphResult;
use Hormones\Lymph\LymphVessel;
use Hormones\TimingStats\TimerSet;
use Hormones\Utils\Balancer\BalancerModule;
use Hormones\Utils\Moderation\ModerationModule;
use Hormones\Utils\SingleSession\SingleSessionModule;
use Hormones\Utils\TransferOnly\TransferOnlyModule;
use libasynql\ClearMysqlTask;
use libasynql\DirectQueryMysqlTask;
use libasynql\MysqlCredentials;
use pocketmine\command\ConsoleCommandSender;
use pocketmine\plugin\PluginBase;
use pocketmine\scheduler\PluginTask;
use pocketmine\Server;
use pocketmine\utils\Config;
use pocketmine\utils\Utils;
use spoondetector\SpoonDetector;

class HormonesPlugin extends PluginBase{
	const DATABASE_MAJOR_VERSION = 1; // only to be bumped if backwards-incompatible
	const DATABASE_MINOR_VERSION = 1; // only to be bumped if plugin cannot work with last database

	const DATABASE_VERSION = (HormonesPlugin::DATABASE_MAJOR_VERSION << 16) | (HormonesPlugin::DATABASE_MINOR_VERSION << 0);

	/** @var Config|null */
	private $myConfig;
	/** @var \mysqli */
	private $mysqli;

	/** @var MysqlCredentials */
	private $credentials;
	/** @var int */
	private $organId;
	/** @var string */
	private $organName;
	/** @var string */
	private $serverId;
	/** @var string */
	private $visibleAddress;
	/** @var string */
	private $serverDisplayName;

	private $lymphResult;

	/** @var BalancerModule */
	private $balancerModule;
	/** @var int|null */
	private $softSlotsLimit;
	/** @var ModerationModule */
	private $moderationModule;
	/** @var SingleSessionModule */
	private $singleSessionModule;
	/** @var TransferOnlyModule */
	private $transferOnlyModule;

	/** @var callable[] */
	private $arteryDiastoleHandlers = [];

	/** @var TimerSet */
	private $timers;
	/** @var int */
	private $lastArterialHormoneId;

	public static function getInstance(Server $server) : HormonesPlugin{
		/** @noinspection PhpIncompatibleReturnTypeInspection */
		return $server->getPluginManager()->getPlugin("Hormones");
	}

	public function onLoad() : void{
		$opts = getopt("", ["hormones.data_folder:"]);
		if(isset($opts["hormones.data_folder"])){
			$dataFolder = rtrim(realpath($opts["hormones.data_folder"]), "/\\") . "/";
			$class = new \ReflectionClass(PluginBase::class);
			$prop = $class->getProperty("dataFolder");
			$prop->setAccessible(true);
			$prop->setValue($this, $dataFolder);

			$prop = $class->getProperty("configFile");
			$prop->setAccessible(true);
			$prop->setValue($this, $dataFolder . "config.yml");
		}
	}

	public function onEnable() : void{
		SpoonDetector::printSpoon($this, 'spoon.txt');

		$this->saveDefaultConfig();

		if($this->getConfig()->get("Dear User") === 'Please delete this line after you have finished setting up the config file.'){
			$this->getLogger()->alert("Thank you for using Hormones. Please set up Hormones by editing the config file at " . realpath($this->getDataFolder() . "config.yml"));
			$this->getLogger()->alert("If you have finished editing the config file, please delete the \"Dear User\" line (usually line 2) in the config file.");
			$this->getLogger()->alert("Hormones will not be enabled until you have done so");

			$this->getServer()->getPluginManager()->disablePlugin($this);
			return;
		}
		if(strtolower($this->getConfig()->getNested("localize.organ")) === "last"){
			$this->getLogger()->critical("\"last\" is an invalid organ name!");
			$this->getServer()->getPluginManager()->disablePlugin($this);
		}

		$this->credentials = $cred = MysqlCredentials::fromArray($this->getConfig()->get("mysql"));
		$this->mysqli = $cred->newMysqli();
		if(!DatabaseSetup::setupDatabase($cred, $this, $organId, $this->mysqli)){
			$this->getServer()->getPluginManager()->disablePlugin($this);
			return;
		}
		$this->getLogger()->debug("Localizing...");
		$this->organName = $this->getConfig()->getNested("localize.organ");
		$this->organId = $organId;
		$this->serverId = $this->calcServerId();
		$this->visibleAddress = $this->getConfig()->getNested("localize.address", "auto");
		if($this->visibleAddress === "auto"){
			$this->getLogger()->notice("You did not set the server visible address, so Hormones is automatically detecting your external IP. This may take a few seconds. Please set localize.address in the Hormones config file to make this faster.");
			$this->visibleAddress = Utils::getIP();
		}elseif(HormonesPlugin::reserved_ip($this->visibleAddress)){
			$this->getLogger()->notice("The server visible address is set to $this->visibleAddress, which is an internal IP. Players may not be able to transfer to this server if you don't change it to an external IP.");
		}
		$this->serverDisplayName = $this->getConfig()->getNested("localize.name", "auto");
		if($this->serverDisplayName === "auto"){
			$this->serverDisplayName = $this->visibleAddress . ":" . $this->getServer()->getPort();
		}

		$this->getLogger()->debug("Scheduling tasks...");
		$this->getServer()->getScheduler()->scheduleAsyncTask(new LymphVessel($cred, $this));
		$this->getServer()->getScheduler()->scheduleAsyncTask(new Artery($cred, Artery::STARTUP_ID, $organId));
		$size = $this->getServer()->getScheduler()->getAsyncTaskPoolSize();
		for($i = 0; $i < $size; $i++){
			$this->getLogger()->debug("Initializing libasynql on async worker #$i");
			$this->getServer()->getScheduler()->scheduleAsyncTaskToWorker(new DirectQueryMysqlTask($this->getCredentials(), "SHOW SCHEMAS"), $i);
		}
		if($this->getServer()->getConfigBoolean("hormones.regular-debug", false)){
			$this->getServer()->getScheduler()->scheduleDelayedRepeatingTask(new class($this) extends PluginTask{
				private $i = 0;

				public function onRun(int $currentTick){
					$this->getOwner()->getServer()->dispatchCommand(new ConsoleCommandSender(), "status");
					$this->getOwner()->getServer()->dispatchCommand(new ConsoleCommandSender(), "hormones");
					++$this->i;
					if(($this->i & 7) === 7){
						$this->getOwner()->getServer()->getMemoryManager()->dumpServerMemory($this->getOwner()->getServer()->getDataPath() . "/dumpmem_{$this->i}", 48, 80);
					}
				}
			}, 1200, 1200);
		} // debug stuff

		Kidney::init($this);

		$this->getLogger()->debug("Registering commands");
		if($this->getConfig()->getNested("organicTransfer.enabled", true)){
			SwitchOrganCommand::registerOrganicStuff($this);
		}
		$this->getServer()->getCommandMap()->register("hormones", new StopNetworkCommand($this));
		$this->getServer()->getCommandMap()->register("hormones", new HormonesStatusCommand($this));
		$this->getServer()->getCommandMap()->register("hormones", new TissuesCommand($this));

		$this->timers = new TimerSet;

		$this->getLogger()->debug("Initializing modules");
		$this->balancerModule = new BalancerModule($this);
		$this->moderationModule = new ModerationModule($this);
		$this->singleSessionModule = new SingleSessionModule($this);
		$this->transferOnlyModule = new TransferOnlyModule($this);
	}

	public function onDisable() : void{
		if(isset($this->balancerModule)){
			$this->getBalancerModule()->onDisable();
		}
		if(isset($this->credentials)){
			ClearMysqlTask::closeAll($this, $this->credentials);
		}
	}

	public function getConfig() : Config{
		if(!isset($this->myConfig)){
			$this->myConfig = new Config($this->getDataFolder() . "config.yml");
		}
		return $this->myConfig;
	}

	public function connectMainThreadMysql() : \mysqli{
		return $this->mysqli;
	}

	private function calcServerId() : string{
		return md5($this->getServer()->getDataPath() . $this->getServer()->getIp() . $this->getServer()->getPort() . Utils::getMachineUniqueId()->toString());
	}

	public function getTissueId() : string{
		return $this->serverId;
	}

	public function getOrganName() : string{
		return $this->organName;
	}

	public function getOrganId() : int{
		return $this->organId;
	}

	public function getVisibleAddress() : string{
		return $this->visibleAddress;
	}

	public function getServerDisplayName() : string{
		return $this->serverDisplayName;
	}

	public function getCredentials() : MysqlCredentials{
		return $this->credentials;
	}

	public function getSoftSlotsLimit() : int{
		return $this->softSlotsLimit ?? ($this->softSlotsLimit =
				$this->getConfig()->getNested("balancer.playerSoftLimit", $this->getServer()->getMaxPlayers() - 2));
	}

	public function getLymphResult() : LymphResult{
		return $this->lymphResult;
	}

	public function setLymphResult(LymphResult $lymphResult) : void{
		$this->lymphResult = $lymphResult;
	}

	public function setLastArterialHormoneId(int $lastArterialHormoneId) : void{
		$this->lastArterialHormoneId = $lastArterialHormoneId;
	}

	public function getLastArterialHormoneId() : int{
		return $this->lastArterialHormoneId;
	}

	public function addDiastoleListener(callable $callable) : void{
		$this->arteryDiastoleHandlers[] = $callable;
	}

	public function onArteryDiastole() : void{
		$handlers = $this->arteryDiastoleHandlers;
		$this->arteryDiastoleHandlers = [];
		foreach($handlers as $handler){
			$handler();
		}
	}

	public function getTimers() : TimerSet{
		return $this->timers;
	}


	public function getBalancerModule() : BalancerModule{
		return $this->balancerModule;
	}

	public function getModerationModule() : ModerationModule{
		return $this->moderationModule;
	}

	public function getSingleSessionModule() : SingleSessionModule{
		return $this->singleSessionModule;
	}

	public function getTransferOnlyModule() : TransferOnlyModule{
		return $this->transferOnlyModule;
	}


	public static function setNthBit(int $n, int $bytes = 8) : string{
		$offset = $n >> 3;
		$byteArray = str_repeat("\0", $bytes);
		$byteArray{$bytes - 1 - $offset} = chr(1 << ($n & 7));
		return $byteArray;
	}

	/**
	 * @param string $ip
	 *
	 * @return bool
	 * @link http://stackoverflow.com/a/14125871
	 */
	public static function reserved_ip(string $ip) : bool{
		if($ip === "localhost"){
			return true;
		}
		$ip2long = ip2long($ip);
		if($ip2long === false){
			return false;
		}
		$reserved_ips = [ // not an exhaustive list
			'167772160' => 184549375,  /*    10.0.0.0 -  10.255.255.255 */
			'3232235520' => 3232301055, /* 192.168.0.0 - 192.168.255.255 */
			'2130706432' => 2147483647, /*   127.0.0.0 - 127.255.255.255 */
			'2851995648' => 2852061183, /* 169.254.0.0 - 169.254.255.255 */
			'2886729728' => 2887778303, /*  172.16.0.0 -  172.31.255.255 */
			'3758096384' => 4026531839, /*   224.0.0.0 - 239.255.255.255 */
		];

		$ip_long = sprintf('%u', $ip2long);

		foreach($reserved_ips as $ip_start => $ip_end){
			if(($ip_long >= $ip_start) && ($ip_long <= $ip_end)){
				return true;
			}
		}
		return false;
	}
}
