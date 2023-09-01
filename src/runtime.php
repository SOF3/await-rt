<?php

declare(strict_types=1);

namespace SOFe\AwaitRuntime;

use Generator;
use RuntimeException;

/**
 * @internal
 */
final class Runtime {
	/**
	 * @param Generator<mixed, mixed, mixed, void> $generator
	 */
	public function __construct(
		public Generator $generator,
		public State\State $state,
		public bool $shouldTrace,
	) {
	}

	public function wakeup() : void {
		while ($this->state->resume($this) && $this->generator->valid()) {
			// handle global messages in an inner loop without calling $this->state->resume() again
			do {
				$message = $this->generator->current();
				if ($this->handleGlobal($message)) {
					continue;
				}

				break;
			} while ($this->generator->valid());

			// the generator might return after sending the last global message
			if (!$this->generator->valid()) {
				break;
			}

			$state = $this->state->handleMessage($this, $message);
			if ($state === null) {
				throw new RuntimeException("Unknown message: $message");
			}

			$this->state = $state;
		}
	}

	/**
	 * @phpstan-impure
	 */
	private function handleGlobal(mixed $message) : bool {
		if ($message === Protocol::IDENTITY) {
			$this->generator->send($this);
			return true;
		}

		return false;
	}
}

/**
 * @internal
 */
final class Protocol {
	const IDENTITY = "identity";
	const RESOLVE = "resolve";
	const REJECT = "reject";
	const ONCE = "once";
}
