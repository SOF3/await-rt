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
	 * @param Closure(Closure(T): void, Closure(Throwable): void): void $closure
	 * @return Generator<Await, Await, Await, T>
	 */
	public static function promise(Closure $closure) : Generator {
		/** @var Closure(T): void */
		$resolve = yield Protocol::RESOLVE;
		/** @var Closure(Throwable): void */
		$reject = yield Protocol::REJECT;
		$closure($resolve, $reject);

		/** @var T */
		$result = yield Protocol::ONCE;
		return $result;
	}

	/**
	 * @template T
	 * @return Generator<Await, Await, Await, array{Closure(T): void, Closure(Throwable): void, Generator<Await, Await, Await, T>}>
	 */
	public static function callbackPair() : Generator {
		$identity = yield from Await::currentCoroutine();
		/** @var Closure(T): void */
		$resolve = yield Protocol::RESOLVE;
		/** @var Closure(Throwable): void */
		$reject = yield Protocol::REJECT;
		return [$resolve, $reject, self::waitOnceWithidentity($identity)];
	}

	/**
	 * @template T
	 * @return Generator<Await, Await, Await, T>
	 */
	private static function waitOnceWithidentity(object $identity) : Generator {
		if ($identity !== yield from Await::currentCoroutine()) {
			throw new RuntimeException("generator returned by callbackPair must be resumed in the same coroutine that called callbackPair");
		}
		/** @var T */
		return yield Protocol::ONCE;
	}

	/**
	 * Returns an object that uniquely identifies the current coroutine.
	 *
	 * The only supported operations on the returned object are `===`, `spl_object_id` and `spl_object_hash`.
	 *
	 * @return Generator<Await, Await, Await, object>
	 */
	public static function currentCoroutine() : Generator {
		return yield Protocol::IDENTITY;
	}
}
