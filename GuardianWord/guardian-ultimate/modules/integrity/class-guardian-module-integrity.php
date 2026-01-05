<?php

namespace Guardian;

final class ModuleIntegrity implements ModuleInterface {
	public function id(): string {
		return Modules::INTEGRITY;
	}

	public function register(ModuleContext $ctx): void {
		AdminRegistry::add_section(new AdminSectionIntegrity());

		// Upgrader hooks are useful for integrity + backup.
		$hooks = new UpgraderHooks($ctx->storage, $ctx->scanner(), $ctx->backup());
		$hooks->register();
	}
}

