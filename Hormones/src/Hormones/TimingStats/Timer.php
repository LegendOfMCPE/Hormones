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

namespace Hormones\TimingStats;

class Timer{
	private $threshold = 10;

	private $data = [];

	public function __construct(int $threshold = 100){
		$this->threshold = $threshold;
	}


	public function getThreshold() : int{
		return $this->threshold;
	}

	public function addDatum(float $datum) : void{
		$this->data[] = $datum;
		while(count($this->data) > $this->getThreshold()){
			array_shift($this->data);
		}
	}

	public function evalAverage() : float{
		return count($this->data) === 0 ? \NAN : array_sum($this->data) / count($this->data);
	}
}
