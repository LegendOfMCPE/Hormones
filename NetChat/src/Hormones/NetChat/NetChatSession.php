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

namespace Hormones\NetChat;

use libasynql\DirectQueryMysqlTask;
use libasynql\result\MysqlErrorResult;
use libasynql\result\MysqlResult;
use libasynql\result\MysqlSelectResult;
use pocketmine\Player;

class NetChatSession{
	private $plugin;
	private $player;

	private $inited = false;
	private $initCount = 0;
	/** @var NetChatSubscription[] */
	private $subs = [];

	public function __construct(NetChat $module, Player $player){
		$this->plugin = $module;
		$this->player = $player;

		$this->plugin->getServer()->getScheduler()->scheduleAsyncTask(new DirectQueryMysqlTask(
			$this->plugin->getHormones()->getCredentials(),
			"SELECT channel, permLevel, subLevel FROM hormones_netchat_subs WHERE user = ?",
			[
				["s", $this->player->getName()]
			],
			[$this, "init"]
		));
	}

	/**
	 * Only to be called internally
	 *
	 * @hidden
	 * @internal
	 *
	 * @param MysqlResult $result
	 */
	public function init(MysqlResult $result) : void{
		if(!($result instanceof MysqlSelectResult)){
			assert($result instanceof MysqlErrorResult);
			throw $result->getException();
		}

		$result->fixTypes([
			"channel" => MysqlSelectResult::TYPE_STRING,
			"permLevel" => MysqlSelectResult::TYPE_INT,
			"subLevel" => MysqlSelectResult::TYPE_INT,
		]);

		$this->initCount = count($result->rows);
		foreach($result->rows as $row){
			$this->plugin->lazyGetChannel($row["channel"], function(NetChatChannel $channel) use ($row){
				$sub = new NetChatSubscription($this, $channel, $row["permLevel"], $row["subLevel"]);
				$this->subs[mb_strtolower($channel->getName())] = $sub;
				$channel->addKnownSubscription($sub);
				$this->decrementInitCount();
			}, function(){
				// ON DELETE CASCADE took place between the `SELECT FROM subs` and the `SELECT FROM channels`
				$this->decrementInitCount();
			});
		}
	}

	private function decrementInitCount() : void{
		--$this->initCount;
		if($this->initCount === 0){
			$this->inited = false;
		}
	}

	public function finalize() : void{
		// TODO save data
	}

	public function getPlayer() : Player{
		return $this->player;
	}

	public function getPlugin() : NetChat{
		return $this->plugin;
	}

	public function subToChannel(string $name, string $passphrase, callable $callback) : void{
		$this->plugin->lazyGetChannel($name, function(NetChatChannel $channel) use ($callback, $passphrase){
			if($channel->isInviteOnly()){
				// TODO check
			}
			if($channel->hasPermission() && !$this->player->hasPermission($channel->getPermission())){
				$callback(false, "permission");
				return;
			}
			if($channel->hasPassphrase() && !password_verify($passphrase, $channel->getPassphrase())){
				$callback(false, "passphrase");
				return;
			}

		});
	}
}
