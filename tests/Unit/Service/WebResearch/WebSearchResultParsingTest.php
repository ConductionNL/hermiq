<?php

/**
 * Characterisation of how WebSearchClient reads search responses that are
 * not shaped as expected: SearXNG and generic-JSON entries that are not
 * objects, missing fields, a results path that does not lead to a list,
 * and nested field paths; plus how the settings handler reports a stored
 * configuration it cannot read or write.
 *
 * @category Tests
 * @package  OCA\Hermiq\Tests\Unit\Service\WebResearch
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

namespace OCA\Hermiq\Tests\Unit\Service\WebResearch;

use OCA\Hermiq\Service\WebResearch\WebResearchEgressGuard;
use OCA\Hermiq\Service\WebResearch\WebResearchSettingsHandler;
use OCA\Hermiq\Service\WebResearch\WebSearchClient;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * Lenient result parsing and settings failures.
 *
 * @covers \OCA\Hermiq\Service\WebResearch\WebSearchClient
 * @covers \OCA\Hermiq\Service\WebResearch\WebResearchSettingsHandler
 * @uses   \OCA\Hermiq\Service\WebResearch\WebSearchRun
 */
final class WebSearchResultParsingTest extends TestCase {

	/**
	 * Search once against a backend that answers with this body.
	 *
	 * @param array<string, mixed> $settings Settings overrides.
	 * @param mixed                $body     The decoded response body.
	 *
	 * @return array<string, mixed>
	 */
	private function searchReturning(array $settings, mixed $body): array {
		$handler = $this->createMock(WebResearchSettingsHandler::class);
		$handler->method('getWebResearchSettingsOnly')->willReturn(
			array_merge(
				[
					'searchProvider' => 'searxng',
					'searchEndpoint' => 'https://searxng.internal',
					'searchCredentialId' => '',
					'searchFieldMapping' => [],
					'allowInsecureHttp' => false,
					'timeoutSeconds' => 10,
				],
				$settings
			)
		);
		$guard = $this->createMock(WebResearchEgressGuard::class);
		$guard->method('assertSafe')->willReturn(['allowed' => true, 'code' => null, 'message' => null]);
		$response = $this->createMock(IResponse::class);
		$response->method('getBody')->willReturn((string)json_encode($body));
		$client = $this->createMock(IClient::class);
		$client->method('get')->willReturn($response);
		$clients = $this->createMock(IClientService::class);
		$clients->method('newClient')->willReturn($client);

		return (new WebSearchClient($clients, $handler, $guard, new NullLogger()))->search(query: 'q');
	}//end searchReturning()

	/**
	 * SearXNG entries that are not objects are skipped; missing fields read as empty.
	 *
	 * @return void
	 */
	public function testSearxngSkipsNonObjectsAndFillsMissingFields(): void {
		$result = $this->searchReturning(
			settings: [],
			body: ['results' => ['a string', ['url' => 'https://a.example'], 7, ['title' => 'T', 'content' => 'C']]]
		);

		self::assertSame(
			[
				['title' => '', 'url' => 'https://a.example', 'snippet' => ''],
				['title' => 'T', 'url' => '', 'snippet' => 'C'],
			],
			$result['results']
		);
	}//end testSearxngSkipsNonObjectsAndFillsMissingFields()

	/**
	 * Without a mapping the generic parser reads results/title/url/content, through nested paths when mapped.
	 *
	 * @return void
	 */
	public function testGenericJsonDefaultsAndNestedFields(): void {
		$defaults = $this->searchReturning(
			settings: ['searchProvider' => 'generic-json'],
			body: ['results' => [['title' => 'A', 'url' => 'https://a.example', 'content' => 'x'], 'skip me']]
		);
		$nested = $this->searchReturning(
			settings: [
				'searchProvider' => 'generic-json',
				'searchFieldMapping' => ['resultsPath' => 'hits', 'titleField' => 'doc.title', 'urlField' => 'doc.link', 'snippetField' => 'doc.meta.summary'],
			],
			body: ['hits' => [['doc' => ['title' => 'B', 'link' => 'https://b.example', 'meta' => 'flat']]]]
		);

		self::assertSame([['title' => 'A', 'url' => 'https://a.example', 'snippet' => 'x']], $defaults['results']);
		self::assertSame([['title' => 'B', 'url' => 'https://b.example', 'snippet' => '']], $nested['results']);
	}//end testGenericJsonDefaultsAndNestedFields()

	/**
	 * A results path that is missing or does not lead to a list yields no results, not an error.
	 *
	 * @return void
	 */
	public function testGenericJsonWithoutAListYieldsNoResults(): void {
		$mapping = ['searchProvider' => 'generic-json', 'searchFieldMapping' => ['resultsPath' => 'data.items']];

		self::assertSame(['query' => 'q', 'results' => []], $this->searchReturning(settings: $mapping, body: ['data' => 'none']));
		self::assertSame(['query' => 'q', 'results' => []], $this->searchReturning(settings: $mapping, body: ['other' => []]));
		self::assertSame(['query' => 'q', 'results' => []], $this->searchReturning(settings: $mapping, body: ['data' => ['items' => 'x']]));
	}//end testGenericJsonWithoutAListYieldsNoResults()

	/**
	 * A stored configuration that is not a JSON object is reported, not defaulted.
	 *
	 * @return void
	 */
	public function testUnreadableStoredSettingsAreReported(): void {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->with('hermiq', 'webResearch', '')->willReturn('"just a string"');

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('Failed to retrieve web-research settings: Stored hermiq.webResearch config is not a JSON object');

		(new WebResearchSettingsHandler($config))->getWebResearchSettingsOnly();
	}//end testUnreadableStoredSettingsAreReported()

	/**
	 * An update over unreadable stored settings is reported and writes nothing.
	 *
	 * @return void
	 */
	public function testAnUpdateOverUnreadableSettingsWritesNothing(): void {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturn('[not json');
		$config->expects($this->never())->method('setValueString');

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('Failed to update web-research settings: Failed to retrieve web-research settings:');

		(new WebResearchSettingsHandler($config))->updateWebResearchSettingsOnly(['searchProvider' => 'searxng']);
	}//end testAnUpdateOverUnreadableSettingsWritesNothing()
}//end class
