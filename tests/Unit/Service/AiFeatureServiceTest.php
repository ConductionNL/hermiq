<?php

/**
 * Hermiq AiFeatureService unit tests.
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

use OCA\Hermiq\Service\AiFeature\AiFeatureLookup;
use OCA\Hermiq\Service\AiFeatureService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Exception\HookStoppedException;
use OCA\OpenRegister\Service\ObjectService;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the AI-feature register: listing, DPO acknowledgement, publication and lifecycle.
 *
 * @covers \OCA\Hermiq\Service\AiFeatureService
 */
class AiFeatureServiceTest extends TestCase {

	/**
	 * The object service double.
	 *
	 * @var ObjectService&MockObject
	 */
	private ObjectService&MockObject $objects;

	/**
	 * The app config double.
	 *
	 * @var IAppConfig&MockObject
	 */
	private IAppConfig&MockObject $config;

	/**
	 * The slug lookup double.
	 *
	 * @var AiFeatureLookup&MockObject
	 */
	private AiFeatureLookup&MockObject $lookup;

	/**
	 * The saveObject() calls as [object, register, schema, uuid].
	 *
	 * @var list<array{0: array<string, mixed>, 1: mixed, 2: mixed, 3: ?string}>
	 */
	private array $saved = [];

	/**
	 * Wire the doubles.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->objects = $this->createMock(ObjectService::class);
		$this->config = $this->createMock(IAppConfig::class);
		$this->lookup = $this->createMock(AiFeatureLookup::class);
		$this->saved = [];
	}//end setUp()

	/**
	 * The service under test.
	 *
	 * @return AiFeatureService The service.
	 */
	private function service(): AiFeatureService {
		return new AiFeatureService(objectService: $this->objects, appConfig: $this->config, lookup: $this->lookup);
	}//end service()

	/**
	 * A feature object.
	 *
	 * @param array<string, mixed> $data The payload.
	 *
	 * @return ObjectEntity The entity.
	 */
	private function feature(array $data): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid('feat-1');
		$entity->setObject($data);
		return $entity;
	}//end feature()

	/**
	 * Record saveObject() calls and return a saved entity, or throw.
	 *
	 * @param \Throwable|null $throw What to throw instead of saving.
	 *
	 * @return void
	 */
	private function recordSaves(?\Throwable $throw = null): void {
		$this->objects->method('saveObject')->willReturnCallback(
			function (array|ObjectEntity $object, ?array $extend = [], mixed $register = null, mixed $schema = null, ?string $uuid = null) use ($throw): ObjectEntity {
				if ($throw !== null) {
					throw $throw;
				}

				$this->saved[] = [$object, $register, $schema, $uuid];
				return $this->feature((array)$object);
			}
		);
	}//end recordSaves()

	/**
	 * listFeatures() reads the feature schema of the hermiq register and keeps only entities.
	 *
	 * @return void
	 */
	public function testListFeaturesKeepsOnlyEntities(): void {
		$entity = $this->feature(['slug' => 'samenvatten']);
		$this->objects->expects($this->once())->method('setRegister')->with('hermiq')->willReturnSelf();
		$this->objects->expects($this->once())->method('setSchema')->with('agentaifeature')->willReturnSelf();
		$this->objects->expects($this->once())->method('findAll')->with(['limit' => 200])->willReturn([$entity, ['raw' => true], null]);

		$this->assertSame([$entity], $this->service()->listFeatures());
	}//end testListFeaturesKeepsOnlyEntities()

	/**
	 * An empty id is not looked up; any other id is found in the feature schema.
	 *
	 * @return void
	 */
	public function testGetFeatureSkipsTheEmptyId(): void {
		$entity = $this->feature([]);
		$this->objects->expects($this->once())
			->method('find')
			->with('feat-1', [], false, 'hermiq', 'agentaifeature')
			->willReturn($entity);

		$this->assertNull($this->service()->getFeature(''));
		$this->assertSame($entity, $this->service()->getFeature('feat-1'));
	}//end testGetFeatureSkipsTheEmptyId()

	/**
	 * Both slug lookups delegate to the lookup.
	 *
	 * @return void
	 */
	public function testSlugLookupsDelegate(): void {
		$entity = $this->feature([]);
		$this->lookup->expects($this->once())->method('findBySlug')->with('samenvatten')->willReturn($entity);
		$this->lookup->expects($this->once())->method('findBySlugForGate')->with('vertalen')->willReturn(null);

		$this->assertSame($entity, $this->service()->findBySlug('samenvatten'));
		$this->assertNull($this->service()->findBySlugForGate('vertalen'));
	}//end testSlugLookupsDelegate()

	/**
	 * Acknowledging an unknown slug writes nothing.
	 *
	 * @return void
	 */
	public function testAcknowledgingAnUnknownSlugWritesNothing(): void {
		$this->lookup->method('findBySlug')->willReturn(null);
		$this->config->expects($this->never())->method('setValueString');
		$this->objects->expects($this->never())->method('saveObject');

		$this->assertNull($this->service()->acknowledge('onbekend', 'dpo'));
	}//end testAcknowledgingAnUnknownSlugWritesNothing()

	/**
	 * The acknowledgement is keyed per tenant and mirrored onto the object.
	 *
	 * @return void
	 */
	public function testAcknowledgeWritesTheTenantKeyAndTheMirror(): void {
		$this->lookup->method('findBySlug')->willReturn($this->feature(['slug' => 'samenvatten', 'tenant_id' => ' t-9 ']));
		$this->config->expects($this->once())
			->method('setValueString')
			->with(
				'hermiq',
				'dpo_ack.t-9.samenvatten',
				$this->matchesRegularExpression('/^dpo@\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d\+00:00$/')
			);
		$this->recordSaves();

		$result = $this->service()->acknowledge('samenvatten', 'dpo');

		$this->assertInstanceOf(ObjectEntity::class, $result);
		$this->assertCount(1, $this->saved);
		[$object, $register, $schema, $uuid] = $this->saved[0];
		$this->assertSame('dpo', $object['dpoAckBy']);
		$this->assertMatchesRegularExpression('/\+00:00$/', $object['dpoAckAt']);
		$this->assertSame(['hermiq', 'agentaifeature', 'feat-1'], [$register, $schema, $uuid]);
	}//end testAcknowledgeWritesTheTenantKeyAndTheMirror()

	/**
	 * Without a tenant the key is the slug alone; tenantId wins over tenant_id.
	 *
	 * @return void
	 */
	public function testAcknowledgeKeyWithoutAndWithTenantId(): void {
		$this->lookup->method('findBySlug')->willReturnOnConsecutiveCalls(
			$this->feature(['slug' => 'a']),
			$this->feature(['slug' => 'b', 'tenantId' => 'org', 'tenant_id' => 'other'])
		);
		$keys = [];
		$this->config->method('setValueString')->willReturnCallback(
			function (string $app, string $key) use (&$keys): bool {
				$keys[] = $key;
				return true;
			}
		);
		$this->recordSaves();

		$this->service()->acknowledge('a', 'dpo');
		$this->service()->acknowledge('b', 'dpo');

		$this->assertSame(['dpo_ack.a', 'dpo_ack.org.b'], $keys);
	}//end testAcknowledgeKeyWithoutAndWithTenantId()

	/**
	 * Publication and withdrawal set the register status; an unknown id saves nothing.
	 *
	 * @return void
	 */
	public function testPublicationAndWithdrawal(): void {
		$this->objects->method('find')->willReturnCallback(
			fn (int|string $id): ?ObjectEntity => ($id === 'feat-1' ? $this->feature(['algoritmeregisterRef' => 'old']) : null)
		);
		$this->recordSaves();

		$this->assertNull($this->service()->recordPublication('nope', 'ref'));
		$this->assertNull($this->service()->recordWithdrawal('nope'));
		$this->service()->recordPublication('feat-1', 'AR-123');
		$this->service()->recordWithdrawal('feat-1');

		$this->assertCount(2, $this->saved);
		$this->assertSame('gepubliceerd', $this->saved[0][0]['algoritmeregisterStatus']);
		$this->assertSame('AR-123', $this->saved[0][0]['algoritmeregisterRef']);
		$this->assertSame('ingetrokken', $this->saved[1][0]['algoritmeregisterStatus']);
		$this->assertSame('old', $this->saved[1][0]['algoritmeregisterRef']);
	}//end testPublicationAndWithdrawal()

	/**
	 * The lifecycle transitions: not found, idempotent, invalid, allowed and hook-blocked.
	 *
	 * @return void
	 */
	public function testLifecycleTransitions(): void {
		$features = [
			'disabled' => $this->feature(['lifecycle' => 'disabled']),
			'enabled' => $this->feature(['lifecycle' => 'enabled']),
			'unset' => $this->feature([]),
			'retired' => $this->feature(['lifecycle' => 'retired']),
		];
		$this->objects->method('find')->willReturnCallback(fn (int|string $id): ?ObjectEntity => ($features[$id] ?? null));
		$this->recordSaves();
		$service = $this->service();

		$this->assertSame(AiFeatureService::RESULT_NOT_FOUND, $service->enable('nope'));
		$this->assertSame(AiFeatureService::RESULT_ENABLED, $service->enable('enabled'));
		$this->assertSame(AiFeatureService::RESULT_DISABLED, $service->disable('unset'));
		$this->assertSame(AiFeatureService::RESULT_INVALID, $service->enable('retired'));
		$this->assertSame([], $this->saved);

		$this->assertSame(AiFeatureService::RESULT_ENABLED, $service->enable('disabled'));
		$this->assertSame(AiFeatureService::RESULT_DISABLED, $service->disable('enabled'));
		$this->assertSame(['enabled', 'disabled'], array_map(fn (array $s): string => $s[0]['lifecycle'], $this->saved));
	}//end testLifecycleTransitions()

	/**
	 * A transition the lifecycle hook stops is reported as blocked.
	 *
	 * @return void
	 */
	public function testAHookStoppedTransitionIsBlocked(): void {
		$this->objects->method('find')->willReturn($this->feature(['lifecycle' => 'disabled']));
		$this->recordSaves(new HookStoppedException('no DPO acknowledgement'));

		$this->assertSame(AiFeatureService::RESULT_BLOCKED, $this->service()->enable('feat-1'));
	}//end testAHookStoppedTransitionIsBlocked()
}//end class
