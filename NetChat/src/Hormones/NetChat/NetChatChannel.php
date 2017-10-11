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

class NetChatChannel{
	/** @var string */
	private $name;
	/** @var bool */
	private $visible;
	/** @var bool */
	private $invite;
	/** @var string|null */
	private $passphrase;
	/** @var string|null */
	private $permission;
	/** @var int */
	private $defaultPerm;

	/** @var NetChatSubscription[] */
	private $onlineSubs = [];

	public function __construct(string $name, bool $visible, bool $invite, string $passphrase = null, string $permission = null, int $defaultPerm){
		$this->name = $name;
		$this->visible = $visible;
		$this->invite = $invite;
		$this->passphrase = $passphrase;
		$this->permission = $permission;
		$this->defaultPerm = $defaultPerm;
	}

	public function addKnownSubscription(NetChatSubscription $sub) : void{
		$this->onlineSubs[strtolower($sub->session->getPlayer()->getName())] = $sub;
	}

	public function removeKnownOnlineSubscription(NetChatSubscription $sub) : void{
		if(!isset($this->onlineSubs[strtolower($sub->session->getPlayer()->getName())])){
			throw new \InvalidArgumentException("No such subscriber");
		}
		unset($this->onlineSubs[strtolower($sub->session->getPlayer()->getName())]);
	}


	public function getName() : string{
		return $this->name;
	}

	public function isVisible() : bool{
		return $this->visible;
	}

	public function isInviteOnly() : bool{
		return $this->invite;
	}

	public function hasPassphrase() : bool{
		return isset($this->passphrase);
	}

	public function getPassphrase(){
		return $this->passphrase;
	}

	public function hasPermission() : bool{
		return isset($this->permission);
	}

	public function getPermission() : ?string{
		return $this->permission;
	}

	public function getDefaultPerm() : int{
		return $this->defaultPerm;
	}

	/**
	 * @return NetChatSubscription[]
	 */
	public function getOnlineSubs() : array{
		return $this->onlineSubs;
	}
}
