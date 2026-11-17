<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category  Test
 * @package   OCA\Hermiq\Tests\Unit\Controller
 * @author    Conduction B.V. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 * @link      https://github.com/ConductionNL/hermiq
 *
 * @spec openspec/changes/flows-hand-off-and-wait/specs/remote-agents/spec.md#requirement-an-admin-registers-a-remote-agent-through-an-integriq-source-req-a2a-001
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Controller;

use InvalidArgumentException;
use OCA\Hermiq\Controller\RemoteAgentController;
use OCA\Hermiq\Service\RemoteAgent\RemoteAgentService;
use OCA\Hermiq\Service\RemoteAgent\RemoteAgentUnavailableException;
use OCA\Hermiq\Settings\AdminSettings;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * The remote agent routes are admin settings, and a refused registration says why.
 *
 * @covers \OCA\Hermiq\Controller\RemoteAgentController
 */
class RemoteAgentControllerTest extends TestCase {

	/**
	 * Every action is an admin setting and none is open to non-admins.
	 *
	 * @return void
	 */
	public function testEveryActionIsAnAdminSetting(): void {
		$actions = array_filter(
			(new ReflectionClass(RemoteAgentController::class))->getMethods(ReflectionMethod::IS_PUBLIC),
			static fn (ReflectionMethod $method): bool => $method->getDeclaringClass()->getName() === RemoteAgentController::class && $method->getName() !== '__construct'
		);

		$this->assertSame(['index', 'create', 'update', 'destroy'], array_values(array_map(static fn (ReflectionMethod $m): string => $m->getName(), $actions)));
		foreach ($actions as $action) {
			$attributes = $action->getAttributes(AuthorizedAdminSetting::class);
			$this->assertCount(1, $attributes, $action->getName());
			$this->assertSame([AdminSettings::class], $attributes[0]->getArguments(), $action->getName());
			$this->assertSame([], $action->getAttributes(NoAdminRequired::class), $action->getName());
		}
	}//end testEveryActionIsAnAdminSetting()

	/**
	 * A registration answers 201, a bad one 400, an unreachable card 502.
	 *
	 * @return void
	 */
	public function testCreateMapsTheOutcomes(): void {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(static fn (string $key, mixed $default = null): mixed => ['name' => 'KvK-assistent', 'source' => 'src-kvk'][$key] ?? $default);
		$service = $this->createMock(RemoteAgentService::class);
		$service->expects($this->exactly(3))->method('register')
			->with(['name' => 'KvK-assistent', 'slug' => '', 'description' => '', 'source' => 'src-kvk'])
			->willReturnOnConsecutiveCalls(
				['id' => 'ra-1', 'slug' => 'kvk-assistent', 'enabled' => false],
				$this->throwException(new InvalidArgumentException('taken')),
				$this->throwException(new RemoteAgentUnavailableException('no card'))
			);
		$controller = new RemoteAgentController(request: $request, remoteAgents: $service);

		$this->assertSame(201, $controller->create()->getStatus());
		$this->assertSame(400, $controller->create()->getStatus());
		$refused = $controller->create();
		$this->assertSame(502, $refused->getStatus());
		$this->assertSame(['message' => 'no card'], $refused->getData());
	}//end testCreateMapsTheOutcomes()

	/**
	 * Switching on reads the flag as a boolean; an unknown remote agent is 404.
	 *
	 * @return void
	 */
	public function testUpdateSwitchesAndAnswers404ForAnUnknownAgent(): void {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(static fn (string $key): mixed => ($key === 'enabled') ? 'true' : null);
		$service = $this->createMock(RemoteAgentService::class);
		$service->method('update')->willReturnCallback(static fn (string $id, array $input): ?array => ($id === 'ra-1') ? ['id' => 'ra-1'] + $input : null);
		$controller = new RemoteAgentController(request: $request, remoteAgents: $service);

		$this->assertSame(['id' => 'ra-1', 'enabled' => true], $controller->update(id: 'ra-1')->getData());
		$this->assertSame(404, $controller->update(id: 'gone')->getStatus());
	}//end testUpdateSwitchesAndAnswers404ForAnUnknownAgent()
}//end class
