<?php

/**
 * The computer-target routes are admin-only and answer a refusal with its status.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/tools-browser-and-computer-use/specs/computer-use/spec.md#requirement-an-admin-registers-a-machine-reached-only-through-integriq-req-computer-001
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Controller\Settings;

use OCA\Hermiq\Controller\Settings\ComputerTargetController;
use OCA\Hermiq\Service\ComputerUse\ComputerTargetRefusal;
use OCA\Hermiq\Service\ComputerUse\ComputerTargetService;
use OCA\Hermiq\Settings\AdminSettings;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * @covers \OCA\Hermiq\Controller\Settings\ComputerTargetController
 * @uses   \OCA\Hermiq\Service\ComputerUse\ComputerTargetRefusal
 */
final class ComputerTargetControllerTest extends TestCase {

	public function testEveryRouteIsAdminOnly(): void {
		$routes = require __DIR__ . '/../../../../appinfo/routes.php';
		$named  = array_filter(
			array_merge(...array_values(array_filter($routes, 'is_array'))),
			static fn (mixed $route): bool => is_array($route) && str_starts_with((string)($route['name'] ?? ''), 'Settings\ComputerTarget#')
		);
		$actions = array_map(static fn (array $route): string => substr($route['name'], strlen('Settings\ComputerTarget#')), $named);
		$actions = array_values(array_unique($actions));
		sort($actions);
		self::assertSame(['create', 'destroy', 'index', 'screenshot', 'update'], $actions);

		$public = array_filter(
			(new ReflectionClass(ComputerTargetController::class))->getMethods(ReflectionMethod::IS_PUBLIC),
			static fn (ReflectionMethod $method): bool => $method->getDeclaringClass()->getName() === ComputerTargetController::class && $method->isConstructor() === false
		);
		self::assertCount(5, $public);
		foreach ($public as $method) {
			$admin = $method->getAttributes(AuthorizedAdminSetting::class);
			self::assertCount(1, $admin, $method->getName());
			self::assertSame([AdminSettings::class], $admin[0]->getArguments(), $method->getName());
			self::assertSame([], $method->getAttributes(NoAdminRequired::class), $method->getName());
			self::assertSame([], $method->getAttributes(PublicPage::class), $method->getName());
		}
	}

	public function testARefusalIsAnsweredWithItsStatusAndASuccessWithTheBody(): void {
		$targets = $this->createMock(ComputerTargetService::class);
		$targets->method('screenshot')->willReturnCallback(
			static function (string $id): array {
				if ($id === 'gone') {
					throw new ComputerTargetRefusal(message: 'There is no computer target "gone".', status: 404);
				}

				return ['image' => 'aW1n', 'mimeType' => 'image/png', 'width' => 1, 'height' => 1];
			}
		);
		$targets->expects(self::once())->method('save')->with(['name' => 'W', 'source' => 's'], null)->willReturn(['id' => 'ct-1']);
		$request = $this->createMock(IRequest::class);
		$request->method('getParams')->willReturn(['name' => 'W', 'source' => 's']);
		$controller = new ComputerTargetController($request, $targets);

		$refused = $controller->screenshot(id: 'gone');
		self::assertSame(404, $refused->getStatus());
		self::assertSame(['error' => 'There is no computer target "gone".'], $refused->getData());
		self::assertSame(200, $controller->screenshot(id: 'ct-1')->getStatus());
		$created = $controller->create();
		self::assertSame(201, $created->getStatus());
		self::assertSame(['id' => 'ct-1'], $created->getData());
	}
}
