<?php

/**
 * Hermiq effective model policy: which policy governs an organisation, and what it allows.
 *
 * Golden for the allowed AND refused outcomes of the model-policy check: the
 * organisation's own policy wins, then the instance default, then the fail-closed
 * fallback to the configured chat provider (or nothing at all); a pair is allowed only
 * when its provider is listed and its model is listed or the list is open; the
 * no-training flag is read from the same effective policy. Also pins the stored-policy
 * shape the writes return.
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
 * @spec openspec/changes/organisation-model-policy/tasks.md#task-1-2
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service;

use InvalidArgumentException;
use OCA\Hermiq\Service\Llm\LlmSettingsHandler;
use OCA\Hermiq\Service\OrganisationModelPolicyService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the effective model policy and its check.
 */
class EffectiveModelPolicyTest extends TestCase {

	/**
	 * A stored policy.
	 *
	 * @param string $uuid         The UUID.
	 * @param string $organisation The organisation, '' for the instance default.
	 * @param array  $data         The policy data.
	 *
	 * @return ObjectEntity
	 */
	private function policy(string $uuid, string $organisation, array $data): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid($uuid);
		$entity->setOrganisation($organisation);
		$entity->setObject($data);
		return $entity;
	}//end policy()

	/**
	 * A register double serving the policies and echoing saves.
	 *
	 * @param array<int, mixed> $policies The rows.
	 *
	 * @return ObjectService
	 */
	private function objectService(array $policies): ObjectService {
		return new class($policies) extends ObjectService {
			/** @var array<int, array<string, mixed>> */
			public array $saves = [];

			/**
			 * Keep the rows.
			 *
			 * @param array<int, mixed> $policies The rows.
			 */
			public function __construct(private array $policies) {
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

			public function find(
				int|string $id,
				?array $_extend = [],
				bool $files = false,
				mixed $register = null,
				mixed $schema = null,
				bool $_rbac = true,
				bool $_multitenancy = true,
				bool $_render = true,
				bool $_audit = true,
			): ?ObjectEntity {
				foreach ($this->policies as $policy) {
					if ($policy instanceof ObjectEntity && $policy->getUuid() === $id) {
						return $policy;
					}
				}

				return null;
			}

			public function saveObject(
				array|ObjectEntity $object,
				?array $extend = [],
				mixed $register = null,
				mixed $schema = null,
				?string $uuid = null,
				bool $_rbac = true,
				bool $_multitenancy = true,
				bool $silent = false,
				bool $_validation = true,
				?array $uploadedFiles = null,
				?\OCP\IUser $currentUser = null,
				bool $failIfExists = false,
				bool $_unowned = false,
				bool $_dedupOverride = false,
				?string $_owner = null,
			): ObjectEntity {
				$this->saves[] = ['object' => $object, 'uuid' => $uuid];
				$saved = new ObjectEntity();
				$saved->setUuid($uuid ?? 'new-uuid');
				$saved->setOrganisation((string)($object['@self']['organisation'] ?? 'org-of-record'));
				$saved->setObject($object);
				return $saved;
			}
		};
	}//end objectService()

	/**
	 * The policy check under test.
	 *
	 * @param array<int, mixed> $policies     The stored policies.
	 * @param string|null       $chatProvider The configured chat provider.
	 *
	 * @return object
	 */
	private function check(array $policies, ?string $chatProvider = null): object {
		return $this->service(objects: $this->objectService($policies), chatProvider: $chatProvider);
	}//end check()

	/**
	 * The stored-policy service.
	 *
	 * @param ObjectService $objects      The register double.
	 * @param string|null   $chatProvider The configured chat provider.
	 *
	 * @return OrganisationModelPolicyService
	 */
	private function service(ObjectService $objects, ?string $chatProvider = null): OrganisationModelPolicyService {
		$settings = $this->createMock(LlmSettingsHandler::class);
		$settings->method('getLLMSettingsOnly')->willReturn($chatProvider === null ? [] : ['chatProvider' => $chatProvider]);

		return new OrganisationModelPolicyService(objectService: $objects, settingsHandler: $settings);
	}//end service()

	/**
	 * The organisation's own policy is returned whole, in the stored-policy shape.
	 *
	 * @return void
	 */
	public function testTheOrganisationsOwnPolicyGoverns(): void {
		$check = $this->check(
			[
				'not-an-entity',
				$this->policy('p-instance', '', ['allowed' => [['provider' => 'openai', 'models' => []]]]),
				$this->policy('p-org', 'org-a', ['allowed' => [['provider' => 'ollama', 'models' => ['qwen']]], 'defaultModel' => ['provider' => 'ollama', 'model' => 'qwen'], 'requireNoTraining' => true]),
			]
		);

		$this->assertSame(
			[
				'id' => 'p-org',
				'organisation' => 'org-a',
				'source' => 'organisation',
				'allowed' => [['provider' => 'ollama', 'models' => ['qwen']]],
				'defaultModel' => ['provider' => 'ollama', 'model' => 'qwen'],
				'requireNoTraining' => true,
			],
			$check->effectivePolicyFor(organisation: 'org-a')
		);
		$this->assertTrue($check->requiresNoTraining(organisation: 'org-a'));
		$this->assertSame('p-org', $check->getForOrganisation(organisation: 'org-a')?->getUuid());
		$this->assertNull($check->getForOrganisation(organisation: 'org-b'));
	}//end testTheOrganisationsOwnPolicyGoverns()

	/**
	 * Without its own policy, an organisation (and the instance itself) gets the instance default.
	 *
	 * @return void
	 */
	public function testTheInstanceDefaultGovernsTheRest(): void {
		$check = $this->check([$this->policy('p-instance', '', ['allowed' => [['provider' => 'openai', 'models' => ['gpt-4o']]], 'requireNoTraining' => 'yes'])]);

		foreach (['org-b', ''] as $organisation) {
			$effective = $check->effectivePolicyFor(organisation: $organisation);
			$this->assertSame(['p-instance', 'instance', null, false], [$effective['id'], $effective['source'], $effective['defaultModel'], $effective['requireNoTraining']]);
		}

		$this->assertFalse($check->requiresNoTraining(organisation: 'org-b'));
	}//end testTheInstanceDefaultGovernsTheRest()

	/**
	 * With no policy stored, only the configured chat provider is allowed; with none
	 * configured, nothing is.
	 *
	 * @return void
	 */
	public function testTheFallbackIsFailClosed(): void {
		$configured = $this->check([], chatProvider: 'ollama');
		$unconfigured = $this->check([], chatProvider: '');

		$this->assertSame(
			['source' => 'fallback', 'allowed' => [['provider' => 'ollama', 'models' => []]], 'defaultModel' => null, 'requireNoTraining' => false],
			$configured->effectivePolicyFor(organisation: 'org-a')
		);
		$this->assertTrue($configured->isAllowed(organisation: 'org-a', provider: 'ollama', model: 'any'));
		$this->assertFalse($configured->isAllowed(organisation: 'org-a', provider: 'openai', model: 'gpt-4o'));
		$this->assertSame([], $unconfigured->effectivePolicyFor(organisation: 'org-a')['allowed']);
		$this->assertFalse($unconfigured->isAllowed(organisation: 'org-a', provider: 'ollama', model: 'any'));
		$this->assertFalse($unconfigured->requiresNoTraining(organisation: 'org-a'));
	}//end testTheFallbackIsFailClosed()

	/**
	 * A pair is allowed when its provider is listed and its model is listed or the list is open.
	 *
	 * @return void
	 */
	public function testAPairIsAllowedOnlyWithinTheList(): void {
		$check = $this->check(
			[$this->policy('p-org', 'org-a', ['allowed' => [['provider' => 'openai', 'models' => ['gpt-4o-mini']], ['provider' => 'ollama', 'models' => []]]])],
			chatProvider: 'fireworks'
		);

		$this->assertTrue($check->isAllowed(organisation: 'org-a', provider: 'openai', model: 'gpt-4o-mini'));
		$this->assertTrue($check->isAllowed(organisation: 'org-a', provider: 'ollama', model: 'whatever'));
		$this->assertFalse($check->isAllowed(organisation: 'org-a', provider: 'openai', model: 'gpt-4o'));
		$this->assertFalse($check->isAllowed(organisation: 'org-a', provider: 'fireworks', model: 'llama'));
		$this->assertTrue($check->isAllowed(organisation: 'org-b', provider: 'fireworks', model: 'llama'), 'org-b falls back to the configured provider');
	}//end testAPairIsAllowedOnlyWithinTheList()

	/**
	 * A write returns the stored-policy shape; an upsert updates the organisation's own row.
	 *
	 * @return void
	 */
	public function testAWriteReturnsTheStoredShape(): void {
		$objects = $this->objectService([$this->policy('p-org', 'org-a', ['allowed' => [], 'requireNoTraining' => true])]);
		$service = $this->service(objects: $objects);

		$upserted = $service->upsertForOrganisation(organisation: 'org-a', payload: ['allowed' => [['provider' => 'openai', 'models' => ['gpt-4o']]], 'defaultModel' => ['provider' => 'openai', 'model' => 'gpt-4o']]);
		$instance = $service->upsertForOrganisation(organisation: '', payload: []);
		$updated = $service->update(uuid: 'p-org', payload: ['requireNoTraining' => false]);

		$this->assertSame(
			['id' => 'p-org', 'organisation' => 'org-a', 'source' => 'organisation', 'allowed' => [['provider' => 'openai', 'models' => ['gpt-4o']]], 'defaultModel' => ['provider' => 'openai', 'model' => 'gpt-4o'], 'requireNoTraining' => true],
			$upserted
		);
		$this->assertSame(['new-uuid', '', 'instance', [], null, false], array_values($instance));
		$this->assertSame(['p-org', 'org-of-record', 'organisation', [], null, false], array_values($updated));
		$this->assertSame('p-org', $objects->saves[0]['uuid']);
		$this->assertNull($objects->saves[1]['uuid']);
	}//end testAWriteReturnsTheStoredShape()

	/**
	 * A default model outside the allowed list, or an unknown policy, is refused.
	 *
	 * @return void
	 */
	public function testAWriteOutsideTheRulesIsRefused(): void {
		$service = $this->service(objects: $this->objectService([]));

		try {
			$service->upsertForOrganisation(organisation: 'org-a', payload: ['allowed' => [['provider' => 'openai', 'models' => ['gpt-4o']]], 'defaultModel' => ['provider' => 'openai', 'model' => 'gpt-5']]);
			$this->fail('A default model outside the allowed list was stored.');
		} catch (InvalidArgumentException $e) {
			$this->assertSame("defaultModel 'openai/gpt-5' must itself be one of the allowed provider/model combinations", $e->getMessage());
		}

		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessage("ModelPolicy 'missing' does not exist");
		$service->update(uuid: 'missing', payload: []);
	}//end testAWriteOutsideTheRulesIsRefused()
}//end class
