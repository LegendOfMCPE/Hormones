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

class VerifyDatabaseVersionHormone extends Hormone{
	const TYPE = "Hormones.VerifyDbVersion";
	public $dbVersion;
	public $pluginVersion;

	public function getType() : string{
		return VerifyDatabaseVersionHormone::TYPE;
	}

	public function getData() : array{
		return [
			"dbVersion" => $this->dbVersion,
			"pluginVersion" => $this->pluginVersion
		];
	}

	public function respond(array $args) : void{
		if(($this->dbVersion >> 16) > HormonesPlugin::DATABASE_MAJOR_VERSION){
			/** @var HormonesPlugin $plugin */
			list($plugin) = $args;
			$plugin->getLogger()->emergency("The database is updated to version $this->dbVersion, because another server is using Hormones $this->pluginVersion that updated the database. Hormones will be disabled from this server. Please install the compatible version of Hormones from https://poggit.pmmp.io/p/Hormones/$this->pluginVersion");
			$plugin->getServer()->getPluginManager()->disablePlugin($plugin);
		}
		// it's unreasonable that the database minor version gets smaller, so no need to check
	}
}
