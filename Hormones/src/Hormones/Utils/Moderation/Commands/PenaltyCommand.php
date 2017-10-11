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

namespace Hormones\Utils\Moderation\Commands;

use Hormones\Commands\HormonesCommand;
use Hormones\HormonesPlugin;
use Hormones\Utils\Moderation\Hormones\KickPlayerHormone;
use Hormones\Utils\Moderation\Hormones\PenaltyHormone;
use Hormones\Utils\Moderation\Penalty;
use pocketmine\command\CommandSender;
use pocketmine\utils\TextFormat;

class PenaltyCommand extends HormonesCommand{
	private $type;

	public function __construct(HormonesPlugin $plugin, string $type, string $name, string $verb){
		parent::__construct($plugin, $name, "$verb a player", "/$name <player> <duration> <message ...>");
		$this->type = $type;
		$this->setPermission("hormones.moderation.moderator.sectional.{$this->type};hormones.moderation.moderator.global.{$this->type}");
	}

	public function execute(CommandSender $sender, string $commandLabel, array $args) : bool{
		if(!$this->testPermission($sender)){
			return false;
		}
		if(!isset($args[1])){
			$sender->sendMessage($this->getUsage());
			return false;
		}
		$targetName = array_shift($args);
		$target = $sender->getServer()->getPlayer($targetName);
		if($target === null){
			$sender->sendMessage(TextFormat::RED . "No such player!");
			return false;
		}
		$duration = array_shift($args);
		if(!is_numeric($duration)){
			try{
				$duration = HormonesCommand::ui_inputToSecs($duration);
			}catch(\InvalidArgumentException $e){
				$sender->sendMessage("Error parsing duration: " . $e->getMessage());
				return false;
			}
		}else{
			$duration = (int) ((float) $duration * 60);
		}
		$message = implode(" ", $args);
		$organMask = $sender->hasPermission("hormones.moderation.moderator.global.{$this->type}") ? str_repeat("\xFF", 8) :
			HormonesPlugin::setNthBit($this->getPlugin()->getOrganId(), 8);

		$hormone = new PenaltyHormone($organMask, $duration);
		$hormone->type = $this->type;
		$hormone->name = $target->getName();
		$hormone->ip = $target->getAddress();
		$hormone->message = $message;
		$hormone->source = $sender->getName();
		$hormone->release($this->getPlugin());

		$penalty = $hormone->toPenalty();
		$sender->sendMessage($penalty->getNotifyMessage(false));

		if($this->type === Penalty::TYPE_BAN){
			$hormone = new KickPlayerHormone($organMask);
			$hormone->playerName = $target->getName();
			$hormone->ip = $target->getAddress();
			$hormone->message = $penalty->getNotifyMessage();
			$hormone->release($this->getPlugin());
		}

		return true;
	}
}
