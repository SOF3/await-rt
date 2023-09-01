<?php

declare(strict_types=1);

namespace SOFe\AwaitRuntime\SuspendSession;

use Exception;
use Throwable;

final class Id {
	public ?Result $result = null;
}

interface Result {
	public function getTrace() : ?Exception;
}

final class Resolved implements Result {
	public function __construct(
		public mixed $value,
		public ?Exception $trace,
	) {
	}

	public function getTrace() : ?Exception {
		return $this->trace;
	}
}

final class Rejected implements Result {
	public function __construct(
		public Throwable $error,
		public ?Exception $trace,
	) {
	}

	public function getTrace() : ?Exception {
		return $this->trace;
	}
}
