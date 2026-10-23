<?php

/**
 * Unit tests for AiFeatureService::findBySlugForGate (ai-translation-provenance).
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
 * @spec openspec/changes/ai-translation-provenance/specs/message-translation/spec.md#requirement-req-011-the-gate-answers-for-a-caller-without-a-nextcloud-session
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service;

use OCA\Hermiq\Service\AiFeatureService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;

/**
 * The gate read answers without RBAC; the ordinary read keeps it.
 *
 * @spec openspec/changes/ai-translation-provenance/specs/message-translation/spec.md#requirement-req-011-the-gate-answers-for-a-caller-without-a-nextcloud-session
 */
class AiFeatureServiceGateTest extends TestCase {

	/**
	 * An ObjectService double that returns the rows only to a read without
	 * RBAC, the way a caller with no Nextcloud session sees the register, and
	 * records the flags of every read.
	 *
	 * @param array<int, mixed> $rows The rows a system read finds.
	 *
	 * @return ObjectService
	 */
	private function objectService(array $rows): ObjectService {
		return new class($rows) extends ObjectService {
			/**
			 * The `_rbac` / `_multitenancy` flags of every findAll().
			 *
			 * @var array<int, array{rbac: bool, multitenancy: bool}>
			 */
			public array $reads = [];

			/**
			 * @param array<int, mixed> $rows The rows a system read finds.
			 */
			public function __construct(private array $rows) {
			}

			public function setRegister(mixed $register): static {
				return $this;
			}

			public function setSchema(mixed $schema): static {
				return $this;
			}

			public function findAll(array $config = [], bool $_rbac = true, bool $_multitenancy = true): array {
				$this->reads[] = ['rbac' => $_rbac, 'multitenancy' => $_multitenancy];
				if ($_rbac === true) {
					return [];
				}

				return $this->rows;
			}
		};
	}//end objectService()

	/**
	 * A feature row.
	 *
	 * @param string $slug The slug.
	 *
	 * @return ObjectEntity
	 */
	private function feature(string $slug): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid('feature-' . $slug);
		$entity->setObject(['slug' => $slug, 'lifecycle' => 'enabled']);
		return $entity;
	}//end feature()

	/**
	 * A caller without a session still sees the feature through the gate read,
	 * which skips RBAC and keeps tenant scoping.
	 *
	 * @return void
	 */
	public function testTheGateReadSkipsRbacAndKeepsTenancy(): void {
		$objects = $this->objectService([$this->feature('course-recommendations'), 'not-an-entity', $this->feature('message-translation')]);
		$service = new AiFeatureService(objectService: $objects, appConfig: $this->createMock(IAppConfig::class));

		$found = $service->findBySlugForGate(slug: 'message-translation');

		$this->assertNotNull($found);
		$this->assertSame('feature-message-translation', $found->getUuid());
		$this->assertSame([['rbac' => false, 'multitenancy' => true]], $objects->reads);

	}//end testTheGateReadSkipsRbacAndKeepsTenancy()

	/**
	 * The ordinary read keeps RBAC, so it still reads as absent without a session.
	 *
	 * @return void
	 */
	public function testTheOrdinaryReadKeepsRbac(): void {
		$objects = $this->objectService([$this->feature('message-translation')]);
		$service = new AiFeatureService(objectService: $objects, appConfig: $this->createMock(IAppConfig::class));

		$this->assertNull($service->findBySlug(slug: 'message-translation'));
		$this->assertSame([['rbac' => true, 'multitenancy' => true]], $objects->reads);

	}//end testTheOrdinaryReadKeepsRbac()

	/**
	 * An empty slug or an unknown slug is null, without surprises.
	 *
	 * @return void
	 */
	public function testEmptyAndUnknownSlugsAreNull(): void {
		$objects = $this->objectService([$this->feature('message-translation')]);
		$service = new AiFeatureService(objectService: $objects, appConfig: $this->createMock(IAppConfig::class));

		$this->assertNull($service->findBySlugForGate(slug: ''));
		$this->assertNull($service->findBySlugForGate(slug: 'lesson-authoring'));
		$this->assertCount(1, $objects->reads, 'An empty slug never reaches the register.');

	}//end testEmptyAndUnknownSlugsAreNull()
}//end class
