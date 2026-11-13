<?php

/**
 * Unit tests for CredentialRedactor: headers, bot tokens, keys, DSNs, URL tokens and JWTs.
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Service\Redaction
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/run-audit-log/tasks.md#1-redaction
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service\Redaction;

use OCA\Hermiq\Service\Redaction\CredentialRedactor;
use OCA\Hermiq\Service\Redaction\TokenMasker;
use PHPUnit\Framework\TestCase;

/**
 * Each credential shape is masked in place, and a code file keeps its DSN placeholders.
 *
 * @covers \OCA\Hermiq\Service\Redaction\CredentialRedactor
 * @uses   \OCA\Hermiq\Service\Redaction\TokenMasker
 *
 * @spec openspec/changes/run-audit-log/tasks.md#1-redaction
 */
class CredentialRedactorTest extends TestCase {

	/**
	 * The redactor under test, on the real masker.
	 *
	 * @return CredentialRedactor
	 */
	private function redactor(): CredentialRedactor {
		return new CredentialRedactor(masker: new TokenMasker());
	}//end redactor()

	/**
	 * Authorization headers keep their scheme and mask the credential.
	 *
	 * @return void
	 */
	public function testAuthorizationHeadersAreMasked(): void {
		$redactor = $this->redactor();

		$this->assertSame(
			'Authorization: Bearer abcdef...wxyz',
			$redactor->redact(text: 'Authorization: Bearer abcdefghijklmnopqrstuvwxyz', codeFile: false)
		);
		$this->assertSame(
			'Proxy-Authorization: Basic ***',
			$redactor->redact(text: 'Proxy-Authorization: Basic dXNlcjpwYXNz', codeFile: false)
		);
	}//end testAuthorizationHeadersAreMasked()

	/**
	 * An API key header is masked.
	 *
	 * @return void
	 */
	public function testAnApiKeyHeaderIsMasked(): void {
		$this->assertSame(
			'x-api-key: abcdef...wxyz',
			$this->redactor()->redact(text: 'x-api-key: abcdefghijklmnopqrstuvwxyz', codeFile: false)
		);
	}//end testAnApiKeyHeaderIsMasked()

	/**
	 * A bot token keeps its numeric id and loses its secret.
	 *
	 * @return void
	 */
	public function testABotTokenLosesItsSecret(): void {
		$this->assertSame(
			'bot123456789:***',
			$this->redactor()->redact(text: 'bot123456789:' . str_repeat('A', 35), codeFile: false)
		);
	}//end testABotTokenLosesItsSecret()

	/**
	 * A private key block is replaced whole, keeping the text around it.
	 *
	 * @return void
	 */
	public function testAPrivateKeyBlockIsReplacedWhole(): void {
		$block = "-----BEGIN RSA PRIVATE KEY-----\nMIIabc\n-----END RSA PRIVATE KEY-----";

		$this->assertSame(
			"a\n[REDACTED PRIVATE KEY]\nb",
			$this->redactor()->redact(text: "a\n" . $block . "\nb", codeFile: false)
		);
	}//end testAPrivateKeyBlockIsReplacedWhole()

	/**
	 * A database DSN loses its password; a code file keeps a {PLACEHOLDER}
	 * password, which is not a secret, and loses a literal one.
	 *
	 * @return void
	 */
	public function testADsnPasswordIsMaskedUnlessItIsAPlaceholderInCode(): void {
		$redactor = $this->redactor();
		$placeholder = 'mysql://app:{DB_PASSWORD}@db/app';

		$this->assertSame('postgres://user:***@db:5432/app', $redactor->redact(text: 'postgres://user:pa55word@db:5432/app', codeFile: true));
		$this->assertSame('mysql://app:***@db/app', $redactor->redact(text: $placeholder, codeFile: false));
		$this->assertSame($placeholder, $redactor->redact(text: $placeholder, codeFile: true));
	}//end testADsnPasswordIsMaskedUnlessItIsAPlaceholderInCode()

	/**
	 * A bare token in a URL's user part is masked head and tail.
	 *
	 * @return void
	 */
	public function testABareUrlTokenIsMasked(): void {
		$this->assertSame(
			'https://abcdef...stuv@example.org/org/repo',
			$this->redactor()->redact(text: 'https://abcdefghijklmnopqrstuv@example.org/org/repo', codeFile: false)
		);
	}//end testABareUrlTokenIsMasked()

	/**
	 * A JWT is masked as one token, all three segments.
	 *
	 * @return void
	 */
	public function testAJwtIsMaskedWhole(): void {
		$this->assertSame(
			'token eyJhbG...1234 end',
			$this->redactor()->redact(text: 'token eyJhbGciOiJIUzI1NiJ9.eyJzdWIiOiIxIn0.abcd1234 end', codeFile: false)
		);
	}//end testAJwtIsMaskedWhole()

	/**
	 * Text with no credential shape is returned unchanged.
	 *
	 * @return void
	 */
	public function testBenignTextIsUnchanged(): void {
		$text = "Deploy at 10:30 to https://example.org/docs, see -----notes-----";

		$this->assertSame($text, $this->redactor()->redact(text: $text, codeFile: false));
	}//end testBenignTextIsUnchanged()
}//end class
