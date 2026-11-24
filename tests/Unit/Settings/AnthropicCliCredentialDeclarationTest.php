<?php

/**
 * The anthropic-cli credential declaration against OpenRegister's catalogue.
 *
 * `src/credentials.json` names the providers the Credentials tab offers, and
 * OpenRegister's `CredentialController::create()` refuses a provider its
 * catalogue does not know. Nothing in hermiq reads the catalogue, so a renamed
 * identifier or a catalogue entry that lost `inject_only` fails nowhere in this
 * repository. These assertions pin the declaration's shape, and, where an
 * OpenRegister checkout sits next to this one, that the declared identifier
 * resolves to an inject-only entry the broker never proxies.
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
 * @spec openspec/changes/archive/2026-11-24-cli-runner-credential-declaration/specs/agent-credentials/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Settings;

use PHPUnit\Framework\TestCase;

/**
 * Guards the anthropic-cli declaration and its catalogue entry.
 *
 * @coversNothing
 */
class AnthropicCliCredentialDeclarationTest extends TestCase {

	/**
	 * The provider identifier the CLI execution mode resolves.
	 *
	 * @var string
	 */
	private const PROVIDER = 'anthropic-cli';

	/**
	 * The repository root.
	 *
	 * @return string
	 */
	private function root(): string {
		return dirname(__DIR__, 3);
	}//end root()

	/**
	 * The declared credentials, decoded.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function declarations(): array {
		$raw = file_get_contents($this->root() . '/src/credentials.json');
		$this->assertIsString(actual: $raw, message: 'src/credentials.json must exist');
		$decoded = json_decode(json: $raw, associative: true, flags: JSON_THROW_ON_ERROR);
		$this->assertIsArray(actual: $decoded);

		return $decoded;
	}//end declarations()

	/**
	 * The declarations for one provider.
	 *
	 * @param string $provider The provider identifier.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function declared(string $provider): array {
		return array_values(
			array_filter(
				$this->declarations(),
				static fn (array $entry): bool => ($entry['provider'] ?? null) === $provider
			)
		);
	}//end declared()

	/**
	 * OpenRegister's provider catalogue from a sibling checkout, or a skip.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function catalogue(): array {
		$path = $this->root() . '/../openregister/lib/Settings/credential-providers.json';
		if (is_file($path) === false) {
			$this->markTestSkipped(message: 'No OpenRegister checkout next to this one; the catalogue cannot be read.');
		}

		$decoded = json_decode(json: (string) file_get_contents($path), associative: true, flags: JSON_THROW_ON_ERROR);
		$this->assertIsArray(actual: ($decoded['providers'] ?? null), message: 'The catalogue holds a providers map');

		return $decoded['providers'];
	}//end catalogue()

	/**
	 * Every declaration has the provider, reason and scopes shape.
	 *
	 * @return void
	 */
	public function testEveryDeclarationHasTheManifestShape(): void {
		foreach ($this->declarations() as $entry) {
			$this->assertSame(expected: ['provider', 'reason', 'scopes'], actual: array_keys($entry));
			$this->assertIsString(actual: $entry['provider']);
			$this->assertNotSame(expected: '', actual: trim($entry['reason']));
			$this->assertIsArray(actual: $entry['scopes']);
		}
	}//end testEveryDeclarationHasTheManifestShape()

	/**
	 * The anthropic-cli declaration is there once, unscoped, with a reason a user can read.
	 *
	 * @return void
	 */
	public function testTheCliCredentialIsDeclaredOnceWithAReadableReason(): void {
		$entries = $this->declared(provider: self::PROVIDER);
		$this->assertCount(expectedCount: 1, haystack: $entries);

		$entry = $entries[0];
		$this->assertSame(expected: [], actual: $entry['scopes']);
		$this->assertStringContainsString(needle: 'CLI', haystack: $entry['reason']);
		$this->assertStringContainsString(needle: 'cannot be kept inside OpenRegister and proxied', haystack: $entry['reason']);
	}//end testTheCliCredentialIsDeclaredOnceWithAReadableReason()

	/**
	 * The two proxy-path Anthropic providers stay declared beside it.
	 *
	 * @return void
	 */
	public function testTheProxyAnthropicProvidersStayDeclared(): void {
		$this->assertCount(expectedCount: 1, haystack: $this->declared(provider: 'anthropic'));
		$this->assertCount(expectedCount: 1, haystack: $this->declared(provider: 'anthropic-oauth'));
	}//end testTheProxyAnthropicProvidersStayDeclared()

	/**
	 * The declared identifier resolves byte for byte to an inject-only entry with nothing to proxy.
	 *
	 * @return void
	 */
	public function testTheCatalogueEntryIsInjectOnlyWithNothingToProxy(): void {
		$catalogue = $this->catalogue();
		$this->assertArrayHasKey(key: self::PROVIDER, array: $catalogue);

		$entry = $catalogue[self::PROVIDER];
		$this->assertSame(expected: self::PROVIDER, actual: ($entry['identifier'] ?? null));
		$this->assertTrue(condition: ($entry['inject_only'] ?? false) === true);
		$this->assertArrayNotHasKey(key: 'baseUrl', array: $entry);
		$this->assertArrayNotHasKey(key: 'allowRules', array: $entry);
	}//end testTheCatalogueEntryIsInjectOnlyWithNothingToProxy()

	/**
	 * The OAuth provider stays on the proxy path, so the broker never hands out its secret.
	 *
	 * @return void
	 */
	public function testTheOauthProviderIsNotInjectOnly(): void {
		$catalogue = $this->catalogue();
		$this->assertArrayHasKey(key: 'anthropic-oauth', array: $catalogue);
		$this->assertFalse(condition: ($catalogue['anthropic-oauth']['inject_only'] ?? false) === true);
	}//end testTheOauthProviderIsNotInjectOnly()
}//end class
