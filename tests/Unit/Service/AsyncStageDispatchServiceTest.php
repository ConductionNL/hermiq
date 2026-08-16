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

	/**
	 * The canned response the next runner call returns.
	 *
	 * @var mixed
	 */
	public mixed $nextResponse = null;

	/**
	 * The route the last call used, so the handle can be asserted on the wire.
	 *
	 * @var string
	 */
	public string $lastRoute = '';

	/**
	 * The params the last call sent, so the payload can be asserted.
	 *
	 * @var array
	 */
	public array $lastParams = [];

	/**
	 * Answer from the canned response instead of reaching AppAPI.
	 *
	 * @param string $route The route.
	 * @param string $method The method.
	 * @param array $params The params.
	 * @param string|null $uid The user.
	 *
	 * @return mixed The canned response.
	 */
	protected function callRunner(string $route, string $method, array $params, ?string $uid): mixed {
		$this->lastRoute  = $route;
		$this->lastParams = $params;
		return $this->nextResponse;
	}//end callRunner()
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
	 * Build a stub HTTP response with the given status and body.
	 *
	 * @param int $status The status code.
	 * @param string $body The body.
	 *
	 * @return object The response double.
	 */
	private function response(int $status, string $body): object {
		return new class($status, $body) {

			/**
			 * @param int $status The status.
			 * @param string $body The body.
			 */
			public function __construct(private int $status, private string $body) {
			}

			/**
			 * @return int The status.
			 */
			public function getStatusCode(): int {
				return $this->status;
			}

			/**
			 * @return string The body.
			 */
			public function getBody(): string {
				return $this->body;
			}
		};
	}//end response()

	/**
	 * A finished stage is `done`, and a NON-ZERO exit is a verdict, not an error.
	 *
	 * @return void
	 */
	public function testADoneJobCarriesItsExitCodeThrough(): void {
		$this->service->nextResponse = $this->response(
			200,
			(string)json_encode(['status' => 'done', 'result' => ['exitCode' => 1, 'output' => 'gates found 3 issues']])
		);

		$state = $this->service->collect(jobId: 'larpingapp-327-code-review');

		$this->assertSame(expected: 'done', actual: $state['status']);
		$this->assertSame(
			expected: 1,
			actual: $state['result']['exitCode'],
			message: 'a stage that ran and exited non-zero is a RESULT the gate must read, not a transport failure'
		);
		$this->assertStringContainsString(
			needle: 'jobId=larpingapp-327-code-review',
			haystack: $this->service->lastRoute,
			message: 'the handle must reach the runner on the wire, or a later tick collects nothing'
		);
	}//end testADoneJobCarriesItsExitCodeThrough()

	/**
	 * A refused stage is `failed`, keeps its code, and carries NO result.
	 *
	 * This is the confusion that matters most: a refused push read as a
	 * completed stage turns the scope fence advisory.
	 *
	 * @return void
	 */
	public function testAFailedJobKeepsItsCodeAndCarriesNoResult(): void {
		$this->service->nextResponse = $this->response(
			200,
			(string)json_encode([
				'status' => 'failed',
				'error' => 'push refused: "README.md" is outside the scope this issue declared',
				'code' => 'scope_violation',
			])
		);

		$state = $this->service->collect(jobId: 'j1');

		$this->assertSame(expected: 'failed', actual: $state['status']);
		$this->assertSame(expected: 'scope_violation', actual: $state['code']);
		$this->assertArrayNotHasKey(
			key: 'result',
			array: $state,
			message: 'a refusal must carry nothing a caller could read as a completed stage'
		);
	}//end testAFailedJobKeepsItsCodeAndCarriesNoResult()

	/**
	 * A job this runner does not have is `unknown` — and that is TERMINAL.
	 *
	 * The registry is in memory, so a restart loses every job. A poller that
	 * cannot tell "lost" from "not finished yet" waits forever.
	 *
	 * @return void
	 */
	public function testAnUnknownJobIsReportedAsSuchRatherThanAsRunning(): void {
		$this->service->nextResponse = $this->response(200, (string)json_encode(['status' => 'unknown']));

		$this->assertSame(
			expected: 'unknown',
			actual: $this->service->collect(jobId: 'gone')['status'],
			message: 'a lost job must never be reported as still running'
		);
	}//end testAnUnknownJobIsReportedAsSuchRatherThanAsRunning()

	/**
	 * An unreachable ExApp is a refusal, not an empty answer.
	 *
	 * AppAPI never throws — failure is the RETURN VALUE, and it is an array.
	 *
	 * @return void
	 */
	public function testAnUnreachableRunnerIsRefused(): void {
		$this->service->nextResponse = ['error' => 'not found'];

		$this->expectException(exception: RuntimeException::class);
		$this->expectExceptionMessageMatches(regularExpression: '/could not reach/');

		$this->service->collect(jobId: 'j1');
	}//end testAnUnreachableRunnerIsRefused()

	/**
	 * A body that is not a job state at all is refused rather than guessed at.
	 *
	 * @return void
	 */
	public function testABodyThatIsNotAJobStateIsRefused(): void {
		$this->service->nextResponse = $this->response(200, '<html>gateway timeout</html>');

		$this->expectException(exception: RuntimeException::class);
		$this->expectExceptionMessageMatches(regularExpression: '/not a job state/');

		$this->service->collect(jobId: 'j1');
	}//end testABodyThatIsNotAJobStateIsRefused()

	/**
	 * A non-2xx answer is a refusal carrying the runner's own reason.
	 *
	 * @return void
	 */
	public function testANonSuccessStatusIsRefused(): void {
		$this->service->nextResponse = $this->response(500, (string)json_encode(['error' => 'runner exploded']));

		$this->expectException(exception: RuntimeException::class);

		$this->service->collect(jobId: 'j1');
	}//end testANonSuccessStatusIsRefused()

	/**
	 * An async dispatch is ACCEPTED, and says so in a shape with no verdict in it.
	 *
	 * The payload must carry `async: true` — without it the runner holds the
	 * call open for the whole stage, which is the blocking this class exists to
	 * remove, and the flow would suspend on a wait for work that had already
	 * finished somewhere else.
	 *
	 * @return void
	 */
	public function testAnAsyncDispatchIsAcceptedAndAsksForAsync(): void {
		$this->service->nextResponse = $this->response(
			202,
			(string)json_encode(['jobId' => 'j-42', 'status' => 'running'])
		);

		$handle = $this->service->dispatchAsync(
			repo: 'https://github.com/ConductionNL/larpingapp',
			ref: 'feature/327/hydra-build',
			command: ['claude', '-p', 'review']
		);

		$this->assertSame(expected: 'j-42', actual: $handle['job']['id']);
		$this->assertArrayNotHasKey(
			key: 'exitCode',
			array: $handle,
			message: 'an acknowledgement must not carry the field a verdict is read from'
		);
		$this->assertTrue(
			condition: ($this->service->lastParams['async'] ?? false),
			message: 'without async:true the runner holds the call open for the whole stage'
		);
	}//end testAnAsyncDispatchIsAcceptedAndAsksForAsync()

	/**
	 * A dispatch the runner refuses is an exception, not an empty handle.
	 *
	 * A dispatch whose handle was lost is a stage running somewhere with
	 * nothing able to collect it — worse than one that never started, because
	 * it holds a slot and spends a model budget while being invisible.
	 *
	 * @return void
	 */
	public function testARefusedDispatchThrowsRatherThanReturningNoHandle(): void {
		$this->service->nextResponse = $this->response(503, (string)json_encode(['error' => 'no capacity']));

		$this->expectException(exception: RuntimeException::class);
		$this->expectExceptionMessageMatches(regularExpression: '/could not start the stage/');

		$this->service->dispatchAsync(
			repo: 'https://github.com/ConductionNL/larpingapp',
			ref: 'main',
			command: ['claude', '-p', 'review']
		);
	}//end testARefusedDispatchThrowsRatherThanReturningNoHandle()

	/**
	 * A supplied key reaches the payload, so a later tick can rebuild the handle.
	 *
	 * The flow engine suspends a run exactly once, so a run whose stage is still
	 * going must END and let a later tick collect — and that tick has only the
	 * issue to work from, so a uuid would be lost with the run that received it.
	 * A rebuildable key needs nothing stored, and so survives a marker write
	 * that never happened.
	 *
	 * @return void
	 */
	public function testASuppliedKeyBecomesTheHandleOnThePayload(): void {
		$this->assertSame(
			expected: 'larpingapp-327-code-review',
			actual: ($this->service->withJobKeyPublic(
				params: ['repo' => 'x'],
				jobKey: 'larpingapp-327-code-review'
			)['jobKey'] ?? null),
			message: 'a key that never reaches the payload leaves the stage uncollectable by a later tick'
		);
	}//end testASuppliedKeyBecomesTheHandleOnThePayload()

	/**
	 * No key means NO field, rather than an empty one.
	 *
	 * A blank `jobKey` would make the runner key a job on '', so two concurrent
	 * stages would collide on one handle and each would collect the other's
	 * verdict.
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
