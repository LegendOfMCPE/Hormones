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

namespace Hormones\Hormone;

use Hormones\Event\UnknownHormoneEvent;
use Hormones\Hormone\Defaults\StopServerHormone;
use Hormones\Hormone\Defaults\VerifyDatabaseVersionHormone;
use Hormones\HormonesPlugin;
use Hormones\Utils\Moderation\Hormones\BroadcastMessageHormone;
use Hormones\Utils\Moderation\Hormones\KickPlayerHormone;
use Hormones\Utils\Moderation\Hormones\PenaltyHormone;
use Hormones\Utils\SingleSession\Hormones\NotifyJoinHormone;
use Hormones\Utils\SingleSession\Hormones\PushPlayersHormone;
use Hormones\Utils\SingleSession\Hormones\SpecificBumpHormone;
use Hormones\Utils\TransferOnly\DeclareTransferHormone;

abstract class Hormone{
	public static $knownTypes = [ // I struggled so many times not to rename this to antigen...
		StopServerHormone::TYPE => StopServerHormone::class,
		VerifyDatabaseVersionHormone::TYPE => VerifyDatabaseVersionHormone::class,
		BroadcastMessageHormone::TYPE => BroadcastMessageHormone::class,
		PenaltyHormone::TYPE => PenaltyHormone::class,
		NotifyJoinHormone::TYPE => NotifyJoinHormone::class,
		PushPlayersHormone::TYPE => PushPlayersHormone::class,
		SpecificBumpHormone::TYPE => SpecificBumpHormone::class,
		DeclareTransferHormone::TYPE => DeclareTransferHormone::class,
		KickPlayerHormone::TYPE => KickPlayerHormone::class
	];

	private $hormoneId;
	/** @var string [8] a 64-bit byte-array bitmask */
	private $hormoneReceptors; // I wanted to make this an int, but then I considered it won't work on 32-bit systems
	private $hormoneCreationTime;
	private $hormoneExpiryTime;

	/**
	 * Only to be called from Artery.php
	 *
	 * @param HormonesPlugin $plugin
	 * @param array          $row
	 */
	public static function handleRow(HormonesPlugin $plugin, array $row) : void{
		if(isset(Hormone::$knownTypes[$row["type"]])){
			$class = Hormone::$knownTypes[$row["type"]];
			$hormone = new $class($row["receptors"]);
			$args = [$plugin];
		}else{
			$event = new UnknownHormoneEvent($plugin, $row["type"], $row["receptors"]);
			$plugin->getServer()->getPluginManager()->callEvent($event);
			$hormone = $event->getHormone();
			if($hormone === null){
				$plugin->getLogger()->error("Received hormone of unknown type: " . $row["type"]);
				return;
			}
			$args = $event->getRespondArgs();
		}
		/** @var Hormone $hormone */
		$hormone->hormoneId = $row["hormoneId"];
		$hormone->hormoneCreationTime = $row["creationTime"];
		$hormone->hormoneExpiryTime = $row["expiryTime"];
		$hormone->setData(json_decode($row["json"], true));
		$hormone->respond($args);
	}

	public function release(HormonesPlugin $plugin) : void{
		$plugin->getServer()->getScheduler()->scheduleAsyncTask(new Vein($plugin->getCredentials(), $this, $plugin));
	}

	/**
	 * Internal constructor. Subclasses MUST call this method.
	 *
	 * @param string|null $receptors the bitmask for organs to handle this hormone
	 * @param int         $lifetime  number of seconds that this hormone should persist.
	 */
	public function __construct(string $receptors = null, int $lifetime = 0){
		$this->hormoneReceptors = $receptors ?? str_repeat("\xFF", 8);
		$this->hormoneCreationTime = time();
		$this->hormoneExpiryTime = $this->hormoneCreationTime + $lifetime;
	}

	protected function enableAllOrgans() : void{
		$this->hormoneReceptors = str_repeat("\xFF", 8);
	}

	protected function disableReceptors() : void{
		$this->hormoneReceptors = str_repeat("\0", 8);
	}

	protected function enableOrgan(int $organId) : void{
		$this->hormoneReceptors |= HormonesPlugin::setNthBit($organId, 8);
	}

	protected function disableOrgan(int $organId) : void{
		$this->hormoneReceptors &= ~HormonesPlugin::setNthBit($organId, 8);
	}

	public abstract function getType() : string;

	public function getReceptors() : string{
		return $this->hormoneReceptors;
	}

	public function getCreationTime() : int{
		return $this->hormoneCreationTime;
	}

	public function getExpiryTime() : int{
		return $this->hormoneExpiryTime;
	}

	public function setExpiryTime(int $expiryTime) : void{
		$this->hormoneExpiryTime = $expiryTime;
	}

	public function setLifeTime(int $lifeTime) : void{
		$this->hormoneExpiryTime = $this->hormoneCreationTime + $lifeTime;
	}

	public function getLifeTime() : int{
		return $this->hormoneExpiryTime - $this->hormoneCreationTime;
	}

	public abstract function getData() : array;

	public function setData(array $data) : void{
		foreach($data as $k => $v){
			$this->{$k} = $v;
		}
	}

	public abstract function respond(array $args);

	/**
	 * This should only be called from LymphVessel.php
	 *
	 * @param int $hormoneId
	 */
	public function setHormoneId(int $hormoneId) : void{
		$this->hormoneId = $hormoneId;
	}
}
