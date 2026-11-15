<?php

/**
 * Unit tests for AgentsController's paging and failure answers.
 *
 * AgentsControllerTest covers the happy paths and the access guards. This
 * file pins what the controller answers when the page parameter is used,
 * when an agent is missing on update, and when ObjectService throws on each
 * route: the status code, the error body, and that the failure is logged.
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/agent-engine-port/tasks.md#4-mirror-the-routes
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Controller;

use OCA\Hermiq\Controller\AgentsController;
use OCA\Hermiq\Service\Agent\AgentAvailabilityService;
use OCA\Hermiq\Service\Agent\AgentCatalog;
use OCA\Hermiq\Service\AgentAccessService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Mcp\Service\Mcp\ToolRegistryFacade;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Http;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Paging and failure answers of AgentsController.
 *
 * @covers \OCA\Hermiq\Controller\AgentsController
 * @uses   \OCA\Hermiq\Service\Agent\AgentCatalog
 * @uses   \OCA\Hermiq\Service\Agent\CatalogViewer
 * @uses   \OCA\Hermiq\Service\AgentAccessService
 *
 * @spec openspec/changes/agent-engine-port/tasks.md#4-mirror-the-routes
 */
final class AgentsControllerFailuresTest extends TestCase {

	/**
	 * @var IRequest&MockObject
	 */
	private IRequest $request;

	/**
	 * @var ObjectService&MockObject
	 */
	private ObjectService $objectService;

	/**
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface $logger;

	/**
	 * Build the doubles; the session user is alice.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->request = $this->createMock(IRequest::class);
		$this->objectService = $this->createMock(ObjectService::class);
		$this->objectService->method('setRegister')->willReturnSelf();
		$this->objectService->method('setSchema')->willReturnSelf();
		$this->logger = $this->createMock(LoggerInterface::class);
	}//end setUp()

	/**
	 * The controller over the doubles, with the real catalogue and access rule.
	 *
	 * @return AgentsController
	 */
	private function controller(): AgentsController {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		$access = new AgentAccessService($this->objectService, $this->createMock(LoggerInterface::class), $this->createMock(IGroupManager::class));
		$availability = $this->createMock(AgentAvailabilityService::class);
		$availability->method('activeOrganisation')->willReturn('org-1');
		$availability->method('mayAdministerOrganisation')->willReturn(false);

		return new AgentsController(
			$this->request,
			$this->objectService,
			$this->createMock(ToolRegistryFacade::class),
			$session,
			$this->logger,
			$access,
			new AgentCatalog(
				objectService: $this->objectService,
				agentAccess: $access,
				availability: $availability,
				userManager: $this->createMock(IUserManager::class),
			)
		);
	}//end controller()

	/**
	 * An agent entity owned by alice.
	 *
	 * @param array<string, mixed> $payload The object payload.
	 *
	 * @return ObjectEntity
	 */
	private function ownAgent(array $payload): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid('agent-own');
		$entity->setOwner('alice');
		$entity->setObject($payload);
		return $entity;
	}//end ownAgent()

	/**
	 * Expect exactly one error log with the given message.
	 *
	 * @param string $message The log message.
	 *
	 * @return void
	 */
	private function expectErrorLogged(string $message): void {
		$this->logger->expects($this->once())->method('error')->with($message, $this->isType('array'));
	}//end expectErrorLogged()

	/**
	 * A page number becomes an offset of (page - 1) * limit.
	 *
	 * @return void
	 */
	public function testIndexTurnsAPageNumberIntoAnOffset(): void {
		$this->request->method('getParams')->willReturn(['_limit' => '10', '_page' => '3', '_offset' => '99']);
		$this->objectService->expects($this->once())->method('searchObjectsPaginated')
			->with(['_limit' => 10, '_offset' => 20], true)
			->willReturn(['results' => [], 'total' => 0]);

		$response = $this->controller()->index();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
	}//end testIndexTurnsAPageNumberIntoAnOffset()

	/**
	 * Without paging parameters the first 50 agents are asked for.
	 *
	 * @return void
	 */
	public function testIndexDefaultsToTheFirstFifty(): void {
		$this->request->method('getParams')->willReturn([]);
		$this->objectService->expects($this->once())->method('searchObjectsPaginated')
			->with(['_limit' => 50, '_offset' => 0], true)
			->willReturn(['results' => [], 'total' => 0]);

		$this->assertSame(Http::STATUS_OK, $this->controller()->index()->getStatus());
	}//end testIndexDefaultsToTheFirstFifty()

	/**
	 * A failing listing answers 500 with a generic error and logs it.
	 *
	 * @return void
	 */
	public function testIndexAnswers500WhenTheListingFails(): void {
		$this->request->method('getParams')->willReturn([]);
		$this->objectService->method('searchObjectsPaginated')->willThrowException(new RuntimeException('db down'));
		$this->expectErrorLogged('[AgentsController] Failed to get agents');

		$response = $this->controller()->index();

		$this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
		$this->assertSame(['error' => 'Failed to retrieve agents'], $response->getData());
	}//end testIndexAnswers500WhenTheListingFails()

	/**
	 * A failing lookup on show answers the same 404 as a missing agent.
	 *
	 * @return void
	 */
	public function testShowAnswers404WhenTheLookupFails(): void {
		$this->objectService->method('find')->willThrowException(new RuntimeException('db down'));
		$this->expectErrorLogged('[AgentsController] Failed to get agent');

		$response = $this->controller()->show('agent-1');

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		$this->assertSame(['error' => 'Agent not found'], $response->getData());
	}//end testShowAnswers404WhenTheLookupFails()

	/**
	 * A missing agent on show is a 404.
	 *
	 * @return void
	 */
	public function testShowAnswers404ForAMissingAgent(): void {
		$this->objectService->method('find')->willReturn(null);

		$response = $this->controller()->show('agent-1');

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		$this->assertSame(['error' => 'Agent not found'], $response->getData());
	}//end testShowAnswers404ForAMissingAgent()

	/**
	 * An explicit empty tool list is kept; explicit flags are not overridden.
	 *
	 * @return void
	 */
	public function testCreateKeepsExplicitToolsAndFlags(): void {
		$this->request->method('getParams')->willReturn(
			['name' => 'Quiet', 'tools' => [], 'isPrivate' => false, 'searchFiles' => false, 'searchObjects' => false]
		);
		$saved = null;
		$this->objectService->method('saveObject')->willReturnCallback(
			function (mixed $object) use (&$saved): ObjectEntity {
				$saved = $object;
				return $this->ownAgent($object);
			}
		);

		$response = $this->controller()->create();

		$this->assertSame(Http::STATUS_CREATED, $response->getStatus());
		$this->assertSame([], $saved['tools']);
		$this->assertFalse($saved['isPrivate']);
		$this->assertFalse($saved['searchFiles']);
		$this->assertFalse($saved['searchObjects']);
	}//end testCreateKeepsExplicitToolsAndFlags()

	/**
	 * Without a tool list a new agent gets the three capability meta-tools.
	 *
	 * @return void
	 */
	public function testCreateGivesANewAgentTheMetaTools(): void {
		$this->request->method('getParams')->willReturn(['name' => 'Builder']);
		$saved = null;
		$this->objectService->method('saveObject')->willReturnCallback(
			function (mixed $object) use (&$saved): ObjectEntity {
				$saved = $object;
				return $this->ownAgent($object);
			}
		);

		$this->controller()->create();

		$this->assertSame(['hermiq.searchTools', 'hermiq.listAvailableTools', 'hermiq.requestToolAccess'], $saved['tools']);
	}//end testCreateGivesANewAgentTheMetaTools()

	/**
	 * A failing save on create answers 400 naming the cause, and logs it.
	 *
	 * @return void
	 */
	public function testCreateAnswers400WhenTheSaveFails(): void {
		$this->request->method('getParams')->willReturn(['name' => 'Broken']);
		$this->objectService->method('saveObject')->willThrowException(new RuntimeException('schema missing'));
		$this->expectErrorLogged('[AgentsController] Failed to create agent');

		$response = $this->controller()->create();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame(['error' => 'Failed to create agent: schema missing'], $response->getData());
	}//end testCreateAnswers400WhenTheSaveFails()

	/**
	 * Updating a missing agent is a 404 and saves nothing.
	 *
	 * @return void
	 */
	public function testUpdateAnswers404ForAMissingAgent(): void {
		$this->objectService->method('find')->willReturn(null);
		$this->objectService->expects($this->never())->method('saveObject');

		$response = $this->controller()->update('agent-gone');

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		$this->assertSame(['error' => 'Agent not found'], $response->getData());
	}//end testUpdateAnswers404ForAMissingAgent()

	/**
	 * A failing save on update answers 400 naming the cause, and logs it.
	 *
	 * @return void
	 */
	public function testUpdateAnswers400WhenTheSaveFails(): void {
		$this->request->method('getParams')->willReturn(['name' => 'Renamed']);
		$this->objectService->method('find')->willReturn($this->ownAgent(['name' => 'Mine']));
		$this->objectService->method('saveObject')->willThrowException(new RuntimeException('locked'));
		$this->expectErrorLogged('[AgentsController] Failed to update agent');

		$response = $this->controller()->patch('agent-own');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame(['error' => 'Failed to update agent: locked'], $response->getData());
	}//end testUpdateAnswers400WhenTheSaveFails()
}//end class
