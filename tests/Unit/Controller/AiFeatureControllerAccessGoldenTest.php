<?php

/**
 * Golden tests for AiFeatureController's gated actions.
 *
 * Pins the allowed and refused outcomes of every action-gated route: an anonymous
 * caller gets 401 before any action check, a refused caller gets 403 with the refusal
 * message and never reaches a service, and an allowed caller is checked against the
 * route's own action name. Also pins every outcome of the Algoritmeregister publish.
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
 * @spec openspec/changes/algoritmeregister-publication/tasks.md#3-publish-withdraw-action-delegated-to-opencatalogi
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Controller;

use OCA\Hermiq\Controller\AiFeatureController;
use OCA\Hermiq\Service\ActionAuthService;
use OCA\Hermiq\Service\AiFeature\AiFeatureBindingService;
use OCA\Hermiq\Service\AiFeature\FeatureProviderResolver;
use OCA\Hermiq\Service\AiFeatureService;
use OCA\Hermiq\Service\AlgoritmekaderMapper;
use OCA\Hermiq\Service\PublicationGateway;
use OCA\OpenRegister\Db\ObjectEntity;
use OCP\AppFramework\OCS\OCSForbiddenException;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Golden tests for the gated AI-feature routes.
 *
 * @spec openspec/changes/algoritmeregister-publication/tasks.md#3-publish-withdraw-action-delegated-to-opencatalogi
 */
class AiFeatureControllerAccessGoldenTest extends TestCase {

	/**
	 * The action names the action check was asked for.
	 *
	 * @var array<int, string>
	 */
	private array $asked = [];

	/**
	 * Every gated route, with the action it requires.
	 *
	 * @return array<string, array{0: string, 1: string}>
	 */
	public static function routes(): array {
		return [
			'acknowledge' => ['acknowledge', 'aifeature.acknowledge'],
			'bind' => ['bind', 'aifeature.bind'],
			'enable' => ['enable', 'aifeature.enable'],
			'disable' => ['disable', 'aifeature.disable'],
			'publish' => ['publishToAlgoritmeregister', 'aifeature.publish-to-algoritmeregister'],
			'withdraw' => ['withdrawFromAlgoritmeregister', 'aifeature.withdraw-from-algoritmeregister'],
		];
	}//end routes()

	/**
	 * A controller over the given collaborators.
	 *
	 * @param string|null $uid The caller, or null for anonymous.
	 * @param bool $allowed Whether the action check passes.
	 * @param AiFeatureService|null $service The feature service.
	 * @param AlgoritmekaderMapper|null $mapper The readiness/mapping service.
	 * @param PublicationGateway|null $gateway The publication gateway.
	 *
	 * @return AiFeatureController
	 */
	private function controller(?string $uid, bool $allowed, ?AiFeatureService $service = null, ?AlgoritmekaderMapper $mapper = null, ?PublicationGateway $gateway = null): AiFeatureController {
		$session = $this->createMock(IUserSession::class);
		$user = null;
		if ($uid !== null) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($uid);
		}

		$session->method('getUser')->willReturn($user);

		$actionAuth = $this->createMock(ActionAuthService::class);
		$actionAuth->method('requireAction')->willReturnCallback(
			function ($caller, string $action) use ($allowed): void {
				$this->asked[] = $action;
				if ($allowed === false) {
					throw new OCSForbiddenException('not for you');
				}
			}
		);

		return new AiFeatureController(
			$this->createMock(IRequest::class),
			($service ?? $this->createMock(AiFeatureService::class)),
			$actionAuth,
			$session,
			$this->createMock(LoggerInterface::class),
			($mapper ?? $this->createMock(AlgoritmekaderMapper::class)),
			($gateway ?? $this->createMock(PublicationGateway::class)),
			$this->createMock(AiFeatureBindingService::class),
			$this->createMock(FeatureProviderResolver::class)
		);
	}//end controller()

	/**
	 * A service that must never be reached.
	 *
	 * @return AiFeatureService
	 */
	private function untouchedService(): AiFeatureService {
		$service = $this->createMock(AiFeatureService::class);
		foreach (['acknowledge', 'getFeature', 'enable', 'disable', 'recordPublication', 'recordWithdrawal'] as $method) {
			$service->expects($this->never())->method($method);
		}

		return $service;
	}//end untouchedService()

	/**
	 * Anonymous: 401 before any action check.
	 *
	 * @param string $route The route method.
	 * @param string $action The action it requires.
	 *
	 * @return void
	 *
	 * @dataProvider routes
	 */
	public function testAnAnonymousCallerIsUnauthenticated(string $route, string $action): void {
		$response = $this->controller(null, true, $this->untouchedService())->{$route}('feat-1');

		$this->assertSame(401, $response->getStatus());
		$this->assertSame(['error' => 'Unauthenticated'], $response->getData());
		$this->assertSame([], $this->asked);
	}//end testAnAnonymousCallerIsUnauthenticated()

	/**
	 * Refused: 403 with the refusal message, the route's own action asked, nothing reached.
	 *
	 * @param string $route The route method.
	 * @param string $action The action it requires.
	 *
	 * @return void
	 *
	 * @dataProvider routes
	 */
	public function testARefusedCallerIsForbidden(string $route, string $action): void {
		$response = $this->controller('mallory', false, $this->untouchedService())->{$route}('feat-1');

		$this->assertSame(403, $response->getStatus());
		$this->assertSame(['error' => 'not for you'], $response->getData());
		$this->assertSame([$action], $this->asked);
	}//end testARefusedCallerIsForbidden()

	/**
	 * Allowed: the route's own action is asked once and the route proceeds.
	 *
	 * @param string $route The route method.
	 * @param string $action The action it requires.
	 *
	 * @return void
	 *
	 * @dataProvider routes
	 */
	public function testAnAllowedCallerProceeds(string $route, string $action): void {
		$response = $this->controller('admin', true)->{$route}('feat-1');

		$this->assertNotContains($response->getStatus(), [401, 403]);
		$this->assertSame([$action], $this->asked);
	}//end testAnAllowedCallerProceeds()

	/**
	 * Every outcome of a publish past the gate.
	 *
	 * @return void
	 */
	public function testEveryPublishOutcome(): void {
		$feature = new ObjectEntity();
		$feature->setUuid('feat-1');
		$feature->setObject(['slug' => 's']);
		$stamped = new ObjectEntity();
		$stamped->setUuid('feat-1');
		$stamped->setObject(['slug' => 's', 'algoritmeregisterRef' => 'pub-1']);

		$publish = function (?ObjectEntity $found, array $missing, bool $available, ?string $reference, ?ObjectEntity $recorded, bool $throws = false) use (&$calls): array {
			$service = $this->createMock(AiFeatureService::class);
			if ($throws === true) {
				$service->method('getFeature')->willThrowException(new RuntimeException('db'));
			} else {
				$service->method('getFeature')->willReturn($found);
			}

			$service->method('recordPublication')->willReturn($recorded);
			$mapper = $this->createMock(AlgoritmekaderMapper::class);
			$mapper->method('assessReadiness')->willReturn($missing);
			$mapper->method('map')->willReturn(['title' => 't']);
			$gateway = $this->createMock(PublicationGateway::class);
			$gateway->method('isAvailable')->willReturn($available);
			$gateway->method('publish')->willReturn($reference);

			$response = $this->controller('admin', true, $service, $mapper, $gateway)->publishToAlgoritmeregister('feat-1');
			return [$response->getStatus(), $response->getData()];
		};

		$this->assertSame([404, ['error' => 'AI feature not found']], $publish(null, [], true, 'r', $stamped));
		$this->assertSame(
			[422, ['error' => 'Not ready to publish to the Algoritmeregister.', 'missing' => ['statutoryBasis']]],
			$publish($feature, ['statutoryBasis'], true, 'r', $stamped)
		);
		$this->assertSame([503, ['error' => 'The publication path (OpenCatalogi) is not available.']], $publish($feature, [], false, 'r', $stamped));
		$this->assertSame([503, ['error' => 'The publication path (OpenCatalogi) is not available.']], $publish($feature, [], true, null, $stamped));
		$this->assertSame([404, ['error' => 'AI feature not found']], $publish($feature, [], true, 'pub-1', null));
		$this->assertSame([500, ['error' => 'Publish failed']], $publish($feature, [], true, 'pub-1', $stamped, true));

		[$status, $data] = $publish($feature, [], true, 'pub-1', $stamped);
		$this->assertSame(200, $status);
		$this->assertSame('feat-1', $data['id']);
	}//end testEveryPublishOutcome()

	/**
	 * Every transition outcome of enable and disable, with its exact body.
	 *
	 * @return void
	 */
	public function testEveryTransitionOutcome(): void {
		$outcomes = [
			AiFeatureService::RESULT_ENABLED => [200, ['id' => 'feat-1', 'lifecycle' => AiFeatureService::RESULT_ENABLED]],
			AiFeatureService::RESULT_DISABLED => [200, ['id' => 'feat-1', 'lifecycle' => AiFeatureService::RESULT_DISABLED]],
			AiFeatureService::RESULT_NOT_FOUND => [404, ['error' => 'AI feature not found']],
			AiFeatureService::RESULT_BLOCKED => [409, ['error' => 'Transition blocked: the DPO has not acknowledged this AI feature.']],
			'something-else' => [422, ['error' => 'Transition not allowed from the current state.']],
		];
		foreach ($outcomes as $result => $expected) {
			foreach (['enable', 'disable'] as $route) {
				$service = $this->createMock(AiFeatureService::class);
				$service->method($route)->willReturn($result);
				$response = $this->controller('admin', true, $service)->{$route}('feat-1');
				$this->assertSame($expected, [$response->getStatus(), $response->getData()], $route . ' ' . $result);
			}
		}
	}//end testEveryTransitionOutcome()
}//end class
