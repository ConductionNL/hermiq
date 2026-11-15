<?php

/**
 * Tests for the public health check.
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Controller
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
 * @spec openspec/specs/observability/spec.md#REQ-OBS-002
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Controller;

use OCA\Hermiq\Controller\HealthController;
use OCA\Hermiq\Service\SettingsService;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * The three health answers, exactly.
 *
 * @covers \OCA\Hermiq\Controller\HealthController
 *
 * @spec openspec/specs/observability/spec.md#REQ-OBS-002
 */
class HealthControllerTest extends TestCase {

	/**
	 * OpenRegister available is ok, unavailable is degraded, a failed check is an error.
	 *
	 * @return void
	 */
	public function testTheThreeAnswers(): void {
		$answers = [];
		foreach ([true, false, null] as $available) {
			$settings = $this->createMock(originalClassName: SettingsService::class);
			$logger = $this->createMock(originalClassName: LoggerInterface::class);
			if ($available === null) {
				$failure = new RuntimeException(message: 'down');
				$settings->method('isOpenRegisterAvailable')->willThrowException($failure);
				$logger->expects($this->once())->method('error')->with('Hermiq: health check failed', ['exception' => $failure]);
			} else {
				$settings->method('isOpenRegisterAvailable')->willReturn($available);
				$logger->expects($this->never())->method('error');
			}

			$response = (new HealthController(
				request: $this->createMock(originalClassName: IRequest::class),
				settingsService: $settings,
				logger: $logger
			))->index();
			$answers[] = [$response->getStatus(), $response->getData()];
		}

		$this->assertSame(
			expected: [
				[200, ['status' => 'ok', 'app' => 'hermiq', 'version' => '0.1.0', 'dependencies' => ['openregister' => true]]],
				[503, ['status' => 'degraded', 'app' => 'hermiq', 'version' => '0.1.0', 'dependencies' => ['openregister' => false]]],
				[500, ['status' => 'error', 'message' => 'Health check failed']],
			],
			actual: $answers
		);

	}//end testTheThreeAnswers()
}//end class
