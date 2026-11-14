<?php

/**
 * Golden tests for the two organisation-scoped policy controllers,
 * GuardrailPolicyController and OrganisationModelPolicyController.
 *
 * Pins every endpoint's allowed and refused outcomes before their shared access
 * helpers (who may administer an organisation's policy, who owns an
 * organisation, which organisation is the caller's own) move into one place:
 * the 401 for no user; who sees which policies in the list; who may create
 * the instance default, their own organisation's policy or another's; who may
 * update a policy, judged by the policy's own organisation; who may read an
 * effective policy; and each failure's status, body and log line.
 *
 * @category  Test
 * @package   OCA\Hermiq\Tests\Unit\Controller
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Controller;

use InvalidArgumentException;
use OCA\Hermiq\Controller\GuardrailPolicyController;
use OCA\Hermiq\Controller\OrganisationModelPolicyController;
use OCA\Hermiq\Service\EffectiveModelPolicy;
use OCA\Hermiq\Service\GuardrailPolicyService;
use OCA\Hermiq\Service\OrganisationModelPolicyService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Organisation;
use OCA\OpenRegister\Db\OrganisationMapper;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Golden access outcomes of the organisation policy controllers.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) Both controllers' collaborator sets.
 * @SuppressWarnings(PHPMD.TooManyPublicMethods)   One test per endpoint and controller.
 */
class OrganisationPolicyControllersGoldenTest extends TestCase {

	/**
	 * The policy service mock of the controller last built.
	 *
	 * @var (GuardrailPolicyService&MockObject)|(OrganisationModelPolicyService&MockObject)
	 */
	private MockObject $service;

	/**
	 * The double the controller reads the effective policy from.
	 *
	 * @var MockObject
	 */
	private MockObject $reads;

	/**
	 * Every logger call, as `<level>: <message>`.
	 *
	 * @var array<int, string>
	 */
	private array $logged = [];

	/**
	 * Build one controller.
	 *
	 * Organisation `org-a` is owned by `alice`, `org-b` by `bob`; any other
	 * organisation is not found. `admin` is the only instance admin.
	 *
	 * @param string $kind 'guardrail' or 'model'.
	 * @param array<string, mixed> $setup `user` (uid or null), `params`, `active` (uid's active org or a Throwable).
	 *
	 * @return GuardrailPolicyController|OrganisationModelPolicyController
	 */
	private function controller(string $kind, array $setup): GuardrailPolicyController|OrganisationModelPolicyController {
		$this->logged = [];
		$params = $setup['params'] ?? [];
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			static fn (string $key, $default = null) => array_key_exists($key, $params) === true ? $params[$key] : $default
		);
		$request->method('getParams')->willReturn($params);

		$session = $this->createMock(IUserSession::class);
		$user = null;
		if (($setup['user'] ?? null) !== null) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($setup['user']);
		}

		$session->method('getUser')->willReturn($user);

		$groups = $this->createMock(IGroupManager::class);
		$groups->method('isAdmin')->willReturnCallback(static fn (string $uid): bool => $uid === 'admin');

		$mapper = $this->createMock(OrganisationMapper::class);
		$mapper->method('findByUuid')->willReturnCallback(
			function (string $uuid): Organisation {
				$owners = ['org-a' => 'alice', 'org-b' => 'bob'];
				if (isset($owners[$uuid]) === false) {
					throw new RuntimeException('no organisation ' . $uuid);
				}

				return $this->organisation(owner: $owners[$uuid]);
			}
		);
		$active = $setup['active'] ?? '';
		if ($active instanceof \Throwable) {
			$mapper->method('getActiveOrganisationWithFallback')->willThrowException($active);
		} else {
			$mapper->method('getActiveOrganisationWithFallback')->willReturn($active);
		}

		$logger = $this->createMock(LoggerInterface::class);
		foreach (['warning', 'error'] as $level) {
			$logger->method($level)->willReturnCallback(
				function (string $message) use ($level): void {
					$this->logged[] = $level . ': ' . $message;
				}
			);
		}

		if ($kind === 'guardrail') {
			$this->service = $this->createMock(GuardrailPolicyService::class);
			$this->reads = $this->service;
			return new GuardrailPolicyController($request, $this->service, $session, $groups, $mapper, $logger);
		}

		$this->service = $this->createMock(OrganisationModelPolicyService::class);
		$this->reads = $this->createMock(EffectiveModelPolicy::class);
		return new OrganisationModelPolicyController($request, $this->service, $this->reads, $session, $groups, $mapper, $logger);
	}//end controller()

	/**
	 * An organisation owned by $owner.
	 *
	 * @param string $owner The owner uid.
	 *
	 * @return Organisation
	 */
	private function organisation(string $owner): Organisation {
		return new class ($owner) extends Organisation {
			/**
			 * @param string $ownerUid The owning uid.
			 */
			public function __construct(private string $ownerUid) {
			}

			/**
			 * @return string|null
			 */
			public function getOwner(): ?string {
				return $this->ownerUid;
			}
		};
	}//end organisation()

	/**
	 * A stored policy of an organisation.
	 *
	 * @param string $uuid The policy uuid.
	 * @param string|null $organisation The organisation, null for the instance default.
	 *
	 * @return ObjectEntity
	 */
	private function policy(string $uuid, ?string $organisation): ObjectEntity {
		$policy = new ObjectEntity();
		$policy->setUuid($uuid);
		$policy->setOrganisation($organisation);
		return $policy;
	}//end policy()

	/**
	 * Assert status and body.
	 *
	 * @param JSONResponse $response The response.
	 * @param int $status The status.
	 * @param mixed $body The body.
	 * @param string $case The case name.
	 *
	 * @return void
	 */
	private function answer(JSONResponse $response, int $status, mixed $body, string $case): void {
		$this->assertSame($status, $response->getStatus(), $case);
		$this->assertSame($body, $response->getData(), $case);
	}//end answer()

	/**
	 * Every endpoint of both controllers answers 401 to nobody.
	 *
	 * @return void
	 */
	public function testNobodyIs401(): void {
		foreach (['guardrail', 'model'] as $kind) {
			foreach (['effective', 'index', 'create', 'update'] as $action) {
				$controller = $this->controller($kind, ['user' => null]);
				$this->reads->expects($this->never())->method('effectivePolicyFor');
				$response = $action === 'update' ? $controller->update('p-1') : $controller->{$action}();
				$this->answer($response, Http::STATUS_UNAUTHORIZED, ['error' => 'Unauthenticated'], $kind . ' ' . $action);
			}
		}
	}//end testNobodyIs401()

	/**
	 * The list holds every policy for an admin, and only owned organisations' policies otherwise.
	 *
	 * @return void
	 */
	public function testIndexVisibility(): void {
		$stored = [$this->policy('p-default', null), $this->policy('p-a', 'org-a'), $this->policy('p-b', 'org-b'), $this->policy('p-x', 'org-x')];
		$expected = [
			'admin' => ['', 'org-a', 'org-b', 'org-x'],
			'alice' => ['org-a'],
			'bob' => ['org-b'],
			'eve' => [],
		];
		foreach ($expected as $uid => $organisations) {
			$controller = $this->controller('guardrail', ['user' => $uid]);
			$this->service->method('listAll')->willReturn($stored);
			$this->reads->method('effectivePolicyFor')->willReturnCallback(static fn (string $org): array => ['for' => $org]);
			$this->answer(
				$controller->index(),
				Http::STATUS_OK,
				['policies' => array_map(static fn (string $org): array => ['for' => $org], $organisations)],
				'guardrail ' . $uid
			);

			$controller = $this->controller('model', ['user' => $uid]);
			$this->service->method('listAll')->willReturn($stored);
			$this->reads->method('effectivePolicyFor')->willReturnCallback(static fn (string $org): array => ['for' => $org]);
			$ids = ['' => 'p-default', 'org-a' => 'p-a', 'org-b' => 'p-b', 'org-x' => 'p-x'];
			$this->answer(
				$controller->index(),
				Http::STATUS_OK,
				[
					'policies' => array_map(
						static fn (string $org): array => ['for' => $org, 'id' => $ids[$org], 'organisation' => $org, 'source' => $org === '' ? 'instance' : 'organisation'],
						$organisations
					),
					'dataUse' => ['openai' => 'undeclared', 'ollama' => 'undeclared', 'fireworks' => 'undeclared', 'nextcloud' => 'undeclared', 'anthropic' => 'undeclared'],
				],
				'model ' . $uid
			);
		}

		foreach (['guardrail' => 'Could not load guardrail policies', 'model' => 'Could not load model policies'] as $kind => $message) {
			$controller = $this->controller($kind, ['user' => 'admin']);
			$this->service->method('listAll')->willThrowException(new RuntimeException('db down'));
			$this->answer($controller->index(), Http::STATUS_INTERNAL_SERVER_ERROR, ['error' => $message], $kind);
			$this->assertSame(['error: Hermiq ' . ($kind === 'guardrail' ? 'guardrail' : 'model') . '-policy list failed: db down'], $this->logged, $kind);
		}
	}//end testIndexVisibility()

	/**
	 * Only an admin may create the instance default; an admin or the owner their organisation's.
	 *
	 * @return void
	 */
	public function testCreateAccess(): void {
		$allowed = [
			['admin', ''], ['admin', 'org-a'], ['admin', 'org-x'], ['alice', 'org-a'], ['bob', 'org-b'],
		];
		$refused = [
			['alice', ''], ['alice', 'org-b'], ['eve', 'org-a'], ['eve', ''], ['alice', 'org-x'],
		];
		foreach (['guardrail', 'model'] as $kind) {
			foreach ($allowed as [$uid, $org]) {
				$params = ['organisation' => $org, 'mode' => 'x'];
				$controller = $this->controller($kind, ['user' => $uid, 'params' => $params]);
				$this->service->expects($this->once())->method('upsertForOrganisation')->with($org, $params)->willReturn(['saved' => $org]);
				$this->answer($controller->create(), Http::STATUS_OK, ['saved' => $org], $kind . ' ' . $uid . ' ' . $org);
			}

			foreach ($refused as [$uid, $org]) {
				$controller = $this->controller($kind, ['user' => $uid, 'params' => ['organisation' => $org]]);
				$this->service->expects($this->never())->method('upsertForOrganisation');
				$this->answer($controller->create(), Http::STATUS_FORBIDDEN, ['error' => 'Forbidden'], $kind . ' ' . $uid . ' ' . $org);
			}

			$controller = $this->controller($kind, ['user' => 'admin']);
			$this->service->expects($this->once())->method('upsertForOrganisation')->with('', [])->willReturn([]);
			$this->answer($controller->create(), Http::STATUS_OK, [], $kind . ' no organisation param');

			$controller = $this->controller($kind, ['user' => 'admin', 'params' => ['organisation' => 'org-a']]);
			$this->service->method('upsertForOrganisation')->willThrowException(new InvalidArgumentException('bad mode'));
			$this->answer($controller->create(), Http::STATUS_BAD_REQUEST, ['error' => 'bad mode'], $kind);

			$controller = $this->controller($kind, ['user' => 'admin', 'params' => ['organisation' => 'org-a']]);
			$this->service->method('upsertForOrganisation')->willThrowException(new RuntimeException('db down'));
			$label = $kind === 'guardrail' ? 'guardrail' : 'model';
			$this->answer($controller->create(), Http::STATUS_INTERNAL_SERVER_ERROR, ['error' => 'Could not create the ' . $label . ' policy'], $kind);
			$this->assertSame(['error: Hermiq ' . $label . '-policy create failed: db down'], $this->logged, $kind);
		}
	}//end testCreateAccess()

	/**
	 * Update is judged by the stored policy's organisation, never the request's.
	 *
	 * @return void
	 */
	public function testUpdateAccess(): void {
		$cases = [
			['admin', null, true], ['admin', 'org-b', true], ['alice', 'org-a', true],
			['alice', null, false], ['alice', 'org-b', false], ['eve', 'org-a', false], ['alice', 'org-x', false],
		];
		foreach (['guardrail', 'model'] as $kind) {
			$label = $kind === 'guardrail' ? 'guardrail' : 'model';
			foreach ($cases as [$uid, $org, $allowed]) {
				$params = ['organisation' => 'org-a', 'mode' => 'x'];
				$controller = $this->controller($kind, ['user' => $uid, 'params' => $params]);
				$this->service->method('findById')->with('p-1')->willReturn($this->policy('p-1', $org));
				$name = $kind . ' ' . $uid . ' ' . (string)$org;
				if ($allowed === true) {
					$this->service->expects($this->once())->method('update')->with('p-1', $params)->willReturn(['updated' => true]);
					$this->answer($controller->update('p-1'), Http::STATUS_OK, ['updated' => true], $name);
				} else {
					$this->service->expects($this->never())->method('update');
					$this->answer($controller->update('p-1'), Http::STATUS_FORBIDDEN, ['error' => 'Forbidden'], $name);
				}
			}

			$controller = $this->controller($kind, ['user' => 'admin']);
			$this->service->method('findById')->willReturn(null);
			$this->answer($controller->update('p-1'), Http::STATUS_NOT_FOUND, ['error' => 'Not found'], $kind);

			$failures = [
				[new RuntimeException('gone'), Http::STATUS_NOT_FOUND, ['error' => 'gone'], []],
				[new \Error('boom'), Http::STATUS_INTERNAL_SERVER_ERROR, ['error' => 'Could not update the ' . $label . ' policy'], ['error: Hermiq ' . $label . '-policy update failed: boom']],
			];
			$failures[] = [new InvalidArgumentException('bad'), Http::STATUS_BAD_REQUEST, ['error' => 'bad'], []];

			foreach ($failures as [$throwable, $status, $body, $logged]) {
				$controller = $this->controller($kind, ['user' => 'admin']);
				$this->service->method('findById')->willReturn($this->policy('p-1', 'org-a'));
				$this->service->method('update')->willThrowException($throwable);
				$this->answer($controller->update('p-1'), $status, $body, $kind . ' ' . get_class($throwable));
				$this->assertSame($logged, $this->logged, $kind . ' ' . get_class($throwable));
			}
		}
	}//end testUpdateAccess()

	/**
	 * The guardrail effective read: the named organisation, else the caller's own; readable by owner, admin, or for the default.
	 *
	 * @return void
	 */
	public function testGuardrailEffectiveAccess(): void {
		$cases = [
			['alice', ['organisation' => 'org-a'], 'org-b', 'org-a'],
			['admin', ['organisation' => 'org-b'], '', 'org-b'],
			['eve', ['organisation' => ''], 'org-b', ''],
			['eve', [], 'org-x', 'REFUSED'],
			['alice', [], 'org-a', 'org-a'],
			['eve', ['organisation' => 'org-a'], 'org-a', 'REFUSED'],
			['eve', [], '', ''],
		];
		foreach ($cases as [$uid, $params, $active, $read]) {
			$controller = $this->controller('guardrail', ['user' => $uid, 'params' => $params, 'active' => $active]);
			$name = $uid . ' ' . json_encode($params) . ' ' . $active;
			if ($read === 'REFUSED') {
				$this->reads->expects($this->never())->method('effectivePolicyFor');
				$this->answer(
					$controller->effective(),
					Http::STATUS_FORBIDDEN,
					['error' => 'You are not allowed to read this organisation\'s guardrail policy'],
					$name
				);
				continue;
			}

			$this->reads->expects($this->once())->method('effectivePolicyFor')->with($read)->willReturn(['for' => $read]);
			$this->answer($controller->effective(), Http::STATUS_OK, ['for' => $read], $name);
		}

		$controller = $this->controller('guardrail', ['user' => 'eve', 'active' => new RuntimeException('no org app')]);
		$this->reads->expects($this->once())->method('effectivePolicyFor')->with('')->willReturn([]);
		$this->answer($controller->effective(), Http::STATUS_OK, [], 'active org fails');
		$this->assertSame(['warning: Hermiq could not resolve active organisation: no org app'], $this->logged);

		$controller = $this->controller('guardrail', ['user' => 'admin']);
		$this->reads->method('effectivePolicyFor')->willThrowException(new RuntimeException('db down'));
		$this->answer($controller->effective(), Http::STATUS_INTERNAL_SERVER_ERROR, ['error' => 'Could not load the effective guardrail policy'], 'fails');
		$this->assertSame(['error: Hermiq guardrail-policy effective read failed: db down'], $this->logged);
	}//end testGuardrailEffectiveAccess()

	/**
	 * The model effective read is always the caller's own organisation.
	 *
	 * @return void
	 */
	public function testModelEffective(): void {
		foreach (['org-a', 'org-x', ''] as $active) {
			$controller = $this->controller('model', ['user' => 'eve', 'params' => ['organisation' => 'org-b'], 'active' => $active]);
			$this->reads->expects($this->once())->method('effectivePolicyFor')->with($active)->willReturn(['for' => $active]);
			$this->answer($controller->effective(), Http::STATUS_OK, ['for' => $active], $active);
		}

		$controller = $this->controller('model', ['user' => 'eve', 'active' => new RuntimeException('no org app')]);
		$this->reads->expects($this->once())->method('effectivePolicyFor')->with('')->willReturn([]);
		$this->answer($controller->effective(), Http::STATUS_OK, [], 'active org fails');
		$this->assertSame(['warning: Hermiq could not resolve active organisation: no org app'], $this->logged);

		$controller = $this->controller('model', ['user' => 'eve']);
		$this->reads->method('effectivePolicyFor')->willThrowException(new RuntimeException('db down'));
		$this->answer($controller->effective(), Http::STATUS_INTERNAL_SERVER_ERROR, ['error' => 'Could not load the effective model policy'], 'fails');
		$this->assertSame(['error: Hermiq model-policy effective read failed: db down'], $this->logged);
	}//end testModelEffective()
}//end class
