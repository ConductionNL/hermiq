<?php

/**
 * The action matrix endpoints: the list, a change, an unknown action or group, admin kept, and the reset.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
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
 * @spec openspec/changes/action-matrix-screen/specs/action-authorization/spec.md
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Controller;

use OCA\Hermiq\Controller\ActionMatrixController;
use OCA\Hermiq\Kernel\Contracts\ActionMatrixInterface;
use OCA\Hermiq\Service\ActionSeed;
use OCP\AppFramework\Http;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IL10N;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * @covers \OCA\Hermiq\Controller\ActionMatrixController
 * @uses \OCA\Hermiq\Service\ActionSeed
 */
final class ActionMatrixControllerTest extends TestCase {

	/**
	 * The stored matrix, as the fake store holds it.
	 *
	 * @var array<string, list<string>>
	 */
	private array $stored = [];

	/**
	 * How often the matrix was written.
	 *
	 * @var int
	 */
	private int $writes = 0;

	/**
	 * A controller over the shipped seed, the stored matrix above, the groups `admin`, `dpo`, `auditors` and `beheer`,
	 * and a request carrying these groups.
	 *
	 * @param mixed $groups The `groups` parameter.
	 *
	 * @return ActionMatrixController
	 */
	private function controller(mixed $groups = []): ActionMatrixController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(static fn (string $key, mixed $default = null): mixed => $key === 'groups' ? $groups : $default);

		$matrix = $this->createMock(ActionMatrixInterface::class);
		$matrix->method('getMatrix')->willReturnCallback(fn (): array => $this->stored);
		$matrix->method('setMatrix')->willReturnCallback(function (array $matrix): void {
			$this->stored = $matrix;
			$this->writes++;
		});

		$existing = ['admin', 'dpo', 'auditors', 'beheer'];
		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('groupExists')->willReturnCallback(static fn (string $gid): bool => in_array($gid, $existing, true));
		$groupManager->method('search')->willReturnCallback(
			fn (): array => array_map(
				function (string $gid): IGroup {
					$group = $this->createMock(IGroup::class);
					$group->method('getGID')->willReturn($gid);

					return $group;
				},
				['dpo', 'admin', 'beheer', 'auditors']
			)
		);

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text): string => $text);

		return new ActionMatrixController($request, $matrix, new ActionSeed(), $groupManager, $l10n);
	}//end controller()

	/**
	 * The row for one action from the list.
	 *
	 * @param array<string, mixed> $data   The list response.
	 * @param string               $action The action.
	 *
	 * @return array<string, mixed>
	 */
	private static function rowOf(array $data, string $action): array {
		foreach ($data['actions'] as $row) {
			if ($row['action'] === $action) {
				return $row;
			}
		}

		self::fail('No row for ' . $action);
	}//end rowOf()

	public function testTheListHasEverySeededActionWithItsLabelGroupsAndChangedMark(): void {
		$this->stored = ['compliance.view-dashboard' => ['admin', 'auditors'], 'custom.only-stored' => ['admin']];

		$data = $this->controller()->index()->getData();

		$this->assertSame(['admin', 'auditors', 'beheer', 'dpo'], $data['groups']);
		$this->assertCount(25, $data['actions']);
		$this->assertSame(
			[
				'action' => 'aifeature.acknowledge',
				'label' => 'Acknowledge an AI feature',
				'description' => 'Confirm that an AI feature has been reviewed before people may use it.',
				'groups' => ['admin'],
				'seeded' => ['admin'],
				'changed' => false,
			],
			self::rowOf($data, 'aifeature.acknowledge')
		);
		$this->assertSame(['admin', 'auditors'], self::rowOf($data, 'compliance.view-dashboard')['groups']);
		$this->assertTrue(self::rowOf($data, 'compliance.view-dashboard')['changed']);
		// An action only the stored matrix knows is listed under its own name.
		$this->assertSame('custom.only-stored', self::rowOf($data, 'custom.only-stored')['label']);
	}//end testTheListHasEverySeededActionWithItsLabelGroupsAndChangedMark()

	public function testAnAdministratorGivesTheDpoGroupOneAction(): void {
		$response = $this->controller(groups: ['admin', 'dpo'])->update('aifeature.acknowledge');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['admin', 'dpo'], $this->stored['aifeature.acknowledge']);
		// The other seeded actions are kept when the stored matrix starts empty.
		$this->assertSame(['admin'], $this->stored['compliance.export-pack']);
		$this->assertSame(['admin', 'dpo'], $response->getData()['groups']);
		$this->assertTrue($response->getData()['changed']);
	}//end testAnAdministratorGivesTheDpoGroupOneAction()

	public function testAnUnknownActionIsRefusedWith404AndNothingIsStored(): void {
		$response = $this->controller(groups: ['dpo'])->update('nothing.like-this');

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		$this->assertSame(0, $this->writes);
	}//end testAnUnknownActionIsRefusedWith404AndNothingIsStored()

	public function testAnUnknownGroupIsRefusedWith400NamingItAndNothingIsStored(): void {
		$this->stored = ['skill.publish-hub' => ['admin']];

		$response = $this->controller(groups: ['dpo', 'bestaat-niet'])->update('skill.publish-hub');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame('bestaat-niet', $response->getData()['group']);
		$this->assertStringContainsString('bestaat-niet', $response->getData()['message']);
		$this->assertSame(0, $this->writes);
		$this->assertSame(['skill.publish-hub' => ['admin']], $this->stored);
	}//end testAnUnknownGroupIsRefusedWith400NamingItAndNothingIsStored()

	public function testGroupsThatAreNotAListOfIdsAreRefused(): void {
		$this->assertSame(Http::STATUS_BAD_REQUEST, $this->controller(groups: 'dpo')->update('skill.publish-hub')->getStatus());
		$this->assertSame(Http::STATUS_BAD_REQUEST, $this->controller(groups: [['dpo']])->update('skill.publish-hub')->getStatus());
		$this->assertSame(0, $this->writes);
	}//end testGroupsThatAreNotAListOfIdsAreRefused()

	public function testRemovingAdminKeepsAdmin(): void {
		$this->controller(groups: ['beheer'])->update('tenantops.update-retention');

		$this->assertSame(['admin', 'beheer'], $this->stored['tenantops.update-retention']);
	}//end testRemovingAdminKeepsAdmin()

	public function testSavingTheSeededValueResetsTheActionAndClearsTheChangedMark(): void {
		$this->stored = ['compliance.view-dashboard' => ['admin', 'auditors']];

		$response = $this->controller(groups: ['admin'])->update('compliance.view-dashboard');

		$this->assertSame(['admin'], $this->stored['compliance.view-dashboard']);
		$this->assertFalse($response->getData()['changed']);
	}//end testSavingTheSeededValueResetsTheActionAndClearsTheChangedMark()

	public function testNoMethodIsOpenedToNonAdministrators(): void {
		foreach (['index', 'update'] as $method) {
			$attributes = array_map(static fn ($attribute): string => $attribute->getName(), (new ReflectionMethod(ActionMatrixController::class, $method))->getAttributes());
			$this->assertNotContains('OCP\AppFramework\Http\Attribute\NoAdminRequired', $attributes, $method);
			$this->assertNotContains('OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting', $attributes, $method);
			$this->assertStringNotContainsString('@NoAdminRequired', (string)(new ReflectionMethod(ActionMatrixController::class, $method))->getDocComment());
		}
	}//end testNoMethodIsOpenedToNonAdministrators()
}//end class
