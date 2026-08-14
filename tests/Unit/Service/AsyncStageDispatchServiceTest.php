<?php

/**
 * The async half of the stage transport.
 *
 * Two methods rather than one with a flag, because the RETURN SHAPES differ and
 * that difference is load-bearing: `dispatch()` promises
 * `{exitCode, output, ref}`, while an accepted async dispatch has no exit code
 * at all — nothing has run yet. A caller reading `exitCode` off an
 * acknowledgement would read "accepted" as "exited 0", which is the single most
 * dangerous confusion this transport can make.
 *
 * What is pinned here is exactly that separation, plus the refusal that keeps a
 * lost handle from becoming an invisible stage.
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Service
 *
 * @author  Conduction Development Team <dev@conduction.nl>
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service;

use OCA\Hermiq\Service\AsyncStageDispatchService;
use OCA\Hermiq\Service\Llm\RunTokenService;
use OCP\ICache;
use OCP\ICacheFactory;
use OCP\Security\ISecureRandom;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Exposes the protected mapper so the shape can be asserted directly.
 */
class ExposedAsyncStageDispatchService extends AsyncStageDispatchService {

	/**
	 * @param string $body The body.
	 *
	 * @return array The mapped handle.
	 */
	public function mapAcceptedPublic(string $body): array {
		return $this->mapAccepted(body: $body);
	}//end mapAcceptedPublic()

	/**
	 * @param array $params The payload.
	 * @param string $jobKey The key.
	 *
	 * @return array The payload with the key applied.
	 */
	public function withJobKeyPublic(array $params, string $jobKey): array {
		return $this->withJobKey(params: $params, jobKey: $jobKey);
	}//end withJobKeyPublic()
}//end class

/**
 * @covers \OCA\Hermiq\Service\AsyncStageDispatchService
 *
 * `@uses`, not `@covers`, and the distinction is enforced: PHPUnit runs with
 * `beStrictAboutCoverageMetadata`, so a test that EXECUTES a class it has not
 * declared is risky and fails the cell. Constructing the subject unavoidably
 * runs its parent's constructor and the run-token service — used, not under
 * test.
 *
 * @uses \OCA\Hermiq\Service\Llm\RunTokenService
 * @uses \OCA\Hermiq\Service\StageDispatchService
 */
final class AsyncStageDispatchServiceTest extends TestCase {

	/**
	 * The service under test.
	 *
	 * @var ExposedAsyncStageDispatchService
	 */
	private ExposedAsyncStageDispatchService $service;

	/**
	 * Build the service with stub collaborators.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$cacheFactory = $this->createMock(originalClassName: ICacheFactory::class);
		$cacheFactory->method('createDistributed')->willReturn($this->createMock(originalClassName: ICache::class));

		$this->service = new ExposedAsyncStageDispatchService(
			$this->createMock(originalClassName: LoggerInterface::class),
			new RunTokenService(
				$cacheFactory,
				$this->createMock(originalClassName: ISecureRandom::class),
				$this->createMock(originalClassName: LoggerInterface::class)
			)
		);

	}//end setUp()

	/**
	 * An acknowledgement is a HANDLE, and carries no field a verdict is read from.
	 *
	 * @return void
	 */
	public function testAnAcknowledgementIsAHandleAndNotAResult(): void {
		$mapped = $this->service->mapAcceptedPublic(
			body: (string)json_encode(['jobId' => '11111111-2222-4333-8444-555555555555', 'status' => 'running'])
		);

		$this->assertSame(expected: '11111111-2222-4333-8444-555555555555', actual: $mapped['job']['id']);
		$this->assertSame(expected: 'running', actual: $mapped['job']['status']);
		$this->assertArrayNotHasKey(
			key: 'exitCode',
			array: $mapped,
			message: 'an acknowledgement must not carry the field a verdict is read from'
		);
	}//end testAnAcknowledgementIsAHandleAndNotAResult()

	/**
	 * A 202 with no job id is refused rather than returned empty.
	 *
	 * A dispatch whose handle was lost is a stage running somewhere with nothing
	 * able to collect it — worse than one that never started, because it holds a
	 * slot and spends a model budget while being invisible.
	 *
	 * @return void
	 */
	public function testAnAcknowledgementWithoutAJobIdIsRefused(): void {
		$this->expectException(exception: RuntimeException::class);
		$this->expectExceptionMessageMatches(regularExpression: '/no job id/');

		$this->service->mapAcceptedPublic(body: (string)json_encode(['status' => 'running']));
	}//end testAnAcknowledgementWithoutAJobIdIsRefused()

	/**
	 * A body that is not JSON at all is refused the same way.
	 *
	 * @return void
	 */
	public function testANonJsonAcknowledgementIsRefused(): void {
		$this->expectException(exception: RuntimeException::class);

		$this->service->mapAcceptedPublic(body: '<html>gateway timeout</html>');
	}//end testANonJsonAcknowledgementIsRefused()

	/**
	 * Collecting without a handle is refused before any request is made.
	 *
	 * An empty job id is not a job that is still running — it is a dispatch
	 * whose acknowledgement never reached the item.
	 *
	 * @return void
	 */
	public function testCollectingWithoutAHandleIsRefused(): void {
		$this->expectException(exception: RuntimeException::class);
		$this->expectExceptionMessageMatches(regularExpression: '/without a job id/');

		$this->service->collect(jobId: '   ');
	}//end testCollectingWithoutAHandleIsRefused()

	/**
	 * A supplied key reaches the payload, so a later tick can rebuild the handle.
	 *
	 * This is the whole point of the key: the flow engine suspends a run exactly
	 * once, so a run whose stage is still going must END and let a later tick
	 * collect — and that tick has only the issue to work from. A uuid would be
	 * lost with the run, and there is nowhere to park one (the lock row's schema
	 * has no free property; flow-state does `claim` and `release` only).
	 *
	 * @return void
	 */
	public function testASuppliedKeyBecomesTheHandleOnThePayload(): void {
		$params = $this->service->withJobKeyPublic(
			params: ['repo' => 'x'],
			jobKey: 'larpingapp-327-code-review'
		);

		$this->assertSame(
			expected: 'larpingapp-327-code-review',
			actual: ($params['jobKey'] ?? null),
			message: 'a key that never reaches the payload leaves the stage uncollectable by a later tick'
		);
	}//end testASuppliedKeyBecomesTheHandleOnThePayload()

	/**
	 * No key means NO field, rather than an empty one.
	 *
	 * An empty `jobKey` in the payload would make the runner key a job on '',
	 * so two concurrent stages would collide on one handle and each would
	 * collect the other's verdict.
	 *
	 * @return void
	 */
	public function testAnAbsentKeyLeavesThePayloadUntouched(): void {
		$this->assertArrayNotHasKey(
			key: 'jobKey',
			array: $this->service->withJobKeyPublic(params: ['repo' => 'x'], jobKey: '   '),
			message: 'an empty key must be absent, not blank — a blank one collides across stages'
		);
	}//end testAnAbsentKeyLeavesThePayloadUntouched()

	/**
	 * The async surface is SEPARATE from the synchronous one.
	 *
	 * Asserted on the class rather than through a call, because the property
	 * that matters is structural: `dispatch()` must not have grown a boolean
	 * that changes what it returns. phpmd calls that a Single Responsibility
	 * violation and static analysis calls it a return-type lie; both were right,
	 * and this is the check that keeps it from coming back.
	 *
	 * @return void
	 */
	public function testTheSynchronousDispatchHasNoAsyncFlag(): void {
		$sync = new \ReflectionMethod(\OCA\Hermiq\Service\StageDispatchService::class, 'dispatch');
		foreach ($sync->getParameters() as $parameter) {
			$this->assertNotSame(
				expected: 'async',
				actual: $parameter->getName(),
				message: 'dispatch() must not take a boolean that changes what it returns — that is two methods sharing a name'
			);
		}

		$this->assertTrue(
			condition: method_exists(AsyncStageDispatchService::class, 'dispatchAsync'),
			message: 'the async dispatch must exist as its own method, with its own return type'
		);
	}//end testTheSynchronousDispatchHasNoAsyncFlag()
}//end class
