<?php

/**
 * Characterisation test for the PII action GuardrailPolicyService applies at the
 * input and the output boundary.
 *
 * Pins every action at both boundaries with the exact result, including the
 * withheld-response placeholder a blocked output gets, so the action can be applied
 * without a boundary flag.
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Service
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
 * @spec openspec/changes/agent-guardrails/specs/agent-guardrails/spec.md
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service;

use OCA\Hermiq\Service\GuardrailPolicyService;
use OCA\Hermiq\Service\RedactionService;
use OCA\OpenRegister\Service\ObjectService;
use PHPUnit\Framework\TestCase;

/**
 * Characterisation test for the PII action at each boundary.
 *
 * @spec openspec/changes/agent-guardrails/specs/agent-guardrails/spec.md
 */
class GuardrailPiiActionTest extends TestCase {

	/**
	 * The service, with a redaction double that redacts `SECRET`.
	 *
	 * @return GuardrailPolicyService
	 */
	private function service(): GuardrailPolicyService {
		$redaction = $this->createMock(RedactionService::class);
		$redaction->method('redact')->willReturnCallback(
			static fn (string $text): string => str_replace('SECRET', '[REDACTED]', $text)
		);

		return new GuardrailPolicyService(
			objectService: $this->createMock(ObjectService::class),
			redactionService: $redaction,
		);
	}//end service()

	/**
	 * The PII action at each boundary: off, empty text and clean text pass through;
	 * redact (and any action other than off or block) replaces the sensitive part;
	 * block withholds the text, as empty input and as the placeholder on output.
	 *
	 * @return void
	 */
	public function testThePiiActionAtEachBoundary(): void {
		$service = $this->service();
		$pass = static fn (string $text): array => ['text' => $text, 'blocked' => false, 'reason' => null];
		$input = static fn (string $pii): array => ['inputFilters' => ['piiAction' => $pii, 'promptInjectionAction' => 'off']];
		$output = static fn (string $pii): array => ['outputFilters' => ['piiAction' => $pii]];
		$placeholder = $service->filterOutput(policy: $output('block'), text: 'SECRET')['text'];

		$this->assertNotSame('', $placeholder);
		$this->assertStringNotContainsString('SECRET', $placeholder);
		$this->assertSame($pass('a SECRET b'), $service->filterInput(policy: $input('off'), text: 'a SECRET b'));
		$this->assertSame($pass(''), $service->filterInput(policy: $input('block'), text: ''));
		$this->assertSame($pass('clean'), $service->filterInput(policy: $input('block'), text: 'clean'));
		$this->assertSame($pass('a [REDACTED] b'), $service->filterInput(policy: $input('redact'), text: 'a SECRET b'));
		$this->assertSame(['text' => '', 'blocked' => true, 'reason' => 'sensitive_content'], $service->filterInput(policy: $input('block'), text: 'a SECRET b'));
		$this->assertSame($pass('a [REDACTED] b'), $service->filterInput(policy: $input('unknown'), text: 'a SECRET b'));

		$this->assertSame($pass('a SECRET b'), $service->filterOutput(policy: $output('off'), text: 'a SECRET b'));
		$this->assertSame($pass(''), $service->filterOutput(policy: $output('block'), text: ''));
		$this->assertSame($pass('a [REDACTED] b'), $service->filterOutput(policy: $output('redact'), text: 'a SECRET b'));
		$this->assertSame(['text' => $placeholder, 'blocked' => true, 'reason' => 'sensitive_content'], $service->filterOutput(policy: $output('block'), text: 'a SECRET b'));
		$this->assertSame($pass('clean'), $service->filterOutput(policy: [], text: 'clean'));

	}//end testThePiiActionAtEachBoundary()
}//end class
