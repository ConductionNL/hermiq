<?php

/**
 * Hermiq TenantModelPolicyService unit tests.
 *
 * Covers the effective-policy resolution order (organisation → instance default →
 * fail-closed fallback) and the isAllowed() semantics an out-of-policy run is refused
 * on (tenant-model-policy).
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
 * @spec openspec/changes/tenant-model-policy/specs/tenant-model-policy/spec.md
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service;

use OCA\Hermiq\Service\Llm\LlmSettingsHandler;
use OCA\Hermiq\Service\TenantModelPolicyService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use PHPUnit\Framework\TestCase;

/**
 * TenantModelPolicyService unit tests (tenant-model-policy).
 *
 * @spec openspec/changes/tenant-model-policy/specs/tenant-model-policy/spec.md
 */
class TenantModelPolicyServiceTest extends TestCase {

	/**
	 * A ModelPolicy ObjectEntity pinned to an organisation ('' = instance default).
	 *
	 * @param string $uuid The object uuid.
	 * @param string $organisation The organisation ('' = instance default).
	 * @param array<int, mixed> $allowed The allowed provider/model entries.
	 * @param string|null $defaultModel The optional default model.
	 *
	 * @return ObjectEntity
	 */
	private function policy(string $uuid, string $organisation, array $allowed, ?string $defaultModel = null): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid($uuid);
		$entity->setOrganisation($organisation);
		$entity->setObject(
			[
				'allowed' => $allowed,
				'defaultModel' => $defaultModel,
			]
		);
		return $entity;
	}//end policy()

	/**
	 * An ObjectService stub returning the given ModelPolicy objects.
	 *
	 * @param array<int, ObjectEntity> $policies The stored policies.
	 *
	 * @return ObjectService
	 */
	private function objectService(array $policies): ObjectService {
		return new class($policies) extends ObjectService {
			/**
			 * @param array<int, ObjectEntity> $policies The stored policies.
			 */
			public function __construct(
				private array $policies,
			) {
			}

			public function setRegister(mixed $register): static {
				return $this;
			}

			public function setSchema(mixed $schema): static {
				return $this;
			}

			public function findAll(array $config = [], bool $_rbac = true, bool $_multitenancy = true): array {
				return $this->policies;
			}
		};

	}//end objectService()

	/**
	 * The service under test.
	 *
	 * @param array<int, ObjectEntity> $policies The stored policies.
	 * @param string|null $chatProvider The instance-wide hermiq.llm chatProvider.
	 *
	 * @return TenantModelPolicyService
	 */
	private function service(array $policies, ?string $chatProvider = null): TenantModelPolicyService {
		$settings = $this->createMock(LlmSettingsHandler::class);
		$settings->method('getLLMSettingsOnly')->willReturn(
			$chatProvider === null ? [] : ['chatProvider' => $chatProvider]
		);

		return new TenantModelPolicyService(
			objectService: $this->objectService($policies),
			settingsHandler: $settings,
		);

	}//end service()

	/**
	 * An organisation's own policy wins over the instance default.
	 *
	 * @return void
	 */
	public function testEffectivePolicyPrefersTheOrganisationsOwnPolicy(): void {
		$service = $this->service(
			[
				$this->policy('p-instance', '', [['provider' => 'openai', 'models' => []]]),
				$this->policy('p-org', 'org-a', [['provider' => 'ollama', 'models' => ['qwen2.5']]], 'qwen2.5'),
			]
		);

		$effective = $service->effectivePolicyFor(organisation: 'org-a');

		$this->assertSame('organisation', $effective['source']);
		$this->assertSame('ollama', $effective['allowed'][0]['provider']);
		$this->assertSame('qwen2.5', $effective['defaultModel']);

	}//end testEffectivePolicyPrefersTheOrganisationsOwnPolicy()

	/**
	 * Without an own policy the instance default applies.
	 *
	 * @return void
	 */
	public function testEffectivePolicyFallsBackToTheInstanceDefault(): void {
		$service = $this->service(
			[$this->policy('p-instance', '', [['provider' => 'openai', 'models' => []]])]
		);

		$effective = $service->effectivePolicyFor(organisation: 'org-without-policy');

		$this->assertSame('instance', $effective['source']);
		$this->assertSame('openai', $effective['allowed'][0]['provider']);

	}//end testEffectivePolicyFallsBackToTheInstanceDefault()

	/**
	 * With no policy anywhere the fallback is the single configured chatProvider —
	 * never "everything allowed".
	 *
	 * @return void
	 */
	public function testFallbackPolicyIsFailClosedToTheConfiguredProvider(): void {
		$service = $this->service([], chatProvider: 'ollama');

		$effective = $service->effectivePolicyFor(organisation: 'org-a');

		$this->assertSame('fallback', $effective['source']);
		$this->assertCount(1, $effective['allowed']);
		$this->assertSame('ollama', $effective['allowed'][0]['provider']);

	}//end testFallbackPolicyIsFailClosedToTheConfiguredProvider()

	/**
	 * isAllowed(): unlisted provider refused; empty models list means any model;
	 * non-empty models list is an allowlist.
	 *
	 * @return void
	 */
	public function testIsAllowedSemantics(): void {
		$service = $this->service(
			[
				$this->policy(
					'p-org',
					'org-a',
					[
						['provider' => 'ollama', 'models' => []],
						['provider' => 'openai', 'models' => ['gpt-4o-mini']],
					]
				),
			]
		);

		$this->assertTrue($service->isAllowed(organisation: 'org-a', provider: 'ollama', model: 'anything'));
		$this->assertTrue($service->isAllowed(organisation: 'org-a', provider: 'openai', model: 'gpt-4o-mini'));
		$this->assertFalse($service->isAllowed(organisation: 'org-a', provider: 'openai', model: 'gpt-4o'));
		$this->assertFalse($service->isAllowed(organisation: 'org-a', provider: 'fireworks', model: 'llama-v3'));

	}//end testIsAllowedSemantics()
}//end class
