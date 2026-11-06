<?php

/**
 * The web research save and the report it sends to integriq.
 *
 * A web research save is the one moment hermiq learns what the search backend
 * is. An empty provider or endpoint is off, not mocked, so the row must read
 * Not configured. The message must name the endpoint host and never the full
 * endpoint, which may carry a key in its query string.
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Controller\Settings
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/adopt-connection-registry/specs/app-connections/spec.md#requirement-hermiq-reports-what-only-it-can-observe-req-hermiq-conn-003
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Controller\Settings;

use OCA\Hermiq\Controller\Settings\WebResearchSettingsController;
use OCA\Hermiq\Service\Connection\ConnectionReporter;
use OCA\Hermiq\Service\WebResearch\WebResearchSettingsHandler;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * Unit tests for the connection report sent from a web research save.
 *
 * @covers \OCA\Hermiq\Controller\Settings\WebResearchSettingsController
 */
class WebResearchSettingsConnectionReportTest extends TestCase {

	/**
	 * Every report sent, as [key, status, message].
	 *
	 * @var array<int, array{0: string, 1: string, 2: string}>
	 */
	private array $reports = [];

	/**
	 * Save the given patch through a handler that answers the given merged config.
	 *
	 * @param array<string, mixed>|null $merged The merged config the handler returns, or null to throw.
	 *
	 * @return int The response status.
	 */
	private function save(?array $merged): int {
		$request = $this->createMock(originalClassName: IRequest::class);
		$request->method('getParam')->with('webResearch')->willReturn(['searchProvider' => 'searxng']);

		$handler = $this->createMock(originalClassName: WebResearchSettingsHandler::class);
		if ($merged === null) {
			$handler->method('updateWebResearchSettingsOnly')->willThrowException(new RuntimeException('disk full'));
		} else {
			$handler->method('updateWebResearchSettingsOnly')->willReturn($merged);
		}

		$reporter = $this->getMockBuilder(className: ConnectionReporter::class)
			->disableOriginalConstructor()
			->onlyMethods(['report'])
			->getMock();
		$reporter->method('report')->willReturnCallback(
			function (string $key, string $status, string $message = ''): bool {
				$this->reports[] = [$key, $status, $message];
				return true;
			}
		);

		$controller = new WebResearchSettingsController(
			request: $request,
			settingsHandler: $handler,
			logger: new NullLogger(),
			connectionReporter: $reporter
		);

		return $controller->update()->getStatus();
	}//end save()

	/**
	 * A saved provider and endpoint report configured, naming the backend and the host only.
	 *
	 * @return void
	 */
	public function testASavedBackendReportsConfiguredWithTheHostOnly(): void {
		$status = $this->save(
			merged: ['searchProvider' => 'searxng', 'searchEndpoint' => 'https://search.example.nl/?token=s3cret']
		);

		$this->assertSame(expected: 200, actual: $status);
		$this->assertCount(expectedCount: 1, haystack: $this->reports);
		$this->assertSame(expected: ['web-search', 'configured'], actual: array_slice($this->reports[0], 0, 2));
		$this->assertStringContainsString(needle: 'SearXNG at search.example.nl', haystack: $this->reports[0][2]);
		$this->assertStringNotContainsString(needle: 's3cret', haystack: $this->reports[0][2]);
		$this->assertStringContainsString(needle: 'not tested', haystack: $this->reports[0][2]);
	}//end testASavedBackendReportsConfiguredWithTheHostOnly()

	/**
	 * An empty endpoint reports unconfigured, never simulated.
	 *
	 * @return void
	 */
	public function testAnEmptyEndpointReportsUnconfigured(): void {
		$this->save(merged: ['searchProvider' => 'generic-json', 'searchEndpoint' => '  ']);

		$this->assertSame(expected: ['web-search', 'unconfigured'], actual: array_slice($this->reports[0], 0, 2));
		$this->assertStringContainsString(needle: 'search_unavailable', haystack: $this->reports[0][2]);
	}//end testAnEmptyEndpointReportsUnconfigured()

	/**
	 * A generic JSON API is named as such.
	 *
	 * @return void
	 */
	public function testAGenericApiIsNamed(): void {
		$this->save(merged: ['searchProvider' => 'generic-json', 'searchEndpoint' => 'https://api.example.org/search']);

		$this->assertStringContainsString(needle: 'a JSON search API at api.example.org', haystack: $this->reports[0][2]);
	}//end testAGenericApiIsNamed()

	/**
	 * A failed save reports nothing: nothing new was learned.
	 *
	 * @return void
	 */
	public function testAFailedSaveReportsNothing(): void {
		$this->assertSame(expected: 500, actual: $this->save(merged: null));
		$this->assertSame(expected: [], actual: $this->reports);
	}//end testAFailedSaveReportsNothing()
}//end class
