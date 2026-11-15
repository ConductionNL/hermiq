<?php

/**
 * Unit tests for ToolOversightController's guard and failure answers.
 *
 * ToolOversightControllerTest covers the catalogue, invocation and access-
 * request happy paths. This file pins the unauthenticated 401s on the
 * request-resolution and grant-update routes, the grant update's 404/400
 * guards and its save outcomes (the stored grants, 404 on a scope mismatch,
 * a logged 500 on any other failure), the logged 500 when the invocation log
 * cannot be read, and the real class-shape probe behind `available`.
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
 * @spec openspec/changes/agent-tool-governance-and-disclosure/design.md#api-design
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Controller;

use OCA\Hermiq\Controller\ToolOversightController;
use OCA\Hermiq\Service\AgentAccessService;
use OCA\Hermiq\Service\ToolAccessRequestService;
use OCA\Hermiq\Service\ToolOversight\ToolCatalogView;
use OCA\Hermiq\Service\ToolOversight\ToolGrantWrites;
use OCA\Hermiq\Service\ToolOversight\ToolInvocationLog;
use OCA\Hermiq\Service\ToolOversight\ToolOversightAgents;
use OCA\Hermiq\Service\ToolOversight\ToolOversightServices;
use OCA\OpenRegister\Db\AuditTrail;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Mcp\Service\Mcp\ToolRegistryFacade;
use OCA\OpenRegister\Service\Capability\ToolGrantResolver;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Guard and failure answers of ToolOversightController.
 *
 * @covers \OCA\Hermiq\Controller\ToolOversightController
 * @uses   \OCA\Hermiq\Controller\ToolGrantUpdate
 * @uses   \OCA\Hermiq\Controller\ToolInvocationExport
 * @uses   \OCA\Hermiq\Service\AgentAccessService
 * @uses   \OCA\Hermiq\Service\ToolInvocationRows
 * @uses   \OCA\Hermiq\Service\ToolOversight\ToolCatalogView
 * @uses   \OCA\Hermiq\Service\ToolOversight\ToolGrantWrites
 * @uses   \OCA\Hermiq\Service\ToolOversight\ToolInvocationLog
 * @uses   \OCA\Hermiq\Service\ToolOversight\ToolOversightAgents
 * @uses   \OCA\Hermiq\Service\ToolOversight\ToolOversightServices
 *
 * @spec openspec/changes/agent-tool-governance-and-disclosure/design.md#api-design
 */
final class ToolOversightControllerUncoveredPathsTest extends TestCase {

	/**
	 * @var IRequest&MockObject
	 */
	private IRequest $request;

	/**
	 * @var ObjectService&MockObject
	 */
	private ObjectService $objectService;

	/**
	 * @var AuditTrailMapper&MockObject
	 */
	private AuditTrailMapper $auditTrailMapper;

	/**
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface $logger;

	/**
	 * Build the doubles.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->request = $this->createMock(IRequest::class);
		$this->objectService = $this->createMock(ObjectService::class);
		$this->objectService->method('setRegister')->willReturnSelf();
		$this->objectService->method('setSchema')->willReturnSelf();
		$this->auditTrailMapper = $this->createMock(AuditTrailMapper::class);
		$this->logger = $this->createMock(LoggerInterface::class);
	}//end setUp()

	/**
	 * The real controller (no `richAuditAvailable()` override) with the session
	 * resolving to $uid (null = unauthenticated).
	 *
	 * @param string|null $uid The session UID.
	 *
	 * @return ToolOversightController
	 */
	private function controller(?string $uid = 'alice'): ToolOversightController {
		$user = null;
		if ($uid !== null) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($uid);
		}

		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('isAdmin')->willReturn(false);
		$quietLogger = $this->createMock(LoggerInterface::class);

		$services = new ToolOversightServices(
			agents: new ToolOversightAgents($this->objectService, new AgentAccessService($this->objectService, $quietLogger, $groupManager), $groupManager, $quietLogger),
			catalog: new ToolCatalogView($this->createMock(ToolRegistryFacade::class), new ToolGrantResolver(), $this->createMock(IAppConfig::class)),
			invocations: new ToolInvocationLog($this->objectService, $this->auditTrailMapper),
			grants: new ToolGrantWrites($this->objectService, $this->createMock(ToolAccessRequestService::class)),
		);

		return new ToolOversightController($this->request, $session, $this->logger, $services);
	}//end controller()

	/**
	 * An agent entity owned by $owner.
	 *
	 * @param array<string, mixed> $payload The payload.
	 * @param string $owner The owner UID.
	 *
	 * @return ObjectEntity
	 */
	private function agent(array $payload, string $owner = 'alice'): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid('agent-1');
		$entity->setOwner($owner);
		$entity->setObject($payload);
		return $entity;
	}//end agent()

	/**
	 * Resolving an access request without a session user is 401 and reads nothing.
	 *
	 * @return void
	 */
	public function testResolveRequestUnauthenticatedIs401(): void {
		$this->objectService->expects(self::never())->method('find');

		$response = $this->controller(null)->resolveToolAccessRequest('agent-1', 'req-1');

		self::assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
		self::assertSame(['error' => 'Unauthenticated'], $response->getData());
	}//end testResolveRequestUnauthenticatedIs401()

	/**
	 * Updating grants without a session user is 401 and writes nothing.
	 *
	 * @return void
	 */
	public function testUpdateGrantsUnauthenticatedIs401(): void {
		$this->objectService->expects(self::never())->method('find');
		$this->objectService->expects(self::never())->method('saveObject');

		$response = $this->controller(null)->updateToolGrants('agent-1');

		self::assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
		self::assertSame(['error' => 'Unauthenticated'], $response->getData());
	}//end testUpdateGrantsUnauthenticatedIs401()

	/**
	 * An agent that cannot be loaded is 404 on a grant update, and nothing is saved.
	 *
	 * @return void
	 */
	public function testUpdateGrantsUnknownAgentIs404(): void {
		$this->objectService->expects(self::once())->method('find')
			->with('ghost')
			->willThrowException(new DoesNotExistException('gone'));
		$this->objectService->expects(self::never())->method('saveObject');

		$response = $this->controller()->updateToolGrants('ghost');

		self::assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		self::assertSame(['error' => 'Agent not found'], $response->getData());
	}//end testUpdateGrantsUnknownAgentIs404()

	/**
	 * A grants parameter that is not an array is 400 and nothing is saved.
	 *
	 * @return void
	 */
	public function testUpdateGrantsNonArrayIs400(): void {
		$this->objectService->method('find')->willReturn($this->agent(['name' => 'A']));
		$this->request->method('getParam')->with('grants')->willReturn('pipelinq.*');
		$this->objectService->expects(self::never())->method('saveObject');

		$response = $this->controller()->updateToolGrants('agent-1');

		self::assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		self::assertSame(['error' => 'grants must be an array or a grant structure'], $response->getData());
	}//end testUpdateGrantsNonArrayIs400()

	/**
	 * The owner's grant update saves the agent payload with the new grant list
	 * (empty fields dropped) and answers with the stored tools.
	 *
	 * @return void
	 */
	public function testUpdateGrantsSavesAndAnswersStoredTools(): void {
		$this->objectService->method('find')->willReturn($this->agent(['name' => 'A', 'description' => null, 'tools' => ['old.tool']]));
		$this->request->method('getParam')->with('grants')->willReturn(['pipelinq.lead.search']);
		$saved = $this->agent(['name' => 'A', 'tools' => ['pipelinq.lead.search']]);
		$this->objectService->expects(self::once())->method('saveObject')
			->with(
				self::callback(
					static function (array $payload): bool {
						return $payload['tools'] === ['pipelinq.lead.search']
							&& $payload['name'] === 'A'
							&& array_key_exists('description', $payload) === false;
					}
				),
				[],
				'hermiq',
				'agent',
				'agent-1'
			)
			->willReturn($saved);

		$response = $this->controller()->updateToolGrants('agent-1');

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame(['agentId' => 'agent-1', 'tools' => ['pipelinq.lead.search']], $response->getData());
	}//end testUpdateGrantsSavesAndAnswersStoredTools()

	/**
	 * A save refused as out of scope (DoesNotExistException) is 404, not a 500,
	 * and is not logged as a failure.
	 *
	 * @return void
	 */
	public function testUpdateGrantsScopeMismatchOnSaveIs404(): void {
		$this->objectService->method('find')->willReturn($this->agent(['name' => 'A']));
		$this->request->method('getParam')->with('grants')->willReturn(['x.y']);
		$this->objectService->method('saveObject')->willThrowException(new DoesNotExistException('scope'));
		$this->logger->expects(self::never())->method('error');

		$response = $this->controller()->updateToolGrants('agent-1');

		self::assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		self::assertSame(['error' => 'Agent not found'], $response->getData());
	}//end testUpdateGrantsScopeMismatchOnSaveIs404()

	/**
	 * Any other save failure is logged and answered with a generic 500.
	 *
	 * @return void
	 */
	public function testUpdateGrantsSaveFailureIsLogged500(): void {
		$this->objectService->method('find')->willReturn($this->agent(['name' => 'A']));
		$this->request->method('getParam')->with('grants')->willReturn(['x.y']);
		$this->objectService->method('saveObject')->willThrowException(new RuntimeException('db down'));
		$this->logger->expects(self::once())->method('error')
			->with('Hermiq tool-grants update failed: db down', self::arrayHasKey('exception'));

		$response = $this->controller()->updateToolGrants('agent-1');

		self::assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
		self::assertSame(['error' => 'Could not update tool grants'], $response->getData());
	}//end testUpdateGrantsSaveFailureIsLogged500()

	/**
	 * When the invocation log cannot be read the failure is logged and answered
	 * with a generic 500.
	 *
	 * @return void
	 */
	public function testToolInvocationsReadFailureIsLogged500(): void {
		$this->objectService->method('find')->willReturn($this->agent(['name' => 'A']));
		$this->objectService->method('findAll')->willThrowException(new RuntimeException('schedules unavailable'));
		$this->logger->expects(self::once())->method('error')
			->with('Hermiq tool-invocations read failed: schedules unavailable', self::arrayHasKey('exception'));

		$response = $this->controller()->toolInvocations('agent-1');

		self::assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
		self::assertSame(['error' => 'Could not load tool invocations'], $response->getData());
	}//end testToolInvocationsReadFailureIsLogged500()

	/**
	 * Without an override, `available` follows the real AuditTrail class shape:
	 * the installed OpenRegister carries `toolId`, so the rich MCP audit is the
	 * source and the MCP actions are what is read.
	 *
	 * @return void
	 */
	public function testToolInvocationsUsesRealClassShapeProbe(): void {
		self::assertTrue(property_exists(AuditTrail::class, 'toolId'), 'precondition: OpenRegister AuditTrail has toolId');
		$this->objectService->method('find')->willReturn($this->agent(['name' => 'A']));
		$this->objectService->method('findAll')->willReturn([]);
		$this->auditTrailMapper->expects(self::once())->method('findAll')
			->with(null, null, ['action' => 'mcp.search,mcp.get,mcp.create,mcp.update,mcp.delete'])
			->willReturn([]);

		$response = $this->controller()->toolInvocations('agent-1');

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		$data = $response->getData();
		self::assertTrue($data['available']);
		self::assertSame('or-mcp-invocation-audit', $data['source']);
		self::assertSame([], $data['rows']);
	}//end testToolInvocationsUsesRealClassShapeProbe()
}//end class
