<?php

/**
 * Hermiq FeatureProviderResolver unit tests.
 *
 * Covers the join between the AiFeature register and the organisation's ModelPolicy:
 * which provider a feature's run uses, that a binding narrows and never widens, that
 * a policy narrowed after the binding was written refuses the next run with no edit
 * to the feature, that the residency check refuses before the call and that each
 * refusal names the check that spoke.
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

use OCA\Hermiq\Service\AiFeature\FeatureProviderResolver;
use OCA\Hermiq\Service\AiFeature\ProviderResidencyRegistry;
use OCA\Hermiq\Service\AiFeature\ResidencyViolationException;
use OCA\Hermiq\Service\AiFeatureService;
use OCA\Hermiq\Service\Llm\ModelPolicyViolationException;
use OCA\Hermiq\Service\TenantModelPolicyService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;

/**
 * FeatureProviderResolver unit tests.
 *
 * @spec openspec/changes/a-provider-and-a-place-per-ai-feature/specs/ai-feature-governance/spec.md
 */
class FeatureProviderResolverTest extends TestCase {

	/**
	 * An AiFeatureService double answering `findBySlug()` from a slug-keyed map of
	 * feature object data.
	 *
	 * @param array<string, array<string, mixed>> $features The stored features, by slug.
	 *
	 * @return AiFeatureService The double.
	 */
	private function features(array $features): AiFeatureService {
		$service = $this->createMock(AiFeatureService::class);
		$service->method('findBySlug')->willReturnCallback(
			static function (string $slug) use ($features): ?ObjectEntity {
				if (isset($features[$slug]) === false) {
					return null;
				}

				$entity = new ObjectEntity();
				$entity->setUuid('uuid-' . $slug);
				$entity->setObject(array_merge(['slug' => $slug], $features[$slug]));
				return $entity;
			}
		);

		return $service;
	}//end features()

	/**
	 * A TenantModelPolicyService double allowing exactly the given pairs and
	 * offering the given default.
	 *
	 * @param array<int, array{0: string, 1: string}> $allowedPairs The permitted (provider, model) pairs.
	 * @param array{provider: string, model: string}|null $defaultModel The policy default, when it has one.
	 *
	 * @return TenantModelPolicyService The double.
	 */
	private function policy(array $allowedPairs, ?array $defaultModel = null): TenantModelPolicyService {
		$service = $this->createMock(TenantModelPolicyService::class);
		$service->method('isAllowed')->willReturnCallback(
			static function (string $organisation, string $provider, string $model) use ($allowedPairs): bool {
				return in_array([$provider, $model], $allowedPairs, true);
			}
		);
		$service->method('effectivePolicyFor')->willReturn(
			[
				'source' => 'organisation',
				'allowed' => [],
				'defaultModel' => $defaultModel,
			]
		);

		return $service;
	}//end policy()

	/**
	 * A residency registry holding the given declarations.
	 *
	 * @param array<string, array{residency: string, location: string}> $declarations The declarations, by provider.
	 *
	 * @return ProviderResidencyRegistry The registry, backed by a read-only config double.
	 */
	private function residency(array $declarations): ProviderResidencyRegistry {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturn((string)json_encode($declarations));

		return new ProviderResidencyRegistry($config);
	}//end residency()

	/**
	 * Two features, two providers: each feature's runs use its own binding.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/a-provider-and-a-place-per-ai-feature/specs/ai-feature-governance/spec.md#scenario-two-features-two-providers
	 */
	public function testTwoFeaturesResolveToTheirOwnProviders(): void {
		$resolver = new FeatureProviderResolver(
			$this->features(
				[
					'samenvatten' => ['provider' => 'ollama', 'model' => 'llama3'],
					'vertalen' => ['provider' => 'openai', 'model' => 'gpt-4o'],
				]
			),
			$this->policy([['ollama', 'llama3'], ['openai', 'gpt-4o']]),
			$this->residency([])
		);

		$this->assertSame('ollama', $resolver->bindingFor(featureSlug: 'samenvatten', organisation: 'gemeente')['provider']);
		$this->assertSame('openai', $resolver->bindingFor(featureSlug: 'vertalen', organisation: 'gemeente')['provider']);
		$this->assertSame(
			FeatureProviderResolver::SOURCE_FEATURE,
			$resolver->bindingFor(featureSlug: 'vertalen', organisation: 'gemeente')['source']
		);

	}//end testTwoFeaturesResolveToTheirOwnProviders()

	/**
	 * An unbound feature falls back to the organisation's effective policy default,
	 * which is what every feature did before this change.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/a-provider-and-a-place-per-ai-feature/specs/ai-feature-governance/spec.md#scenario-an-unbound-feature-keeps-todays-behaviour
	 */
	public function testAnUnboundFeatureFallsBackToThePolicyDefault(): void {
		$resolver = new FeatureProviderResolver(
			$this->features(['samenvatten' => []]),
			$this->policy([['ollama', 'llama3']], ['provider' => 'ollama', 'model' => 'llama3']),
			$this->residency([])
		);

		$binding = $resolver->bindingFor(featureSlug: 'samenvatten', organisation: 'gemeente');

		$this->assertSame('ollama', $binding['provider']);
		$this->assertSame('llama3', $binding['model']);
		$this->assertSame(FeatureProviderResolver::SOURCE_POLICY, $binding['source']);
	}//end testAnUnboundFeatureFallsBackToThePolicyDefault()

	/**
	 * With no binding and no policy default, nothing is resolved and the instance
	 * configuration stays in charge.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/a-provider-and-a-place-per-ai-feature/specs/ai-feature-governance/spec.md#scenario-an-unbound-feature-keeps-todays-behaviour
	 */
	public function testNothingResolvedLeavesTheInstanceInCharge(): void {
		$resolver = new FeatureProviderResolver(
			$this->features(['samenvatten' => []]),
			$this->policy([['ollama', 'llama3']]),
			$this->residency([])
		);

		$binding = $resolver->bindingFor(featureSlug: 'samenvatten', organisation: 'gemeente');

		$this->assertNull($binding['provider']);
		$this->assertSame(FeatureProviderResolver::SOURCE_INSTANCE, $binding['source']);
	}//end testNothingResolvedLeavesTheInstanceInCharge()

	/**
	 * A policy narrowed after the binding was written refuses the next run, with no
	 * edit to the feature. This is the refusal that matters: the write-time check
	 * cannot see a policy that changes later.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/a-provider-and-a-place-per-ai-feature/specs/ai-feature-governance/spec.md#scenario-narrowing-the-policy-disables-a-stale-binding
	 */
	public function testNarrowingThePolicyRefusesAStaleBinding(): void {
		$resolver = new FeatureProviderResolver(
			$this->features(['vertalen' => ['provider' => 'openai', 'model' => 'gpt-4o']]),
			$this->policy([['ollama', 'llama3']]),
			$this->residency([])
		);

		// The binding still resolves: nothing edited the feature.
		$this->assertSame('openai', $resolver->bindingFor(featureSlug: 'vertalen', organisation: 'gemeente')['provider']);

		$this->expectException(ModelPolicyViolationException::class);
		$this->expectExceptionMessageMatches('/model-policy check/');

		$resolver->enforceForRun(
			featureSlug: 'vertalen',
			organisation: 'gemeente',
			provider: 'openai',
			model: 'gpt-4o'
		);

	}//end testNarrowingThePolicyRefusesAStaleBinding()

	/**
	 * A feature requiring `on-premise` against a provider carrying `outside-eu` is
	 * refused, and the refusal names both residencies so a handler reads a reason
	 * rather than an error.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/a-provider-and-a-place-per-ai-feature/specs/ai-feature-governance/spec.md#scenario-case-text-never-leaves-for-a-forbidden-region
	 */
	public function testCaseTextNeverLeavesForAForbiddenRegion(): void {
		$resolver = new FeatureProviderResolver(
			$this->features(
				[
					'samenvatten' => [
						'provider' => 'openai',
						'model' => 'gpt-4o',
						'requiredResidency' => ProviderResidencyRegistry::RESIDENCY_ON_PREMISE,
					],
				]
			),
			$this->policy([['openai', 'gpt-4o']]),
			$this->residency(['openai' => ['residency' => 'outside-eu', 'location' => 'us-east-1']])
		);

		try {
			$resolver->enforceForRun(
				featureSlug: 'samenvatten',
				organisation: 'gemeente',
				provider: 'openai',
				model: 'gpt-4o'
			);
			$this->fail('The run was not refused on residency.');
		} catch (ResidencyViolationException $refusal) {
			$this->assertSame('residency', $refusal->step());
			$this->assertSame(ProviderResidencyRegistry::RESIDENCY_ON_PREMISE, $refusal->requiredResidency);
			$this->assertSame('outside-eu', $refusal->actualResidency);
			$this->assertStringContainsString('on-premise', $refusal->getMessage());
			$this->assertStringContainsString('outside-eu', $refusal->getMessage());
		}

	}//end testCaseTextNeverLeavesForAForbiddenRegion()

	/**
	 * The two refusals name different checks, so a reader never has to guess which
	 * gate spoke.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/a-provider-and-a-place-per-ai-feature/specs/ai-feature-governance/spec.md#scenario-the-refusing-step-names-itself
	 */
	public function testEachRefusalNamesTheCheckThatRefused(): void {
		$onPolicy = new FeatureProviderResolver(
			$this->features(['vertalen' => ['provider' => 'openai', 'model' => 'gpt-4o']]),
			$this->policy([]),
			$this->residency([])
		);

		$onResidency = new FeatureProviderResolver(
			$this->features(
				[
					'vertalen' => [
						'provider' => 'openai',
						'model' => 'gpt-4o',
						'requiredResidency' => ProviderResidencyRegistry::RESIDENCY_EU,
					],
				]
			),
			$this->policy([['openai', 'gpt-4o']]),
			$this->residency(['openai' => ['residency' => 'outside-eu', 'location' => '']])
		);

		$policyStep = null;
		try {
			$onPolicy->enforceForRun(featureSlug: 'vertalen', organisation: 'gemeente', provider: 'openai', model: 'gpt-4o');
		} catch (ModelPolicyViolationException $refusal) {
			$policyStep = $refusal->step();
		}

		$residencyStep = null;
		try {
			$onResidency->enforceForRun(featureSlug: 'vertalen', organisation: 'gemeente', provider: 'openai', model: 'gpt-4o');
		} catch (ResidencyViolationException $refusal) {
			$residencyStep = $refusal->step();
		}

		$this->assertSame('model-policy', $policyStep);
		$this->assertSame('residency', $residencyStep);
		$this->assertNotSame($policyStep, $residencyStep);
	}//end testEachRefusalNamesTheCheckThatRefused()

	/**
	 * A feature with no required residency refuses nothing, whatever the provider's
	 * label happens to be.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/a-provider-and-a-place-per-ai-feature/specs/ai-feature-governance/spec.md#scenario-no-required-residency-refuses-nothing
	 */
	public function testNoRequiredResidencyRefusesNothing(): void {
		$resolver = new FeatureProviderResolver(
			$this->features(['vertalen' => ['provider' => 'openai', 'model' => 'gpt-4o']]),
			$this->policy([['openai', 'gpt-4o']]),
			$this->residency(['openai' => ['residency' => 'outside-eu', 'location' => 'us-east-1']])
		);

		$disclosure = $resolver->enforceForRun(
			featureSlug: 'vertalen',
			organisation: 'gemeente',
			provider: 'openai',
			model: 'gpt-4o'
		);

		$this->assertSame('vertalen', $disclosure['feature']);
		$this->assertSame('outside-eu', $disclosure['residency']);
	}//end testNoRequiredResidencyRefusesNothing()

	/**
	 * A completed run's disclosure carries the feature, the provider, the model, the
	 * residency and the location, so "which model saw this case, and where" is a
	 * read rather than an investigation.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/a-provider-and-a-place-per-ai-feature/specs/ai-feature-governance/spec.md#scenario-which-model-saw-this-case-and-where-is-a-read
	 */
	public function testTheDisclosureCarriesTheWholeAnswer(): void {
		$resolver = new FeatureProviderResolver(
			$this->features(['samenvatten' => ['provider' => 'ollama', 'model' => 'llama3']]),
			$this->policy([['ollama', 'llama3']]),
			$this->residency(['ollama' => ['residency' => 'on-premise', 'location' => 'Serverruimte Stadskantoor']])
		);

		$disclosure = $resolver->disclosureFor(featureSlug: 'samenvatten', provider: 'ollama', model: 'llama3');

		$this->assertSame(
			[
				'feature' => 'samenvatten',
				'provider' => 'ollama',
				'model' => 'llama3',
				'residency' => 'on-premise',
				'location' => 'Serverruimte Stadskantoor',
			],
			$disclosure
		);

	}//end testTheDisclosureCarriesTheWholeAnswer()

	/**
	 * A feature the register does not know resolves to nothing rather than throwing,
	 * because an unregistered slug must not be able to stop every run of an app that
	 * misspells one.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/a-provider-and-a-place-per-ai-feature/specs/ai-feature-governance/spec.md#requirement-an-ai-feature-may-bind-its-own-provider-and-model
	 */
	public function testAnUnknownFeatureResolvesToNothing(): void {
		$resolver = new FeatureProviderResolver(
			$this->features([]),
			$this->policy([['ollama', 'llama3']]),
			$this->residency([])
		);

		$binding = $resolver->bindingFor(featureSlug: 'does-not-exist', organisation: 'gemeente');

		$this->assertNull($binding['provider']);
		$this->assertNull($binding['requiredResidency']);
	}//end testAnUnknownFeatureResolvesToNothing()
}//end class
