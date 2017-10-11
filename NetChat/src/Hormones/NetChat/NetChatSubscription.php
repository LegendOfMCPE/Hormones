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

/**
 * Note that "join" and "quit" refers to joining and leaving any tissues, while
 * "sub"/"subscribe" and "part" refers to leaving a channel
 */
class NetChatSubscription{
	/** @var NetChatSession */
	public $session;
	/** @var NetChatChannel */
	public $channel;
	/** @var int */
	public $permLevel;
	/** @var int */
	public $subLevel;

	public function __construct(NetChatSession $session, NetChatChannel $channel, int $permLevel, int $subLevel){
		$this->session = $session;
		$this->channel = $channel;
		$this->permLevel = $permLevel;
		$this->subLevel = $subLevel;
	}

	public function sendMessage(string $message, int $level) : void{
		if($this->subLevel <= $level){
			$this->session->getPlayer()->sendMessage("#{$this->channel->getName()}: $message");
		}
	}
}
