<?php

/**
 * Guard: sign-in, the second factor and the licence come from the platform.
 *
 * The app holds no sign-in, single sign-on or second-factor code of its own:
 * every caller arrives through Nextcloud's session, so `user_saml`,
 * `user_oidc`, `user_ldap` and an enforced second factor apply to hermiq
 * without a hermiq setting. And the licence the app manifest declares is the
 * licence the repository ships.
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\AppInfo
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/app-runtime-requirements/spec.md
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\AppInfo;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Asserts the EUPL 1.2 in the manifest and the licence file, and no own sign-in code in lib/.
 *
 * @spec openspec/specs/app-runtime-requirements/spec.md
 *
 * @coversNothing
 */
class PlatformFoundationsTest extends TestCase {

	/**
	 * What a sign-in, single sign-on or second-factor implementation of hermiq's own would contain.
	 *
	 * @var array<string, string>
	 */
	private const OWN_SIGN_IN = [
		'a SAML provider'           => '/\bsaml\b|OneLogin\\\\Saml2/i',
		'an OpenID Connect client'  => '/\boidc\b|OpenIDConnect|openid-configuration/i',
		'a second-factor provider'  => '/OCP\\\\Authentication\\\\TwoFactorAuth|registerTwoFactorProvider|<two-factor-providers>/',
		'an own password check'     => '/password_verify\s*\(|IUserManager::checkPassword|->checkPassword\s*\(/',
	];

	/**
	 * The repository root.
	 *
	 * @return string
	 */
	private static function root(): string {
		return dirname(__DIR__, 3);
	}//end root()

	/**
	 * The manifest declares the EUPL 1.2 and the root LICENSE holds its text (REQ-PLAT-004).
	 *
	 * @return void
	 *
	 * @spec openspec/specs/app-runtime-requirements/spec.md#requirement-hermiq-shall-be-distributed-under-the-eupl-12-req-plat-004
	 */
	public function testTheDeclaredLicenceMatchesTheLicenceFile(): void {
		$manifest = simplexml_load_file(filename: self::root() . '/appinfo/info.xml');
		$this->assertNotFalse(condition: $manifest, message: 'appinfo/info.xml must parse');
		$this->assertSame(expected: 'EUPL-1.2', actual: trim(string: (string) $manifest->licence));

		$licence = (string) file_get_contents(filename: self::root() . '/LICENSE');
		$this->assertStringStartsWith(prefix: 'EUROPEAN UNION PUBLIC LICENCE v. 1.2', string: ltrim(string: $licence));
	}//end testTheDeclaredLicenceMatchesTheLicenceFile()

	/**
	 * lib/ holds no SAML, OpenID Connect, second-factor or password code (REQ-PLAT-001, REQ-PLAT-002).
	 *
	 * @return void
	 *
	 * @spec openspec/specs/app-runtime-requirements/spec.md#requirement-sign-in-and-single-sign-on-shall-be-nextclouds-req-plat-001
	 * @spec openspec/specs/app-runtime-requirements/spec.md#requirement-a-second-factor-at-sign-in-shall-be-nextclouds-req-plat-002
	 */
	public function testLibHoldsNoSignInOfItsOwn(): void {
		$found = [];
		$files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(self::root() . '/lib', RecursiveDirectoryIterator::SKIP_DOTS));
		foreach ($files as $file) {
			if ($file->getExtension() !== 'php') {
				continue;
			}

			$name = $file->getBasename('.php');
			$source = (string) file_get_contents($file->getPathname());
			if (preg_match('/saml|oidc|twofactor|two-?factor/i', $name) === 1) {
				$found[] = $file->getPathname() . ': its name';
			}

			foreach (self::OWN_SIGN_IN as $what => $pattern) {
				if (preg_match($pattern, $source) === 1) {
					$found[] = $file->getPathname() . ': ' . $what;
				}
			}
		}

		$this->assertSame(
			expected: [],
			actual: $found,
			message: 'Sign-in, single sign-on and the second factor are Nextcloud\'s; hermiq holds none of its own.'
		);

		$manifest = (string) file_get_contents(filename: self::root() . '/appinfo/info.xml');
		$this->assertStringNotContainsString(needle: '<two-factor-providers>', haystack: $manifest);
	}//end testLibHoldsNoSignInOfItsOwn()
}//end class
