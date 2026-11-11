<?php

/**
 * Unit tests for TenantKillSwitch (the organisation kill-switch reader).
 *
 * Pins both outcomes of the gate — an engaged organisation is halted, every
 * other organisation is let through — plus the fail-open read and the
 * system-wide query every governed run relies on.
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/human-approval-gate-enforcement/tasks.md#3-dispatcher-kill-switch-scheduleservice
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service;

use OCA\Hermiq\Service\TenantKillSwitch;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Tests for TenantKillSwitch.
 */
class TenantKillSwitchTest extends TestCase {

	/**
	 * @var ObjectService&MockObject
	 */
	private ObjectService&MockObject $objectService;

	/**
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * Set up fresh mocks.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->objectService = $this->createMock(ObjectService::class);
		$this->objectService->method('setRegister')->willReturnSelf();
		$this->objectService->method('setSchema')->willReturnSelf();
		$this->logger = $this->createMock(LoggerInterface::class);
	}//end setUp()

	/**
	 * Build a TenantControl object.
	 *
	 * @param string|null $organisation The organisation it controls.
	 * @param mixed $engaged The stored `engaged` value.
	 *
	 * @return ObjectEntity
	 */
	private function control(?string $organisation, mixed $engaged): ObjectEntity {
		$control = new ObjectEntity();
		$control->setOrganisation($organisation);
		$control->setObject(['engaged' => $engaged]);
		return $control;
	}//end control()

	/**
	 * Build the class under test.
	 *
	 * @return TenantKillSwitch
	 */
	private function killSwitch(): TenantKillSwitch {
		return new TenantKillSwitch(objectService: $this->objectService, logger: $this->logger);
	}//end killSwitch()

	/**
	 * An engaged organisation is halted; an organisation without an engaged
	 * control is let through.
	 *
	 * @return void
	 */
	public function testEngagedOrganisationIsHaltedAndOthersAreNot(): void {
		$this->objectService->method('findAll')->willReturn([$this->control('org-x', true)]);

		$this->assertTrue($this->killSwitch()->isOrganisationEngaged(organisation: 'org-x'));
		$this->assertFalse($this->killSwitch()->isOrganisationEngaged(organisation: 'org-y'));
	}//end testEngagedOrganisationIsHaltedAndOthersAreNot()

	/**
	 * Only real entities with `engaged === true` and an organisation count, in
	 * query order and without repeats.
	 *
	 * @return void
	 */
	public function testEngagedOrganisationsSkipsUnengagedUnnamedAndForeignRows(): void {
		$this->objectService->method('findAll')->willReturn(
			[
				'not-an-entity',
				$this->control('org-b', true),
				$this->control('org-released', 'yes'),
				$this->control(null, true),
				$this->control('org-a', true),
				$this->control('org-b', true),
			]
		);

		$this->assertSame(['org-b', 'org-a'], $this->killSwitch()->engagedOrganisations());
	}//end testEngagedOrganisationsSkipsUnengagedUnnamedAndForeignRows()

	/**
	 * The query is register/schema-wide and system-level (no RBAC, no
	 * multitenancy), filtered on engaged controls.
	 *
	 * @return void
	 */
	public function testQueryReadsEveryEngagedControlSystemWide(): void {
		$this->objectService = $this->createMock(ObjectService::class);
		$this->objectService->expects($this->once())->method('setRegister')->with('hermiq')->willReturnSelf();
		$this->objectService->expects($this->once())->method('setSchema')->with('tenantcontrol')->willReturnSelf();
		$this->objectService->expects($this->once())
			->method('findAll')
			->with(['filters' => ['engaged' => true]], false, false)
			->willReturn([]);

		$this->assertSame([], $this->killSwitch()->engagedOrganisations());
	}//end testQueryReadsEveryEngagedControlSystemWide()

	/**
	 * An empty organisation is never engaged and costs no query.
	 *
	 * @return void
	 */
	public function testEmptyOrganisationIsNeverEngaged(): void {
		$this->objectService->expects($this->never())->method('findAll');

		$this->assertFalse($this->killSwitch()->isOrganisationEngaged(organisation: ''));
	}//end testEmptyOrganisationIsNeverEngaged()

	/**
	 * A failed read is logged and answers "no organisation engaged".
	 *
	 * @return void
	 */
	public function testUnreadableControlsAreLoggedAndFailOpen(): void {
		$error = new RuntimeException('register down');
		$this->objectService->method('findAll')->willThrowException($error);
		$this->logger->expects($this->once())
			->method('error')
			->with('Hermiq could not load engaged kill-switches: register down', ['exception' => $error]);

		$this->assertFalse($this->killSwitch()->isOrganisationEngaged(organisation: 'org-x'));
	}//end testUnreadableControlsAreLoggedAndFailOpen()
}//end class
