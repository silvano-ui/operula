<?php

namespace Guardian;

final class ModuleContext {
	public Storage $storage;
	public License $license;
	public array $settings;
	public array $payload;
	public array $enabledModules;

	private ?Scanner $scanner = null;
	private ?Backup $backup = null;
	private ?RestorePoints $restorePoints = null;

	public function __construct(Storage $storage, License $license, array $settings, array $payload, array $enabledModules) {
		$this->storage = $storage;
		$this->license = $license;
		$this->settings = $settings;
		$this->payload = $payload;
		$this->enabledModules = $enabledModules;
	}

	public function has(string $moduleId): bool {
		return in_array($moduleId, $this->enabledModules, true);
	}

	public function scanner(): Scanner {
		if ($this->scanner === null) {
			$this->scanner = new Scanner($this->storage);
		}
		return $this->scanner;
	}

	public function backup(): Backup {
		if ($this->backup === null) {
			$this->backup = new Backup($this->storage);
		}
		return $this->backup;
	}

	public function restorePoints(): RestorePoints {
		if ($this->restorePoints === null) {
			$this->restorePoints = new RestorePoints($this->storage);
		}
		return $this->restorePoints;
	}
}

