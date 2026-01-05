<?php

namespace Guardian;

/**
 * Simple runtime registry for admin sections contributed by modules.
 */
final class AdminRegistry {
	/** @var array<string, AdminSectionInterface> */
	private static $sections = [];

	public static function add_section(AdminSectionInterface $section): void {
		self::$sections[$section->id()] = $section;
	}

	/**
	 * @return array<string, AdminSectionInterface>
	 */
	public static function all(): array {
		return self::$sections;
	}

	public static function register_actions(ModuleContext $ctx): void {
		foreach (self::$sections as $section) {
			$section->register_actions($ctx);
		}
	}
}

