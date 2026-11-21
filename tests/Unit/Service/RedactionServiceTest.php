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

	/**
	 * Every pattern family, mode and toggle branch, pinned to the exact output
	 * the pipeline produced before its pattern families moved into their own
	 * classes.
	 *
	 * @return array<string, array{0: string, 1: int, 2: string, 3: string}>
	 */
	public static function goldenCases(): array {
		return [
			'vendor prefix' => [
				'key sk-abcdefghijklmnop123 end',
				0,
				'yes',
				'key sk-abc...p123 end',
			],
			'vendor prefix in file read' => [
				'key ghp_abcdefghijklmnop123 end',
				RedactionService::MODE_FILE_READ,
				'yes',
				'key «redacted:ghp_…» end',
			],
			'vendor prefix unknown label in file read' => [
				'AKIAABCDEFGHIJKLMNOP',
				RedactionService::MODE_FILE_READ,
				'yes',
				'«redacted:AKIA…»',
			],
			'short vendor key' => [
				'sk-abcdefghij',
				0,
				'yes',
				'***',
			],
			'no known prefix' => [
				'plain words only',
				0,
				'yes',
				'plain words only',
			],
			'env assignment' => [
				'OPENAI_API_KEY=supersecretvalue123456',
				0,
				'yes',
				'OPENAI_API_KEY=***',
			],
			'quoted env assignment' => [
				'DB_PASSWORD=\'supersecretvalue123456\'',
				0,
				'yes',
				'DB_PASSWORD=\'***\'',
			],
			'env assignment in code file' => [
				'OPENAI_API_KEY=supersecretvalue123456',
				RedactionService::MODE_CODE_FILE,
				'yes',
				'OPENAI_API_KEY=supersecretvalue123456',
			],
			'dotted config key' => [
				'spring.datasource.password=hunter2hunter2hunter',
				0,
				'yes',
				'spring.datasource.password=hunter...nter',
			],
			'anchored config key' => [
				"export my_token=abcdefghijklmnopqrstu\nnext",
				0,
				'yes',
				"export my_token=abcdef...rstu\nnext",
			],
			'config key beside a url' => [
				'see https://x.io/a my_token=abcdefghijklmnopqrstu',
				0,
				'yes',
				'see https://x.io/a my_token=abcdefghijklmnopqrstu',
			],
			'json field' => [
				'{"api_key": "abcdefghijklmnopqrstuvwxyz"}',
				0,
				'yes',
				'{"api_key": "abcdef...wxyz"}',
			],
			'json field in code file' => [
				'{"api_key": "abcdefghijklmnopqrstuvwxyz"}',
				RedactionService::MODE_CODE_FILE,
				'yes',
				'{"api_key": "abcdefghijklmnopqrstuvwxyz"}',
			],
			'json field in file read' => [
				'{"refresh_token": "abcdefghijklmnopqrstuvwxyz"}',
				RedactionService::MODE_FILE_READ,
				'yes',
				'{"refresh_token": "abcdefghijklmnopqrstuvwxyz"}',
			],
			'yaml key' => [
				"db:\n  password: supersecretpassword123",
				0,
				'yes',
				"db:\n  password: supers...d123",
			],
			'yaml quoted value' => [
				'password: \'supersecretpassword123\'',
				0,
				'yes',
				'password: \'supersecretpassword123\'',
			],
			'yaml beside a url' => [
				"url: https://x.io\npassword: supersecretpassword123",
				0,
				'yes',
				"url: https://x.io\npassword: supersecretpassword123",
			],
			'bearer header' => [
				'Authorization: Bearer abcdefghijklmnopqrstuvwxyz',
				0,
				'yes',
				'Authorization: Bearer abcdef...wxyz',
			],
			'proxy header without scheme' => [
				'Proxy-Authorization: abcdefghijklmnopqrstuvwxyz1',
				0,
				'yes',
				'Proxy-Authorization: abcdef...xyz1',
			],
			'api key header' => [
				'X-Api-Key: abcdefghijklmnopqrstuvwxyz',
				0,
				'yes',
				'X-Api-Key: ***',
			],
			'telegram token' => [
				'bot123456789:ABCdefGHIjklMNOpqrSTUvwxYZ0123456789ab',
				0,
				'yes',
				'bot123456789:***',
			],
			'private key block' => [
				"a\n-----BEGIN RSA PRIVATE KEY-----\nMIIabc\n-----END RSA PRIVATE KEY-----\nb",
				0,
				'yes',
				"a\n[REDACTED PRIVATE KEY]\nb",
			],
			'db connection string' => [
				'postgres://user:s3cretpass@db.host/app',
				0,
				'yes',
				'postgres://user:***@db.host/app',
			],
			'db placeholder in code file' => [
				'mysql://user:{DB_PASS}@db.host/app',
				RedactionService::MODE_CODE_FILE,
				'yes',
				'mysql://user:{DB_PASS}@db.host/app',
			],
			'db placeholder outside code file' => [
				'mysql://user:{DB_PASS}@db.host/app',
				0,
				'yes',
				'mysql://user:***@db.host/app',
			],
			'url bare token' => [
				'https://abcdefghijklmnopqrstuv@github.com/x.git',
				0,
				'yes',
				'https://abcdef...stuv@github.com/x.git',
			],
			'jwt' => [
				'token eyJhbGciOiJIUzI1NiJ9.eyJzdWIiOiIxMjM0In0.abcdEFGH',
				0,
				'yes',
				'token eyJhbG...EFGH',
			],
			'long phone' => [
				'call +31612345678 now',
				0,
				'yes',
				'call +316****5678 now',
			],
			'short phone' => [
				'call +1234567 now',
				0,
				'yes',
				'call +1****67 now',
			],
			'form body' => [
				'access_token=abc&user=bob&Secret=x',
				0,
				'yes',
				'access_token=***&user=bob&Secret=***',
			],
			'padded form body' => [
				'  user=bob&code=123  ',
				0,
				'yes',
				'user=bob&code=***',
			],
			'multi-line form' => [
				"user=bob&token=1\nnext=2",
				0,
				'yes',
				"user=bob&token=1\nnext=2",
			],
			'not a form' => [
				'a & b = c',
				0,
				'yes',
				'a & b = c',
			],
			'empty' => [
				'',
				RedactionService::MODE_FORCE,
				'yes',
				'',
			],
			'toggle off, not forced' => [
				'key sk-abcdefghijklmnop123 end',
				0,
				'no',
				'key sk-abcdefghijklmnop123 end',
			],
			'toggle off, forced' => [
				'key sk-abcdefghijklmnop123 end',
				RedactionService::MODE_FORCE,
				'no',
				'key sk-abc...p123 end',
			],
			'toggle on spelled true' => [
				'key sk-abcdefghijklmnop123 end',
				0,
				'TRUE',
				'key sk-abc...p123 end',
			],
			'everything at once' => [
				"Authorization: Bearer eyJhbGciOiJIUzI1NiJ9.eyJzdWIiOiIxMjM0In0.abcdEFGH\nOPENAI_API_KEY=sk-abcdefghijklmnop123 call +31612345678",
				0,
				'yes',
				"Authorization: Bearer eyJhbG...EFGH\nOPENAI_API_KEY=*** call +316****5678",
			],
		];
	}//end goldenCases()

	/**
	 * Golden: the full pipeline output for each branch stays byte-identical.
	 *
	 * @param string $text The text to redact.
	 * @param int $mode The mode bitmask.
	 * @param string $setting The frozen toggle's app-config value.
	 * @param string $expected The pinned output.
	 *
	 * @return void
	 *
	 * @dataProvider goldenCases
	 *
	 * @spec openspec/changes/run-audit-log/tasks.md#task-1-1
	 */
	public function testPipelineOutputIsPinned(string $text, int $mode, string $setting, string $expected): void {
		$this->assertSame($expected, $this->service($setting)->redactSensitiveText($text, $mode));
	}//end testPipelineOutputIsPinned()
}//end class
