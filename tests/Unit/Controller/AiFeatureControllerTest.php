<?php

/**
 * Unit tests for AiFeatureController (ai-feature-governance-register).
 *
 * Focuses on the ADR-023 action-auth gate on the three governance mutations: each of
 * acknowledge/enable/disable calls ActionAuthService::requireAction() before doing work;
 * a refused (non-admin / non-DPO) caller gets 403, an unauthenticated caller 401. Also
 * covers the transition-outcome → HTTP mapping (enabled=200, blocked=409, notfound=404).
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
 * @spec openspec/changes/ai-feature-governance-register/tasks.md#task-6-2
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Controller;

use OCA\Hermiq\Controller\AiFeatureController;
use OCA\Hermiq\Service\ActionAuthService;
use OCA\Hermiq\Service\AiFeatureService;
use OCA\Hermiq\Service\AlgoritmekaderMapper;
use OCA\Hermiq\Service\PublicationGateway;
use OCA\OpenRegister\Db\ObjectEntity;
use OCP\AppFramework\Http;
use OCP\AppFramework\OCS\OCSForbiddenException;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for the AI-feature governance controller.
 *
 * @spec openspec/changes/ai-feature-governance-register/tasks.md#task-6-2
 */
class AiFeatureControllerTest extends TestCase {
	/**
	 * An AiFeature ObjectEntity with the given slug.
	 *
	 * @param string $slug The feature slug.
	 *
	 * @return ObjectEntity
	 */
	private function feature(string $slug = 'autonomous-agent-run'): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid('feat-1');
		$entity->setObject(['slug' => $slug, 'name' => 'Autonomous agent run', 'lifecycle' => 'disabled']);
		return $entity;
	}//end feature()

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
	 * Build the controller with the given collaborators.
	 *
	 * @param AiFeatureService $service The AiFeature service.
	 * @param ActionAuthService $actionAuth The action-auth service.
	 * @param IUserSession $session The user session.
	 * @param AlgoritmekaderMapper|null $mapper The Algoritmekader mapper (readiness + map).
	 * @param PublicationGateway|null $gateway The publication gateway (runtime seam).
	 *
	 * @return AiFeatureController
	 */
	private function controller(
		AiFeatureService $service,
		ActionAuthService $actionAuth,
		IUserSession $session,
		?AlgoritmekaderMapper $mapper = null,
		?PublicationGateway $gateway = null,
	): AiFeatureController {
		return new AiFeatureController(
			$this->createMock(IRequest::class),
			$service,
			$actionAuth,
			$session,
			$this->createMock(LoggerInterface::class),
			($mapper ?? $this->createMock(AlgoritmekaderMapper::class)),
			($gateway ?? $this->createMock(PublicationGateway::class))
		);

	}//end controller()

	/**
	 * The index lists the tenant's features for an authenticated caller.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/ai-feature-governance-register/tasks.md#task-6-2
	 */
	public function testIndexReturnsFeatures(): void {
		$service = $this->createMock(AiFeatureService::class);
		$service->method('listFeatures')->willReturn([$this->feature()]);

		$response = $this->controller($service, $this->createMock(ActionAuthService::class), $this->session('admin'))->index();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(1, $response->getData()['total']);

	}//end testIndexReturnsFeatures()

	/**
	 * An unauthenticated caller gets 401 on index.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/ai-feature-governance-register/tasks.md#task-6-2
	 */
	public function testIndexUnauthenticated(): void {
		$response = $this->controller(
			$this->createMock(AiFeatureService::class),
			$this->createMock(ActionAuthService::class),
			$this->session(null)
		)->index();

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());

	}//end testIndexUnauthenticated()

	/**
	 * An unauthenticated caller gets 401 on a mutating endpoint (before action-auth).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/ai-feature-governance-register/tasks.md#task-6-2
	 */
	public function testAcknowledgeUnauthenticated(): void {
		$actionAuth = $this->createMock(ActionAuthService::class);
		$actionAuth->expects($this->never())->method('requireAction');

		$response = $this->controller(
			$this->createMock(AiFeatureService::class),
			$actionAuth,
			$this->session(null)
		)->acknowledge('autonomous-agent-run');

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());

	}//end testAcknowledgeUnauthenticated()

	/**
	 * Acknowledge gates on requireAction('aifeature.acknowledge') then stamps the feature.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/ai-feature-governance-register/tasks.md#task-6-2
	 */
	public function testAcknowledgeCallsRequireActionAndSucceeds(): void {
		$service = $this->createMock(AiFeatureService::class);
		$service->method('acknowledge')->willReturn($this->feature());

		$actionAuth = $this->createMock(ActionAuthService::class);
		$actionAuth->expects($this->once())
			->method('requireAction')
			->with($this->anything(), 'aifeature.acknowledge');

		$response = $this->controller($service, $actionAuth, $this->session('admin'))->acknowledge('autonomous-agent-run');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());

	}//end testAcknowledgeCallsRequireActionAndSucceeds()

	/**
	 * A non-admin / non-DPO caller is refused (403) on acknowledge, never reaching the service.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/ai-feature-governance-register/tasks.md#task-6-2
	 */
	public function testAcknowledgeForbiddenForNonAdmin(): void {
		$service = $this->createMock(AiFeatureService::class);
		$service->expects($this->never())->method('acknowledge');

		$actionAuth = $this->createMock(ActionAuthService::class);
		$actionAuth->method('requireAction')->willThrowException(new OCSForbiddenException('nope'));

		$response = $this->controller($service, $actionAuth, $this->session('mallory'))->acknowledge('autonomous-agent-run');

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());

	}//end testAcknowledgeForbiddenForNonAdmin()

	/**
	 * Acknowledge returns 404 when no feature has that slug.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/ai-feature-governance-register/tasks.md#task-6-2
	 */
	public function testAcknowledgeNotFound(): void {
		$service = $this->createMock(AiFeatureService::class);
		$service->method('acknowledge')->willReturn(null);

		$response = $this->controller($service, $this->createMock(ActionAuthService::class), $this->session('admin'))->acknowledge('missing');

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());

	}//end testAcknowledgeNotFound()

	/**
	 * Enable gates on requireAction('aifeature.enable') and returns 200 on success.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/ai-feature-governance-register/tasks.md#task-6-2
	 */
	public function testEnableCallsRequireActionAndSucceeds(): void {
		$service = $this->createMock(AiFeatureService::class);
		$service->method('enable')->willReturn(AiFeatureService::RESULT_ENABLED);

		$actionAuth = $this->createMock(ActionAuthService::class);
		$actionAuth->expects($this->once())
			->method('requireAction')
			->with($this->anything(), 'aifeature.enable');

		$response = $this->controller($service, $actionAuth, $this->session('admin'))->enable('feat-1');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame('enabled', $response->getData()['lifecycle']);

	}//end testEnableCallsRequireActionAndSucceeds()

	/**
	 * Enable is refused (403) for a non-admin / non-DPO caller, never driving the transition.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/ai-feature-governance-register/tasks.md#task-6-2
	 */
	public function testEnableForbiddenForNonAdmin(): void {
		$service = $this->createMock(AiFeatureService::class);
		$service->expects($this->never())->method('enable');

		$actionAuth = $this->createMock(ActionAuthService::class);
		$actionAuth->method('requireAction')->willThrowException(new OCSForbiddenException('nope'));

		$response = $this->controller($service, $actionAuth, $this->session('mallory'))->enable('feat-1');

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());

	}//end testEnableForbiddenForNonAdmin()

	/**
	 * A blocked enable (guard denied — no DPO ack) surfaces as 409.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/ai-feature-governance-register/tasks.md#task-6-2
	 */
	public function testEnableBlockedIsConflict(): void {
		$service = $this->createMock(AiFeatureService::class);
		$service->method('enable')->willReturn(AiFeatureService::RESULT_BLOCKED);

		$response = $this->controller($service, $this->createMock(ActionAuthService::class), $this->session('admin'))->enable('feat-1');

		$this->assertSame(Http::STATUS_CONFLICT, $response->getStatus());

	}//end testEnableBlockedIsConflict()

	/**
	 * Enabling a cross-tenant / missing feature is 404.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/ai-feature-governance-register/tasks.md#task-6-2
	 */
	public function testEnableNotFound(): void {
		$service = $this->createMock(AiFeatureService::class);
		$service->method('enable')->willReturn(AiFeatureService::RESULT_NOT_FOUND);

		$response = $this->controller($service, $this->createMock(ActionAuthService::class), $this->session('admin'))->enable('feat-1');

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());

	}//end testEnableNotFound()

	/**
	 * Disable gates on requireAction('aifeature.disable') and returns 200 on success.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/ai-feature-governance-register/tasks.md#task-6-2
	 */
	public function testDisableCallsRequireActionAndSucceeds(): void {
		$service = $this->createMock(AiFeatureService::class);
		$service->method('disable')->willReturn(AiFeatureService::RESULT_DISABLED);

		$actionAuth = $this->createMock(ActionAuthService::class);
		$actionAuth->expects($this->once())
			->method('requireAction')
			->with($this->anything(), 'aifeature.disable');

		$response = $this->controller($service, $actionAuth, $this->session('admin'))->disable('feat-1');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame('disabled', $response->getData()['lifecycle']);

	}//end testDisableCallsRequireActionAndSucceeds()

	/**
	 * Disable is refused (403) for a non-admin / non-DPO caller.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/ai-feature-governance-register/tasks.md#task-6-2
	 */
	public function testDisableForbiddenForNonAdmin(): void {
		$service = $this->createMock(AiFeatureService::class);
		$service->expects($this->never())->method('disable');

		$actionAuth = $this->createMock(ActionAuthService::class);
		$actionAuth->method('requireAction')->willThrowException(new OCSForbiddenException('nope'));

		$response = $this->controller($service, $actionAuth, $this->session('mallory'))->disable('feat-1');

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());

	}//end testDisableForbiddenForNonAdmin()

	/**
	 * An unauthenticated caller gets 401 on publish (before action-auth).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/algoritmeregister-publication/tasks.md#3-publish-withdraw-action-delegated-to-opencatalogi
	 */
	public function testPublishUnauthenticated(): void {
		$actionAuth = $this->createMock(ActionAuthService::class);
		$actionAuth->expects($this->never())->method('requireAction');

		$response = $this->controller(
			$this->createMock(AiFeatureService::class),
			$actionAuth,
			$this->session(null)
		)->publishToAlgoritmeregister('feat-1');

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());

	}//end testPublishUnauthenticated()

	/**
	 * A non-admin caller is refused (403) on publish, never reaching the service.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/algoritmeregister-publication/tasks.md#3-publish-withdraw-action-delegated-to-opencatalogi
	 */
	public function testPublishForbiddenForNonAdmin(): void {
		$service = $this->createMock(AiFeatureService::class);
		$service->expects($this->never())->method('getFeature');

		$actionAuth = $this->createMock(ActionAuthService::class);
		$actionAuth->method('requireAction')->willThrowException(new OCSForbiddenException('nope'));

		$response = $this->controller($service, $actionAuth, $this->session('mallory'))->publishToAlgoritmeregister('feat-1');

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());

	}//end testPublishForbiddenForNonAdmin()

	/**
	 * Publishing a missing / cross-tenant feature is 404.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/algoritmeregister-publication/tasks.md#3-publish-withdraw-action-delegated-to-opencatalogi
	 */
	public function testPublishNotFound(): void {
		$service = $this->createMock(AiFeatureService::class);
		$service->method('getFeature')->willReturn(null);

		$response = $this->controller($service, $this->createMock(ActionAuthService::class), $this->session('admin'))
			->publishToAlgoritmeregister('missing');

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());

	}//end testPublishNotFound()

	/**
	 * A not-ready feature is refused fail-closed (422) with the failing conditions named.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/algoritmeregister-publication/specs/algoritmeregister-publication/spec.md#requirement-publication-is-gated-to-impactful-enabled-dpo-acknowledged-fully-described-features
	 */
	public function testPublishRefusedWhenNotReady(): void {
		$service = $this->createMock(AiFeatureService::class);
		$service->method('getFeature')->willReturn($this->feature());
		$service->expects($this->never())->method('recordPublication');

		$mapper = $this->createMock(AlgoritmekaderMapper::class);
		$mapper->method('assessReadiness')->willReturn(['wettelijkeGrondslag']);

		$gateway = $this->createMock(PublicationGateway::class);
		$gateway->expects($this->never())->method('publish');

		$response = $this->controller($service, $this->createMock(ActionAuthService::class), $this->session('admin'), $mapper, $gateway)
			->publishToAlgoritmeregister('feat-1');

		$this->assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $response->getStatus());
		$this->assertSame(['wettelijkeGrondslag'], $response->getData()['missing']);

	}//end testPublishRefusedWhenNotReady()

	/**
	 * When OpenCatalogi is absent, publish is unavailable (503) — the feature stays governable.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/algoritmeregister-publication/specs/algoritmeregister-publication/spec.md#requirement-publication-is-delegated-to-the-fleet-publication-path-not-re-implemented
	 */
	public function testPublishUnavailableWithoutOpenCatalogi(): void {
		$service = $this->createMock(AiFeatureService::class);
		$service->method('getFeature')->willReturn($this->feature());
		$service->expects($this->never())->method('recordPublication');

		$mapper = $this->createMock(AlgoritmekaderMapper::class);
		$mapper->method('assessReadiness')->willReturn([]);

		$gateway = $this->createMock(PublicationGateway::class);
		$gateway->method('isAvailable')->willReturn(false);
		$gateway->expects($this->never())->method('publish');

		$response = $this->controller($service, $this->createMock(ActionAuthService::class), $this->session('admin'), $mapper, $gateway)
			->publishToAlgoritmeregister('feat-1');

		$this->assertSame(Http::STATUS_SERVICE_UNAVAILABLE, $response->getStatus());

	}//end testPublishUnavailableWithoutOpenCatalogi()

	/**
	 * A ready publish delegates to the seam and records the external reference (200).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/algoritmeregister-publication/specs/algoritmeregister-publication/spec.md#requirement-publication-is-delegated-to-the-fleet-publication-path-not-re-implemented
	 */
	public function testPublishDelegatesAndRecordsReference(): void {
		$stamped = $this->feature();
		$stamped->setObject(['slug' => 'x', 'algoritmeregisterStatus' => 'gepubliceerd', 'algoritmeregisterRef' => 'pub-9']);

		$service = $this->createMock(AiFeatureService::class);
		$service->method('getFeature')->willReturn($this->feature());
		$service->expects($this->once())
			->method('recordPublication')
			->with('feat-1', 'pub-9')
			->willReturn($stamped);

		$mapper = $this->createMock(AlgoritmekaderMapper::class);
		$mapper->method('assessReadiness')->willReturn([]);
		$mapper->method('map')->willReturn(['title' => 'x']);

		$gateway = $this->createMock(PublicationGateway::class);
		$gateway->method('isAvailable')->willReturn(true);
		$gateway->expects($this->once())->method('publish')->with(['title' => 'x'])->willReturn('pub-9');

		$response = $this->controller($service, $this->createMock(ActionAuthService::class), $this->session('admin'), $mapper, $gateway)
			->publishToAlgoritmeregister('feat-1');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame('gepubliceerd', $response->getData()['algoritmeregisterStatus']);
		$this->assertSame('pub-9', $response->getData()['algoritmeregisterRef']);

	}//end testPublishDelegatesAndRecordsReference()

	/**
	 * An unauthenticated caller gets 401 on withdraw (before action-auth).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/algoritmeregister-publication/tasks.md#3-publish-withdraw-action-delegated-to-opencatalogi
	 */
	public function testWithdrawUnauthenticated(): void {
		$actionAuth = $this->createMock(ActionAuthService::class);
		$actionAuth->expects($this->never())->method('requireAction');

		$response = $this->controller(
			$this->createMock(AiFeatureService::class),
			$actionAuth,
			$this->session(null)
		)->withdrawFromAlgoritmeregister('feat-1');

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());

	}//end testWithdrawUnauthenticated()

	/**
	 * Withdraw requests unpublication via the seam and stamps `ingetrokken` (200).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/algoritmeregister-publication/specs/algoritmeregister-publication/spec.md#requirement-publication-is-delegated-to-the-fleet-publication-path-not-re-implemented
	 */
	public function testWithdrawStampsIngetrokken(): void {
		$published = $this->feature();
		$published->setObject(['slug' => 'x', 'algoritmeregisterRef' => 'pub-9']);

		$stamped = $this->feature();
		$stamped->setObject(['slug' => 'x', 'algoritmeregisterStatus' => 'ingetrokken']);

		$service = $this->createMock(AiFeatureService::class);
		$service->method('getFeature')->willReturn($published);
		$service->method('recordWithdrawal')->willReturn($stamped);

		$gateway = $this->createMock(PublicationGateway::class);
		$gateway->expects($this->once())->method('withdraw')->with('pub-9')->willReturn(true);

		$response = $this->controller($service, $this->createMock(ActionAuthService::class), $this->session('admin'), null, $gateway)
			->withdrawFromAlgoritmeregister('feat-1');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame('ingetrokken', $response->getData()['algoritmeregisterStatus']);

	}//end testWithdrawStampsIngetrokken()
}//end class
