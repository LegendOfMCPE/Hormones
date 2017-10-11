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

namespace Hormones\Event;

use Hormones\Hormone\Hormone;
use Hormones\HormonesPlugin;

/**
 * This event is fired when a hormone of unknown type is downloaded from the blood table.
 */
class UnknownHormoneEvent extends HormonesEvent{
	public static $handlerList = null;

	/** @var string */
	private $type;
	/** @var string */
	private $receptors;
	/** @var Hormone|null */
	private $hormone = null;

	/** @var mixed[] */
	private $respondArgs;

	public function __construct(HormonesPlugin $plugin, string $type, string $receptors){
		parent::__construct($plugin);
		$this->type = $type;
		$this->receptors = $receptors;
	}

	public function getType() : string{
		return $this->type;
	}

	public function getReceptors() : string{
		return $this->receptors;
	}

	public function getHormone() : ?Hormone{
		return $this->hormone;
	}

	public function setHormone(Hormone $hormone) : void{
		$this->hormone = $hormone;
	}

	public function getRespondArgs() : array{
		return $this->respondArgs;
	}

	public function setRespondArgs(array $respondArgs) : void{
		$this->respondArgs = $respondArgs;
	}
}
