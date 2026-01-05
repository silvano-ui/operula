<?php

namespace Guardian;

interface ModuleInterface {
	public function id(): string;
	public function register(ModuleContext $ctx): void;
}

