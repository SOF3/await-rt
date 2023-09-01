<?php

declare(strict_types=1);

namespace SOFe\AwaitRuntime;

use Closure;
use Exception;
use Generator;
use PHPUnit\Framework\TestCase;
use Throwable;

final class Test extends TestCase {
	/** @var (Closure(): void)[] */
	private array $deferred = [];

	/**
	 * @param Closure(): void $run
	 */
	private function defer(Closure $run) : void {
		$this->deferred[] = $run;
	}

	private function flushDeferAll() : int {
		$counter = 0;
		while(count($this->deferred) > 0) {
			$counter += 1;
			$this->flushDeferOnce();
		}
		return $counter;
	}

	private function flushDeferOnce() : void {
		$closures = $this->deferred;
		$this->deferred = [];
		foreach($closures as $defer) {
			$defer();
		}
	}

	/**
	 * @template T
	 * @param T $value
	 * @return Generator<Await, Await, Await, T>
	 */
	private function okSync($value) : Generator {
		return yield from Await::promise(fn($resolve) => $resolve($value));
	}

	/**
	 * @return Generator<Await, Await, Await, never>
	 */
	private function errSync(Throwable $err) : Generator {
		return yield from Await::promise(fn($resolve, $reject) => $reject($err));
	}

	/**
	 * @template T
	 * @param T $value
	 * @return Generator<Await, Await, Await, T>
	 */
	private function okAsync($value) : Generator {
		return yield from Await::promise(fn($resolve) => $this->defer(function()use($value,$resolve){
			$resolve($value);
		}));
	}

	/**
	 * @return Generator<Await, Await, Await, never>
	 */
	private function errAsync(Throwable $err) : Generator {
		return yield from Await::promise(fn($resolve, $reject) => $this->defer(fn() => $reject($err)));
	}

	/**
	 * @param ?Closure(): void $preAssert
	 * @param ?Closure(): void $syncAssert
	 * @param ?Closure(): void $asyncAssert
	 */
	private function assertGenerator(
		Closure $closure,
		?Closure $preAssert = null, ?Closure $syncAssert = null, ?Closure $asyncAssert = null,
		?int $flushTimes = null,
	) : void {
		$complete = false;
		$generator = $closure();
		Await::run((function() use($generator, $preAssert, &$complete) : Generator{
			$preAssert?->__invoke();
			yield from $generator;
			$complete = true;
		})());
		$syncAssert?->__invoke();
		$actualFlushTimes = $this->flushDeferAll();
		if($flushTimes !== null) {
			self::assertSame($flushTimes, $actualFlushTimes);
		}
		$asyncAssert?->__invoke();
		self::assertTrue($complete, "generator should be fully executed after defer queue is emptied by $actualFlushTimes flush(es)");
	}

	public function testBare() : void {
		$this->assertGenerator(function() : Generator { 0 && yield; }, flushTimes: 0);
	}

	public function testOkSync() : void {
		$this->assertGenerator(
			function() : Generator {
				self::assertSame(1234, yield from $this->okSync(1234));
			},
			flushTimes: 0,
		);
	}

	public function testErrSync() : void {
		$this->assertGenerator(
			function() : Generator {
				$ex = new Exception("should be caught");;
				$this->expectExceptionObject($ex);
				yield from $this->errSync($ex);
			},
			flushTimes: 0,
		);
	}

	public function testOkAsync() : void {
		$this->assertGenerator(
			function() : Generator {
				self::assertSame(1234, yield from $this->okAsync(1234));
			},
			flushTimes: 1,
		);
	}

	public function testErrAsync() : void {
		$this->assertGenerator(
			function() : Generator {
				$ex = new Exception("should be caught");;
				$this->expectExceptionObject($ex);
				yield from $this->errAsync($ex);
			},
			flushTimes: 1,
		);
	}
}
