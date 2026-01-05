<?php

namespace Guardian;

interface AdminSectionInterface {
	public function id(): string;
	public function label(): string;

	/**
	 * Register admin_post hooks etc.
	 */
	public function register_actions(ModuleContext $ctx): void;

	/**
	 * Render section contents.
	 */
	public function render(ModuleContext $ctx): void;
}

