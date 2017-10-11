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

namespace Hormones\Hormone\Defaults;

use Hormones\Hormone\Hormone;
use Hormones\HormonesPlugin;

class StopServerHormone extends Hormone{
	const TYPE = "Hormones.StopServer";

	public function getType() : string{
		return self::TYPE;
	}

	public function getData() : array{
		return [];
	}

	public function respond(array $args) : void{
		/** @var HormonesPlugin $plugin */
		list($plugin) = $args;

		$plugin->getServer()->shutdown();
	}
}
