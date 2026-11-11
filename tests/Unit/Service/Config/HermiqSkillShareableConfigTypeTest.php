<?php

/**
 * Tests for installing shared skills from a config bundle.
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Service\Config
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec exclude Implements OpenRegister's IShareableConfigType; the canonical spec has its single home in openregister.
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service\Config;

use OCA\Hermiq\Service\Config\HermiqSkillShareableConfigType;
use OCA\Hermiq\Service\SkillMarketplaceService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use RuntimeException;

/**
 * Exact installs from a bundle, by who imports it.
 *
 * @covers \OCA\Hermiq\Service\Config\HermiqSkillShareableConfigType
 *
 * @spec exclude Implements OpenRegister's IShareableConfigType; the canonical spec has its single home in openregister.
 */
class HermiqSkillShareableConfigTypeTest extends TestCase {

	/**
	 * Every package is installed through the hub path as the importer (or nobody
	 * when no user can be read); entries without a package are skipped.
	 *
	 * @return void
	 */
	public function testEachPackageIsInstalledAsTheImporter(): void {
		$bundle = ['skills' => ['junk', ['package' => ''], ['package' => 'pkg-a'], ['name' => 'no package'], ['package' => 'pkg-b']]];
		$seen = [];
		foreach (['alice', null, 'throws'] as $who) {
			$calls = [];
			$result = $this->type(who: $who, calls: $calls)->deserialise(bundle: $bundle);
			$seen[] = ['result' => $result, 'calls' => $calls];
		}

		$installs = [['pkg-a', 'hub', 'alice'], ['pkg-b', 'hub', 'alice']];
		$anonymous = [['pkg-a', 'hub', ''], ['pkg-b', 'hub', '']];
		$this->assertSame(
			expected: [
				['result' => ['installed' => ['uuid-pkg-a', 'uuid-pkg-b']], 'calls' => $installs],
				['result' => ['installed' => ['uuid-pkg-a', 'uuid-pkg-b']], 'calls' => $anonymous],
				['result' => ['installed' => ['uuid-pkg-a', 'uuid-pkg-b']], 'calls' => $anonymous],
			],
			actual: $seen
		);
		$this->assertSame(expected: ['installed' => []], actual: $this->type(who: 'alice', calls: $calls)->deserialise(bundle: []));

	}//end testEachPackageIsInstalledAsTheImporter()

	/**
	 * The config type over a recording marketplace and the given session.
	 *
	 * @param string|null       $who   The signed-in uid, null for none, or `throws`.
	 * @param array<int, mixed> $calls The install calls, recorded.
	 *
	 * @return HermiqSkillShareableConfigType
	 */
	private function type(?string $who, array &$calls): HermiqSkillShareableConfigType {
		$marketplace = $this->createMock(originalClassName: SkillMarketplaceService::class);
		$marketplace->method('installFromSource')->willReturnCallback(
			static function (string $package, string $source, string $createdBy) use (&$calls): ObjectEntity {
				$calls[] = [$package, $source, $createdBy];
				$saved = new ObjectEntity();
				$saved->setUuid('uuid-' . $package);
				return $saved;
			}
		);
		$session = $this->createMock(originalClassName: IUserSession::class);
		if ($who === 'throws') {
			$session->method('getUser')->willThrowException(new RuntimeException(message: 'no session'));
		} else {
			$user = null;
			if ($who !== null) {
				$user = $this->createMock(originalClassName: IUser::class);
				$user->method('getUID')->willReturn($who);
			}

			$session->method('getUser')->willReturn($user);
		}

		$container = $this->createMock(originalClassName: ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static fn (string $id): object => [SkillMarketplaceService::class => $marketplace, IUserSession::class => $session][$id]
		);

		return new HermiqSkillShareableConfigType(container: $container);

	}//end type()
}//end class
