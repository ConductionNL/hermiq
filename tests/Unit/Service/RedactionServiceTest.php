<?php

/**
 * Unit tests for RedactionService (run-audit-log).
 *
 * Exercises the full ported redact.py pattern set: the vendor key prefixes,
 * Authorization / x-api-key headers, DB DSN passwords, private-key blocks, JWTs,
 * Telegram tokens, ENV/dotted-config/YAML/JSON assignments, bare-token URLs,
 * form-urlencoded bodies and E.164 phone numbers — plus the mask_secret head/tail
 * behaviour and the frozen enable toggle.
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Service
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

namespace OCA\Hermiq\Tests\Unit\Service;

use OCA\Hermiq\Service\RedactionService;
use OCP\IConfig;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the run-audit-log RedactionService.
 *
 * @spec openspec/changes/run-audit-log/tasks.md#1-redaction
 */
class RedactionServiceTest extends TestCase {

	/**
	 * Build a RedactionService whose frozen toggle resolves to $enabled.
	 *
	 * @param string $setting The app-config value the toggle reads.
	 *
	 * @return RedactionService
	 */
	private function service(string $setting = 'yes'): RedactionService {
		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')->willReturn($setting);
		return new RedactionService($config);
	}//end service()

	/**
	 * Vendor key prefixes are masked (never emitted verbatim).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/run-audit-log/tasks.md#task-1-2
	 */
	public function testVendorPrefixesAreMasked(): void {
		$svc = $this->service();
		$secrets = [
			'sk-ABCDEF1234567890abcdef',
			'ghp_ABCDEF1234567890abcdef',
			'AKIAABCDEFGHIJKLMNOP',
			'xai-ABCDEFGHIJ1234567890KLMNOPQRSTUVWXYZ',
			'AIzaABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789',
			'SG.ABCDEF1234567890abcdef',
			'gsk_ABCDEF1234567890abcdef',
		];

		foreach ($secrets as $secret) {
			$out = $svc->redact('the token is ' . $secret . ' now');
			$this->assertStringNotContainsString($secret, $out, 'Prefix secret must be masked: ' . $secret);
		}

	}//end testVendorPrefixesAreMasked()

	/**
	 * A JWT (eyJ…) is masked.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/run-audit-log/tasks.md#task-1-2
	 */
	public function testJwtIsMasked(): void {
		$jwt = 'eyJhbGciOiJIUzI1NiJ9.eyJzdWIiOiIxMjM0NTY3ODkwIn0.abcDEFghiJKL';
		$out = $this->service()->redact('auth token ' . $jwt);
		$this->assertStringNotContainsString($jwt, $out);

	}//end testJwtIsMasked()

	/**
	 * Authorization and x-api-key headers have their credential masked.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/run-audit-log/tasks.md#task-1-2
	 */
	public function testAuthHeadersAreMasked(): void {
		$svc = $this->service();

		$bearer = $svc->redact('Authorization: Bearer supersecretbearervalue123');
		$this->assertStringNotContainsString('supersecretbearervalue123', $bearer);
		$this->assertStringContainsString('Authorization:', $bearer, 'Header name is preserved.');

		$apiKey = $svc->redact('x-api-key: myopaqueapikeyvalue987654');
		$this->assertStringNotContainsString('myopaqueapikeyvalue987654', $apiKey);

	}//end testAuthHeadersAreMasked()

	/**
	 * Database connection-string passwords are masked.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/run-audit-log/tasks.md#task-1-2
	 */
	public function testDbConnStringPasswordIsMasked(): void {
		$out = $this->service()->redact('postgres://user:hunter2secret@db.example.com:5432/app');
		$this->assertStringNotContainsString('hunter2secret', $out);
		$this->assertStringContainsString('postgres://user:***@', $out);

	}//end testDbConnStringPasswordIsMasked()

	/**
	 * PEM private-key blocks are replaced with a marker.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/run-audit-log/tasks.md#task-1-2
	 */
	public function testPrivateKeyBlockIsRedacted(): void {
		$pem = "-----BEGIN RSA PRIVATE KEY-----\nSECRETKEYMATERIAL\n-----END RSA PRIVATE KEY-----";
		$out = $this->service()->redact('key: ' . $pem);
		$this->assertStringNotContainsString('SECRETKEYMATERIAL', $out);
		$this->assertStringContainsString('[REDACTED PRIVATE KEY]', $out);

	}//end testPrivateKeyBlockIsRedacted()

	/**
	 * Telegram bot tokens are masked.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/run-audit-log/tasks.md#task-1-2
	 */
	public function testTelegramTokenIsMasked(): void {
		$token = 'bot123456789:AAExampleTelegramTokenValue1234567890abc';
		$out = $this->service()->redact('use ' . $token);
		$this->assertStringNotContainsString('AAExampleTelegramTokenValue1234567890abc', $out);

	}//end testTelegramTokenIsMasked()

	/**
	 * ENV / dotted-config / YAML / JSON assignments have their value masked.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/run-audit-log/tasks.md#task-1-2
	 */
	public function testAssignmentSecretsAreMasked(): void {
		$svc = $this->service();

		$env = $svc->redact('OPENAI_API_KEY=supersecretenvvalue123');
		$this->assertStringNotContainsString('supersecretenvvalue123', $env);

		$dotted = $svc->redact('spring.datasource.password=hunter2secretvalue');
		$this->assertStringNotContainsString('hunter2secretvalue', $dotted);

		$yaml = $svc->redact('password: hunter2secretvalue');
		$this->assertStringNotContainsString('hunter2secretvalue', $yaml);

		$json = $svc->redact('{"apiKey": "supersecretjsonvalue123"}');
		$this->assertStringNotContainsString('supersecretjsonvalue123', $json);

	}//end testAssignmentSecretsAreMasked()

	/**
	 * A bare-token credential URL (scheme://TOKEN@host) is masked.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/run-audit-log/tasks.md#task-1-2
	 */
	public function testUrlBareTokenIsMasked(): void {
		$out = $this->service()->redact('git remote add origin https://ghtokenvalue1234567890@github.com/x/y');
		$this->assertStringNotContainsString('ghtokenvalue1234567890', $out);

	}//end testUrlBareTokenIsMasked()

	/**
	 * E.164 phone numbers (PII) are masked.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/run-audit-log/tasks.md#task-1-2
	 */
	public function testPhoneNumberIsMasked(): void {
		$out = $this->service()->redact('call me at +31612345678 tomorrow');
		$this->assertStringNotContainsString('+31612345678', $out);

	}//end testPhoneNumberIsMasked()

	/**
	 * Benign prose and non-secret assignments pass through unchanged.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/run-audit-log/tasks.md#task-1-2
	 */
	public function testBenignTextIsUntouched(): void {
		$svc = $this->service();
		$this->assertSame('The run completed successfully.', $svc->redact('The run completed successfully.'));
		$this->assertSame('', $svc->redact(''));
		// A non-secret key name is not masked.
		$this->assertSame('items=42', $svc->redact('items=42'));

	}//end testBenignTextIsUntouched()

	/**
	 * mask_secret preserves head/tail for long values and fully masks short ones.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/run-audit-log/tasks.md#task-1-2
	 */
	public function testMaskSecretHeadTail(): void {
		$svc = $this->service();
		$this->assertSame('sk-p...7890', $svc->maskSecret('sk-proj-abcdef1234567890'));
		$this->assertSame('***', $svc->maskSecret('short'));
		$this->assertSame('', $svc->maskSecret(''));

	}//end testMaskSecretHeadTail()

	/**
	 * The enable toggle is frozen: when off, non-forced redaction is a no-op, but a
	 * forced (audit) redaction still masks.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/run-audit-log/tasks.md#task-1-2
	 */
	public function testFrozenToggleOffStillForcesForAudit(): void {
		$svc = $this->service('no');
		$secret = 'sk-ABCDEF1234567890abcdef';

		// Non-forced: disabled toggle → passthrough.
		$this->assertStringContainsString($secret, $svc->redactSensitiveText('token ' . $secret));

		// redact() is a forced safety boundary → always masks.
		$this->assertStringNotContainsString($secret, $svc->redact('token ' . $secret));

	}//end testFrozenToggleOffStillForcesForAudit()
}//end class
