<?php

/**
 * Unit tests for the SeedFreshnessService (seed-staleness hardening).
 *
 * Covers the freshness contract that keeps seeded skills out of the Curator's
 * age-staleness trap: a fresh seed payload is stamped with `lastActivityAt`; a
 * repair re-run refreshes a `__system__`-owned seed still in `active`/`stale`
 * (a stale seed flips back to active, dropping `staleSince`); `archived` /
 * `quarantined` seeds and human-owned skills are NEVER touched.
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
 * @spec openspec/specs/skill-maturity/spec.md#requirement-seeded-example-skills-demonstrate-distinct-maturity-levels
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service;

use DateTimeImmutable;
use OCA\Hermiq\Service\SeedFreshnessService;
use OCA\OpenRegister\Db\ObjectEntity;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the seed-staleness hardening rules.
 *
 * @spec openspec/specs/skill-maturity/spec.md#requirement-seeded-example-skills-demonstrate-distinct-maturity-levels
 */
class SeedFreshnessServiceTest extends TestCase {

	/**
	 * The service under test.
	 *
	 * @var SeedFreshnessService
	 */
	private SeedFreshnessService $service;

	/**
	 * Set up the service.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->service = new SeedFreshnessService();

	}//end setUp()

	/**
	 * An ObjectEntity with the given owner and payload.
	 *
	 * @param string|null $owner The stored owner uid.
	 * @param array<string, mixed> $payload The object payload.
	 *
	 * @return ObjectEntity
	 */
	private function object(?string $owner, array $payload): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid('uuid-1');
		$entity->setOwner($owner);
		$entity->setObject($payload);

		return $entity;
	}//end object()

	/**
	 * stampFresh() stamps a parseable ISO-8601 `lastActivityAt` on a creation payload
	 * and leaves every other field alone.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/skill-maturity/spec.md#requirement-seeded-example-skills-demonstrate-distinct-maturity-levels
	 */
	public function testStampFreshStampsLastActivityAtAtCreation(): void {
		$seed = [
			'name' => 'tender-summary',
			'state' => 'active',
		];

		$stamped = $this->service->stampFresh(seed: $seed);

		$this->assertSame('tender-summary', $stamped['name']);
		$this->assertSame('active', $stamped['state']);
		$this->assertArrayHasKey('lastActivityAt', $stamped);

		$parsed = new DateTimeImmutable((string)$stamped['lastActivityAt']);
		$this->assertEqualsWithDelta(time(), $parsed->getTimestamp(), 60);

	}//end testStampFreshStampsLastActivityAtAtCreation()

	/**
	 * A re-run refreshes a STALE `__system__` seed back to active: fresh
	 * `lastActivityAt`, `staleSince` dropped, content untouched.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/skill-maturity/spec.md#requirement-seeded-example-skills-demonstrate-distinct-maturity-levels
	 */
	public function testRefreshFlipsStaleSystemSeedBackToActive(): void {
		$skill = $this->object(
			'__system__',
			[
				'name' => 'woo-request-triage',
				'body' => 'Seeded body.',
				'state' => 'stale',
				'lastActivityAt' => '2025-01-01 00:00:00',
				'staleSince' => '2025-06-01 00:00:00',
			]
		);

		$refreshed = $this->service->refreshedPayload(skill: $skill);

		$this->assertNotNull($refreshed);
		$this->assertSame('active', $refreshed['state']);
		$this->assertSame('Seeded body.', $refreshed['body']);
		$this->assertArrayNotHasKey('staleSince', $refreshed);

		$parsed = new DateTimeImmutable((string)$refreshed['lastActivityAt']);
		$this->assertEqualsWithDelta(time(), $parsed->getTimestamp(), 60);

	}//end testRefreshFlipsStaleSystemSeedBackToActive()

	/**
	 * A re-run refreshes an ACTIVE `__system__` seed's `lastActivityAt` and keeps it
	 * active — the staleness clock restarts on every repair run.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/skill-maturity/spec.md#requirement-seeded-example-skills-demonstrate-distinct-maturity-levels
	 */
	public function testRefreshRestampsActiveSystemSeed(): void {
		$skill = $this->object(
			'__system__',
			[
				'name' => 'tender-summary',
				'state' => 'active',
				'lastActivityAt' => '2025-01-01 00:00:00',
			]
		);

		$refreshed = $this->service->refreshedPayload(skill: $skill);

		$this->assertNotNull($refreshed);
		$this->assertSame('active', $refreshed['state']);
		$this->assertNotSame('2025-01-01 00:00:00', (string)$refreshed['lastActivityAt']);

	}//end testRefreshRestampsActiveSystemSeed()

	/**
	 * An ARCHIVED `__system__` seed is NEVER touched — archiving is a curator
	 * decision and it wins; a quarantined seed likewise.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/skill-maturity/spec.md#requirement-seeded-example-skills-demonstrate-distinct-maturity-levels
	 */
	public function testRefreshNeverTouchesArchivedOrQuarantinedSeed(): void {
		$archived = $this->object(
			'__system__',
			['name' => 'tender-summary', 'state' => 'archived', 'archivedAt' => '2025-06-01 00:00:00']
		);
		$this->assertNull($this->service->refreshedPayload(skill: $archived));

		$quarantined = $this->object(
			'__system__',
			['name' => 'tender-summary', 'state' => 'quarantined']
		);
		$this->assertNull($this->service->refreshedPayload(skill: $quarantined));

	}//end testRefreshNeverTouchesArchivedOrQuarantinedSeed()

	/**
	 * A HUMAN-created skill is NEVER touched, whatever its state — its lifecycle
	 * belongs to its owner, not to the seed repair step.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/skill-maturity/spec.md#requirement-seeded-example-skills-demonstrate-distinct-maturity-levels
	 */
	public function testRefreshNeverTouchesHumanCreatedSkills(): void {
		$activeHuman = $this->object(
			'alice',
			['name' => 'my-own-skill', 'state' => 'active', 'lastActivityAt' => '2025-01-01 00:00:00']
		);
		$this->assertNull($this->service->refreshedPayload(skill: $activeHuman));

		$staleHuman = $this->object(
			'alice',
			['name' => 'my-own-skill', 'state' => 'stale', 'staleSince' => '2025-06-01 00:00:00']
		);
		$this->assertNull($this->service->refreshedPayload(skill: $staleHuman));

		$ownerless = $this->object(
			null,
			['name' => 'my-own-skill', 'state' => 'stale']
		);
		$this->assertNull($this->service->refreshedPayload(skill: $ownerless));

	}//end testRefreshNeverTouchesHumanCreatedSkills()

	/**
	 * The refreshed payload strips OR envelope keys and normalises remaining
	 * lifecycle date fields to ISO-8601 (the space-separated re-save gotcha).
	 *
	 * @return void
	 *
	 * @spec openspec/specs/skill-maturity/spec.md#requirement-seeded-example-skills-demonstrate-distinct-maturity-levels
	 */
	public function testRefreshStripsEnvelopeKeysAndNormalisesDates(): void {
		$skill = $this->object(
			'__system__',
			[
				'id' => 42,
				'uuid' => 'uuid-1',
				'@self' => ['register' => 'hermiq'],
				'name' => 'tender-summary',
				'state' => 'active',
				'lastActivityAt' => '2025-01-01 00:00:00',
			]
		);

		$refreshed = $this->service->refreshedPayload(skill: $skill);

		$this->assertNotNull($refreshed);
		$this->assertArrayNotHasKey('id', $refreshed);
		$this->assertArrayNotHasKey('uuid', $refreshed);
		$this->assertArrayNotHasKey('@self', $refreshed);

	}//end testRefreshStripsEnvelopeKeysAndNormalisesDates()
}//end class
