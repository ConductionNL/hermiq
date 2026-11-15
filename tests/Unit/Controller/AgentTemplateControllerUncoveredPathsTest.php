<?php

/**
 * Unit tests for AgentTemplateController's remaining guard and failure answers.
 *
 * AgentTemplateControllerTest covers the happy paths and the action-auth
 * gates. This file pins the 401 for a missing session user on every write
 * route, the 404 the ownership and read-access guards answer (including when
 * the lookup throws), the source fallback on import, the organisation fallback
 * on instantiate, and the error status and log line when the template service
 * throws.
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
 * @spec openspec/specs/agent-template-gallery/spec.md#requirement-an-agenttemplate-carries-no-secrets-and-no-tenant-data
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Controller;

use OCA\Hermiq\Controller\AgentTemplateController;
use OCA\Hermiq\Kernel\Contracts\ActionAuthServiceInterface;
use OCA\Hermiq\Kernel\Contracts\AgentAccessServiceInterface;
use OCA\Hermiq\Kernel\Contracts\AgentTemplateServiceInterface;
use OCA\Hermiq\Kernel\Contracts\GitHubTemplateCatalogServiceInterface;
use OCA\Hermiq\Kernel\Contracts\GitHubTemplatePushServiceInterface;
use OCA\Hermiq\Kernel\Contracts\SeedCustodyServiceInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\OrganisationMapper;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Guard and failure answers of AgentTemplateController.
 *
 * @covers \OCA\Hermiq\Controller\AgentTemplateController
 *
 * @spec openspec/specs/agent-template-gallery/spec.md#requirement-an-agenttemplate-carries-no-secrets-and-no-tenant-data
 */
final class AgentTemplateControllerUncoveredPathsTest extends TestCase {

	/**
	 * @var AgentTemplateServiceInterface&MockObject
	 */
	private AgentTemplateServiceInterface $service;

	/**
	 * @var IUserSession&MockObject
	 */
	private IUserSession $session;

	/**
	 * @var OrganisationMapper&MockObject
	 */
	private OrganisationMapper $organisationMapper;

	/**
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface $logger;

	/**
	 * @var SeedCustodyServiceInterface&MockObject
	 */
	private SeedCustodyServiceInterface $seedCustody;

	/**
	 * @var AgentAccessServiceInterface&MockObject
	 */
	private AgentAccessServiceInterface $agentAccess;

	/**
	 * @var ObjectService&MockObject
	 */
	private ObjectService $objectService;

	/**
	 * Request parameters the request double answers with.
	 *
	 * @var array<string, mixed>
	 */
	private array $params = [];

	/**
	 * Build the doubles; no session user until a test signs one in.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->service = $this->createMock(AgentTemplateServiceInterface::class);
		$this->session = $this->createMock(IUserSession::class);
		$this->organisationMapper = $this->createMock(OrganisationMapper::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->seedCustody = $this->createMock(SeedCustodyServiceInterface::class);
		$this->agentAccess = $this->createMock(AgentAccessServiceInterface::class);
		$this->objectService = $this->createMock(ObjectService::class);
	}//end setUp()

	/**
	 * A user double for alice.
	 *
	 * @return IUser
	 */
	private function alice(): IUser {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		return $user;
	}//end alice()

	/**
	 * Make alice the session user.
	 *
	 * @return void
	 */
	private function signInAlice(): void {
		$this->session->method('getUser')->willReturn($this->alice());
	}//end signInAlice()

	/**
	 * Leave the session without a user.
	 *
	 * @return void
	 */
	private function signedOut(): void {
		$this->session->method('getUser')->willReturn(null);
	}//end signedOut()

	/**
	 * A template entity owned by alice.
	 *
	 * @return ObjectEntity
	 */
	private function template(): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid('template-1');
		$entity->setOwner('alice');
		$entity->setObject(['name' => 'Example template', 'state' => 'active']);
		return $entity;
	}//end template()

	/**
	 * The controller over the doubles.
	 *
	 * @return AgentTemplateController
	 */
	private function controller(): AgentTemplateController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			function (string $key, $default = null) {
				return $this->params[$key] ?? $default;
			}
		);
		$request->method('getParams')->willReturnCallback(
			function (): array {
				return $this->params;
			}
		);

		return new AgentTemplateController(
			$request,
			$this->service,
			$this->createMock(ActionAuthServiceInterface::class),
			$this->session,
			$this->organisationMapper,
			$this->logger,
			$this->createMock(GitHubTemplateCatalogServiceInterface::class),
			$this->createMock(GitHubTemplatePushServiceInterface::class),
			$this->seedCustody,
			$this->agentAccess,
			$this->objectService
		);
	}//end controller()

	/**
	 * Expect exactly one error log starting with the given prefix.
	 *
	 * @param string $prefix The log message prefix.
	 *
	 * @return void
	 */
	private function expectErrorLogged(string $prefix): void {
		$this->logger->expects($this->once())->method('error')->with(
			$this->stringStartsWith($prefix),
			$this->callback(
				static function (array $context): bool {
					return ($context['exception'] ?? null) instanceof RuntimeException;
				}
			)
		);
	}//end expectErrorLogged()

	/**
	 * A failing list answers 500 and logs the cause.
	 *
	 * @return void
	 */
	public function testIndexFailureIsLoggedAndServerError(): void {
		$this->signInAlice();
		$this->service->expects($this->once())->method('list')->willThrowException(new RuntimeException('db down'));
		$this->expectErrorLogged('Hermiq agent-templates list failed: db down');

		$response = $this->controller()->index();

		$this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
		$this->assertSame(['error' => 'Could not load agent templates'], $response->getData());
	}//end testIndexFailureIsLoggedAndServerError()

	/**
	 * Every write route answers 401 without a session user and never reaches the service.
	 *
	 * @return void
	 */
	public function testWriteRoutesWithoutUserAreUnauthorized(): void {
		$this->signedOut();
		$this->service->expects($this->never())->method($this->anything());
		$this->objectService->expects($this->never())->method($this->anything());
		$this->agentAccess->expects($this->never())->method($this->anything());
		$controller = $this->controller();

		foreach ([
			$controller->create(),
			$controller->update('template-1'),
			$controller->destroy('template-1'),
			$controller->export('agent-1'),
			$controller->import(),
		] as $response) {
			$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
			$this->assertSame(['error' => 'Unauthenticated'], $response->getData());
		}
	}//end testWriteRoutesWithoutUserAreUnauthorized()

	/**
	 * Create passes the request params and the caller's uid and answers 201 with the shaped template.
	 *
	 * @return void
	 */
	public function testCreateAnswersTheShapedTemplate(): void {
		$this->signInAlice();
		$this->params = ['name' => 'Example template'];
		$this->service->expects($this->once())->method('create')
			->with(['name' => 'Example template'], 'alice')
			->willReturn($this->template());

		$response = $this->controller()->create();

		$this->assertSame(Http::STATUS_CREATED, $response->getStatus());
		$this->assertSame(
			['id' => 'template-1', 'name' => 'Example template', 'state' => 'active', 'uuid' => 'template-1'],
			$response->getData()
		);
	}//end testCreateAnswersTheShapedTemplate()

	/**
	 * A failing create answers 400 carrying the cause and logs it.
	 *
	 * @return void
	 */
	public function testCreateFailureIsBadRequest(): void {
		$this->signInAlice();
		$this->service->method('create')->willThrowException(new RuntimeException('name required'));
		$this->expectErrorLogged('Hermiq agent-template create failed: name required');

		$response = $this->controller()->create();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame(['error' => 'Could not create the agent template: name required'], $response->getData());
	}//end testCreateFailureIsBadRequest()

	/**
	 * A template the lookup does not return is a 404 on update and is never written.
	 *
	 * @return void
	 */
	public function testUpdateOfAnUnresolvedTemplateIsNotFound(): void {
		$this->signInAlice();
		$this->objectService->expects($this->once())->method('find')->willReturn(null);
		$this->seedCustody->expects($this->never())->method('actsAsOwner');
		$this->service->expects($this->never())->method('update');

		$response = $this->controller()->update('template-1');

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		$this->assertSame(['error' => 'Not found'], $response->getData());
	}//end testUpdateOfAnUnresolvedTemplateIsNotFound()

	/**
	 * A lookup that throws inside the ownership guard is logged as a warning and answered 404.
	 *
	 * @return void
	 */
	public function testUpdateWhenTheLookupThrowsIsNotFound(): void {
		$this->signInAlice();
		$this->objectService->method('find')->willThrowException(new RuntimeException('absent'));
		$this->logger->expects($this->once())->method('warning')->with(
			'Hermiq agent-template lookup failed for template-9: absent',
			$this->isType('array')
		);
		$this->service->expects($this->never())->method('update');

		$response = $this->controller()->update('template-9');

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		$this->assertSame(['error' => 'Not found'], $response->getData());
	}//end testUpdateWhenTheLookupThrowsIsNotFound()

	/**
	 * A session that loses its user between the route check and the guard is refused 404.
	 *
	 * @return void
	 */
	public function testUpdateWhenTheGuardSeesNoUserIsNotFound(): void {
		$this->session->method('getUser')->willReturnOnConsecutiveCalls($this->alice(), null);
		$this->objectService->expects($this->never())->method('find');
		$this->service->expects($this->never())->method('update');

		$response = $this->controller()->update('template-1');

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
	}//end testUpdateWhenTheGuardSeesNoUserIsNotFound()

	/**
	 * An owner's update that the service rejects answers 400 with the cause.
	 *
	 * @return void
	 */
	public function testUpdateFailureIsBadRequest(): void {
		$this->signInAlice();
		$this->params = ['name' => 'Renamed'];
		$this->objectService->method('find')->willReturn($this->template());
		$this->seedCustody->expects($this->once())->method('actsAsOwner')->with('alice', 'alice')->willReturn(true);
		$this->service->expects($this->once())->method('update')
			->with('template-1', ['name' => 'Renamed'])
			->willThrowException(new RuntimeException('invalid state'));
		$this->expectErrorLogged('Hermiq agent-template update failed: invalid state');

		$response = $this->controller()->update('template-1');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame(['error' => 'Could not update the agent template: invalid state'], $response->getData());
	}//end testUpdateFailureIsBadRequest()

	/**
	 * An owner's delete that throws answers 500 and logs the cause.
	 *
	 * @return void
	 */
	public function testDestroyFailureIsServerError(): void {
		$this->signInAlice();
		$this->objectService->method('find')->willReturn($this->template());
		$this->seedCustody->method('actsAsOwner')->willReturn(true);
		$this->objectService->expects($this->once())->method('deleteObject')
			->willThrowException(new RuntimeException('locked'));
		$this->expectErrorLogged('Hermiq agent-template delete failed: locked');

		$response = $this->controller()->destroy('template-1');

		$this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
		$this->assertSame(['error' => 'Could not delete the agent template'], $response->getData());
	}//end testDestroyFailureIsServerError()

	/**
	 * Exporting an agent the caller cannot read is a 404 and never builds a package.
	 *
	 * @return void
	 */
	public function testExportOfAnUnreadableAgentIsNotFound(): void {
		$this->signInAlice();
		$this->agentAccess->expects($this->once())->method('loadAccessibleAgent')
			->with('agent-1', 'alice')
			->willReturn(null);
		$this->service->expects($this->never())->method('exportFromAgent');

		$response = $this->controller()->export('agent-1');

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		$this->assertSame(['error' => 'Agent not found'], $response->getData());
	}//end testExportOfAnUnreadableAgentIsNotFound()

	/**
	 * A session that loses its user before the read guard is refused 404.
	 *
	 * @return void
	 */
	public function testExportWhenTheGuardSeesNoUserIsNotFound(): void {
		$this->session->method('getUser')->willReturnOnConsecutiveCalls($this->alice(), null);
		$this->agentAccess->expects($this->never())->method('loadAccessibleAgent');
		$this->service->expects($this->never())->method('exportFromAgent');

		$response = $this->controller()->export('agent-1');

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
	}//end testExportWhenTheGuardSeesNoUserIsNotFound()

	/**
	 * A failing agent export answers 500 and logs the cause.
	 *
	 * @return void
	 */
	public function testExportFailureIsServerError(): void {
		$this->signInAlice();
		$this->agentAccess->method('loadAccessibleAgent')->willReturn(new ObjectEntity());
		$this->service->expects($this->once())->method('exportFromAgent')->with('agent-1')
			->willThrowException(new RuntimeException('serialise'));
		$this->expectErrorLogged('Hermiq agent-template export failed: serialise');

		$response = $this->controller()->export('agent-1');

		$this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
		$this->assertSame(['error' => 'Export failed'], $response->getData());
	}//end testExportFailureIsServerError()

	/**
	 * A failing template package export answers 500 and logs the cause.
	 *
	 * @return void
	 */
	public function testExportPackageFailureIsServerError(): void {
		$this->signInAlice();
		$this->service->expects($this->once())->method('exportTemplate')->with('template-1')
			->willThrowException(new RuntimeException('serialise'));
		$this->expectErrorLogged('Hermiq agent-template export-package failed: serialise');

		$response = $this->controller()->exportPackage('template-1');

		$this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
		$this->assertSame(['error' => 'Export failed'], $response->getData());
	}//end testExportPackageFailureIsServerError()

	/**
	 * An unknown import source falls back to `org`, so the package lands quarantined.
	 *
	 * @return void
	 */
	public function testImportWithAnUnknownSourceFallsBackToOrg(): void {
		$this->signInAlice();
		$this->params = ['package' => '{"name":"x"}', 'source' => 'internet'];
		$this->service->expects($this->once())->method('importPackage')
			->with('{"name":"x"}', 'org', 'alice')
			->willReturn($this->template());

		$response = $this->controller()->import();

		$this->assertSame(Http::STATUS_CREATED, $response->getStatus());
		$this->assertSame('template-1', $response->getData()['uuid']);
	}//end testImportWithAnUnknownSourceFallsBackToOrg()

	/**
	 * A failing import answers 500 and logs the cause.
	 *
	 * @return void
	 */
	public function testImportFailureIsServerError(): void {
		$this->signInAlice();
		$this->params = ['package' => '{"name":"x"}', 'source' => 'local'];
		$this->service->expects($this->once())->method('importPackage')
			->with('{"name":"x"}', 'local', 'alice')
			->willThrowException(new RuntimeException('bad json'));
		$this->expectErrorLogged('Hermiq agent-template import failed: bad json');

		$response = $this->controller()->import();

		$this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
		$this->assertSame(['error' => 'Import failed'], $response->getData());
	}//end testImportFailureIsServerError()

	/**
	 * When the active organisation cannot be resolved, instantiate uses the instance-wide scope ''.
	 *
	 * @return void
	 */
	public function testInstantiateFallsBackToTheInstanceScope(): void {
		$this->signInAlice();
		$this->params = ['overrides' => ['name' => 'Mine']];
		$this->organisationMapper->method('getActiveOrganisationWithFallback')
			->willThrowException(new RuntimeException('no org'));
		$this->logger->expects($this->once())->method('warning')->with(
			'Hermiq could not resolve active organisation: no org',
			$this->isType('array')
		);
		$this->service->expects($this->once())->method('instantiate')
			->with('template-1', '', ['name' => 'Mine'])
			->willReturn(['agentId' => 'agent-9']);

		$response = $this->controller()->instantiate('template-1');

		$this->assertSame(Http::STATUS_CREATED, $response->getStatus());
		$this->assertSame(['agentId' => 'agent-9'], $response->getData());
	}//end testInstantiateFallsBackToTheInstanceScope()

	/**
	 * A failing instantiate answers 400 with the cause and logs it.
	 *
	 * @return void
	 */
	public function testInstantiateFailureIsBadRequest(): void {
		$this->signInAlice();
		$this->organisationMapper->method('getActiveOrganisationWithFallback')->willReturn('org-a');
		$this->service->expects($this->once())->method('instantiate')
			->with('template-1', 'org-a', [])
			->willThrowException(new RuntimeException('policy'));
		$this->expectErrorLogged('Hermiq agent-template instantiate failed: policy');

		$response = $this->controller()->instantiate('template-1');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame(['error' => 'Could not create an agent from this template: policy'], $response->getData());
	}//end testInstantiateFailureIsBadRequest()
	/**
	 * An owner's update the service cannot resolve any more answers 404.
	 *
	 * @return void
	 */
	public function testUpdateThatTheServiceCannotResolveIsNotFound(): void {
		$this->signInAlice();
		$this->objectService->method('find')->willReturn($this->template());
		$this->seedCustody->method('actsAsOwner')->willReturn(true);
		$this->service->expects($this->once())->method('update')->with('template-1', [])->willReturn(null);

		$response = $this->controller()->update('template-1');

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		$this->assertSame(['error' => 'Not found'], $response->getData());
	}//end testUpdateThatTheServiceCannotResolveIsNotFound()

	/**
	 * A readable agent the service cannot package answers 404.
	 *
	 * @return void
	 */
	public function testExportWithoutAPackageIsNotFound(): void {
		$this->signInAlice();
		$this->agentAccess->method('loadAccessibleAgent')->willReturn(new ObjectEntity());
		$this->service->expects($this->once())->method('exportFromAgent')->with('agent-1')->willReturn(null);

		$response = $this->controller()->export('agent-1');

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		$this->assertSame(['error' => 'Agent not found'], $response->getData());
	}//end testExportWithoutAPackageIsNotFound()
}//end class
