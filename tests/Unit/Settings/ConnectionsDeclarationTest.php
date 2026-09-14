<?php

/**
 * The connection declaration integriq reads.
 *
 * `lib/Settings/connections.json` is static JSON that integriq turns into the
 * rows of the Integrations page. Nothing in hermiq reads it at runtime, so a
 * broken file fails nowhere in this repository: integriq skips it whole and the
 * page goes empty on some other instance. Every assertion here is a way that
 * file could go wrong without a sound.
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Settings
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/adopt-connection-registry/specs/app-connections/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Settings;

use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Validator;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Guards lib/Settings/connections.json against hydra connection-registry D2 and D12.
 *
 * @coversNothing
 */
class ConnectionsDeclarationTest extends TestCase {

	/**
	 * Integriq's schema, copied from integriq `development` at
	 * 605a87792a062ebbd38e05e8f598597fcf8a94c4 (the D12 amendments:
	 * `reportedOnly`, `jsonPath`, `simulatedValues`).
	 *
	 * @var string
	 */
	private const SCHEMA = '/tests/Fixtures/Integriq/connections.schema.json';

	/**
	 * The keys the file declares, in declared order.
	 *
	 * Frozen once shipped: integriq keys a row by app and key, so a renamed key
	 * is a deleted row and a new one.
	 *
	 * @var array<int, string>
	 */
	private const DECLARED_KEYS = ['llm', 'llm-runner', 'speech', 'web-search', 'webhook-delivery', 'github-templates'];

	/**
	 * The repository root.
	 *
	 * @return string
	 */
	private function root(): string {
		return dirname(__DIR__, 3);
	}//end root()

	/**
	 * The raw declaration file.
	 *
	 * @return string
	 */
	private function raw(): string {
		$raw = file_get_contents($this->root() . '/lib/Settings/connections.json');
		$this->assertIsString(actual: $raw, message: 'lib/Settings/connections.json must exist');

		return $raw;
	}//end raw()

	/**
	 * The decoded declaration.
	 *
	 * @return array<string, mixed>
	 */
	private function declaration(): array {
		$decoded = json_decode($this->raw(), true, 512, JSON_THROW_ON_ERROR);
		$this->assertIsArray(actual: $decoded);

		return $decoded;
	}//end declaration()

	/**
	 * The vendored schema.
	 *
	 * @return string
	 */
	private function schema(): string {
		$schema = file_get_contents($this->root() . self::SCHEMA);
		$this->assertIsString(actual: $schema);

		return $schema;
	}//end schema()

	/**
	 * The file validates against integriq's JSON Schema.
	 *
	 * @return void
	 */
	public function testTheFileValidatesAgainstIntegriqsSchema(): void {
		$result = (new Validator())->validate(json_decode($this->raw()), $this->schema());

		$errors = [];
		if ($result->hasError() === true) {
			$errors = (new ErrorFormatter())->format($result->error());
		}

		$this->assertTrue(condition: $result->isValid(), message: (string)json_encode($errors, JSON_PRETTY_PRINT));
	}//end testTheFileValidatesAgainstIntegriqsSchema()

	/**
	 * The schema check can fail: a misspelled field is refused.
	 *
	 * Without this control a validator that accepts everything would pass the
	 * test above as well.
	 *
	 * @return void
	 */
	public function testTheSchemaRefusesAnUnknownField(): void {
		$declaration = json_decode($this->raw());
		$declaration->connections[0]->setingsUrl = '/settings/admin/hermiq#section-ai-provider';

		$this->assertFalse(condition: (new Validator())->validate($declaration, $this->schema())->isValid());
	}//end testTheSchemaRefusesAnUnknownField()

	/**
	 * The file names the app it ships in.
	 *
	 * @return void
	 */
	public function testTheFileNamesThisApp(): void {
		$infoXml = simplexml_load_file($this->root() . '/appinfo/info.xml');

		$this->assertNotFalse(condition: $infoXml);
		$this->assertSame(expected: (string)$infoXml->id, actual: $this->declaration()['app']);
	}//end testTheFileNamesThisApp()

	/**
	 * The keys are unique, frozen and in rising order.
	 *
	 * @return void
	 */
	public function testTheKeysAreUniqueFrozenAndOrdered(): void {
		$connections = $this->declaration()['connections'];
		$keys = array_column($connections, 'key');

		$this->assertSame(expected: array_values(array_unique($keys)), actual: $keys, message: 'a key is declared twice');
		$this->assertSame(expected: self::DECLARED_KEYS, actual: $keys);

		$orders = array_column($connections, 'order');
		$sorted = $orders;
		sort($sorted);
		$this->assertSame(expected: $sorted, actual: $orders);
		$this->assertCount(expectedCount: count($keys), haystack: array_unique($orders));
	}//end testTheKeysAreUniqueFrozenAndOrdered()

	/**
	 * No text a reader sees carries an em-dash (voice rule 8).
	 *
	 * @return void
	 */
	public function testNoTextCarriesAnEmDash(): void {
		$this->assertStringNotContainsString(needle: "\u{2014}", haystack: $this->raw());
		$this->assertStringNotContainsString(needle: ' -- ', haystack: $this->raw());
	}//end testNoTextCarriesAnEmDash()

	/**
	 * Every settings link lands on an element id that exists under src/ or templates/.
	 *
	 * A link into a section that does not exist opens the settings page at the
	 * top and logs nothing. A copy of the link itself does not count as the
	 * element, so only an `id="..."` attribute is accepted.
	 *
	 * @return void
	 */
	public function testEverySettingsLinkPointsAtAnExistingElement(): void {
		$sources = $this->sourcesUnder(dirs: ['src', 'templates']);
		$linked = [];

		foreach ($this->declaration()['connections'] as $connection) {
			if (array_key_exists('settingsUrl', $connection) === false) {
				continue;
			}

			$url = (string)$connection['settingsUrl'];
			$this->assertStringStartsWith(prefix: '/settings/admin/hermiq#section-', string: $url, message: $connection['key']);
			$anchor = substr($url, (int)strpos($url, '#') + 1);
			$this->assertMatchesRegularExpression(
				pattern: '/\bid="' . preg_quote($anchor, '/') . '"/',
				string: $sources,
				message: $connection['key'] . ' links to a missing element #' . $anchor
			);
			$linked[] = $connection['key'];
		}

		$this->assertSame(expected: ['llm', 'web-search', 'github-templates'], actual: $linked);
	}//end testEverySettingsLinkPointsAtAnExistingElement()

	/**
	 * No row can read Simulated, because nothing in hermiq is a mock.
	 *
	 * An unset AI provider or search backend is off: chat answers 503 and web
	 * search answers search_unavailable. Rule 3 of the contract would call that
	 * a mock, so no row carries an adapter block (design D2 and D3).
	 *
	 * @return void
	 */
	public function testNoRowCanReadSimulated(): void {
		foreach ($this->declaration()['connections'] as $connection) {
			$this->assertArrayNotHasKey(key: 'adapter', array: $connection, message: $connection['key']);
		}
	}//end testNoRowCanReadSimulated()

	/**
	 * Only the speech row is decided by a filled key, and its message names the fallback.
	 *
	 * @return void
	 */
	public function testOnlySpeechIsDecidedByAFilledKey(): void {
		$withRequired = array_filter(
			$this->declaration()['connections'],
			static fn (array $c): bool => array_key_exists('requiredConfig', $c)
		);

		$this->assertSame(expected: ['speech'], actual: array_values(array_column($withRequired, 'key')));
		$speech = array_values($withRequired)[0];
		$this->assertSame(expected: [\OCA\Hermiq\Service\Speech\SpeechClient::CONFIG_BASE_URL], actual: $speech['requiredConfig']);
		$this->assertStringContainsString(needle: 'http://127.0.0.1:8000', haystack: (string)$speech['unconfiguredMessage']);
	}//end testOnlySpeechIsDecidedByAFilledKey()

	/**
	 * The contents of every .vue, .js and .php file under the given directories.
	 *
	 * @param array<int, string> $dirs Directories relative to the repository root.
	 *
	 * @return string
	 */
	private function sourcesUnder(array $dirs): string {
		$contents = '';
		foreach ($dirs as $dir) {
			$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->root() . '/' . $dir));
			foreach ($iterator as $file) {
				if ($file->isFile() === false || preg_match('/\.(vue|js|php)$/', $file->getFilename()) !== 1) {
					continue;
				}

				$contents .= (string)file_get_contents($file->getPathname()) . "\n";
			}
		}

		return $contents;
	}//end sourcesUnder()
}//end class
