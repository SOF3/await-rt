<?php

declare(strict_types=1);

namespace SOFe\AwaitRuntime;

use Closure;
use Generator;
use RuntimeException;
use Throwable;

final class Await {
	public static function run(Generator $generator) : void {
		// TODO
	}

	/**
	 * @template T
	 * @param Closure(Closure(T): void, Closure(Throwable): void): void
	 * @return Generator<Await, Await, Await, T>
	 */
	public static function promise(Closure $closure) : Generator {
		$resolve = yield Protocol::RESOLVE;
		$reject = yield Protocol::REJECT;
		$closure($resolve, $reject);
		return yield Protocol::ONCE;
	}

	/**
	 * @template T
	 * @return Generator<Await, Await, Await, array{Closure(T), Closure(Throwable), Generator<Await, Await, Await, T>}>
	 */
	public static function callbackPair() : Generator {
		$identity = yield Protocol::IDENTITY;
		$resolve = yield Protocol::RESOLVE;
		$reject = yield Protocol::REJECT;
		return [$resolve, $reject, (function() use ($identity) {
			if ($identity !== yield Protocol::IDENTITY) {
				throw new RuntimeException("generator returned by callbackPair must be resumed in the same coroutine that called callbackPair");
			}
			return yield Protocol::ONCE;
		})()];
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
