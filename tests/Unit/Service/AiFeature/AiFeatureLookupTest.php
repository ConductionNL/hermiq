<?php

/**
 * Tests for AiFeatureLookup.
 *
 * Pins both reads: the empty slug short-circuits without a query, the ordinary
 * read keeps RBAC on and the gate read turns it off, both query the hermiq
 * register's agentaifeature schema with the slug filter, and the first object
 * whose slug matches exactly is returned (non-entities and other slugs skipped).
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
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service\AiFeature;

use OCA\Hermiq\Service\AiFeature\AiFeatureLookup;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * AiFeature reads by slug.
 */
#[CoversClass(AiFeatureLookup::class)]
final class AiFeatureLookupTest extends TestCase {

	/**
	 * Every call the object service received.
	 *
	 * @var array<int, array<int, mixed>>
	 */
	private array $calls = [];

	/**
	 * A lookup over an object service that returns the given objects.
	 *
	 * @param array<int, mixed> $objects What findAll returns.
	 *
	 * @return AiFeatureLookup
	 */
	private function lookup(array $objects): AiFeatureLookup {
		$this->calls = [];
		$service = $this->createMock(ObjectService::class);
		$service->method('setRegister')->willReturnCallback(
			function (string $register) use ($service): ObjectService {
				$this->calls[] = ['setRegister', $register];
				return $service;
			}
		);
		$service->method('setSchema')->willReturnCallback(
			function (string $schema) use ($service): ObjectService {
				$this->calls[] = ['setSchema', $schema];
				return $service;
			}
		);
		$service->method('findAll')->willReturnCallback(
			function (array $config = [], bool $_rbac = true, bool $_multitenancy = true) use ($objects): array {
				$this->calls[] = ['findAll', $config, $_rbac, $_multitenancy];
				return $objects;
			}
		);

		return new AiFeatureLookup($service);
	}//end lookup()

	/**
	 * An entity carrying the given payload.
	 *
	 * @param array<string, mixed> $payload The object payload.
	 *
	 * @return ObjectEntity
	 */
	private function entity(array $payload): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setObject($payload);
		return $entity;
	}//end entity()

	/**
	 * An empty slug is not looked up at all, on either read.
	 *
	 * @return void
	 */
	public function testEmptySlugReturnsNullWithoutQuerying(): void {
		$lookup = $this->lookup([$this->entity(['slug' => ''])]);

		$this->assertNull($lookup->findBySlug(''));
		$this->assertNull($lookup->findBySlugForGate(''));
		$this->assertSame([], $this->calls);
	}//end testEmptySlugReturnsNullWithoutQuerying()

	/**
	 * The ordinary read queries the feature schema with RBAC and tenancy on.
	 *
	 * @return void
	 */
	public function testFindBySlugQueriesWithRbac(): void {
		$match = $this->entity(['slug' => 'summarise']);
		$lookup = $this->lookup([$match]);

		$this->assertSame($match, $lookup->findBySlug('summarise'));
		$this->assertSame(
			[
				['setRegister', 'hermiq'],
				['setSchema', 'agentaifeature'],
				['findAll', ['filters' => ['slug' => 'summarise'], 'limit' => 200], true, true],
			],
			$this->calls
		);
	}//end testFindBySlugQueriesWithRbac()

	/**
	 * The gate read skips RBAC but keeps tenant scoping.
	 *
	 * @return void
	 */
	public function testFindBySlugForGateSkipsRbacOnly(): void {
		$match = $this->entity(['slug' => 'translate']);
		$lookup = $this->lookup([$match]);

		$this->assertSame($match, $lookup->findBySlugForGate('translate'));
		$this->assertSame(
			[
				['setRegister', 'hermiq'],
				['setSchema', 'agentaifeature'],
				['findAll', ['filters' => ['slug' => 'translate'], 'limit' => 200], false, true],
			],
			$this->calls
		);
	}//end testFindBySlugForGateSkipsRbacOnly()

	/**
	 * Non-entities and objects with another or missing slug are skipped; the first
	 * exact match wins.
	 *
	 * @return void
	 */
	public function testReturnsFirstExactMatchSkippingOthers(): void {
		$first = $this->entity(['slug' => 'summarise', 'n' => 1]);
		$second = $this->entity(['slug' => 'summarise', 'n' => 2]);
		$objects = [
			['slug' => 'summarise'],
			'junk',
			$this->entity([]),
			$this->entity(['slug' => 'Summarise']),
			$this->entity(['slug' => 'summarise-long']),
			$first,
			$second,
		];

		$this->assertSame($first, $this->lookup($objects)->findBySlug('summarise'));
		$this->assertSame($first, $this->lookup($objects)->findBySlugForGate('summarise'));
	}//end testReturnsFirstExactMatchSkippingOthers()

	/**
	 * No exact match reads as absent.
	 *
	 * @return void
	 */
	public function testNoMatchReturnsNull(): void {
		$objects = ['junk', $this->entity(['slug' => 'other']), $this->entity(['title' => 'summarise'])];

		$this->assertNull($this->lookup($objects)->findBySlug('summarise'));
		$this->assertNull($this->lookup([])->findBySlugForGate('summarise'));
	}//end testNoMatchReturnsNull()
}//end class
