<?php

/**
 * Hermiq EvalRunController unit tests.
 *
 * Covers the owner-guard (IDOR) posture: unauthenticated → 401; a dataset or agent the
 * caller does not own → 404 (never 403, so existence is not confirmed); the happy path
 * delegates to EvalRunService and returns its result (agent-evals). skill-evals widens
 * the guard for `baseline: true`: empty `skillRefs` → 400 with zero cases executed; a
 * missing or non-owned linked skill → the same indistinguishable 404; an owned set
 * delegates with `baseline: true`.
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/agent-evals/specs/agent-evals/spec.md#requirement-run-trigger-endpoint-is-owner-guarded-idor
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Controller;

use OCA\Hermiq\Controller\EvalRunController;
use OCA\Hermiq\Service\EvalRunService;
use OCA\Hermiq\Service\SeedCustodyService;
use OCA\OpenRegister\Mcp\Db\Agent;
use OCA\OpenRegister\Mcp\Db\AgentMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * EvalRunController owner-guard tests (agent-evals).
 *
 * @spec openspec/changes/agent-evals/specs/agent-evals/spec.md#requirement-run-trigger-endpoint-is-owner-guarded-idor
 */
class EvalRunControllerTest extends TestCase {

	/**
	 * A user session that resolves to $uid, or null (unauthenticated) when $uid is null.
	 *
	 * @param string|null $uid The UID, or null for no user.
	 *
	 * @return IUserSession
	 */
	private function session(?string $uid): IUserSession {
		$session = $this->createMock(IUserSession::class);
		if ($uid === null) {
			$session->method('getUser')->willReturn(null);
			return $session;
		}

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$session->method('getUser')->willReturn($user);
		return $session;
	}//end session()

	/**
	 * A REAL SeedCustodyService over an IGroupManager mock: the plain owner rule
	 * plus the seed-custodian rule (admin acts as owner of `__system__` objects).
	 *
	 * @param bool $callerIsAdmin Whether isAdmin() reports the caller as instance admin.
	 *
	 * @return SeedCustodyService
	 */
	private function custody(bool $callerIsAdmin = false): SeedCustodyService {
		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('isAdmin')->willReturn($callerIsAdmin);

		return new SeedCustodyService(groupManager: $groupManager);
	}//end custody()

	/**
	 * A dataset ObjectEntity owned by $owner.
	 *
	 * @param string $owner The owner UID.
	 *
	 * @return ObjectEntity
	 */
	private function dataset(string $owner): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid('ds-1');
		$entity->setOwner($owner);
		return $entity;
	}//end dataset()

	/**
	 * An unauthenticated caller is refused with 401.
	 *
	 * @return void
	 */
	public function testUnauthenticatedReturns401(): void {
		$controller = new EvalRunController(
			request: $this->createMock(IRequest::class),
			objectService: $this->createMock(ObjectService::class),
			agentMapper: $this->createMock(AgentMapper::class),
			userSession: $this->session(null),
			evalRunService: $this->createMock(EvalRunService::class),
			seedCustody: $this->custody(),
			logger: $this->createMock(LoggerInterface::class),
		);

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $controller->run('ds-1')->getStatus());

	}//end testUnauthenticatedReturns401()

	/**
	 * A dataset the caller does not own is 404 — never 403 (no existence oracle).
	 *
	 * @return void
	 */
	public function testNonOwnedDatasetReturns404(): void {
		$objectService = $this->createMock(ObjectService::class);
		$objectService->method('find')->willReturn($this->dataset('someone-else'));

		$controller = new EvalRunController(
			request: $this->createMock(IRequest::class),
			objectService: $objectService,
			agentMapper: $this->createMock(AgentMapper::class),
			userSession: $this->session('alice'),
			evalRunService: $this->createMock(EvalRunService::class),
			seedCustody: $this->custody(),
			logger: $this->createMock(LoggerInterface::class),
		);

		$this->assertSame(Http::STATUS_NOT_FOUND, $controller->run('ds-1')->getStatus());

	}//end testNonOwnedDatasetReturns404()

	/**
	 * A THROWING dataset lookup is the same 404, not a 500.
	 *
	 * `ObjectService::find()` documents `@throws Exception If the object is not
	 * found` and only returns null on some paths, so "absent" reaches this
	 * controller both ways. `run()` calls `loadOwnedDataset()` BEFORE opening its
	 * own try block, so before the fix the throw escaped to the dispatcher as a
	 * framework 500 with a stack trace on a `#[NoAdminRequired]` route.
	 *
	 * @return void
	 */
	public function testThrowingDatasetLookupReturns404(): void {
		$objectService = $this->createMock(ObjectService::class);
		$objectService->method('find')->willThrowException(new DoesNotExistException('no such object'));

		$controller = new EvalRunController(
			request: $this->createMock(IRequest::class),
			objectService: $objectService,
			agentMapper: $this->createMock(AgentMapper::class),
			userSession: $this->session('alice'),
			evalRunService: $this->createMock(EvalRunService::class),
			seedCustody: $this->custody(),
			logger: $this->createMock(LoggerInterface::class),
		);

		$this->assertSame(Http::STATUS_NOT_FOUND, $controller->run('ds-1')->getStatus());

	}//end testThrowingDatasetLookupReturns404()

	/**
	 * An agent the caller does not own is 404, even when the dataset is owned.
	 *
	 * @return void
	 */
	public function testNonOwnedAgentReturns404(): void {
		$objectService = $this->createMock(ObjectService::class);
		$objectService->method('find')->willReturn($this->dataset('alice'));

		// Real entity, not a mock (Entity magic accessors are unmockable
		// under a server tree with the real OpenRegister loaded).
		$agent = new Agent();
		$agent->setOwner('bob');
		$agentMapper = $this->createMock(AgentMapper::class);
		$agentMapper->method('findByUuid')->willReturn($agent);

		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			static fn (string $key, $default = null) => $key === 'agentId' ? 'ag-1' : $default
		);

		$controller = new EvalRunController(
			request: $request,
			objectService: $objectService,
			agentMapper: $agentMapper,
			userSession: $this->session('alice'),
			evalRunService: $this->createMock(EvalRunService::class),
			seedCustody: $this->custody(),
			logger: $this->createMock(LoggerInterface::class),
		);

		$this->assertSame(Http::STATUS_NOT_FOUND, $controller->run('ds-1')->getStatus());

	}//end testNonOwnedAgentReturns404()

	/**
	 * When the caller owns both the dataset and the agent, the run delegates to
	 * EvalRunService and returns its result.
	 *
	 * @return void
	 */
	public function testOwnedRunDelegatesToService(): void {
		$objectService = $this->createMock(ObjectService::class);
		$objectService->method('find')->willReturn($this->dataset('alice'));

		// Real entity, not a mock (Entity magic accessors are unmockable
		// under a server tree with the real OpenRegister loaded).
		$agent = new Agent();
		$agent->setOwner('alice');
		$agentMapper = $this->createMock(AgentMapper::class);
		$agentMapper->method('findByUuid')->willReturn($agent);

		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			static fn (string $key, $default = null) => $key === 'agentId' ? 'ag-1' : $default
		);

		$outcome = ['evalRunId' => 'er-1', 'status' => 'complete', 'passRate' => 100.0, 'regressionGateResult' => 'pass', 'previousPassRate' => null];
		$evalRunService = $this->createMock(EvalRunService::class);
		$evalRunService->expects($this->once())->method('run')->with($this->anything(), $agent)->willReturn($outcome);

		$controller = new EvalRunController(
			request: $request,
			objectService: $objectService,
			agentMapper: $agentMapper,
			userSession: $this->session('alice'),
			evalRunService: $evalRunService,
			seedCustody: $this->custody(),
			logger: $this->createMock(LoggerInterface::class),
		);

		$response = $controller->run('ds-1');
		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame($outcome, $response->getData());

	}//end testOwnedRunDelegatesToService()

	/**
	 * A dataset ObjectEntity owned by $owner carrying the given skillRefs.
	 *
	 * @param string $owner The owner UID.
	 * @param array<int, string> $skillRefs Linked skill uuids.
	 *
	 * @return ObjectEntity
	 */
	private function datasetWithSkills(string $owner, array $skillRefs): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid('ds-1');
		$entity->setOwner($owner);
		$entity->setObject(['name' => 'demo', 'skillRefs' => $skillRefs]);
		return $entity;
	}//end datasetWithSkills()

	/**
	 * A skill ObjectEntity owned by $owner.
	 *
	 * @param string $owner The owner UID.
	 * @param string $uuid The skill uuid.
	 *
	 * @return ObjectEntity
	 */
	private function skill(string $owner, string $uuid = 'sk-1'): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid($uuid);
		$entity->setOwner($owner);
		$entity->setObject(['name' => 'skill']);
		return $entity;
	}//end skill()

	/**
	 * A request mock whose params include agentId=ag-1 and baseline=true.
	 *
	 * @return IRequest
	 */
	private function baselineRequest(): IRequest {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			static function (string $key, $default = null) {
				if ($key === 'agentId') {
					return 'ag-1';
				}

				if ($key === 'baseline') {
					return true;
				}

				return $default;
			}
		);
		return $request;
	}//end baselineRequest()

	/**
	 * An AgentMapper mock resolving an agent owned by alice.
	 *
	 * @return AgentMapper
	 */
	private function aliceAgentMapper(): AgentMapper {
		// Real entity, not a mock (Entity magic accessors are unmockable
		// under a server tree with the real OpenRegister loaded).
		$agent = new Agent();
		$agent->setOwner('alice');
		$agentMapper = $this->createMock(AgentMapper::class);
		$agentMapper->method('findByUuid')->willReturn($agent);
		return $agentMapper;
	}//end aliceAgentMapper()

	/**
	 * `baseline: true` on a dataset with empty skillRefs is 400 and no case executes.
	 *
	 * @return void
	 */
	public function testBaselineWithEmptySkillRefsReturns400(): void {
		$objectService = $this->createMock(ObjectService::class);
		$objectService->method('find')->willReturn($this->datasetWithSkills('alice', []));

		$evalRunService = $this->createMock(EvalRunService::class);
		$evalRunService->expects($this->never())->method('run');

		$controller = new EvalRunController(
			request: $this->baselineRequest(),
			objectService: $objectService,
			agentMapper: $this->aliceAgentMapper(),
			userSession: $this->session('alice'),
			evalRunService: $evalRunService,
			seedCustody: $this->custody(),
			logger: $this->createMock(LoggerInterface::class),
		);

		$this->assertSame(Http::STATUS_BAD_REQUEST, $controller->run('ds-1')->getStatus());

	}//end testBaselineWithEmptySkillRefsReturns400()

	/**
	 * `baseline: true` when a linked skill is owned by ANOTHER user is 404 — never
	 * 403 — and no case executes (the widened IDOR guard covers every linked skill).
	 *
	 * @return void
	 */
	public function testBaselineWithNonOwnedLinkedSkillReturns404(): void {
		$objectService = $this->createMock(ObjectService::class);
		$objectService->method('find')->willReturnCallback(
			fn (string $id) => ($id === 'ds-1') ? $this->datasetWithSkills('alice', ['sk-bob']) : $this->skill('bob', 'sk-bob')
		);

		$evalRunService = $this->createMock(EvalRunService::class);
		$evalRunService->expects($this->never())->method('run');

		$controller = new EvalRunController(
			request: $this->baselineRequest(),
			objectService: $objectService,
			agentMapper: $this->aliceAgentMapper(),
			userSession: $this->session('alice'),
			evalRunService: $evalRunService,
			seedCustody: $this->custody(),
			logger: $this->createMock(LoggerInterface::class),
		);

		$this->assertSame(Http::STATUS_NOT_FOUND, $controller->run('ds-1')->getStatus());

	}//end testBaselineWithNonOwnedLinkedSkillReturns404()

	/**
	 * `baseline: true` when a linked skill does not resolve at all is the SAME 404
	 * (missing and non-owned are indistinguishable — no existence oracle).
	 *
	 * @return void
	 */
	public function testBaselineWithMissingLinkedSkillReturns404(): void {
		$objectService = $this->createMock(ObjectService::class);
		$objectService->method('find')->willReturnCallback(
			fn (string $id) => ($id === 'ds-1') ? $this->datasetWithSkills('alice', ['sk-gone']) : null
		);

		$evalRunService = $this->createMock(EvalRunService::class);
		$evalRunService->expects($this->never())->method('run');

		$controller = new EvalRunController(
			request: $this->baselineRequest(),
			objectService: $objectService,
			agentMapper: $this->aliceAgentMapper(),
			userSession: $this->session('alice'),
			evalRunService: $evalRunService,
			seedCustody: $this->custody(),
			logger: $this->createMock(LoggerInterface::class),
		);

		$this->assertSame(Http::STATUS_NOT_FOUND, $controller->run('ds-1')->getStatus());

	}//end testBaselineWithMissingLinkedSkillReturns404()

	/**
	 * When the caller owns dataset + agent + every linked skill, `baseline: true`
	 * is forwarded to EvalRunService.
	 *
	 * @return void
	 */
	public function testOwnedBaselineRunDelegatesWithBaselineTrue(): void {
		$objectService = $this->createMock(ObjectService::class);
		$objectService->method('find')->willReturnCallback(
			fn (string $id) => ($id === 'ds-1') ? $this->datasetWithSkills('alice', ['sk-1']) : $this->skill('alice')
		);

		$outcome = ['evalRunId' => 'er-1', 'status' => 'completed', 'passRate' => 1.0, 'regressionGateResult' => 'not_applicable', 'previousPassRate' => null];
		$evalRunService = $this->createMock(EvalRunService::class);
		$evalRunService->expects($this->once())->method('run')
			->with($this->anything(), $this->anything(), null, null, true)
			->willReturn($outcome);

		$controller = new EvalRunController(
			request: $this->baselineRequest(),
			objectService: $objectService,
			agentMapper: $this->aliceAgentMapper(),
			userSession: $this->session('alice'),
			evalRunService: $evalRunService,
			seedCustody: $this->custody(),
			logger: $this->createMock(LoggerInterface::class),
		);

		$response = $controller->run('ds-1');
		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame($outcome, $response->getData());

	}//end testOwnedBaselineRunDelegatesWithBaselineTrue()

	/**
	 * Seed custodianship: an instance ADMIN acts as owner of the `__system__`-seeded
	 * dataset AND the `__system__`-seeded agent — without this rule the seeded
	 * example pair would 404 for everyone forever (repair steps stamp no human owner).
	 *
	 * @return void
	 */
	public function testAdminCanRunSystemSeededDatasetAndAgent(): void {
		$objectService = $this->createMock(ObjectService::class);
		$objectService->method('find')->willReturn($this->dataset(SeedCustodyService::SYSTEM_OWNER));

		// Real entity, not a mock (Entity magic accessors are unmockable
		// under a server tree with the real OpenRegister loaded).
		$agent = new Agent();
		$agent->setOwner(SeedCustodyService::SYSTEM_OWNER);
		$agentMapper = $this->createMock(AgentMapper::class);
		$agentMapper->method('findByUuid')->willReturn($agent);

		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			static fn (string $key, $default = null) => $key === 'agentId' ? 'ag-1' : $default
		);

		$outcome = ['evalRunId' => 'er-1', 'status' => 'completed', 'passRate' => 1.0, 'regressionGateResult' => 'not_applicable', 'previousPassRate' => null];
		$evalRunService = $this->createMock(EvalRunService::class);
		$evalRunService->expects($this->once())->method('run')->willReturn($outcome);

		$controller = new EvalRunController(
			request: $request,
			objectService: $objectService,
			agentMapper: $agentMapper,
			userSession: $this->session('admin'),
			evalRunService: $evalRunService,
			seedCustody: $this->custody(callerIsAdmin: true),
			logger: $this->createMock(LoggerInterface::class),
		);

		$this->assertSame(Http::STATUS_OK, $controller->run('ds-1')->getStatus());

	}//end testAdminCanRunSystemSeededDatasetAndAgent()

	/**
	 * A NON-admin caller still 404s on the `__system__`-seeded dataset — seed
	 * custodianship never widens access to regular users.
	 *
	 * @return void
	 */
	public function testNonAdminCannotRunSystemSeededDataset(): void {
		$objectService = $this->createMock(ObjectService::class);
		$objectService->method('find')->willReturn($this->dataset(SeedCustodyService::SYSTEM_OWNER));

		$evalRunService = $this->createMock(EvalRunService::class);
		$evalRunService->expects($this->never())->method('run');

		$controller = new EvalRunController(
			request: $this->createMock(IRequest::class),
			objectService: $objectService,
			agentMapper: $this->createMock(AgentMapper::class),
			userSession: $this->session('alice'),
			evalRunService: $evalRunService,
			seedCustody: $this->custody(callerIsAdmin: false),
			logger: $this->createMock(LoggerInterface::class),
		);

		$this->assertSame(Http::STATUS_NOT_FOUND, $controller->run('ds-1')->getStatus());

	}//end testNonAdminCannotRunSystemSeededDataset()

	/**
	 * A HUMAN-owned dataset stays closed to admins — custodianship applies to
	 * `__system__`-seeded objects ONLY, never between two human users.
	 *
	 * @return void
	 */
	public function testAdminCannotRunAnotherHumansDataset(): void {
		$objectService = $this->createMock(ObjectService::class);
		$objectService->method('find')->willReturn($this->dataset('bob'));

		$evalRunService = $this->createMock(EvalRunService::class);
		$evalRunService->expects($this->never())->method('run');

		$controller = new EvalRunController(
			request: $this->createMock(IRequest::class),
			objectService: $objectService,
			agentMapper: $this->createMock(AgentMapper::class),
			userSession: $this->session('admin'),
			evalRunService: $evalRunService,
			seedCustody: $this->custody(callerIsAdmin: true),
			logger: $this->createMock(LoggerInterface::class),
		);

		$this->assertSame(Http::STATUS_NOT_FOUND, $controller->run('ds-1')->getStatus());

	}//end testAdminCannotRunAnotherHumansDataset()

	/**
	 * An owned run with the given request parameters and service behaviour.
	 *
	 * @param array<string, mixed> $params  Request parameters besides agentId.
	 * @param EvalRunService       $service The eval run service.
	 * @param LoggerInterface|null $logger  The logger.
	 *
	 * @return \OCP\AppFramework\Http\JSONResponse
	 */
	private function ownedRun(array $params, EvalRunService $service, ?LoggerInterface $logger = null): \OCP\AppFramework\Http\JSONResponse {
		$objectService = $this->createMock(ObjectService::class);
		$objectService->method('find')->willReturn($this->dataset('alice'));
		$agent = new Agent();
		$agent->setOwner('alice');
		$agentMapper = $this->createMock(AgentMapper::class);
		$agentMapper->method('findByUuid')->willReturn($agent);
		$params['agentId'] = 'ag-1';
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			static fn (string $key, $default = null) => (array_key_exists($key, $params) === true ? $params[$key] : $default)
		);

		$controller = new EvalRunController(
			request: $request,
			objectService: $objectService,
			agentMapper: $agentMapper,
			userSession: $this->session('alice'),
			evalRunService: $service,
			seedCustody: $this->custody(),
			logger: ($logger ?? $this->createMock(LoggerInterface::class)),
		);

		return $controller->run('ds-1');
	}//end ownedRun()

	/**
	 * The agent version id is passed as a string and a numeric regression
	 * threshold as an integer; a non-numeric threshold is ignored.
	 *
	 * @return void
	 */
	public function testRunOptionsArePassedToTheService(): void {
		$service = $this->createMock(EvalRunService::class);
		$calls = [];
		$service->method('run')->willReturnCallback(
			static function (...$args) use (&$calls): array {
				$calls[] = $args;
				return ['status' => 'complete'];
			}
		);

		$this->ownedRun(['agentVersionId' => 42, 'regressionThresholdPercent' => '15'], $service);
		$this->ownedRun(['regressionThresholdPercent' => 'high'], $service);

		$this->assertSame(['42', 15, false], [$calls[0][2], $calls[0][3], $calls[0][4]]);
		$this->assertSame([null, null, false], [$calls[1][2], $calls[1][3], $calls[1][4]]);
	}//end testRunOptionsArePassedToTheService()

	/**
	 * A rejected input is a 400 with its message; any other failure is logged
	 * and a 500 naming it.
	 *
	 * @return void
	 */
	public function testServiceFailuresMapToStatusCodes(): void {
		$rejecting = $this->createMock(EvalRunService::class);
		$rejecting->method('run')->willThrowException(new \InvalidArgumentException('Dataset has no cases'));
		$bad = $this->ownedRun([], $rejecting);

		$failing = $this->createMock(EvalRunService::class);
		$failing->method('run')->willThrowException(new \RuntimeException('engine down'));
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('error')->with('Hermiq eval run failed: engine down');
		$broken = $this->ownedRun([], $failing, $logger);

		$this->assertSame([Http::STATUS_BAD_REQUEST, ['error' => 'Dataset has no cases']], [$bad->getStatus(), $bad->getData()]);
		$this->assertSame([Http::STATUS_INTERNAL_SERVER_ERROR, ['error' => 'Eval run failed', 'message' => 'engine down']], [$broken->getStatus(), $broken->getData()]);
	}//end testServiceFailuresMapToStatusCodes()
}//end class
