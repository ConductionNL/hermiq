<?php

/**
 * Unit tests for the hydra-console agent applicationSlug backfill
 * (hermiq-agent-application-slug).
 *
 * hermiq's `agent` schema gained the OPTIONAL `applicationSlug` property; every Agent
 * object written before that change necessarily has it empty. These tests pin the
 * backfill's three load-bearing behaviours: it writes only when the field is empty, it
 * never overwrites a value already present, and — the sharp one — it writes through
 * `ObjectService::patchObject()` rather than `saveObject()`, because `saveObject()` is
 * PUT-semantic and would silently null every other field on the agent it "backfills".
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Repair
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/hermiq-agent-application-slug/specs/hermiq-agent-application-slug/spec.md#requirement-the-four-hydra-console-agents-are-backfilled-with-their-application-slug
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Repair;

use OCA\Hermiq\Repair\BackfillAgentApplicationSlug;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Tests for the agent applicationSlug backfill's write behaviour.
 *
 * @spec openspec/changes/hermiq-agent-application-slug/specs/hermiq-agent-application-slug/spec.md#requirement-the-four-hydra-console-agents-are-backfilled-with-their-application-slug
 */
class BackfillAgentApplicationSlugTest extends TestCase {

	/**
	 * Build a container serving the given ObjectService, or one that throws
	 * (simulating OpenRegister being unavailable) when `$objectService` is null.
	 *
	 * @param ObjectService|null $objectService The service to serve, or null.
	 *
	 * @return ContainerInterface
	 */
	private function containerWith(?ObjectService $objectService): ContainerInterface {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			function (string $id) use ($objectService) {
				if ($id === ObjectService::class && $objectService !== null) {
					return $objectService;
				}

				throw new RuntimeException('not available in this test: ' . $id);
			}
		);

		return $container;
	}//end containerWith()

	/**
	 * A mock `ObjectEntity` carrying a name, uuid, and current applicationSlug.
	 *
	 * @param string $name The agent's `name`.
	 * @param string $uuid The agent's uuid.
	 * @param string $applicationSlug The agent's current applicationSlug ('' for empty).
	 *
	 * @return ObjectEntity&\PHPUnit\Framework\MockObject\MockObject
	 */
	private function agentMock(string $name, string $uuid, string $applicationSlug) {
		$agent = $this->createMock(ObjectEntity::class);
		$agent->method('getUuid')->willReturn($uuid);
		$agent->method('getObject')->willReturn(
			[
				'name' => $name,
				'applicationSlug' => $applicationSlug,
			]
		);

		return $agent;
	}//end agentMock()

	/**
	 * An `ObjectService` mock whose `setRegister()`/`setSchema()`/`findAll()` chain
	 * returns the given agents for every name lookup, and whose `patchObject()`
	 * calls are recorded.
	 *
	 * @param array<int, ObjectEntity> $agents The agents `findAll()` returns for
	 *                                         every name queried.
	 * @param array<int, array{0: string, 1: array}> $patchCalls Filled with
	 *                                                           `[objectId, data]` for every `patchObject()` call.
	 *
	 * @return ObjectService&\PHPUnit\Framework\MockObject\MockObject
	 */
	private function objectServiceReturning(array $agents, array &$patchCalls = []) {
		$objectService = $this->createMock(ObjectService::class);
		// runAsSystem MUST invoke its callable — a bare createMock() stubs it to
		// return null without running anything, so the backfill's body would
		// silently not execute and every assertion would fail against an empty
		// store. A fake that does not model the contract makes a CORRECT change
		// look broken, the mirror of one that omits the method and lets a broken
		// change look green.
		$objectService->method('runAsSystem')->willReturnCallback(
			static fn (callable $operation) => $operation()
		);
		$objectService->method('setRegister')->willReturnSelf();
		$objectService->method('setSchema')->willReturnSelf();
		$objectService->method('findAll')->willReturnCallback(
			function (array $config) use ($agents): array {
				$name = (string)($config['filters']['name'] ?? '');
				return array_values(
					array_filter(
						$agents,
						static fn (ObjectEntity $agent): bool => ($agent->getObject()['name'] ?? '') === $name
					)
				);
			}
		);
		$objectService->method('patchObject')->willReturnCallback(
			function (string $objectId, array $data) use (&$patchCalls, $agents): ObjectEntity {
				$patchCalls[] = [$objectId, $data];
				return $agents[0];
			}
		);

		return $objectService;
	}//end objectServiceReturning()

	/**
	 * With none of the four named agents present, nothing is written and nothing
	 * throws.
	 *
	 * @return void
	 */
	public function testRunWritesNothingWhenNoNamedAgentExists(): void {
		$patchCalls = [];
		$objectService = $this->objectServiceReturning(agents: [], patchCalls: $patchCalls);

		$step = new BackfillAgentApplicationSlug(
			container: $this->containerWith($objectService),
			logger: $this->createMock(LoggerInterface::class)
		);

		$step->run($this->createMock(IOutput::class));

		$this->assertSame([], $patchCalls);

	}//end testRunWritesNothingWhenNoNamedAgentExists()

	/**
	 * 🔴 THE BACKFILL PATH. An agent found by name with an empty applicationSlug is
	 * patched with exactly `{applicationSlug: 'hydra-console'}` — and nothing else.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/hermiq-agent-application-slug/specs/hermiq-agent-application-slug/spec.md#requirement-the-four-hydra-console-agents-are-backfilled-with-their-application-slug
	 */
	public function testRunBackfillsApplicationSlugWhenEmpty(): void {
		$agent = $this->agentMock(name: 'Hydra Triage', uuid: 'uuid-1', applicationSlug: '');

		$patchCalls = [];
		$objectService = $this->objectServiceReturning(agents: [$agent], patchCalls: $patchCalls);

		$step = new BackfillAgentApplicationSlug(
			container: $this->containerWith($objectService),
			logger: $this->createMock(LoggerInterface::class)
		);

		$step->run($this->createMock(IOutput::class));

		$this->assertCount(1, $patchCalls, 'exactly one agent named "Hydra Triage" must be patched once');
		$this->assertSame('uuid-1', $patchCalls[0][0]);
		$this->assertSame(['applicationSlug' => 'hydra-console'], $patchCalls[0][1]);

	}//end testRunBackfillsApplicationSlugWhenEmpty()

	/**
	 * 🔑 NEGATIVE CONTROL: an agent that already carries a non-empty applicationSlug
	 * — an operator's own retag, or a value a prior run of this backfill already
	 * wrote — is never patched. Without this, the test above would also pass on an
	 * implementation that patches unconditionally.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/hermiq-agent-application-slug/specs/hermiq-agent-application-slug/spec.md#requirement-a-previously-set-application-slug-is-never-overwritten
	 */
	public function testRunDoesNotOverwriteAnExistingApplicationSlug(): void {
		$agent = $this->agentMock(name: 'Hydra Triage', uuid: 'uuid-1', applicationSlug: 'some-other-app');

		$patchCalls = [];
		$objectService = $this->objectServiceReturning(agents: [$agent], patchCalls: $patchCalls);

		$step = new BackfillAgentApplicationSlug(
			container: $this->containerWith($objectService),
			logger: $this->createMock(LoggerInterface::class)
		);

		$step->run($this->createMock(IOutput::class));

		$this->assertSame([], $patchCalls);

	}//end testRunDoesNotOverwriteAnExistingApplicationSlug()

	/**
	 * Re-running the backfill after it has already filled every agent in writes
	 * nothing a second time — the idempotency contract a repair step must hold.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/hermiq-agent-application-slug/specs/hermiq-agent-application-slug/spec.md#scenario-backfilling-twice-writes-once
	 */
	public function testBackfillingTwiceWritesOnce(): void {
		$agent = $this->agentMock(name: 'Hydra Triage', uuid: 'uuid-1', applicationSlug: '');

		$patchCalls = [];
		$objectService = $this->objectServiceReturning(agents: [$agent], patchCalls: $patchCalls);

		$container = $this->containerWith($objectService);
		$logger = $this->createMock(LoggerInterface::class);

		(new BackfillAgentApplicationSlug(container: $container, logger: $logger))
			->run($this->createMock(IOutput::class));

		// Simulate the second run seeing the already-backfilled agent.
		$agentAfter = $this->agentMock(name: 'Hydra Triage', uuid: 'uuid-1', applicationSlug: 'hydra-console');
		$secondPatchCalls = [];
		$secondObjectService = $this->objectServiceReturning(agents: [$agentAfter], patchCalls: $secondPatchCalls);

		(new BackfillAgentApplicationSlug(container: $this->containerWith($secondObjectService), logger: $logger))
			->run($this->createMock(IOutput::class));

		$this->assertCount(1, $patchCalls, 'first run backfills once');
		$this->assertSame([], $secondPatchCalls, 'second run finds the value already present and writes nothing');

	}//end testBackfillingTwiceWritesOnce()

	/**
	 * All four named agents present with an empty applicationSlug are each
	 * patched exactly once.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/hermiq-agent-application-slug/specs/hermiq-agent-application-slug/spec.md#requirement-the-four-hydra-console-agents-are-backfilled-with-their-application-slug
	 */
	public function testAllFourNamedAgentsAreBackfilled(): void {
		$agents = [];
		foreach (BackfillAgentApplicationSlug::AGENT_NAMES as $index => $name) {
			$agents[] = $this->agentMock(name: $name, uuid: 'uuid-' . $index, applicationSlug: '');
		}

		$patchCalls = [];
		$objectService = $this->objectServiceReturning(agents: $agents, patchCalls: $patchCalls);

		$step = new BackfillAgentApplicationSlug(
			container: $this->containerWith($objectService),
			logger: $this->createMock(LoggerInterface::class)
		);

		$step->run($this->createMock(IOutput::class));

		$this->assertCount(4, $patchCalls);
		foreach ($patchCalls as $call) {
			$this->assertSame(['applicationSlug' => 'hydra-console'], $call[1]);
		}

	}//end testAllFourNamedAgentsAreBackfilled()

	/**
	 * OpenRegister being unavailable is a skip, not a failure — the step must not
	 * throw.
	 *
	 * @return void
	 */
	public function testRunSkipsSilentlyWhenOpenRegisterIsUnavailable(): void {
		$step = new BackfillAgentApplicationSlug(
			container: $this->containerWith(null),
			logger: $this->createMock(LoggerInterface::class)
		);

		// Must NOT throw.
		$step->run($this->createMock(IOutput::class));
		$this->addToAssertionCount(1);

	}//end testRunSkipsSilentlyWhenOpenRegisterIsUnavailable()

	/**
	 * 🔴 A FAILED patch is non-fatal: the agent itself is unchanged and fully
	 * functional without `applicationSlug`, so a write error on that one field
	 * must not surface as a repair-step failure.
	 *
	 * @return void
	 */
	public function testRunLogsAndSurvivesWhenPatchFails(): void {
		$agent = $this->agentMock(name: 'Hydra Triage', uuid: 'uuid-1', applicationSlug: '');

		$objectService = $this->createMock(ObjectService::class);
		// runAsSystem MUST invoke its callable — a bare createMock() stubs it to
		// return null without running anything, so the backfill's body would
		// silently not execute and every assertion would fail against an empty
		// store. A fake that does not model the contract makes a CORRECT change
		// look broken, the mirror of one that omits the method and lets a broken
		// change look green.
		$objectService->method('runAsSystem')->willReturnCallback(
			static fn (callable $operation) => $operation()
		);
		$objectService->method('setRegister')->willReturnSelf();
		$objectService->method('setSchema')->willReturnSelf();
		$objectService->method('findAll')->willReturnCallback(
			static fn (array $config): array => (($config['filters']['name'] ?? '') === 'Hydra Triage') ? [$agent] : []
		);
		$objectService->method('patchObject')->willThrowException(
			new RuntimeException('a backfill write failure')
		);

		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning')->with(
			$this->stringContains('Could not backfill applicationSlug')
		);

		$step = new BackfillAgentApplicationSlug(
			container: $this->containerWith($objectService),
			logger: $logger
		);

		// Must NOT throw.
		$step->run($this->createMock(IOutput::class));

	}//end testRunLogsAndSurvivesWhenPatchFails()
}//end class
