<?php

/**
 * Hermiq AiFeatureBindingService unit tests.
 *
 * Covers the write-time narrowing check: a binding the organisation's effective
 * policy does not permit is refused, naming the policy, and a permitted binding is
 * written through OpenRegister's single write path.
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
 * @spec openspec/changes/a-provider-and-a-place-per-ai-feature/specs/ai-feature-governance/spec.md#requirement-an-ai-feature-may-bind-its-own-provider-and-model
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service\AiFeature;

use InvalidArgumentException;
use OCA\Hermiq\Service\AiFeature\AiFeatureBindingService;
use OCA\Hermiq\Service\AiFeature\ProviderResidencyRegistry;
use OCA\Hermiq\Service\AiFeatureService;
use OCA\Hermiq\Service\TenantModelPolicyService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;

/**
 * AiFeatureBindingService unit tests.
 *
 * @spec openspec/changes/a-provider-and-a-place-per-ai-feature/specs/ai-feature-governance/spec.md#requirement-an-ai-feature-may-bind-its-own-provider-and-model
 */
class AiFeatureBindingServiceTest extends TestCase {

	/**
	 * The object data handed to the last `saveObject()` call, so a test can read
	 * what was actually written rather than what it hoped was written.
	 *
	 * @var array<string, mixed>|null
	 */
	private ?array $written = null;

	/**
	 * An AiFeatureService double returning one feature by UUID.
	 *
	 * @param array<string, mixed> $data The feature's object data.
	 * @param string $organisation The feature's organisation.
	 *
	 * @return AiFeatureService The double.
	 */
	private function features(array $data, string $organisation = 'gemeente'): AiFeatureService {
		$entity = new ObjectEntity();
		$entity->setUuid('feature-uuid');
		$entity->setOrganisation($organisation);
		$entity->setObject(array_merge(['slug' => 'samenvatten', 'name' => 'Samenvatten'], $data));

		$service = $this->createMock(AiFeatureService::class);
		$service->method('getFeature')->willReturnCallback(
			static function (string $id) use ($entity): ?ObjectEntity {
				if ($id === 'feature-uuid') {
					return $entity;
				}

				return null;
			}
		);
		$service->method('listFeatures')->willReturn([$entity]);

		return $service;
	}//end features()

	/**
	 * A TenantModelPolicyService double permitting exactly the given pairs.
	 *
	 * @param array<int, array{0: string, 1: string}> $allowedPairs The permitted pairs.
	 *
	 * @return TenantModelPolicyService The double.
	 */
	private function policy(array $allowedPairs): TenantModelPolicyService {
		$service = $this->createMock(TenantModelPolicyService::class);
		$service->method('isAllowed')->willReturnCallback(
			static function (string $organisation, string $provider, string $model) use ($allowedPairs): bool {
				return in_array([$provider, $model], $allowedPairs, true);
			}
		);
		$service->method('effectivePolicyFor')->willReturn(
			['source' => 'organisation', 'allowed' => [], 'defaultModel' => null]
		);

		return $service;
	}//end policy()

	/**
	 * An ObjectService double that records what it was asked to save and echoes it
	 * back as the stored object.
	 *
	 * @return ObjectService The double.
	 */
	private function objectService(): ObjectService {
		$service = $this->createMock(ObjectService::class);
		$service->method('saveObject')->willReturnCallback(
			function (array $object, ...$rest): ObjectEntity {
				$this->written = $object;

				$entity = new ObjectEntity();
				$entity->setUuid('feature-uuid');
				$entity->setObject($object);
				return $entity;
			}
		);

		return $service;
	}//end objectService()

	/**
	 * A residency registry over an empty config.
	 *
	 * @return ProviderResidencyRegistry The registry.
	 */
	private function residency(): ProviderResidencyRegistry {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturn('');

		return new ProviderResidencyRegistry($config);
	}//end residency()

	/**
	 * A binding within the policy is written, and what was written is the pair that
	 * was asked for.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/a-provider-and-a-place-per-ai-feature/specs/ai-feature-governance/spec.md#scenario-two-features-two-providers
	 */
	public function testAPermittedBindingIsWritten(): void {
		$service = new AiFeatureBindingService(
			$this->features([]),
			$this->policy([['ollama', 'llama3']]),
			$this->residency(),
			$this->objectService()
		);

		$stored = $service->bind(id: 'feature-uuid', provider: 'ollama', model: 'llama3');

		$this->assertNotNull($stored);
		$this->assertSame('ollama', $this->written['provider']);
		$this->assertSame('llama3', $this->written['model']);
	}//end testAPermittedBindingIsWritten()

	/**
	 * A binding outside the policy is refused at write time, naming the policy that
	 * forbids it, and nothing is written.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/a-provider-and-a-place-per-ai-feature/specs/ai-feature-governance/spec.md#scenario-a-binding-outside-the-policy-is-refused-at-write-time
	 */
	public function testABindingOutsideThePolicyIsRefusedAtWriteTime(): void {
		$service = new AiFeatureBindingService(
			$this->features([]),
			$this->policy([['ollama', 'llama3']]),
			$this->residency(),
			$this->objectService()
		);

		try {
			$service->bind(id: 'feature-uuid', provider: 'openai', model: 'gpt-4o');
			$this->fail('The out-of-policy binding was written.');
		} catch (InvalidArgumentException $refusal) {
			$this->assertStringContainsString('model-policy check', $refusal->getMessage());
			$this->assertStringContainsString('organisation model policy', $refusal->getMessage());
			$this->assertNull($this->written);
		}

	}//end testABindingOutsideThePolicyIsRefusedAtWriteTime()

	/**
	 * Half a binding is refused: a provider without a model would leave the model to
	 * the instance configuration, so the binding would not mean what it says.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/a-provider-and-a-place-per-ai-feature/specs/ai-feature-governance/spec.md#requirement-an-ai-feature-may-bind-its-own-provider-and-model
	 */
	public function testHalfABindingIsRefused(): void {
		$service = new AiFeatureBindingService(
			$this->features([]),
			$this->policy([['ollama', 'llama3']]),
			$this->residency(),
			$this->objectService()
		);

		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessageMatches('/both a provider and a model/');

		$service->bind(id: 'feature-uuid', provider: 'ollama', model: '');
	}//end testHalfABindingIsRefused()

	/**
	 * Clearing a binding needs no policy check at all: it returns the feature to the
	 * policy default, which is by definition within the policy.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/a-provider-and-a-place-per-ai-feature/specs/ai-feature-governance/spec.md#scenario-an-unbound-feature-keeps-todays-behaviour
	 */
	public function testClearingABindingIsAllowedUnderAnyPolicy(): void {
		$service = new AiFeatureBindingService(
			$this->features(['provider' => 'openai', 'model' => 'gpt-4o']),
			$this->policy([]),
			$this->residency(),
			$this->objectService()
		);

		$service->bind(id: 'feature-uuid', provider: '', model: '');

		$this->assertSame('', $this->written['provider']);
		$this->assertSame('', $this->written['model']);
	}//end testClearingABindingIsAllowedUnderAnyPolicy()

	/**
	 * A required residency outside the vocabulary is refused, so a typo cannot
	 * silently become a requirement nothing can satisfy.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/a-provider-and-a-place-per-ai-feature/specs/ai-feature-governance/spec.md#requirement-a-feature-may-require-a-residency-and-a-run-outside-it-is-refused-before-the-call
	 */
	public function testAnUnknownRequiredResidencyIsRefused(): void {
		$service = new AiFeatureBindingService(
			$this->features([]),
			$this->policy([['ollama', 'llama3']]),
			$this->residency(),
			$this->objectService()
		);

		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessageMatches('/Unsupported residency/');

		$service->bind(id: 'feature-uuid', provider: 'ollama', model: 'llama3', requiredResidency: 'in-de-buurt');
	}//end testAnUnknownRequiredResidencyIsRefused()

	/**
	 * Binding a feature that does not exist reports nothing rather than inventing
	 * one.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/a-provider-and-a-place-per-ai-feature/specs/ai-feature-governance/spec.md#requirement-an-ai-feature-may-bind-its-own-provider-and-model
	 */
	public function testBindingAnAbsentFeatureReturnsNull(): void {
		$service = new AiFeatureBindingService(
			$this->features([]),
			$this->policy([['ollama', 'llama3']]),
			$this->residency(),
			$this->objectService()
		);

		$this->assertNull($service->bind(id: 'no-such-feature', provider: 'ollama', model: 'llama3'));
		$this->assertNull($this->written);
	}//end testBindingAnAbsentFeatureReturnsNull()
}//end class
