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

namespace Hormones\Utils\Balancer\Event;

use Hormones\Event\HormonesEvent;
use Hormones\HormonesPlugin;
use Hormones\Lymph\AltServerObject;
use pocketmine\event\Cancellable;
use pocketmine\Player;

class PlayerBalancedEvent extends HormonesEvent implements Cancellable{
	public static $handlerList = null;

	/** @var Player */
	private $player;
	/** @var AltServerObject|null */
	private $targetServer;

	public function __construct(HormonesPlugin $plugin, Player $player, AltServerObject $targetServer = null){
		parent::__construct($plugin);
		$this->player = $player;
		$this->targetServer = $targetServer;
	}

	public function getPlayer() : Player{
		return $this->player;
	}

	/**
	 * @return AltServerObject|null
	 */
	public function getTargetServer() : ?AltServerObject{
		return $this->targetServer;
	}
}
