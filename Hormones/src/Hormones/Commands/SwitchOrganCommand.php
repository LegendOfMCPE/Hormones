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
use libasynql\result\MysqlErrorResult;
use libasynql\result\MysqlResult;
use libasynql\result\MysqlSelectResult;
use pocketmine\command\CommandSender;
use pocketmine\permission\Permission;
use pocketmine\Player;
use pocketmine\utils\TextFormat;

class SwitchOrganCommand extends HormonesCommand{
	private $organId;
	private $organName;
	private $organData;

	public static function registerOrganicStuff(HormonesPlugin $plugin) : void{
		$mysqli = $plugin->getCredentials()->newMysqli();

		$mode = $plugin->getConfig()->getNested("organicTransfer.mode", "group");
		if($mode === "off"){
			return;
		}elseif($mode === "group"){
			$modeGroup = true;
		}elseif($mode === "direct"){
			$modeGroup = false;
		}else{
			$plugin->getLogger()->error("Unknown organicTransfer.mode '$mode', using default value 'group'");
			$modeGroup = true;
		}

		$result = MysqlResult::executeQuery($mysqli, "SELECT organId, name FROM hormones_organs", []);
		$mysqli->close();
		if(!($result instanceof MysqlSelectResult)){
			assert($result instanceof MysqlErrorResult);
			throw $result->getException();
		}
		$result->fixTypes([
			"organId" => MysqlSelectResult::TYPE_INT,
			"name" => MysqlSelectResult::TYPE_STRING,
		]);

		$parentPerm = $plugin->getServer()->getPluginManager()->getPermission("hormones.player.transfer.organic");
		if($modeGroup){
			$cmd = new SwitchOrganCommand($plugin, "organic-transfer", "Transfer to a server in another organ", /** @lang text */
				"/ot [target player] <destination organ>", ["ot"]);
			$cmd->setPermission($parentPerm->getName());
			$cmd->organData = [];
			foreach($result->rows as $row){
				$organId = $row["organId"];
				if($organId === $plugin->getOrganId()){
					continue;
				}
				$cmd->organData[strtolower($row["name"])] = $organId;
			}
			$plugin->getServer()->getCommandMap()->register("hormones", $cmd);
		}else{
			foreach($result->rows as $row){
				$organId = $row["organId"];
				if($organId === $plugin->getOrganId()){
					continue;
				}
				$organName = $row["name"];
				$perm = new Permission("hormones.player.transfer.organic.$organName", "Allows transferring to \"$organName\" servers", Permission::DEFAULT_TRUE);
				$parentPerm->getChildren()[$perm->getName()] = true;
				$plugin->getServer()->getPluginManager()->addPermission($perm);
				$cmd = new SwitchOrganCommand($plugin, $organName, "Transfer to a $organName server", "/$organName [target player]");
				$cmd->setPermission($perm->getName());
				$cmd->organId = $organId;
				$cmd->organName = $organName;
				$plugin->getServer()->getCommandMap()->register("organ", $cmd);
			}
		}
	}

	public function execute(CommandSender $sender, string $commandLabel, array $args) : bool{
		if(!$this->testPermission($sender)){
			return false;
		}

		/** @var string|null $target */
		$target = null;
		if(isset($this->organId, $this->organName)){
			$target = array_shift($args);
			$organId = $this->organId;
			$organName = $this->organName;
		}else{
			if(!isset($args[0])){
				$sender->sendMessage(TextFormat::RED . "Usage: " . $this->getUsage());
				$sender->sendMessage(TextFormat::RED . "Available organs: " . implode(", ", array_keys($this->organData)));
				return false;
			}
			if(isset($args[1])){
				$target = array_shift($args);
			}
			$organName = strtolower(array_shift($args));
			if(!isset($this->organData[$organName])){
				$sender->sendMessage(TextFormat::RED . "Usage: " . $this->getUsage());
				$sender->sendMessage(TextFormat::RED . "Unknown organ: $organName");
				$sender->sendMessage(TextFormat::RED . "Available organs: " . implode(", ", array_keys($this->organData)));
				return false;
			}
			$organId = $this->organData[$organName];
		}

		if($target === null and !($sender instanceof Player)){
			$sender->sendMessage(TextFormat::RED . "Please run this command as a player, or specify a target: " . $this->getUsage());
			return false;
		}

		if($target !== null){
			$targetPlayer = $this->getPlugin()->getServer()->getPlayer($target);
		}else{
			$targetPlayer = $sender;
		}
		$sender->getServer()->getScheduler()->scheduleAsyncTask(new FindOrganicTissueTask($this->getPlugin(), $targetPlayer, "Command", $organName, $organId));
		return true;
	}
}
