<?php

/**
 * Unit tests for AgentWebhookController (agent-webhook-trigger).
 *
 * Focuses on the ADR-005 IDOR guard (mirrors RunNowControllerTest): only the
 * agent owner may create/rotate/revoke/patch/read their agent's webhook
 * secret; a non-owner (or an unknown agent) gets a 404 that leaks nothing, an
 * unauthenticated caller gets 401, and create() returns 409 when a webhook
 * already exists.
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
 * @spec openspec/changes/agent-webhook-trigger/tasks.md#task-2-agentwebhookcontroller-session-authenticated-owner-guarded-crud
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Controller;

use OCA\Hermiq\Controller\AgentWebhookController;
use OCA\Hermiq\Service\WebhookSecretService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Tests for the agent-webhook-trigger AgentWebhookController.
 *
 * @spec openspec/changes/agent-webhook-trigger/tasks.md#task-2-agentwebhookcontroller-session-authenticated-owner-guarded-crud
 */
class AgentWebhookControllerTest extends TestCase {

	/**
	 * Mock ObjectService.
	 *
	 * @var ObjectService&\PHPUnit\Framework\MockObject\MockObject
	 */
	private ObjectService $objectService;

	/**
	 * Mock WebhookSecretService.
	 *
	 * @var WebhookSecretService&\PHPUnit\Framework\MockObject\MockObject
	 */
	private WebhookSecretService $webhookSecretService;

	/**
	 * Build an agent ObjectEntity owned by $owner.
	 *
	 * @param string $owner The owner UID.
	 *
	 * @return ObjectEntity
	 */
	private function agent(string $owner): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid('agent-1');
		$entity->setOwner($owner);
		$entity->setObject(['name' => 'Support triage']);
		return $entity;
	}//end agent()

	/**
	 * Build the controller with the given collaborators.
	 *
	 * @param IUserSession $userSession The user session.
	 *
	 * @return AgentWebhookController
	 */
	private function controller(IUserSession $userSession): AgentWebhookController {
		return new AgentWebhookController(
			$this->createMock(IRequest::class),
			$this->objectService,
			$userSession,
			$this->webhookSecretService,
			$this->createMock(LoggerInterface::class)
		);

	}//end controller()

	/**
	 * A session with the given (or no) user.
	 *
	 * @param string|null $uid The UID, or null for unauthenticated.
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
	 * Wire fresh mocks before each test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->objectService = $this->createMock(ObjectService::class);
		$this->webhookSecretService = $this->createMock(WebhookSecretService::class);

	}//end setUp()

	/**
	 * An unauthenticated caller gets 401 on every endpoint.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/agent-webhook-trigger/specs/agent-webhook-trigger/spec.md#requirement-a-per-agent-webhook-secret-can-be-created-rotated-and-revoked
	 */
	public function testUnauthenticatedGets401(): void {
		$controller = $this->controller($this->session(null));

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $controller->create('agent-1')->getStatus());
		$this->assertSame(Http::STATUS_UNAUTHORIZED, $controller->rotate('agent-1')->getStatus());
		$this->assertSame(Http::STATUS_UNAUTHORIZED, $controller->revoke('agent-1')->getStatus());
		$this->assertSame(Http::STATUS_UNAUTHORIZED, $controller->patch('agent-1')->getStatus());
		$this->assertSame(Http::STATUS_UNAUTHORIZED, $controller->show('agent-1')->getStatus());

	}//end testUnauthenticatedGets401()

	/**
	 * A non-owner gets 404 (never 403) for every endpoint, so they cannot
	 * confirm the agent's existence — mirrors RunNowController's IDOR guard.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/agent-webhook-trigger/specs/agent-webhook-trigger/spec.md#requirement-a-per-agent-webhook-secret-can-be-created-rotated-and-revoked
	 */
	public function testNonOwnerGets404OnEveryEndpoint(): void {
		$this->objectService->method('find')->willReturn($this->agent('alice'));
		$this->webhookSecretService->expects($this->never())->method('create');
		$this->webhookSecretService->expects($this->never())->method('rotate');
		$this->webhookSecretService->expects($this->never())->method('revoke');
		$this->webhookSecretService->expects($this->never())->method('patch');

		$controller = $this->controller($this->session('mallory'));

		$this->assertSame(Http::STATUS_NOT_FOUND, $controller->create('agent-1')->getStatus());
		$this->assertSame(Http::STATUS_NOT_FOUND, $controller->rotate('agent-1')->getStatus());
		$this->assertSame(Http::STATUS_NOT_FOUND, $controller->revoke('agent-1')->getStatus());
		$this->assertSame(Http::STATUS_NOT_FOUND, $controller->patch('agent-1')->getStatus());
		$this->assertSame(Http::STATUS_NOT_FOUND, $controller->show('agent-1')->getStatus());

	}//end testNonOwnerGets404OnEveryEndpoint()

	/**
	 * An unknown agent id gets 404 on every endpoint.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/agent-webhook-trigger/specs/agent-webhook-trigger/spec.md#requirement-a-per-agent-webhook-secret-can-be-created-rotated-and-revoked
	 */
	public function testUnknownAgentGets404(): void {
		$this->objectService->method('find')->willReturn(null);

		$controller = $this->controller($this->session('alice'));

		$this->assertSame(Http::STATUS_NOT_FOUND, $controller->create('nope')->getStatus());
		$this->assertSame(Http::STATUS_NOT_FOUND, $controller->show('nope')->getStatus());

	}//end testUnknownAgentGets404()

	/**
	 * A THROWING agent lookup gets the same 404, not a 500.
	 *
	 * `ObjectService::find()` documents `@throws Exception If the object is not
	 * found`, and every endpoint here calls `loadOwnedAgent()` OUTSIDE its own
	 * try block — so before the fix the throw escaped to the dispatcher as a
	 * framework 500 with a stack trace on a `#[NoAdminRequired]` route, on the
	 * routes that mint and reveal a webhook secret.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/agent-webhook-trigger/specs/agent-webhook-trigger/spec.md#requirement-a-per-agent-webhook-secret-can-be-created-rotated-and-revoked
	 */
	public function testThrowingAgentLookupGets404(): void {
		$this->objectService->method('find')->willThrowException(new DoesNotExistException('no such object'));

		$controller = $this->controller($this->session('alice'));

		$this->assertSame(Http::STATUS_NOT_FOUND, $controller->create('nope')->getStatus());
		$this->assertSame(Http::STATUS_NOT_FOUND, $controller->show('nope')->getStatus());

	}//end testThrowingAgentLookupGets404()

	/**
	 * The owner can create a webhook: 201 with the plaintext secret.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/agent-webhook-trigger/specs/agent-webhook-trigger/spec.md#requirement-a-per-agent-webhook-secret-can-be-created-rotated-and-revoked
	 */
	public function testOwnerCanCreateWebhook(): void {
		$this->objectService->method('find')->willReturn($this->agent('alice'));
		$webhookObject = new ObjectEntity();
		$webhookObject->setObject(['enabled' => true, 'secretPrefix' => 'hwh_ab12']);
		$this->webhookSecretService->method('create')
			->with('agent-1', 'alice')
			->willReturn(['secret' => 'hwh_plaintext', 'object' => $webhookObject]);
		$this->webhookSecretService->method('status')->willReturn(['configured' => true, 'enabled' => true]);

		$response = $this->controller($this->session('alice'))->create('agent-1');

		$this->assertSame(Http::STATUS_CREATED, $response->getStatus());
		$this->assertSame('hwh_plaintext', $response->getData()['secret']);

	}//end testOwnerCanCreateWebhook()

	/**
	 * A create request when a webhook already exists gets 409, instructing the
	 * caller to rotate instead.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/agent-webhook-trigger/specs/agent-webhook-trigger/spec.md#requirement-a-per-agent-webhook-secret-can-be-created-rotated-and-revoked
	 */
	public function testCreateConflictsWhenWebhookAlreadyExists(): void {
		$this->objectService->method('find')->willReturn($this->agent('alice'));
		$this->webhookSecretService->method('create')->willThrowException(new RuntimeException('exists'));

		$response = $this->controller($this->session('alice'))->create('agent-1');

		$this->assertSame(Http::STATUS_CONFLICT, $response->getStatus());

	}//end testCreateConflictsWhenWebhookAlreadyExists()

	/**
	 * Rotating a non-configured webhook returns 404.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/agent-webhook-trigger/specs/agent-webhook-trigger/spec.md#requirement-a-per-agent-webhook-secret-can-be-created-rotated-and-revoked
	 */
	public function testRotateWithoutExistingWebhookReturns404(): void {
		$this->objectService->method('find')->willReturn($this->agent('alice'));
		$this->webhookSecretService->method('findForAgent')->willReturn(null);

		$response = $this->controller($this->session('alice'))->rotate('agent-1');

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());

	}//end testRotateWithoutExistingWebhookReturns404()

	/**
	 * show() reports {configured:false} when no webhook exists yet, for the owner.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/agent-webhook-trigger/specs/agent-webhook-trigger/spec.md#requirement-a-per-agent-webhook-secret-can-be-created-rotated-and-revoked
	 */
	public function testShowReportsUnconfiguredForOwnerWithNoWebhook(): void {
		$this->objectService->method('find')->willReturn($this->agent('alice'));
		$this->webhookSecretService->method('findForAgent')->willReturn(null);
		$this->webhookSecretService->method('status')->with(null)->willReturn(['configured' => false]);

		$response = $this->controller($this->session('alice'))->show('agent-1');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['configured' => false], $response->getData());

	}//end testShowReportsUnconfiguredForOwnerWithNoWebhook()
}//end class
