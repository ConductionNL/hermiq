<?php

/**
 * Unit tests for FleetAppId.
 *
 * Pins the newest-first resolution of renamed fleet app ids and namespaces: the
 * first installed id wins, a failing app manager does not abort the search, and
 * a service is fetched under whichever namespace the app ships.
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Support
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

namespace OCA\Hermiq\Tests\Unit\Support;

use OCA\Hermiq\Support\FleetAppId;
use OCP\App\IAppManager;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use RuntimeException;
use stdClass;

/**
 * Tests for the fleet app id and namespace resolver.
 *
 * @covers \OCA\Hermiq\Support\FleetAppId
 */
class FleetAppIdTest extends TestCase {

	/**
	 * An app manager that answers from a fixed list and records what was asked.
	 *
	 * @param list<string> $installed Ids reported as installed.
	 * @param list<string> $failing   Ids whose lookup throws.
	 * @param list<string> $asked     Receives every id asked, in order.
	 *
	 * @return IAppManager The stub.
	 */
	private function appManager(array $installed, array $failing, array &$asked): IAppManager {
		$appManager = $this->createMock(originalClassName: IAppManager::class);
		$appManager->method('isInstalled')->willReturnCallback(
			function (string $appId) use ($installed, $failing, &$asked): bool {
				$asked[] = $appId;
				if (in_array(needle: $appId, haystack: $failing, strict: true) === true) {
					throw new RuntimeException(message: 'cannot answer');
				}

				return in_array(needle: $appId, haystack: $installed, strict: true);
			}
		);

		return $appManager;
	}//end appManager()

	/**
	 * A container that serves fixed entries, throws for the rest, and records what was asked.
	 *
	 * @param array<string, mixed> $entries Served entries by id.
	 * @param list<string>         $asked   Receives every id asked, in order.
	 *
	 * @return ContainerInterface The stub.
	 */
	private function container(array $entries, array &$asked): ContainerInterface {
		$container = $this->createMock(originalClassName: ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			function (string $id) use ($entries, &$asked): mixed {
				$asked[] = $id;
				if (array_key_exists(key: $id, array: $entries) === false) {
					throw new RuntimeException(message: 'not registered');
				}

				return $entries[$id];
			}
		);

		return $container;
	}//end container()

	/**
	 * The new id wins and the old one is never asked about.
	 *
	 * @return void
	 */
	public function testTheNewIdWinsWhenInstalled(): void {
		$asked = [];
		$appManager = $this->appManager(installed: ['learniq', 'scholiq'], failing: [], asked: $asked);

		$this->assertSame(expected: 'learniq', actual: FleetAppId::resolve(appManager: $appManager, canonical: 'learniq'));
		$this->assertSame(expected: ['learniq'], actual: $asked);
	}//end testTheNewIdWinsWhenInstalled()

	/**
	 * An instance that has not migrated resolves to the old id.
	 *
	 * @return void
	 */
	public function testTheOldIdIsTheFallback(): void {
		$asked = [];
		$appManager = $this->appManager(installed: ['openconnector'], failing: [], asked: $asked);

		$this->assertSame(expected: 'openconnector', actual: FleetAppId::resolve(appManager: $appManager, canonical: 'integriq'));
		$this->assertSame(expected: ['integriq', 'openconnector'], actual: $asked);
	}//end testTheOldIdIsTheFallback()

	/**
	 * No installed candidate resolves to null, and isInstalled says false.
	 *
	 * @return void
	 */
	public function testAnAbsentAppResolvesToNull(): void {
		$asked = [];
		$appManager = $this->appManager(installed: [], failing: [], asked: $asked);

		$this->assertNull(actual: FleetAppId::resolve(appManager: $appManager, canonical: 'keepiq'));
		$this->assertSame(expected: ['keepiq', 'doriath'], actual: $asked);
		$this->assertFalse(condition: FleetAppId::isInstalled(appManager: $appManager, canonical: 'keepiq'));
	}//end testAnAbsentAppResolvesToNull()

	/**
	 * An app manager that throws for one candidate does not stop the search.
	 *
	 * @return void
	 */
	public function testAFailingCandidateIsSkipped(): void {
		$asked = [];
		$appManager = $this->appManager(installed: ['docudesk'], failing: ['filinq'], asked: $asked);

		$this->assertSame(expected: 'docudesk', actual: FleetAppId::resolve(appManager: $appManager, canonical: 'filinq'));
		$this->assertTrue(condition: FleetAppId::isInstalled(appManager: $appManager, canonical: 'filinq'));
	}//end testAFailingCandidateIsSkipped()

	/**
	 * An app without a rename is looked up under its own name only.
	 *
	 * @return void
	 */
	public function testAnUnknownAppIsLookedUpUnderItsOwnName(): void {
		$asked = [];
		$appManager = $this->appManager(installed: ['openregister'], failing: [], asked: $asked);

		$this->assertSame(expected: 'openregister', actual: FleetAppId::resolve(appManager: $appManager, canonical: 'openregister'));
		$this->assertSame(expected: ['openregister'], actual: $asked);
	}//end testAnUnknownAppIsLookedUpUnderItsOwnName()

	/**
	 * Class candidates come newest namespace first, with a leading backslash dropped.
	 *
	 * @return void
	 */
	public function testClassCandidatesAreNewestFirst(): void {
		$this->assertSame(
			expected: ['OCA\Buildiq\Service\Thing', 'OCA\OpenBuilt\Service\Thing'],
			actual: FleetAppId::classCandidates(canonical: 'buildiq', relative: '\Service\Thing')
		);
		$this->assertSame(
			expected: ['OCA\Thematiq\X', 'OCA\NLDesign\X'],
			actual: FleetAppId::classCandidates(canonical: 'thematiq', relative: 'X')
		);
	}//end testClassCandidatesAreNewestFirst()

	/**
	 * An app with no known namespaces has no class candidates.
	 *
	 * @return void
	 */
	public function testAnUnknownAppHasNoClassCandidates(): void {
		$this->assertSame(expected: [], actual: FleetAppId::classCandidates(canonical: 'openregister', relative: 'Service\Thing'));
	}//end testAnUnknownAppHasNoClassCandidates()

	/**
	 * The service under the new namespace is returned without asking for the old one.
	 *
	 * @return void
	 */
	public function testGetServicePrefersTheNewNamespace(): void {
		$asked = [];
		$new = new stdClass();
		$container = $this->container(
			entries: ['OCA\Integriq\Service\CallService' => $new, 'OCA\OpenConnector\Service\CallService' => new stdClass()],
			asked: $asked
		);

		$this->assertSame(expected: $new, actual: FleetAppId::getService(container: $container, canonical: 'integriq', relative: 'Service\CallService'));
		$this->assertSame(expected: ['OCA\Integriq\Service\CallService'], actual: $asked);
	}//end testGetServicePrefersTheNewNamespace()

	/**
	 * An unregistered new name falls through to the old namespace.
	 *
	 * @return void
	 */
	public function testGetServiceFallsBackToTheOldNamespace(): void {
		$asked = [];
		$old = new stdClass();
		$container = $this->container(entries: ['OCA\Decidesk\Service\Thing' => $old], asked: $asked);

		$this->assertSame(expected: $old, actual: FleetAppId::getService(container: $container, canonical: 'decidiq', relative: 'Service\Thing'));
		$this->assertSame(expected: ['OCA\Decidiq\Service\Thing', 'OCA\Decidesk\Service\Thing'], actual: $asked);
	}//end testGetServiceFallsBackToTheOldNamespace()

	/**
	 * A non-object entry is not a service: the next name is tried, and null comes back when none is.
	 *
	 * @return void
	 */
	public function testANonObjectEntryIsSkipped(): void {
		$asked = [];
		$container = $this->container(entries: ['OCA\Dossiq\Service\Thing' => 'a string'], asked: $asked);

		$this->assertNull(actual: FleetAppId::getService(container: $container, canonical: 'dossiq', relative: 'Service\Thing'));
		$this->assertSame(expected: ['OCA\Dossiq\Service\Thing', 'OCA\Procest\Service\Thing'], actual: $asked);
	}//end testANonObjectEntryIsSkipped()

	/**
	 * An app with no known namespaces never reaches the container.
	 *
	 * @return void
	 */
	public function testGetServiceForAnUnknownAppIsNull(): void {
		$container = $this->createMock(originalClassName: ContainerInterface::class);
		$container->expects($this->never())->method('get');

		$this->assertNull(actual: FleetAppId::getService(container: $container, canonical: 'openregister', relative: 'Service\Thing'));
	}//end testGetServiceForAnUnknownAppIsNull()
}//end class
