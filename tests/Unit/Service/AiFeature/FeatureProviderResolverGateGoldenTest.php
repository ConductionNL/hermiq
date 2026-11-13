<?php

/**
 * Golden tests for FeatureProviderResolver's bindings, gates and disclosures.
 *
 * Pins the exact binding for each source, the exact refusal of each gate (model
 * policy with and without an organisation, residency, a missing redaction client, a
 * redaction that is not done with and without a reason), and the exact disclosure
 * of a run that passes, with and without a document and a data-use declaration.
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Service\AiFeature
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
 * @spec openspec/changes/a-provider-and-a-place-per-ai-feature/specs/ai-feature-governance/spec.md
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service\AiFeature;

use OCA\Hermiq\Service\AiFeature\DataUseGate;
use OCA\Hermiq\Service\AiFeature\FeatureProviderResolver;
use OCA\Hermiq\Service\AiFeature\ProviderResidencyRegistry;
use OCA\Hermiq\Service\AiFeature\RedactionOutcomeReader;
use OCA\Hermiq\Service\AiFeature\RedactionRequiredException;
use OCA\Hermiq\Service\AiFeature\ResidencyViolationException;
use OCA\Hermiq\Service\AiFeature\AiFeatureLookup;
use OCA\Hermiq\Service\Llm\ModelPolicyViolationException;
use OCA\Hermiq\Service\OrganisationModelPolicyService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Throwable;

/**
 * Golden tests for the feature provider gates.
 *
 * @spec openspec/changes/a-provider-and-a-place-per-ai-feature/specs/ai-feature-governance/spec.md
 */
class FeatureProviderResolverGateGoldenTest extends TestCase {

	/**
	 * A resolver over one stored feature, a policy, residency declarations and the
	 * optional redaction reader and data-use gate.
	 *
	 * @param array<string, mixed>|null $feature The `summary` feature's data, or null for none.
	 * @param bool $allowed Whether the policy allows every pair.
	 * @param array|null $defaultModel The policy default model.
	 * @param array|null $outcome What the redaction reader answers, or null for no reader.
	 * @param bool $withDataUse Whether a data-use gate is wired.
	 *
	 * @return FeatureProviderResolver
	 */
	private function resolver(?array $feature, bool $allowed = true, ?array $defaultModel = null, ?array $outcome = null, bool $withDataUse = false): FeatureProviderResolver {
		$features = $this->createMock(AiFeatureLookup::class);
		$features->method('findBySlug')->willReturnCallback(
			static function (string $slug) use ($feature): ?ObjectEntity {
				if ($slug !== 'summary' || $feature === null) {
					return null;
				}

				$entity = new ObjectEntity();
				$entity->setObject($feature);
				return $entity;
			}
		);

		$policy = $this->createMock(OrganisationModelPolicyService::class);
		$policy->method('isAllowed')->willReturn($allowed);
		$policy->method('effectivePolicyFor')->willReturn(['defaultModel' => $defaultModel]);

		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturn(
			(string)json_encode(['openai' => ['residency' => 'undeclared', 'location' => 'Virginia'], 'ollama' => ['residency' => 'eu', 'location' => 'Amsterdam']])
		);

		$reader = null;
		if ($outcome !== null) {
			$reader = $this->createMock(RedactionOutcomeReader::class);
			$reader->method('outcomeFor')->willReturn($outcome);
		}

		$dataUse = null;
		if ($withDataUse === true) {
			$dataUse = $this->createMock(DataUseGate::class);
			$dataUse->method('declaration')->willReturn(['dataUse' => 'no-training', 'termsReference' => 'https://terms']);
		}

		return new FeatureProviderResolver($features, $policy, new ProviderResidencyRegistry($config), $reader, $dataUse);
	}//end resolver()

	/**
	 * The message and class of what a call throws.
	 *
	 * @param callable $call The call.
	 *
	 * @return string
	 */
	private function refusal(callable $call): string {
		try {
			$call();
		} catch (Throwable $e) {
			return $e::class . ': ' . $e->getMessage() . ' (' . $e->getCode() . ')';
		}

		return 'no refusal';
	}//end refusal()

	/**
	 * The binding from each source, with the feature's requirements.
	 *
	 * @return void
	 */
	public function testTheBindingFromEachSource(): void {
		$this->assertSame(
			['provider' => 'ollama', 'model' => 'qwen', 'requiredResidency' => 'eu', 'requiresRedaction' => true, 'source' => 'feature'],
			$this->resolver(['provider' => ' ollama ', 'model' => 'qwen ', 'requiredResidency' => ' eu ', 'requiresRedaction' => true])
				->bindingFor(featureSlug: 'summary', organisation: 'org')
		);
		$this->assertSame(
			['provider' => 'openai', 'model' => 'gpt', 'requiredResidency' => null, 'requiresRedaction' => false, 'source' => 'policy'],
			$this->resolver(['provider' => 'ollama', 'requiredResidency' => '  ', 'requiresRedaction' => 'yes'], true, ['provider' => 'openai', 'model' => 'gpt'])
				->bindingFor(featureSlug: 'summary', organisation: 'org')
		);
		$this->assertSame(
			['provider' => null, 'model' => null, 'requiredResidency' => null, 'requiresRedaction' => false, 'source' => 'instance'],
			$this->resolver(null, true, ['provider' => 'openai', 'model' => ''])->bindingFor(featureSlug: 'summary', organisation: 'org')
		);
		$this->assertSame(
			['provider' => null, 'model' => null, 'requiredResidency' => null, 'requiresRedaction' => false, 'source' => 'instance'],
			$this->resolver(['provider' => 'x', 'model' => 'y'])->bindingFor(featureSlug: '', organisation: 'org')
		);
	}//end testTheBindingFromEachSource()

	/**
	 * Each gate's exact refusal.
	 *
	 * @return void
	 */
	public function testEachGateRefusesWithItsOwnMessage(): void {
		$this->assertSame(
			ModelPolicyViolationException::class . ": Refused by the model-policy check: organisation '(instance-wide)' does not permit provider 'openai' model 'gpt' for feature 'summary'. (422)",
			$this->refusal(fn () => $this->resolver(null, false)->enforceForRun('summary', '', 'openai', 'gpt'))
		);
		$this->assertStringContainsString(
			"organisation 'org-7' does not permit",
			$this->refusal(fn () => $this->resolver(null, false)->enforceForRun('summary', 'org-7', 'openai', 'gpt'))
		);

		$residency = $this->refusal(fn () => $this->resolver(['requiredResidency' => 'eu'])->enforceForRun('summary', 'org', 'openai', 'gpt'));
		$this->assertStringStartsWith(ResidencyViolationException::class . ': ', $residency);
		$this->assertStringContainsString('summary', $residency);

		$noClient = $this->refusal(fn () => $this->resolver(['requiresRedaction' => true])->enforceForRun('summary', 'org', 'ollama', 'q', ' doc-1 '));
		$this->assertStringStartsWith(RedactionRequiredException::class . ': ', $noClient);
		$this->assertStringContainsString('the redaction client (filinq) is not available to this instance', $noClient);
		$this->assertStringContainsString('doc-1', $noClient);

		$noReason = $this->refusal(
			fn () => $this->resolver(['requiresRedaction' => true], true, null, ['available' => true, 'redacted' => false, 'reason' => '', 'status' => 'pending', 'redactedAt' => ''])
				->enforceForRun('summary', 'org', 'ollama', 'q', 'doc-1')
		);
		$this->assertStringContainsString("the recorded redaction state is 'pending'", $noReason);

		$withReason = $this->refusal(
			fn () => $this->resolver(['requiresRedaction' => true], true, null, ['available' => false, 'redacted' => true, 'reason' => 'filinq down', 'status' => 'x', 'redactedAt' => ''])
				->enforceForRun('summary', 'org', 'ollama', 'q', 'doc-1')
		);
		$this->assertStringContainsString('filinq down', $withReason);
	}//end testEachGateRefusesWithItsOwnMessage()

	/**
	 * The disclosure of a run that passes every gate.
	 *
	 * @return void
	 */
	public function testTheDisclosureOfAPassingRun(): void {
		$this->assertSame(
			[
				'feature' => 'summary',
				'provider' => 'ollama',
				'model' => 'q',
				'residency' => 'eu',
				'location' => 'Amsterdam',
				'dataUse' => 'no-training',
				'termsReference' => 'https://terms',
				'redaction' => ['required' => true, 'satisfied' => true, 'status' => 'redacted', 'redactedAt' => '2026-01-01', 'reference' => 'doc-1'],
			],
			$this->resolver(
				['requiredResidency' => 'eu', 'requiresRedaction' => true],
				true,
				null,
				['available' => true, 'redacted' => true, 'reason' => '', 'status' => 'redacted', 'redactedAt' => '2026-01-01'],
				true
			)->enforceForRun('summary', 'org', 'ollama', 'q', ' doc-1 ')
		);

		$this->assertSame(
			[
				'feature' => 'summary',
				'provider' => 'openai',
				'model' => 'gpt',
				'residency' => 'undeclared',
				'location' => 'Virginia',
				'redaction' => ['required' => true, 'satisfied' => true, 'status' => 'not-applicable', 'redactedAt' => '', 'reference' => ''],
			],
			$this->resolver(['requiresRedaction' => true])->enforceForRun('summary', 'org', 'openai', 'gpt', '  ')
		);

		$this->assertSame(
			['feature' => 'f', 'provider' => 'openai', 'model' => 'gpt', 'residency' => 'undeclared', 'location' => 'Virginia', 'dataUse' => 'no-training', 'termsReference' => 'https://terms'],
			$this->resolver(null, true, null, null, true)->disclosureFor('f', 'openai', 'gpt')
		);
	}//end testTheDisclosureOfAPassingRun()
}//end class
