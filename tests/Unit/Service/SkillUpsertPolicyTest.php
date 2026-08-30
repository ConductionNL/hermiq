<?php

/**
 * Hermiq SkillUpsertPolicy tests
 *
 * Three things an update must not destroy: curated state, an approval that no longer
 * describes the stored content, and local learnings.
 *
 * The learnings tests are the load-bearing ones. The obvious wrong implementation —
 * comparing `lastAcceptedVersionAt` against `publishedAt`, as the existing
 * `SkillConsolidationService::isBehind()` correctly does for the PUBLISH direction —
 * reviews as sensible and never fires on an instance that only installs, because
 * `publishedAt` is empty there forever. A test that only covers the happy path would
 * pass against that implementation, so there is an explicit case pinning the clock.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/skill-install-idempotency/specs/skills-marketplace/spec.md
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service;

use OCA\Hermiq\Service\SkillUpsertPolicy;
use PHPUnit\Framework\TestCase;

/**
 * Tests for what an update from a bundle may overwrite.
 */
class SkillUpsertPolicyTest extends TestCase {

	/**
	 * The canonical identity url used throughout.
	 *
	 * @var string
	 */
	private const URL = 'https://github.com/OWNER/REPO/skills/example-skill';

	/**
	 * The moment this sync happens.
	 *
	 * @var string
	 */
	private const NOW = '2026-08-02T12:00:00+00:00';

	/**
	 * Subject under test.
	 *
	 * @var SkillUpsertPolicy
	 */
	private SkillUpsertPolicy $policy;

	/**
	 * Build the policy.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->policy = new SkillUpsertPolicy();

	}//end setUp()

	/**
	 * A stored skill with curated state and a learnings file.
	 *
	 * @param string $learnings The local learnings contents.
	 * @param string $accepted When learnings were last accepted locally.
	 * @param string $synced When the skill was last synced from source.
	 *
	 * @return array<string,mixed>
	 */
	private function stored(string $learnings = 'LOCAL', string $accepted = '', string $synced = ''): array {
		return [
			'name' => 'example-skill',
			'body' => 'OLD BODY',
			'description' => 'old',
			'frontmatter' => ['a' => 1],
			'files' => [['name' => 'learnings.md', 'contents' => $learnings]],
			'state' => 'active',
			'maturityLevel' => 3,
			'targetLevel' => 4,
			'levelEvidence' => ['some' => 'evidence'],
			'installedOn' => ['agent-1', 'agent-2'],
			'createdBy' => 'alice',
			'publishedAt' => '2026-01-01T00:00:00+00:00',
			'archivedAt' => '',
			'lastAcceptedVersionAt' => $accepted,
			'sourceUpdatedAt' => $synced,
		];

	}//end stored()

	/**
	 * Incoming bundle content.
	 *
	 * @param string $body The incoming body.
	 * @param string $learnings The incoming learnings contents.
	 *
	 * @return array<string,mixed>
	 */
	private function incoming(string $body = 'NEW BODY', string $learnings = 'UPSTREAM'): array {
		return [
			'body' => $body,
			'description' => 'new',
			'frontmatter' => ['a' => 2],
			'files' => [['name' => 'learnings.md', 'contents' => $learnings]],
		];

	}//end incoming()

	/**
	 * Curated state survives an update untouched.
	 *
	 * @return void
	 */
	public function testCurationSurvivesAnUpdate(): void {
		$result = $this->policy->merge(
			existing: $this->stored(),
			incoming: $this->incoming(),
			sourceUrl: self::URL,
			now: self::NOW
		);

		$p = $result['payload'];
		self::assertSame('NEW BODY', $p['body']);
		self::assertSame(3, $p['maturityLevel']);
		self::assertSame(4, $p['targetLevel']);
		self::assertSame(['some' => 'evidence'], $p['levelEvidence']);
		self::assertSame(['agent-1', 'agent-2'], $p['installedOn']);
		self::assertSame('alice', $p['createdBy']);
		self::assertSame('2026-01-01T00:00:00+00:00', $p['publishedAt']);

	}//end testCurationSurvivesAnUpdate()

	/**
	 * A content change re-quarantines an approved skill.
	 *
	 * @return void
	 */
	public function testContentChangeReQuarantines(): void {
		$result = $this->policy->merge(
			existing: $this->stored(),
			incoming: $this->incoming(),
			sourceUrl: self::URL,
			now: self::NOW
		);

		self::assertTrue($result['changed']);
		self::assertSame('quarantined', $result['payload']['state']);
		self::assertNotSame('', (string)$result['payload']['quarantineReason']);

	}//end testContentChangeReQuarantines()

	/**
	 * Identical content leaves the state alone and reports no change.
	 *
	 * @return void
	 */
	public function testIdenticalContentLeavesStateUntouched(): void {
		$stored = $this->stored(learnings: 'SAME');
		$incoming = [
			'body' => $stored['body'],
			'description' => $stored['description'],
			'frontmatter' => $stored['frontmatter'],
			'files' => $stored['files'],
		];

		$result = $this->policy->merge(
			existing: $stored,
			incoming: $incoming,
			sourceUrl: self::URL,
			now: self::NOW
		);

		self::assertFalse($result['changed']);
		self::assertSame('active', $result['payload']['state']);

	}//end testIdenticalContentLeavesStateUntouched()

	/**
	 * Local learnings accepted since the last sync are kept, and the rest of the
	 * update still lands.
	 *
	 * @return void
	 */
	public function testLocalLearningsAreKeptAndTheRestStillUpdates(): void {
		$result = $this->policy->merge(
			existing: $this->stored(
				learnings: 'LOCAL',
				accepted: '2026-07-30T00:00:00+00:00',
				synced: '2026-07-01T00:00:00+00:00'
			),
			incoming: $this->incoming(learnings: 'UPSTREAM'),
			sourceUrl: self::URL,
			now: self::NOW
		);

		self::assertTrue($result['learningsKept']);
		self::assertSame('LOCAL', $result['payload']['files'][0]['contents']);
		self::assertSame('NEW BODY', $result['payload']['body']);

	}//end testLocalLearningsAreKeptAndTheRestStillUpdates()

	/**
	 * A skill nobody has taught anything takes the incoming learnings.
	 *
	 * @return void
	 */
	public function testWithoutLocalLearningsTheIncomingOnesAreTaken(): void {
		$result = $this->policy->merge(
			existing: $this->stored(
				learnings: 'LOCAL',
				accepted: '2026-07-01T00:00:00+00:00',
				synced: '2026-07-30T00:00:00+00:00'
			),
			incoming: $this->incoming(learnings: 'UPSTREAM'),
			sourceUrl: self::URL,
			now: self::NOW
		);

		self::assertFalse($result['learningsKept']);
		self::assertSame('UPSTREAM', $result['payload']['files'][0]['contents']);

	}//end testWithoutLocalLearningsTheIncomingOnesAreTaken()

	/**
	 * The learnings clock is `sourceUpdatedAt`, NOT `publishedAt`.
	 *
	 * This pins the specific wrong implementation: on an instance that only
	 * installs, `publishedAt` is empty, so a guard comparing against it never fires.
	 * Here `publishedAt` is deliberately set LATER than the accepted learnings while
	 * `sourceUpdatedAt` is earlier — an implementation using `publishedAt` would
	 * conclude "no local learnings" and destroy them.
	 *
	 * @return void
	 */
	public function testTheLearningsClockIsSourceUpdatedAtNotPublishedAt(): void {
		$stored = $this->stored(
			learnings: 'LOCAL',
			accepted: '2026-07-30T00:00:00+00:00',
			synced: '2026-07-01T00:00:00+00:00'
		);
		$stored['publishedAt'] = '2026-12-31T00:00:00+00:00';

		$result = $this->policy->merge(
			existing: $stored,
			incoming: $this->incoming(learnings: 'UPSTREAM'),
			sourceUrl: self::URL,
			now: self::NOW
		);

		self::assertTrue($result['learningsKept'], 'publishedAt must not be used as the sync clock');
		self::assertSame('LOCAL', $result['payload']['files'][0]['contents']);

	}//end testTheLearningsClockIsSourceUpdatedAtNotPublishedAt()

	/**
	 * Identity and the refresh clock are stamped; the acceptance clock is not.
	 *
	 * @return void
	 */
	public function testIdentityAndRefreshClockAreStamped(): void {
		$result = $this->policy->merge(
			existing: $this->stored(accepted: '2026-07-30T00:00:00+00:00'),
			incoming: $this->incoming(),
			sourceUrl: self::URL,
			now: self::NOW
		);

		self::assertSame(self::URL, $result['payload']['sourceUrl']);
		self::assertSame(self::NOW, $result['payload']['sourceUpdatedAt']);
		self::assertSame('2026-07-30T00:00:00+00:00', $result['payload']['lastAcceptedVersionAt']);

	}//end testIdentityAndRefreshClockAreStamped()

}//end class
