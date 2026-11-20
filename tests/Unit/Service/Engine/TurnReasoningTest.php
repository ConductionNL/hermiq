<?php

/**
 * The model's reasoning is filtered like the answer and redacted before it is
 * stored; a blocked reasoning is withheld, and without redaction nothing is stored.
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Service\Engine
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/chat-how-an-answer-came-about/specs/answer-transparency/spec.md#requirement-the-models-reasoning-is-shown-only-when-the-owner-allows-it-and-the-provider-returns-it-req-atrn-003
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service\Engine;

use OCA\Hermiq\Service\Engine\TurnGuardrail;
use OCA\Hermiq\Service\Engine\TurnReasoning;
use OCA\Hermiq\Service\GuardrailPolicyService;
use OCA\Hermiq\Service\RedactionService;
use OCP\IConfig;
use PHPUnit\Framework\TestCase;

/**
 * Tests for TurnReasoning.
 */
class TurnReasoningTest extends TestCase {

	/**
	 * A secret the redaction masks.
	 *
	 * @var string
	 */
	private const SECRET = 'ghp_abcdefghijklmnopqrstuvwxyz0123456789';

	/**
	 * A guardrail whose output filter masks e-mail addresses and blocks "FORBIDDEN".
	 *
	 * @return TurnGuardrail
	 */
	private function guardrail(): TurnGuardrail {
		$policies = $this->createMock(GuardrailPolicyService::class);
		$policies->method('effectivePolicyFor')->willReturn(['outputFilters' => ['piiAction' => 'redact']]);
		$policies->method('filterOutput')->willReturnCallback(
			static function (array $policy, string $text): array {
				if (str_contains($text, 'FORBIDDEN') === true) {
					return ['text' => 'withheld', 'blocked' => true, 'reason' => 'pii'];
				}

				return ['text' => str_replace('jan@example.org', '[EMAIL]', $text), 'blocked' => false, 'reason' => null];
			}
		);
		$guardrail = new TurnGuardrail(policies: $policies, trace: null);
		$guardrail->resolve(organisation: 'org-1');
		return $guardrail;
	}//end guardrail()

	/**
	 * A RedactionService that is switched on.
	 *
	 * @return RedactionService
	 */
	private function redaction(): RedactionService {
		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')->willReturn('yes');
		return new RedactionService($config);
	}//end redaction()

	/**
	 * The reasoning is filtered, then redacted, before it is stored.
	 *
	 * @return void
	 */
	public function testTheReasoningIsFilteredAndRedacted(): void {
		$review = (new TurnReasoning(guardrail: $this->guardrail(), redaction: $this->redaction()))->review(
			raw: 'Mail jan@example.org with token ' . self::SECRET . ' first.'
		);

		$this->assertFalse($review['reasoningWithheld']);
		$this->assertIsString($review['reasoning']);
		$this->assertStringContainsString('[EMAIL]', $review['reasoning']);
		$this->assertStringNotContainsString('jan@example.org', $review['reasoning']);
		$this->assertStringNotContainsString(self::SECRET, $review['reasoning']);
		$this->assertStringStartsWith('Mail [EMAIL] with token ', $review['reasoning']);
	}//end testTheReasoningIsFilteredAndRedacted()

	/**
	 * A reasoning the filter blocks is not stored and is marked withheld.
	 *
	 * @return void
	 */
	public function testABlockedReasoningIsWithheld(): void {
		$review = (new TurnReasoning(guardrail: $this->guardrail(), redaction: $this->redaction()))->review(
			raw: 'This is FORBIDDEN text.'
		);

		$this->assertSame(['reasoning' => null, 'reasoningWithheld' => true], $review);
	}//end testABlockedReasoningIsWithheld()

	/**
	 * No reasoning, or no redaction to run, stores nothing and withholds nothing.
	 *
	 * @return void
	 */
	public function testNothingIsStoredWithoutReasoningOrRedaction(): void {
		$none = ['reasoning' => null, 'reasoningWithheld' => false];
		$this->assertSame($none, (new TurnReasoning(guardrail: $this->guardrail(), redaction: $this->redaction()))->review(raw: '  '));
		$this->assertSame($none, (new TurnReasoning(guardrail: $this->guardrail(), redaction: null))->review(raw: 'Some reasoning.'));
	}//end testNothingIsStoredWithoutReasoningOrRedaction()

	/**
	 * Without a policy service the reasoning passes the filter unchanged and is still redacted.
	 *
	 * @return void
	 */
	public function testWithoutAPolicyTheReasoningIsOnlyRedacted(): void {
		$guardrail = new TurnGuardrail(policies: null, trace: null);
		$guardrail->resolve(organisation: '');
		$review = (new TurnReasoning(guardrail: $guardrail, redaction: $this->redaction()))->review(raw: 'Use ' . self::SECRET);

		$this->assertFalse($review['reasoningWithheld']);
		$this->assertStringStartsWith('Use ', (string)$review['reasoning']);
		$this->assertStringNotContainsString(self::SECRET, (string)$review['reasoning']);
	}//end testWithoutAPolicyTheReasoningIsOnlyRedacted()
}//end class
