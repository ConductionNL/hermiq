<?php

/**
 * Unit tests for TurnGuardrail::screensOutput().
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Service\Engine
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/agent-guardrails/spec.md#requirement-output-is-filtered-before-persistence-and-before-delivery
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service\Engine;

use OCA\Hermiq\Service\Engine\TurnGuardrail;
use OCA\Hermiq\Service\GuardrailPolicyService;
use PHPUnit\Framework\TestCase;

/**
 * Tests for whether a turn's output filter can change the answer.
 *
 * @covers \OCA\Hermiq\Service\Engine\TurnGuardrail
 */
class TurnGuardrailTest extends TestCase {

	/**
	 * Only an output PII action of `off` (or none) leaves the answer untouched.
	 *
	 * @return void
	 */
	public function testScreensOutputUnlessTheOutputActionIsOff(): void {
		$this->assertTrue(condition: $this->guardrail(policy: ['outputFilters' => ['piiAction' => 'redact']])->screensOutput());
		$this->assertTrue(condition: $this->guardrail(policy: ['outputFilters' => ['piiAction' => 'block']])->screensOutput());
		$this->assertFalse(condition: $this->guardrail(policy: ['outputFilters' => ['piiAction' => 'off']])->screensOutput());
		$this->assertFalse(condition: $this->guardrail(policy: [])->screensOutput());

	}//end testScreensOutputUnlessTheOutputActionIsOff()

	/**
	 * Without a policy service the turn runs fully open, so nothing is screened.
	 *
	 * @return void
	 */
	public function testScreensNothingWithoutAPolicyService(): void {
		$guardrail = new TurnGuardrail(policies: null, trace: null);
		$this->assertFalse(condition: $guardrail->screensOutput());

		$guardrail->resolve(organisation: 'org-1');
		$this->assertFalse(condition: $guardrail->screensOutput());

	}//end testScreensNothingWithoutAPolicyService()

	/**
	 * A guardrail resolved against a fixed policy for organisation `org-1`.
	 *
	 * @param array<string, mixed> $policy The effective policy.
	 *
	 * @return TurnGuardrail
	 */
	private function guardrail(array $policy): TurnGuardrail {
		$policies = $this->createMock(originalClassName: GuardrailPolicyService::class);
		$policies->method('effectivePolicyFor')->with('org-1')->willReturn($policy);

		$guardrail = new TurnGuardrail(policies: $policies, trace: null);
		$guardrail->resolve(organisation: 'org-1');

		return $guardrail;

	}//end guardrail()
}//end class
