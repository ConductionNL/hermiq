<?php

/**
 * @category Tests
 * @package  OCA\Hermiq\Tests\Unit
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/personal-preferences/specs/personal-preferences/spec.md#requirement-the-voorkeuren-section-offers-the-drawn-preferences-req-pref-002
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service;

use OCA\Hermiq\Service\PersonalPreferences;
use OCP\IConfig;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Hermiq\Service\PersonalPreferences
 */
class PersonalPreferencesTest extends TestCase {

	/**
	 * A preference is read for the person, under the key the preferences endpoint stores.
	 *
	 * @return void
	 */
	public function testAPreferenceIsReadForThePerson(): void {
		$config = $this->createMock(IConfig::class);
		$config->method('getUserValue')->willReturnCallback(
			static fn (string $uid, string $app, string $key, mixed $default): string => ($app === 'hermiq' && $key === 'pref_ask-before-send') ? ['pieter' => 'true', 'anouk' => '0'][$uid] ?? '' : ''
		);
		$prefs = new PersonalPreferences(config: $config);

		self::assertTrue($prefs->isOn(userId: 'pieter', key: PersonalPreferences::ASK_BEFORE_SEND));
		self::assertFalse($prefs->isOn(userId: 'anouk', key: PersonalPreferences::ASK_BEFORE_SEND));
		self::assertFalse($prefs->isOn(userId: 'nobody', key: PersonalPreferences::ASK_BEFORE_SEND));
		self::assertFalse($prefs->isOn(userId: '', key: PersonalPreferences::ASK_BEFORE_SEND));
	}//end testAPreferenceIsReadForThePerson()

	/**
	 * The stored key matches what PreferencesController writes.
	 *
	 * @return void
	 */
	public function testTheStoredKeyMatchesTheEndpoint(): void {
		self::assertSame('pref_ask-before-send', PersonalPreferences::storedKey(key: 'Ask-Before-Send'));
		// Like the endpoint, anything outside a-z, 0-9 and the hyphen is dropped.
		self::assertSame('pref_askbeforesend', PersonalPreferences::storedKey(key: 'ask_before_send'));
	}//end testTheStoredKeyMatchesTheEndpoint()
}//end class
