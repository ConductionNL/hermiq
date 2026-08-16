<?php

/**
 * Degraded-path contract for OpenRegisterManageableOrganisations
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Tenant
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Tenant;

use OCA\Hermiq\Tenant\ManageableOrganisations;
use OCA\Hermiq\Tenant\OpenRegisterManageableOrganisations;
use OCP\App\IAppManager;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

/**
 * ADR-083 rule 3: the start screen must survive OpenRegister being absent.
 *
 * These assertions are the whole point of the class existing. Before it, the
 * dashboard — the app's DEFAULT ROUTE — constructor-injected
 * `OCA\OpenRegister\Db\OrganisationMapper`, so an instance without OpenRegister
 * could not construct the controller and answered the start screen with a 500
 * instead of a message naming the missing app.
 *
 * A guard nobody has watched refuse is untested, so each degraded path here is
 * driven to its refusal and the answer is asserted — never inferred from the
 * fact that nothing threw.
 */
class OpenRegisterManageableOrganisationsTest extends TestCase {

	/**
	 * Build the subject with a stated OpenRegister availability.
	 *
	 * @param bool                    $installed Whether openregister reports installed.
	 * @param ContainerInterface|null $container Optional container override.
	 *
	 * @return OpenRegisterManageableOrganisations
	 */
	private function build(bool $installed, ?ContainerInterface $container = null): OpenRegisterManageableOrganisations {
		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('isInstalled')->willReturnCallback(
			static fn (string $app): bool => ($app === 'openregister' ? $installed : false)
		);

		return new OpenRegisterManageableOrganisations(
			appManager: $appManager,
			container: ($container ?? $this->createMock(ContainerInterface::class)),
		);
	}//end build()

	/**
	 * It satisfies the contract the dashboard controller depends on.
	 *
	 * @return void
	 */
	public function testItImplementsTheHermiqContract(): void {
		$this->assertInstanceOf(ManageableOrganisations::class, $this->build(installed: true));
	}//end testItImplementsTheHermiqContract()

	/**
	 * With OpenRegister absent it answers empty and NEVER touches the container.
	 *
	 * The container expectation is the load-bearing half: it proves availability
	 * is established BEFORE the reach, which is what keeps the start screen up.
	 *
	 * @return void
	 */
	public function testItAnswersEmptyWithoutOpenRegisterAndNeverReachesForIt(): void {
		$container = $this->createMock(ContainerInterface::class);
		$container->expects($this->never())->method('get');

		$subject = $this->build(installed: false, container: $container);

		$this->assertSame([], $subject->forUser(userId: 'alice', isAdmin: false));
		$this->assertSame([], $subject->forUser(userId: 'alice', isAdmin: true));
	}//end testItAnswersEmptyWithoutOpenRegisterAndNeverReachesForIt()

	/**
	 * An unresolvable mapper degrades to empty rather than escaping.
	 *
	 * Models OpenRegister being installed but its service unresolvable — the
	 * case where a throw would take the dashboard down.
	 *
	 * @return void
	 */
	public function testItDegradesWhenTheMapperCannotBeResolved(): void {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willThrowException(new \RuntimeException('not registered'));

		$this->assertSame([], $this->build(installed: true, container: $container)->forUser(userId: 'alice', isAdmin: true));
	}//end testItDegradesWhenTheMapperCannotBeResolved()

	/**
	 * A mapper that throws mid-read degrades to empty as well.
	 *
	 * @return void
	 */
	public function testItDegradesWhenTheMapperThrowsWhileReading(): void {
		$mapper = new class {
			/**
			 * Always fails, standing in for an unhealthy organisation store.
			 *
			 * @param int $limit Row cap.
			 *
			 * @return array<int, object>
			 *
			 * @throws \RuntimeException Always.
			 */
			public function findAll(int $limit = 0): array {
				throw new \RuntimeException('organisation table unavailable');
			}//end findAll()
		};

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($mapper);

		$this->assertSame([], $this->build(installed: true, container: $container)->forUser(userId: 'alice', isAdmin: true));
	}//end testItDegradesWhenTheMapperThrowsWhileReading()

	/**
	 * An instance admin gets every organisation, labelled.
	 *
	 * @return void
	 */
	public function testAnAdminGetsEveryOrganisationLabelled(): void {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn(
			$this->mapperReturning(
				[
					$this->organisation(uuid: 'org-1', name: 'Gemeente Aa', owner: 'bob'),
					$this->organisation(uuid: 'org-2', name: '', owner: 'carol'),
				]
			)
		);

		$result = $this->build(installed: true, container: $container)->forUser(userId: 'alice', isAdmin: true);

		// org-2 has no name, so its UUID stands in as the label.
		$this->assertSame(
			[
				['id' => 'org-1', 'label' => 'Gemeente Aa'],
				['id' => 'org-2', 'label' => 'org-2'],
			],
			$result
		);
	}//end testAnAdminGetsEveryOrganisationLabelled()

	/**
	 * A plain user gets ONLY the organisations they own.
	 *
	 * The ownership filter is a real refusal, so it is driven with a row the
	 * caller does not own and the absence of that row is asserted.
	 *
	 * @return void
	 */
	public function testAPlainUserGetsOnlyTheOrganisationsTheyOwn(): void {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn(
			$this->mapperReturning(
				[
					$this->organisation(uuid: 'org-mine', name: 'Mine', owner: 'alice'),
					$this->organisation(uuid: 'org-theirs', name: 'Theirs', owner: 'bob'),
				]
			)
		);

		$result = $this->build(installed: true, container: $container)->forUser(userId: 'alice', isAdmin: false);

		$this->assertSame([['id' => 'org-mine', 'label' => 'Mine']], $result);
	}//end testAPlainUserGetsOnlyTheOrganisationsTheyOwn()

	/**
	 * Build an organisation-mapper double over a fixed row set.
	 *
	 * @param array<int, object> $rows The organisation rows.
	 *
	 * @return object The mapper double.
	 */
	private function mapperReturning(array $rows): object {
		return new class($rows) {
			/**
			 * Constructor.
			 *
			 * @param array<int, object> $rows The rows to serve.
			 */
			public function __construct(private array $rows) {
			}//end __construct()

			/**
			 * Serve every row.
			 *
			 * @param int $limit Row cap (ignored by the double).
			 *
			 * @return array<int, object>
			 */
			public function findAll(int $limit = 0): array {
				return $this->rows;
			}//end findAll()

			/**
			 * Serve every row; the subject applies the ownership filter itself.
			 *
			 * @param string $userId The user id.
			 *
			 * @return array<int, object>
			 */
			public function findByUserId(string $userId): array {
				return $this->rows;
			}//end findByUserId()
		};
	}//end mapperReturning()

	/**
	 * Build one organisation row double.
	 *
	 * @param string $uuid  The organisation UUID.
	 * @param string $name  The display name ('' to exercise the UUID fallback).
	 * @param string $owner The owning user id.
	 *
	 * @return object The row double.
	 */
	private function organisation(string $uuid, string $name, string $owner): object {
		return new class($uuid, $name, $owner) {
			/**
			 * Constructor.
			 *
			 * @param string $uuid  The UUID.
			 * @param string $name  The name.
			 * @param string $owner The owner uid.
			 */
			public function __construct(
				private string $uuid,
				private string $name,
				private string $owner,
			) {
			}//end __construct()

			/**
			 * The organisation UUID.
			 *
			 * @return string
			 */
			public function getUuid(): string {
				return $this->uuid;
			}//end getUuid()

			/**
			 * The organisation name.
			 *
			 * @return string
			 */
			public function getName(): string {
				return $this->name;
			}//end getName()

			/**
			 * The owning user id.
			 *
			 * @return string
			 */
			public function getOwner(): string {
				return $this->owner;
			}//end getOwner()
		};
	}//end organisation()
}//end class
