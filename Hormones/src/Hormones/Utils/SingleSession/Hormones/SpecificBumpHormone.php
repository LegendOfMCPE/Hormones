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

namespace Hormones\Utils\SingleSession\Hormones;

use Hormones\Hormone\Hormone;
use Hormones\HormonesPlugin;

class SpecificBumpHormone extends Hormone{
	const TYPE = "Hormones.SingleSession.SpecificBump";

	public $username;
	public $receptingTissueId;
	public $bumpedFromTissueName;

	public function getType() : string{
		return SpecificBumpHormone::TYPE;
	}

	public function getData() : array{
		return [
			"username" => $this->username,
			"tissueId" => $this->receptingTissueId,
			"bumpedFromTissueName" => $this->bumpedFromTissueName
		];
	}

	public function respond(array $args) : void{
		/** @var HormonesPlugin $plugin */
		list($plugin) = $args;
		if($plugin->getTissueId() === $this->receptingTissueId){
			$player = $plugin->getServer()->getPlayerExact($this->username);
			if($player !== null){
				$player->kick("Bumped from $this->username at $this->bumpedFromTissueName", false);
			}
		}
	}
}
