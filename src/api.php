<?php

declare(strict_types=1);

namespace SOFe\AwaitRuntime;

use Closure;
use Generator;
use RuntimeException;
use Throwable;

final class Await {
	/** Set this to true to enable asynchronous tracing. */
	public static bool $shouldTrace = false;

	public static function run(Generator $generator) : void {
		$await = new Runtime($generator, new State\Running(fn(Generator $generator) => $generator->rewind()), self::$shouldTrace);
		$await->wakeup();
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
		$identity = yield from Await::currentCoroutine();
		$resolve = yield Protocol::RESOLVE;
		$reject = yield Protocol::REJECT;
		return [$resolve, $reject, (function() use ($identity) {
			if ($identity !== yield from Await::currentCoroutine()) {
				throw new RuntimeException("generator returned by callbackPair must be resumed in the same coroutine that called callbackPair");
			}
			return yield Protocol::ONCE;
		})()];
	}

	/**
	 * Returns an object that uniquely identifies the current coroutine.
	 *
	 * The only supported operations on the returned object are `===`, `spl_object_id` and `spl_object_hash`.
	 */
	public static function currentCoroutine() : object {
		return yield Protocol::IDENTITY;
	}
}
