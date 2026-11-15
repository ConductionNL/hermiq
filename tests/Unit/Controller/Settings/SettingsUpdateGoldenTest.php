<?php

/**
 * Golden tests for the report-similarity and web-research settings updates.
 *
 * Pins what each update hands its settings store and what it answers: the
 * report-similarity thresholds and windows (numbers only, lists only, 422 and 500),
 * and the web-research patch from a nested or a flat body, its provider refusal and
 * a failed save.
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Controller\Settings
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/identical-reports-collapse-into-one/specs/report-similarity/spec.md#scenario-the-thresholds-are-the-administrators
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Controller\Settings;

use InvalidArgumentException;
use OCA\Hermiq\Controller\Settings\ReportSimilaritySettingsController;
use OCA\Hermiq\Controller\Settings\WebResearchSettingsController;
use OCA\Hermiq\Service\ReportSimilarity\ReportSimilaritySettings;
use OCA\Hermiq\Service\WebResearch\WebResearchSettingsHandler;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * Golden tests for two settings updates.
 *
 * @spec openspec/changes/identical-reports-collapse-into-one/specs/report-similarity/spec.md#scenario-the-thresholds-are-the-administrators
 */
class SettingsUpdateGoldenTest extends TestCase {

	/**
	 * A request answering the given params.
	 *
	 * @param array<string, mixed> $params The params.
	 *
	 * @return IRequest
	 */
	private function request(array $params): IRequest {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			static fn (string $key, $default = null) => (array_key_exists($key, $params) === true ? $params[$key] : $default)
		);
		$request->method('getParams')->willReturn($params);
		return $request;
	}//end request()

	/**
	 * What the report-similarity update stores and answers.
	 *
	 * @return void
	 */
	public function testTheReportSimilarityUpdate(): void {
		$stored = [];
		$settings = $this->createMock(ReportSimilaritySettings::class);
		$settings->method('store')->willReturnCallback(
			function (?float $upper, ?float $lower, ?array $windows) use (&$stored): array {
				$stored[] = [$upper, $lower, $windows];
				if ($upper === 9.0) {
					throw new InvalidArgumentException('too high');
				}

				if ($upper === 8.0) {
					throw new RuntimeException('db');
				}

				return ['saved' => true];
			}
		);

		$update = fn (array $params) => (new ReportSimilaritySettingsController($this->request($params), $settings, new NullLogger()))->update();

		$response = $update(['upper' => '0.9', 'lower' => 0.5, 'windows' => ['a' => 1]]);
		$this->assertSame([200, ['saved' => true]], [$response->getStatus(), $response->getData()]);
		$update(['upper' => 'high', 'windows' => 'x']);
		$this->assertSame([[0.9, 0.5, ['a' => 1]], [null, null, null]], $stored);

		$response = $update(['upper' => 9]);
		$this->assertSame([422, ['error' => 'too high']], [$response->getStatus(), $response->getData()]);
		$response = $update(['upper' => 8]);
		$this->assertSame([500, ['error' => 'Failed to save the grouping settings']], [$response->getStatus(), $response->getData()]);
	}//end testTheReportSimilarityUpdate()

	/**
	 * The web-research patch from a flat body, an unsupported provider and a failed save.
	 *
	 * @return void
	 */
	public function testTheWebResearchUpdate(): void {
		$patches = [];
		$handler = $this->createMock(WebResearchSettingsHandler::class);
		$handler->method('updateWebResearchSettingsOnly')->willReturnCallback(
			function (array $patch) use (&$patches): array {
				$patches[] = $patch;
				if (($patch['timeoutSeconds'] ?? 0) === 99) {
					throw new RuntimeException('db');
				}

				return $patch;
			}
		);

		$update = fn (array $params) => (new WebResearchSettingsController($this->request($params), $handler, new NullLogger()))->update();

		$update(['_route' => 'r', 'timeoutSeconds' => 5]);
		$this->assertSame([['timeoutSeconds' => 5]], $patches);

		$response = $update(['webResearch' => ['searchProvider' => 'nope']]);
		$this->assertSame(422, $response->getStatus());
		$this->assertSame('Unsupported search provider', $response->getData()['error']);
		$this->assertSame(WebResearchSettingsHandler::ALLOWED_SEARCH_PROVIDERS, $response->getData()['allowed']);

		$response = $update(['webResearch' => ['timeoutSeconds' => 99]]);
		$this->assertSame([500, ['error' => 'Failed to save web-research configuration']], [$response->getStatus(), $response->getData()]);
		$this->assertCount(2, $patches);
	}//end testTheWebResearchUpdate()
}//end class
