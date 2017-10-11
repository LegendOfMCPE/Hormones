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

namespace Hormones\Commands;

use Hormones\HormonesPlugin;
use pocketmine\command\CommandSender;
use pocketmine\utils\TextFormat;

class HormonesStatusCommand extends HormonesCommand{
	public function __construct(HormonesPlugin $plugin){
		parent::__construct($plugin, "hormones", "See Hormones version and status", "/hormones");
		$this->setPermission("hormones.admin.status");
	}

	public function execute(CommandSender $sender, string $commandLabel, array $args) : bool{
		if(!$this->testPermission($sender)){
			return false;
		}

		$sender->sendMessage(TextFormat::GREEN . "Using {$this->getPlugin()->getName()} v{$this->getPlugin()->getDescription()->getVersion()} by " . implode(", ", $this->getPlugin()->getDescription()->getAuthors()));
		$sender->sendMessage(sprintf('%1$sYou are on a %2$s%3$s %1$sserver: %2$s%4$s %1$s(%2$s%5$s:%6$d%1$s)', TextFormat::GOLD, TextFormat::RED,
			$this->getPlugin()->getOrganName(), $this->getPlugin()->getServerDisplayName(),
			$this->getPlugin()->getVisibleAddress(), $this->getPlugin()->getServer()->getPort()));
		$timers = $this->getPlugin()->getTimers();
		$lymphResult = $this->getPlugin()->getLymphResult();

		$entries = [
			"Organic status" => "{$lymphResult->organicOnlineSlots} / {$lymphResult->organicTotalSlots} in {$lymphResult->organicTissueCount} servers",
			"Network status" => "{$lymphResult->networkOnlineSlots} / {$lymphResult->networkTotalSlots} in {$lymphResult->networkTissueCount} servers",
			"Recommended alt server" => $lymphResult->altServer === null ? "N/A" :
				"{$lymphResult->altServer->displayName} ({$lymphResult->altServer->address}:{$lymphResult->altServer->port})",
			"Vein up time (s)" => $timers->veinUp->evalAverage(),
			"Artery net time (s)" => $timers->arteryNet->evalAverage(),
			"Artery cycle time (s)" => $timers->arteryCycle->evalAverage(),
			"Lymph net time (s)" => $timers->lymphNet->evalAverage(),
			"Lymph cycle time (s)" => $timers->lymphCycle->evalAverage(),
			"Last arterial hormone ID" => $this->getPlugin()->getLastArterialHormoneId()
		];
		foreach($entries as $name => $value){
			$sender->sendMessage(TextFormat::GOLD . $name . ": " . TextFormat::RED . $value);
		}

		return true;
	}
}
