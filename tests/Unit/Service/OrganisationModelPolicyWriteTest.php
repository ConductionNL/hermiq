<?php

/**
 * Hermiq OrganisationModelPolicyService write-path unit tests.
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
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service;

use InvalidArgumentException;
use OCA\Hermiq\Service\Llm\LlmSettingsHandler;
use OCA\Hermiq\Service\OrganisationModelPolicyService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Tests for upsert, update, lookup and the allow-list validation.
 *
 * @covers \OCA\Hermiq\Service\OrganisationModelPolicyService
 */
class OrganisationModelPolicyWriteTest extends TestCase {

	/**
	 * The object service double.
	 *
	 * @var ObjectService&MockObject
	 */
	private ObjectService&MockObject $objects;

	/**
	 * The saveObject() calls as [object, uuid, rbac, multitenancy].
	 *
	 * @var list<array{0: array<string, mixed>, 1: ?string, 2: bool, 3: bool}>
	 */
	private array $saved = [];

	/**
	 * Wire the object service over a set of stored policies.
	 *
	 * @param list<mixed> $stored The stored policies.
	 *
	 * @return OrganisationModelPolicyService The service.
	 */
	private function service(array $stored = []): OrganisationModelPolicyService {
		$this->saved = [];
		$this->objects = $this->createMock(ObjectService::class);
		$this->objects->method('setRegister')->willReturnSelf();
		$this->objects->method('setSchema')->willReturnSelf();
		$this->objects->method('findAll')->willReturn($stored);
		$this->objects->method('saveObject')->willReturnCallback(
			function (array|ObjectEntity $object, ?array $extend = [], mixed $register = null, mixed $schema = null, ?string $uuid = null, bool $rbac = true, bool $multi = true): ObjectEntity {
				$this->saved[] = [(array)$object, $uuid, $rbac, $multi];
				$self = (array)($object['@self'] ?? []);
				return $this->policy((string)($self['organisation'] ?? ''), (string)($uuid ?? 'new-uuid'), (array)$object);
			}
		);

		return new OrganisationModelPolicyService(
			objectService: $this->objects,
			settingsHandler: $this->createMock(LlmSettingsHandler::class)
		);
	}//end service()

	/**
	 * A stored policy.
	 *
	 * @param string               $organisation The organisation ('' for the instance default).
	 * @param string               $uuid         The uuid.
	 * @param array<string, mixed> $data         The payload.
	 *
	 * @return ObjectEntity The entity.
	 */
	private function policy(string $organisation, string $uuid, array $data = []): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid($uuid);
		$entity->setOrganisation($organisation);
		$entity->setObject($data);
		return $entity;
	}//end policy()

	/**
	 * findById() skips the empty uuid and reads unscoped from the policy schema; listAll() keeps entities.
	 *
	 * @return void
	 */
	public function testLookupAndListing(): void {
		$entity = $this->policy('org', 'p-1');
		$service = $this->service([$entity, 'junk', $this->policy('', 'p-0')]);
		$this->objects->expects($this->once())
			->method('find')
			->with('p-1', [], false, 'hermiq', 'modelpolicy', false, false)
			->willReturn($entity);

		$this->assertNull($service->findById(''));
		$this->assertSame($entity, $service->findById('p-1'));
		$this->assertSame(['p-1', 'p-0'], array_map(fn (ObjectEntity $p): ?string => $p->getUuid(), $service->listAll()));
	}//end testLookupAndListing()

	/**
	 * Upserting a new organisation policy normalises it, pins the organisation and saves unscoped.
	 *
	 * @return void
	 */
	public function testUpsertCreatesANormalisedPolicy(): void {
		$service = $this->service();

		$result = $service->upsertForOrganisation(
			'org-a',
			[
				'allowed' => [['provider' => 'openai', 'models' => [4, 'gpt-4o']], ['provider' => 'ollama']],
				'defaultModel' => ['provider' => 'openai', 'model' => 'gpt-4o'],
				'requireNoTraining' => true,
			]
		);

		$this->assertCount(1, $this->saved);
		[$data, $uuid, $rbac, $multi] = $this->saved[0];
		$this->assertNull($uuid);
		$this->assertFalse($rbac);
		$this->assertFalse($multi);
		$this->assertSame([['provider' => 'openai', 'models' => ['4', 'gpt-4o']], ['provider' => 'ollama', 'models' => []]], $data['allowed']);
		$this->assertSame(['organisation' => 'org-a'], $data['@self']);
		$this->assertSame(
			[
				'id' => 'new-uuid',
				'organisation' => 'org-a',
				'source' => 'organisation',
				'allowed' => $data['allowed'],
				'defaultModel' => ['provider' => 'openai', 'model' => 'gpt-4o'],
				'requireNoTraining' => true,
			],
			$result
		);
	}//end testUpsertCreatesANormalisedPolicy()

	/**
	 * Upserting the instance default updates the stored one and keeps its no-training flag when not given.
	 *
	 * @return void
	 */
	public function testUpsertUpdatesTheInstanceDefault(): void {
		$service = $this->service([$this->policy('', 'p-0', ['requireNoTraining' => true, 'note' => 'x'])]);

		$result = $service->upsertForOrganisation('', ['allowed' => [], 'defaultModel' => ['provider' => '', 'model' => 'm']]);

		[$data, $uuid] = $this->saved[0];
		$this->assertSame('p-0', $uuid);
		$this->assertSame('x', $data['note']);
		$this->assertTrue($data['requireNoTraining']);
		$this->assertNull($data['defaultModel']);
		$this->assertSame('instance', $result['source']);
	}//end testUpsertUpdatesTheInstanceDefault()

	/**
	 * Every malformed allow-list or default model is refused before anything is saved.
	 *
	 * @return void
	 */
	public function testMalformedPayloadsAreRefused(): void {
		$cases = [
			'allowed must be an array' => ['allowed' => 'openai'],
			'Each allowed entry must be an object' => ['allowed' => ['openai']],
			"Unsupported provider 'acme'" => ['allowed' => [['provider' => 'acme']]],
			'models must be an array' => ['allowed' => [['provider' => 'openai', 'models' => 'gpt-4o']]],
			'defaultModel must be an object' => ['allowed' => [], 'defaultModel' => 'gpt-4o'],
			"defaultModel 'openai/gpt-5' must itself be one of the allowed" => [
				'allowed' => [['provider' => 'openai', 'models' => ['gpt-4o']], ['provider' => 'ollama', 'models' => []]],
				'defaultModel' => ['provider' => 'openai', 'model' => 'gpt-5'],
			],
		];

		$service = $this->service();
		foreach ($cases as $message => $payload) {
			try {
				$service->upsertForOrganisation('org-a', $payload);
				$this->fail('Expected a refusal for: ' . $message);
			} catch (InvalidArgumentException $e) {
				$this->assertStringContainsString($message, $e->getMessage());
			}
		}

		$this->assertSame([], $this->saved);
	}//end testMalformedPayloadsAreRefused()

	/**
	 * Updating an unknown policy is refused.
	 *
	 * @return void
	 */
	public function testUpdatingAnUnknownPolicyIsRefused(): void {
		$service = $this->service();
		$this->objects->method('find')->willReturn(null);

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage("ModelPolicy 'p-9' does not exist");

		$service->update('p-9', []);
	}//end testUpdatingAnUnknownPolicyIsRefused()

	/**
	 * An update keeps stored fields the payload leaves out and replaces those it names.
	 *
	 * @return void
	 */
	public function testUpdateMergesOverTheStoredPolicy(): void {
		$stored = [
			'allowed' => [['provider' => 'openai', 'models' => ['gpt-4o']]],
			'defaultModel' => ['provider' => 'openai', 'model' => 'gpt-4o'],
			'requireNoTraining' => true,
		];
		$service = $this->service();
		$this->objects->method('find')->willReturnOnConsecutiveCalls(
			$this->policy('org-a', 'p-1', $stored),
			$this->policy('', 'p-0', $stored)
		);

		$kept = $service->update('p-1', []);
		$changed = $service->update('p-0', ['allowed' => [['provider' => 'anthropic', 'models' => []]], 'defaultModel' => null, 'requireNoTraining' => 'yes']);

		$this->assertSame('p-1', $this->saved[0][1]);
		$this->assertSame($stored['allowed'], $this->saved[0][0]['allowed']);
		$this->assertSame($stored['defaultModel'], $this->saved[0][0]['defaultModel']);
		$this->assertTrue($this->saved[0][0]['requireNoTraining']);
		$this->assertSame('organisation', $kept['source']);

		$this->assertSame([['provider' => 'anthropic', 'models' => []]], $this->saved[1][0]['allowed']);
		$this->assertNull($this->saved[1][0]['defaultModel']);
		$this->assertFalse($this->saved[1][0]['requireNoTraining']);
		$this->assertSame('instance', $changed['source']);
	}//end testUpdateMergesOverTheStoredPolicy()

	/**
	 * A provider listed with no models admits any of its models as the default.
	 *
	 * @return void
	 */
	public function testAnEmptyModelListAdmitsAnyDefaultOfThatProvider(): void {
		$service = $this->service();

		$result = $service->upsertForOrganisation(
			'org-a',
			['allowed' => [['provider' => 'ollama', 'models' => []]], 'defaultModel' => ['provider' => 'ollama', 'model' => 'llama3:8b']]
		);

		$this->assertSame(['provider' => 'ollama', 'model' => 'llama3:8b'], $result['defaultModel']);
	}//end testAnEmptyModelListAdmitsAnyDefaultOfThatProvider()

	/**
	 * requiresNoTraining() reads the effective policy's flag.
	 *
	 * @return void
	 */
	public function testRequiresNoTrainingReadsTheEffectivePolicy(): void {
		$service = $this->service([$this->policy('org-a', 'p-1', ['requireNoTraining' => true]), $this->policy('', 'p-0', ['requireNoTraining' => 1])]);

		$this->assertTrue($service->requiresNoTraining('org-a'));
		$this->assertFalse($service->requiresNoTraining('org-b'));
	}//end testRequiresNoTrainingReadsTheEffectivePolicy()
}//end class
