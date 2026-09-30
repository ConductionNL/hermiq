<?php

/**
 * The Ed25519 key that signs approval verdicts (approval-verification-contract).
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Service\Approval
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/approval-verification-contract/specs/human-approval-gate/spec.md#requirement-hermiq-answers-a-signed-verdict-on-a-toolcall-approval-req-apver-001
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service\Approval;

use OCA\Hermiq\Service\Approval\ApprovalVerdictSigner;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;

/**
 * Tests the verdict signer against an in-memory app config.
 *
 * @spec openspec/changes/approval-verification-contract/specs/human-approval-gate/spec.md#requirement-hermiq-answers-a-signed-verdict-on-a-toolcall-approval-req-apver-001
 */
class ApprovalVerdictSignerTest extends TestCase {

	/**
	 * Stored app values, keyed by key name.
	 *
	 * @var array<string, string>
	 */
	private array $values = [];

	/**
	 * Keys stored as sensitive.
	 *
	 * @var array<string, bool>
	 */
	private array $sensitive = [];

	/**
	 * An IAppConfig double that keeps what is written.
	 *
	 * @return IAppConfig
	 */
	private function appConfig(): IAppConfig {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(
			fn (string $app, string $key, string $default = ''): string => ($app === 'hermiq' ? ($this->values[$key] ?? $default) : $default)
		);
		$config->method('setValueString')->willReturnCallback(
			function (string $app, string $key, string $value, bool $lazy = false, bool $sensitive = false): bool {
				$this->assertSame('hermiq', $app);
				$this->values[$key]    = $value;
				$this->sensitive[$key] = $sensitive;
				return true;
			}
		);

		return $config;

	}//end appConfig()

	/**
	 * The verdict integriq's verifier computes its canonical JSON over.
	 *
	 * @return array<string, mixed>
	 */
	private function verdict(): array {
		return [
			'toolId' => 'integriq.replayDeadLetters',
			'approvalId' => 'a1',
			'binding' => str_repeat('b', 64),
			'actingAgent' => 'g1',
			'nonce' => str_repeat('0', 32),
			'approved' => true,
			'reason' => 'approved',
			'decidedBy' => 'anna',
			'decidedAt' => '2026-09-30T10:00:00+00:00',
			'expiresAt' => '2026-09-30T11:00:00+00:00',
			'issuedAt' => '2026-09-30T10:05:00+00:00',
		];

	}//end verdict()

	/**
	 * The first signature makes a key pair: the secret is sensitive, the public
	 * key is published, and the signature verifies over the canonical JSON the
	 * way integriq's ApprovalVerdictVerifier checks it.
	 *
	 * @return void
	 */
	public function testSignsWithAPublishedKeyMadeOnFirstUse(): void {
		$signer    = new ApprovalVerdictSigner(appConfig: $this->appConfig());
		$signature = base64_decode($signer->sign(verdict: $this->verdict()), true);

		$publicKey = base64_decode($this->values[ApprovalVerdictSigner::PUBLIC_KEY] ?? '', true);
		$this->assertIsString($publicKey);
		$this->assertSame(SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES, strlen($publicKey));
		$this->assertTrue($this->sensitive[ApprovalVerdictSigner::SECRET_KEY] ?? false);
		$this->assertFalse($this->sensitive[ApprovalVerdictSigner::PUBLIC_KEY] ?? true);
		$this->assertSame('approval_verdict_public_key', ApprovalVerdictSigner::PUBLIC_KEY);

		$canonical = $this->verdict();
		ksort($canonical);
		$message = (string)json_encode($canonical, (JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
		$this->assertSame($message, ApprovalVerdictSigner::canonical(verdict: $this->verdict()));
		$this->assertIsString($signature);
		$this->assertTrue(sodium_crypto_sign_verify_detached($signature, $message, $publicKey));

		// Negative control: a changed field does not verify.
		$tampered             = $canonical;
		$tampered['approved'] = false;
		$this->assertFalse(
			sodium_crypto_sign_verify_detached($signature, (string)json_encode($tampered, (JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)), $publicKey)
		);

	}//end testSignsWithAPublishedKeyMadeOnFirstUse()

	/**
	 * A second signer on the same config reuses the pair.
	 *
	 * @return void
	 */
	public function testReusesTheStoredPair(): void {
		(new ApprovalVerdictSigner(appConfig: $this->appConfig()))->sign(verdict: $this->verdict());
		$published = $this->values[ApprovalVerdictSigner::PUBLIC_KEY];

		$signature = base64_decode((new ApprovalVerdictSigner(appConfig: $this->appConfig()))->sign(verdict: $this->verdict()), true);

		$this->assertSame($published, $this->values[ApprovalVerdictSigner::PUBLIC_KEY]);
		$this->assertTrue(
			sodium_crypto_sign_verify_detached((string)$signature, ApprovalVerdictSigner::canonical(verdict: $this->verdict()), (string)base64_decode($published, true))
		);

	}//end testReusesTheStoredPair()

	/**
	 * A secret that does not decode to a key makes a new pair instead of failing.
	 *
	 * @return void
	 */
	public function testAnUnreadableSecretRotatesThePair(): void {
		$this->values[ApprovalVerdictSigner::SECRET_KEY] = 'not-a-key';
		$this->values[ApprovalVerdictSigner::PUBLIC_KEY] = base64_encode(str_repeat('x', 32));

		$signature = base64_decode((new ApprovalVerdictSigner(appConfig: $this->appConfig()))->sign(verdict: $this->verdict()), true);

		$this->assertNotSame(base64_encode(str_repeat('x', 32)), $this->values[ApprovalVerdictSigner::PUBLIC_KEY]);
		$this->assertTrue(
			sodium_crypto_sign_verify_detached((string)$signature, ApprovalVerdictSigner::canonical(verdict: $this->verdict()), (string)base64_decode($this->values[ApprovalVerdictSigner::PUBLIC_KEY], true))
		);

	}//end testAnUnreadableSecretRotatesThePair()
}//end class
