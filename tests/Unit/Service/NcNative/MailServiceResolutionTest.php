<?php

/**
 * Tests for how MailReadService resolves Mail's server-only services.
 *
 * Mail's classes exist only inside a Nextcloud server with the Mail app, so the
 * resolution path is exercised by declaring stand-ins in a separate process —
 * the declarations must not leak into the tests that assert Mail is absent.
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Service\NcNative
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service\NcNative;

use OCA\Hermiq\Service\AiFeature\AiFeatureLookup;
use OCA\Hermiq\Service\NcNative\MailReadService;
use OCA\OpenRegister\Db\ObjectEntity;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @covers \OCA\Hermiq\Service\NcNative\MailReadService
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class MailServiceResolutionTest extends TestCase {

	/**
	 * Declare a stand-in for Mail's AccountService.
	 *
	 * @return void
	 */
	private static function declareAccountService(): void {
		eval('namespace OCA\Mail\Service; class AccountService { public function findByUserId($uid) { return []; } }');

	}//end declareAccountService()

	/**
	 * Build the service with the feature enabled over the given container.
	 *
	 * @param ContainerInterface $container The container.
	 * @param LoggerInterface|null $logger The logger.
	 *
	 * @return MailReadService
	 */
	private function service(ContainerInterface $container, ?LoggerInterface $logger = null): MailReadService {
		$feature = new ObjectEntity();
		$feature->setObject(['enabled' => true]);
		$features = $this->createMock(AiFeatureLookup::class);
		$features->method('findBySlug')->willReturn($feature);

		return new MailReadService($features, $container, $logger ?? $this->createMock(LoggerInterface::class));

	}//end service()

	/**
	 * With Mail installed, the account service is fetched from the container by
	 * its class name and used.
	 *
	 * @return void
	 */
	public function testAnInstalledMailServiceIsResolvedFromTheContainer(): void {
		self::declareAccountService();
		$accounts = new class {
			/**
			 * @param string $uid The user id.
			 *
			 * @return array<int, object>
			 */
			public function findByUserId(string $uid): array {
				return [
					new class {
						/**
						 * @return int
						 */
						public function getId(): int {
							return 4;
						}

						/**
						 * @return string
						 */
						public function getEmail(): string {
							return 'bob@example.org';
						}

						/**
						 * @return string
						 */
						public function getName(): string {
							return 'Bob';
						}
					},
				];
			}
		};
		$container = $this->createMock(ContainerInterface::class);
		$container->expects($this->once())
			->method('get')
			->with('\OCA\Mail\Service\AccountService')
			->willReturn($accounts);

		$result = $this->service($container)->listAccounts('bob');

		$this->assertSame(['accounts' => [['id' => 4, 'email' => 'bob@example.org', 'name' => 'Bob']]], $result);

	}//end testAnInstalledMailServiceIsResolvedFromTheContainer()

	/**
	 * A container that cannot build the service makes Mail count as absent.
	 *
	 * @return void
	 */
	public function testAnUnresolvableMailServiceIsMailAbsent(): void {
		self::declareAccountService();
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willThrowException(new RuntimeException('cannot build'));
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('debug');

		$result = $this->service($container, $logger)->listAccounts('bob');

		$this->assertSame('mail_not_available', $result['error']['code']);

	}//end testAnUnresolvableMailServiceIsMailAbsent()

	/**
	 * A container handing back a non-object is treated as Mail absent.
	 *
	 * @return void
	 */
	public function testANonObjectFromTheContainerIsMailAbsent(): void {
		self::declareAccountService();
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn('not a service');

		$result = $this->service($container)->listAccounts('bob');

		$this->assertSame('mail_not_available', $result['error']['code']);

	}//end testANonObjectFromTheContainerIsMailAbsent()

}//end class
