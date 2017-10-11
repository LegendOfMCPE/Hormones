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

namespace Hormones\Utils\Moderation\Hormones;

use Hormones\Hormone\Hormone;
use Hormones\HormonesPlugin;

class BroadcastMessageHormone extends Hormone{
	const TYPE = "Hormones.Moderation.BroadcastMessage";

	/** @var string */
	public $message;
	/** @var string */
	public $permissions = "";

	public function getType() : string{
		return BroadcastMessageHormone::TYPE;
	}

	public function getData() : array{
		return [
			"message" => $this->message,
			"permissions" => $this->permissions
		];
	}

	public function respond(array $args) : void{
		/** @var HormonesPlugin $plugin */
		list($plugin) = $args;

		if($this->permissions === ""){
			$plugin->getServer()->broadcastMessage($this->message, $plugin->getServer()->getOnlinePlayers());
		}else{
			$plugin->getServer()->broadcast($this->message, $this->permissions);
		}
	}
}
