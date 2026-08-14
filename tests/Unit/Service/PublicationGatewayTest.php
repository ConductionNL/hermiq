<?php

/**
 * Unit tests for PublicationGateway (algoritmeregister-publication).
 *
 * Verifies the runtime integration seam: when OpenCatalogi (the fleet publication leaf) is
 * installed the gateway hands the publication to the shared OpenRegister write-path and
 * returns the external reference; when it is absent every method fails closed (null /
 * false) without touching OpenRegister, so the AI-feature register stays governable
 * internally. There is NO hard hermiq→OpenCatalogi coupling and NO national-portal call —
 * the delegation is the shared data layer + an IAppManager availability probe.
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
 * @spec openspec/changes/algoritmeregister-publication/specs/algoritmeregister-publication/spec.md#requirement-publication-is-delegated-to-the-fleet-publication-path-not-re-implemented
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service;

use OCA\Hermiq\Service\PublicationGateway;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use OCP\App\IAppManager;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the runtime publication seam.
 *
 * @spec openspec/changes/algoritmeregister-publication/specs/algoritmeregister-publication/spec.md#requirement-publication-is-delegated-to-the-fleet-publication-path-not-re-implemented
 */
class PublicationGatewayTest extends TestCase {

	/**
	 * `isAvailable` reflects whether OpenCatalogi is installed.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/algoritmeregister-publication/specs/algoritmeregister-publication/spec.md#requirement-publication-is-delegated-to-the-fleet-publication-path-not-re-implemented
	 */
	public function testIsAvailableReflectsAppManager(): void {
		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('isInstalled')->with('opencatalogi')->willReturn(true);

		$gateway = new PublicationGateway($appManager, $this->createMock(ObjectService::class));
		$this->assertTrue($gateway->isAvailable());

	}//end testIsAvailableReflectsAppManager()

	/**
	 * When OpenCatalogi is present, publish delegates to OpenRegister and returns the ref.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/algoritmeregister-publication/specs/algoritmeregister-publication/spec.md#requirement-publication-is-delegated-to-the-fleet-publication-path-not-re-implemented
	 */
	public function testPublishDelegatesWhenAvailable(): void {
		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('isInstalled')->willReturn(true);

		$saved = new ObjectEntity();
		$saved->setUuid('pub-42');

		$objectService = $this->createMock(ObjectService::class);
		$objectService->expects($this->once())->method('saveObject')->willReturn($saved);

		$gateway = new PublicationGateway($appManager, $objectService);
		$ref = $gateway->publish(['title' => 'Autonomous agent run']);

		$this->assertSame('pub-42', $ref);

	}//end testPublishDelegatesWhenAvailable()

	/**
	 * When OpenCatalogi is absent, publish fails closed (null) and never touches OpenRegister.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/algoritmeregister-publication/specs/algoritmeregister-publication/spec.md#requirement-publication-is-delegated-to-the-fleet-publication-path-not-re-implemented
	 */
	public function testPublishUnavailableWhenAbsent(): void {
		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('isInstalled')->willReturn(false);

		$objectService = $this->createMock(ObjectService::class);
		$objectService->expects($this->never())->method('saveObject');

		$gateway = new PublicationGateway($appManager, $objectService);
		$this->assertNull($gateway->publish(['title' => 'x']));

	}//end testPublishUnavailableWhenAbsent()

	/**
	 * Withdraw fails closed (false) and never touches OpenRegister when OpenCatalogi is absent.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/algoritmeregister-publication/specs/algoritmeregister-publication/spec.md#requirement-publication-is-delegated-to-the-fleet-publication-path-not-re-implemented
	 */
	public function testWithdrawUnavailableWhenAbsent(): void {
		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('isInstalled')->willReturn(false);

		$objectService = $this->createMock(ObjectService::class);
		$objectService->expects($this->never())->method('saveObject');

		$gateway = new PublicationGateway($appManager, $objectService);
		$this->assertFalse($gateway->withdraw('pub-42'));

	}//end testWithdrawUnavailableWhenAbsent()

	/**
	 * Withdraw requests unpublication through the shared write-path when available.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/algoritmeregister-publication/specs/algoritmeregister-publication/spec.md#requirement-publication-is-delegated-to-the-fleet-publication-path-not-re-implemented
	 */
	public function testWithdrawDelegatesWhenAvailable(): void {
		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('isInstalled')->willReturn(true);

		$objectService = $this->createMock(ObjectService::class);
		$objectService->method('find')->willReturn(null);
		$objectService->expects($this->once())->method('saveObject')->willReturn(new ObjectEntity());

		$gateway = new PublicationGateway($appManager, $objectService);
		$this->assertTrue($gateway->withdraw('pub-42'));

	}//end testWithdrawDelegatesWhenAvailable()
}//end class
