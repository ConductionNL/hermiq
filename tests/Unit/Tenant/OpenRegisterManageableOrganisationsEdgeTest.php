<?php

/**
 * Edges of OpenRegisterManageableOrganisations: the service it resolves, null fields, failing user reads.
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Tenant
 *
 * @author    Conduction Development Team <info@conduction.nl>
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

use OCA\Hermiq\Tenant\OpenRegisterManageableOrganisations;
use OCP\App\IAppManager;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

/**
 * @covers \OCA\Hermiq\Tenant\OpenRegisterManageableOrganisations
 */
class OpenRegisterManageableOrganisationsEdgeTest extends TestCase {

	/**
	 * The subject over a container answering $mapper.
	 *
	 * @param object $mapper The organisation mapper.
	 *
	 * @return OpenRegisterManageableOrganisations
	 */
	private function subject(object $mapper): OpenRegisterManageableOrganisations {
		$apps = $this->createMock(IAppManager::class);
		$apps->method('isInstalled')->with('openregister')->willReturn(true);
		$container = $this->createMock(ContainerInterface::class);
		$container->expects($this->once())
			->method('get')
			->with('OCA\OpenRegister\Db\OrganisationMapper')
			->willReturn($mapper);

		return new OpenRegisterManageableOrganisations(appManager: $apps, container: $container);
	}//end subject()

	/**
	 * A mapper answering the given rows for both reads.
	 *
	 * @param list<object> $rows The rows.
	 *
	 * @return object
	 */
	private static function mapper(array $rows): object {
		return new class ($rows) {
			/**
			 * Constructor.
			 *
			 * @param list<object> $rows The rows.
			 */
			public function __construct(private array $rows) {
			}

			/**
			 * Every row.
			 *
			 * @param int $limit The cap.
			 *
			 * @return list<object>
			 */
			public function findAll(int $limit): array {
				return $this->rows;
			}

			/**
			 * Every row; the subject filters ownership itself.
			 *
			 * @param string $userId The user.
			 *
			 * @return list<object>
			 */
			public function findByUserId(string $userId): array {
				return $this->rows;
			}
		};
	}//end mapper()

	/**
	 * An organisation whose name and owner may be null.
	 *
	 * @param string      $uuid  The uuid.
	 * @param string|null $name  The name.
	 * @param string|null $owner The owner.
	 *
	 * @return object
	 */
	private static function organisation(string $uuid, ?string $name, ?string $owner): object {
		return new class ($uuid, $name, $owner) {
			/**
			 * Constructor.
			 *
			 * @param string      $uuid  The uuid.
			 * @param string|null $name  The name.
			 * @param string|null $owner The owner.
			 */
			public function __construct(private string $uuid, private ?string $name, private ?string $owner) {
			}

			/**
			 * The uuid.
			 *
			 * @return string
			 */
			public function getUuid(): string {
				return $this->uuid;
			}

			/**
			 * The name, or null.
			 *
			 * @return string|null
			 */
			public function getName(): ?string {
				return $this->name;
			}

			/**
			 * The owner, or null.
			 *
			 * @return string|null
			 */
			public function getOwner(): ?string {
				return $this->owner;
			}
		};
	}//end organisation()

	/**
	 * A null name is labelled by the uuid, and an admin sees ownerless rows in the mapper's order.
	 *
	 * @return void
	 */
	public function testAnAdminSeesOwnerlessRowsLabelledByUuid(): void {
		$subject = $this->subject(
			mapper: self::mapper(
				rows: [
					self::organisation(uuid: 'org-b', name: null, owner: null),
					self::organisation(uuid: 'org-a', name: 'Gemeente A', owner: 'bob'),
				]
			)
		);

		$this->assertSame(
			[['id' => 'org-b', 'label' => 'org-b'], ['id' => 'org-a', 'label' => 'Gemeente A']],
			$subject->forUser(userId: 'alice', isAdmin: true)
		);
	}//end testAnAdminSeesOwnerlessRowsLabelledByUuid()

	/**
	 * A plain user owns no ownerless row, and ownership is compared exactly.
	 *
	 * @return void
	 */
	public function testAPlainUserOwnsNoOwnerlessOrLookalikeRow(): void {
		$subject = $this->subject(
			mapper: self::mapper(
				rows: [
					self::organisation(uuid: 'org-null', name: 'Null', owner: null),
					self::organisation(uuid: 'org-case', name: 'Case', owner: 'Alice'),
					self::organisation(uuid: 'org-pad', name: 'Pad', owner: 'alice '),
					self::organisation(uuid: 'org-mine', name: 'Mine', owner: 'alice'),
				]
			)
		);

		$this->assertSame([['id' => 'org-mine', 'label' => 'Mine']], $subject->forUser(userId: 'alice', isAdmin: false));
	}//end testAPlainUserOwnsNoOwnerlessOrLookalikeRow()

	/**
	 * A plain user's read that fails answers empty instead of escaping.
	 *
	 * @return void
	 */
	public function testAFailingUserReadAnswersEmpty(): void {
		$mapper = new class {
			/**
			 * Fail the user read.
			 *
			 * @param string $userId The user.
			 *
			 * @return list<object>
			 *
			 * @throws \Error Always.
			 */
			public function findByUserId(string $userId): array {
				throw new \Error('membership table gone');
			}
		};

		$this->assertSame([], $this->subject(mapper: $mapper)->forUser(userId: 'alice', isAdmin: false));
	}//end testAFailingUserReadAnswersEmpty()

	/**
	 * A mapper with no rows answers an empty list for both roles.
	 *
	 * @return void
	 */
	public function testNoOrganisationsAnswersEmpty(): void {
		$this->assertSame([], $this->subject(mapper: self::mapper(rows: []))->forUser(userId: 'alice', isAdmin: true));
		$this->assertSame([], $this->subject(mapper: self::mapper(rows: []))->forUser(userId: 'alice', isAdmin: false));
	}//end testNoOrganisationsAnswersEmpty()
}//end class
