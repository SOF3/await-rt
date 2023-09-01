<?php

declare(strict_types=1);

/**
 * @internal
 */
namespace SOFe\AwaitRuntime\State;

use Closure;
use Exception;
use Generator;
use RuntimeException;
use SOFe\AwaitRuntime\Protocol;
use SOFe\AwaitRuntime\Runtime;
use SOFe\AwaitRuntime\SuspendSession;
use SOFe\AwaitRuntime\SuspendSession\Id;
use Throwable;

interface State {
	/**
	 * Resumes the generator after the runtime state has changed.
	 * Returns false if the generator is not resumed.
	 */
	public function resume(Runtime $rt) : bool;

	/**
	 * Handles a message yielded by the user, transitioning to a new state.
	 *
	 * Returns null if the message is unsupported,
	 * which would trigger an exception throw.
	 *
	 * resume might not be called before handleMessage if the state is changed during resumption of another closure.
	 */
	public function handleMessage(Runtime $rt, mixed $message) : ?State;
}

final class Running implements State {
	public function __construct(
		private Closure $resume,
	) {
	}

	public function resume(Runtime $rt) : bool {
		($this->resume)($rt->generator);
		return true;
	}

	public function handleMessage(Runtime $rt, mixed $message) : ?State {
		if ($message === Protocol::RESOLVE) {
			return new ScheduleSingle;
		}

		return null;
	}
}

interface SuspendResultAcceptor extends State {
	public function suspendId() : SuspendSession\Id;

	public function onSuspendResult(Runtime $rt) : void;
}

final class ScheduleSingle implements State, SuspendResultAcceptor {
	private SuspendSession\Id $ssid;

	public function __construct() {
		$this->ssid = new SuspendSession\Id;
	}

	public function resume(Runtime $rt) : bool {
		$rt->generator->send(function($value = null) use ($rt) : void {
			if ($this->ssid->result !== null) {
				throw new RuntimeException("The current resolve/reject closure pair was already called", previous: $this->ssid->result->getTrace());
			}

			$newState = $rt->state;

			if (!($newState instanceof SuspendResultAcceptor) || $newState->suspendId() !== $this->ssid) {
				throw new RuntimeException("Runtime state changed unexpectedly when SuspendSession is still pending");
			}

			$this->ssid->result = new SuspendSession\Resolved($value, $rt->shouldTrace ? new Exception : null);
			$newState->onSuspendResult($rt);
		});

		return true;
	}

	public function handleMessage(Runtime $rt, mixed $message) : ?State {
		if ($message === Protocol::REJECT) {
			return new ScheduleDouble($this->ssid);
		}

		if ($message === Protocol::ONCE) {
			return new Pending($this->ssid);
		}

		return null;
	}

	public function suspendId() : SuspendSession\Id {
		return $this->ssid;
	}

	public function onSuspendResult(Runtime $rt) : void {
		if (!($this->ssid->result instanceof SuspendSession\Resolved)) {
			throw new RuntimeException("ScheduleSingle cannot be rejected");
		}
		$rt->state = new ReadySingle($this->ssid->result->value);
	}
}

final class ScheduleDouble implements State, SuspendResultAcceptor {
	public function __construct(private SuspendSession\Id $ssid) {
	}

	public function resume(Runtime $rt) : bool {
		$rt->generator->send(function(Throwable $error) use ($rt) : void {
			if ($this->ssid->result !== null) {
				throw new RuntimeException("The current resolve/reject closure pair was already called", previous: $this->ssid->result->getTrace());
			}

			$newState = $rt->state;

			if (!($newState instanceof SuspendResultAcceptor) || $newState->suspendId() !== $this->ssid) {
				throw new RuntimeException("Runtime state changed unexpectedly when SuspendSession is still pending");
			}

			$this->ssid->result = new SuspendSession\Rejected($error, $rt->shouldTrace ? new Exception : null);
			$newState->onSuspendResult($rt);
		});

		return true;
	}

	public function handleMessage(Runtime $rt, mixed $message) : ?State {
		if ($message === Protocol::ONCE) {
			return new Pending($this->ssid);
		}

		return null;
	}

	public function suspendId() : SuspendSession\Id {
		return $this->ssid;
	}

	public function onSuspendResult(Runtime $rt) : void {
		if ($this->ssid->result instanceof SuspendSession\Rejected) {
			$rt->state = new Failed($this->ssid->result->error);
		} elseif ($this->ssid->result instanceof SuspendSession\Resolved) {
			$rt->state = new ReadyDouble($this->ssid->result->value);
		} else {
			throw new RuntimeException("Unexpected sealed interface implementation");
		}
	}
}

final class ReadySingle implements State {
	public function __construct(private mixed $result) {
	}

	public function resume(Runtime $rt) : bool {
		throw new RuntimeException("Unreachable code: ReadySingle is never returned in handleMessage");
	}

	public function handleMessage(Runtime $rt, mixed $message) : ?State {
		if ($message === Protocol::REJECT) {
			return new ReadyDouble($this->result);
		}

		if ($message === Protocol::ONCE) {
			return new Running(fn(Generator $generator) => $generator->send($this->result));
		}

		return null;
	}
}

/**
 * @template T
 */
final class ReadyDouble implements State {
	public function __construct(private mixed $result) {
	}

	public function resume(Runtime $rt) : bool {
		throw new RuntimeException("Unreachable code: ReadyDouble is never returned in handleMessage");
	}

	public function handleMessage(Runtime $rt, mixed $message) : ?State {
		if ($message === Protocol::ONCE) {
			return new Running(fn(Generator $generator) => $generator->send($this->result));
		}

		return null;
	}
}

final class Failed implements State {
	public function __construct(private Throwable $error) {
	}

	public function resume(Runtime $rt) : bool {
		throw new RuntimeException("Unreachable code: Failed is never returned in handleMessage");
	}

	public function handleMessage(Runtime $rt, mixed $message) : ?State {
		if ($message === Protocol::ONCE) {
			return new Running(fn(Generator $generator) => $generator->throw($this->error));
		}

		return null;
	}
}

final class Pending implements State, SuspendResultAcceptor {
	public function __construct(private SuspendSession\Id $ssid) {
	}

	public function resume(Runtime $rt) : bool {
		return false;
	}

	public function handleMessage(Runtime $rt, mixed $message) : ?State {
		throw new RuntimeException("resume() returns false");
	}

	public function suspendId() : Id {
		return $this->ssid;
	}

	public function onSuspendResult(Runtime $rt) : void {
		if ($this->ssid->result instanceof SuspendSession\Rejected) {
			$error = $this->ssid->result->error;
			$rt->state = new Running(fn(Generator $generator) => $generator->throw($error));
		} elseif ($this->ssid->result instanceof SuspendSession\Resolved) {
			$value = $this->ssid->result->value;
			$rt->state = new Running(fn(Generator $generator) => $generator->send($value));
		} else {
			throw new RuntimeException("Unexpected sealed interface implementation");
		}
		$rt->wakeup();
	}
}
