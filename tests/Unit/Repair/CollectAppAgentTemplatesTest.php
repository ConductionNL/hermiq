<?php

/**
 * Unit tests for the CollectAppAgentTemplates repair step (agents-bound-to-their-app, task 6).
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Tests
 * @package  OCA\Hermiq\Tests\Unit\Repair
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/agent-template-gallery/spec.md#requirement-an-installed-app-can-offer-an-agent-template-for-itself-req-appag-005
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Repair;

use OCA\Hermiq\Repair\CollectAppAgentTemplates;
use OCA\Hermiq\Service\AppTemplateOffers;
use OCA\OpenRegister\Service\ObjectService;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * The step collects app offers on install and upgrade, under a system identity.
 */
final class CollectAppAgentTemplatesTest extends TestCase {

	public function testTheStepCollectsUnderASystemIdentityAndReportsTheCounts(): void {
		$objectService = new class extends ObjectService {
			public int $elevations = 0;

			public bool $elevated = false;

			public function __construct() {
			}

			public function runAsSystem(callable $operation): mixed {
				$this->elevations++;
				$this->elevated = true;
				try {
					return $operation();
				} finally {
					$this->elevated = false;
				}
			}
		};

		$offers = $this->createMock(AppTemplateOffers::class);
		$offers->expects(self::once())->method('collect')->willReturnCallback(
			static function () use ($objectService): array {
				self::assertTrue($objectService->elevated, 'the collect writes run under the system identity');
				return ['imported' => 1, 'updated' => 0, 'unchanged' => 2, 'refused' => 0];
			}
		);

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static fn (string $id): object => match ($id) {
				ObjectService::class => $objectService,
				AppTemplateOffers::class => $offers,
			}
		);

		$output = $this->createMock(IOutput::class);
		$output->expects(self::once())->method('info')->with(self::stringContains('1 new, 0 updated, 2 unchanged, 0 refused'));

		(new CollectAppAgentTemplates(container: $container, logger: new NullLogger()))->run(output: $output);

		self::assertSame(1, $objectService->elevations);
	}//end testTheStepCollectsUnderASystemIdentityAndReportsTheCounts()

	public function testWithoutOpenRegisterTheStepSkipsWithAWarning(): void {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willThrowException(new RuntimeException('OpenRegister is not installed'));

		$output = $this->createMock(IOutput::class);
		$output->expects(self::once())->method('warning');

		(new CollectAppAgentTemplates(container: $container, logger: new NullLogger()))->run(output: $output);
	}//end testWithoutOpenRegisterTheStepSkipsWithAWarning()

	public function testTheStepIsRegisteredOnInstallAndOnUpgrade(): void {
		$info = (string)file_get_contents(__DIR__ . '/../../../appinfo/info.xml');

		self::assertSame(2, substr_count($info, '<step>OCA\Hermiq\Repair\CollectAppAgentTemplates</step>'));
	}//end testTheStepIsRegisteredOnInstallAndOnUpgrade()
}//end class
