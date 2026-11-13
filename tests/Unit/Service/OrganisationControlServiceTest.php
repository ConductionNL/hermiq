<?php

/**
 * Hermiq OrganisationControlService unit tests.
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service;

use OCA\Hermiq\Service\OrganisationControlService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use PHPUnit\Framework\TestCase;

/**
 * Tests for finding and toggling a tenant's control object.
 *
 * @covers \OCA\Hermiq\Service\OrganisationControlService
 */
class OrganisationControlServiceTest extends TestCase {

	/**
	 * A control object for an organisation.
	 *
	 * @param string               $organisation The organisation.
	 * @param string               $uuid         The uuid.
	 * @param array<string, mixed> $data         The payload.
	 *
	 * @return ObjectEntity The entity.
	 */
	private function control(string $organisation, string $uuid, array $data = []): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid($uuid);
		$entity->setOrganisation($organisation);
		$entity->setObject($data);
		return $entity;
	}//end control()

	/**
	 * An object service whose findAll() returns these objects, unscoped, from the control schema.
	 *
	 * @param list<mixed> $objects The objects.
	 *
	 * @return ObjectService The double.
	 */
	private function objects(array $objects): ObjectService {
		$service = $this->createMock(ObjectService::class);
		$service->method('setRegister')->with('hermiq')->willReturnSelf();
		$service->method('setSchema')->with('tenantcontrol')->willReturnSelf();
		$service->method('findAll')->with([], false, false)->willReturn($objects);
		return $service;
	}//end objects()

	/**
	 * The empty organisation is never looked up.
	 *
	 * @return void
	 */
	public function testTheEmptyOrganisationIsNotLookedUp(): void {
		$service = $this->createMock(ObjectService::class);
		$service->expects($this->never())->method('findAll');

		$this->assertNull((new OrganisationControlService(objectService: $service))->getForOrganisation(''));
	}//end testTheEmptyOrganisationIsNotLookedUp()

	/**
	 * The control of exactly that organisation is returned; non-entities and others are skipped.
	 *
	 * @return void
	 */
	public function testTheMatchingOrganisationsControlIsFound(): void {
		$mine = $this->control('org-b', 'u-b');
		$service = new OrganisationControlService(
			objectService: $this->objects([['organisation' => 'org-b'], $this->control('org-a', 'u-a'), $mine])
		);

		$this->assertSame($mine, $service->getForOrganisation('org-b'));
		$this->assertNull($service->getForOrganisation('org-c'));
	}//end testTheMatchingOrganisationsControlIsFound()

	/**
	 * Toggling an existing control updates it in place, pinned to the target organisation.
	 *
	 * @return void
	 */
	public function testToggleUpdatesTheExistingControl(): void {
		$service = $this->objects([$this->control('org-b', 'u-b', ['note' => 'keep', '@self' => ['owner' => 'x']])]);
		$saved = $this->control('org-b', 'u-b');
		$service->expects($this->once())
			->method('saveObject')
			->with(
				$this->callback(
					function (array $data): bool {
						$this->assertSame('keep', $data['note']);
						$this->assertTrue($data['engaged']);
						$this->assertSame('incident', $data['reason']);
						$this->assertSame('admin', $data['engagedBy']);
						$this->assertMatchesRegularExpression('/\+00:00$/', $data['engagedAt']);
						$this->assertSame(['owner' => 'x', 'organisation' => 'org-b'], $data['@self']);
						return true;
					}
				),
				[],
				'hermiq',
				'tenantcontrol',
				'u-b',
				false,
				false
			)
			->willReturn($saved);

		$result = (new OrganisationControlService(objectService: $service))->toggle('org-b', true, 'incident', 'admin');

		$this->assertSame($saved, $result);
	}//end testToggleUpdatesTheExistingControl()

	/**
	 * Toggling without a control creates one; an empty reason is stored as null.
	 *
	 * @return void
	 */
	public function testToggleCreatesAControlAndNullsAnEmptyReason(): void {
		$service = $this->objects([]);
		$service->expects($this->exactly(2))
			->method('saveObject')
			->with(
				$this->callback(
					function (array $data): bool {
						$this->assertFalse($data['engaged']);
						$this->assertNull($data['reason']);
						$this->assertSame(['organisation' => 'org-new'], $data['@self']);
						return true;
					}
				),
				[],
				'hermiq',
				'tenantcontrol',
				null
			)
			->willReturn($this->control('org-new', 'u-new'));
		$control = new OrganisationControlService(objectService: $service);

		$control->toggle('org-new', false, '', 'admin');
		$control->toggle('org-new', false, null, 'admin');
	}//end testToggleCreatesAControlAndNullsAnEmptyReason()
}//end class
