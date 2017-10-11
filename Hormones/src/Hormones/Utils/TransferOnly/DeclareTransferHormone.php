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

use Hormones\Hormone\Hormone;
use Hormones\HormonesPlugin;

class DeclareTransferHormone extends Hormone{
	const TYPE = "Hormones.TransferOnly.DeclareTransfer";

	public $username;
	public $userIp;

	public $destIp;
	public $destPort;

	public function getType() : string{
		return DeclareTransferHormone::TYPE;
	}

	public function getData() : array{
		return ["username" => $this->username, "ip" => $this->userIp];
	}

	public function respond(array $args) : void{
		/** @var HormonesPlugin $plugin */
		list($plugin) = $args;
		$mod = $plugin->getTransferOnlyModule();
		if($mod->isEnabled() and $mod->matchesAddress($this->destIp, $this->destPort)){
			$decl = new PreTransferDeclaration();
			$decl->username = $this->username;
			$decl->ip = $this->userIp;
			$decl->time = $this->getCreationTime();
			$mod->declareTransfer($decl);
		}
	}
}
