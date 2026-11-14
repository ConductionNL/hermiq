<?php

/**
 * The curator proposes merging duplicate skills; a person approves or rejects.
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
 * @spec openspec/changes/skills-curator-merges-duplicates/specs/skills-marketplace/spec.md#requirement-the-curator-shall-propose-merging-duplicate-skills
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service;

use DateTime;
use OCA\Hermiq\Service\SkillMergeService;
use OCA\Hermiq\Service\SkillService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Detection, approval, rejection, and that nothing is ever deleted.
 *
 * @covers \OCA\Hermiq\Service\SkillMergeService
 */
class SkillMergeServiceTest extends TestCase {

	/**
	 * The skills in the store, by uuid.
	 *
	 * @var array<string, array{data: array<string, mixed>, created: string}>
	 */
	private array $skills = [];

	/**
	 * Agent moves the service asked for, as verb:skill:agent.
	 *
	 * @var array<int, string>
	 */
	private array $moves = [];

	/**
	 * Saves without a session check, by uuid.
	 *
	 * @var array<int, string>
	 */
	private array $systemSaves = [];

	/**
	 * A skill in the store.
	 *
	 * @param string               $uuid    The uuid.
	 * @param array<string, mixed> $data    The skill.
	 * @param string               $created When it was created.
	 *
	 * @return void
	 */
	private function skill(string $uuid, array $data, string $created = '2026-01-01T00:00:00Z'): void {
		$this->skills[$uuid] = ['data' => $data + ['state' => 'active', 'installedOn' => []], 'created' => $created];
	}//end skill()

	/**
	 * An entity of a stored skill.
	 *
	 * @param string $uuid The uuid.
	 *
	 * @return ObjectEntity
	 */
	private function entity(string $uuid): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid($uuid);
		$entity->setObject($this->skills[$uuid]['data']);
		$entity->setCreated(new DateTime($this->skills[$uuid]['created']));
		return $entity;
	}//end entity()

	/**
	 * The service over an in-memory store.
	 *
	 * @return SkillMergeService
	 */
	private function service(): SkillMergeService {
		$objects = $this->createMock(ObjectService::class);
		$objects->method('setRegister')->willReturnSelf();
		$objects->method('setSchema')->willReturnSelf();
		$objects->method('findAll')->willReturnCallback(fn (): array => array_map(fn (string $u): ObjectEntity => $this->entity(uuid: $u), array_keys($this->skills)));
		$objects->method('saveObject')->willReturnCallback(
			function (array $object, ?array $extend = [], mixed $register = null, mixed $schema = null, ?string $uuid = null, bool $_rbac = true, bool $_multitenancy = true): ObjectEntity {
				$this->skills[(string)$uuid]['data'] = $object;
				if ($_rbac === false) {
					$this->systemSaves[] = (string)$uuid;
				}

				return $this->entity(uuid: (string)$uuid);
			}
		);
		$objects->expects($this->never())->method('deleteObject');

		$skills = $this->createMock(SkillService::class);
		$skills->method('getSkill')->willReturnCallback(fn (string $skillId): ?ObjectEntity => isset($this->skills[$skillId]) === true ? $this->entity(uuid: $skillId) : null);
		$skills->method('installOnAgent')->willReturnCallback(
			function (string $skillId, string $agentId): ObjectEntity {
				$this->moves[] = 'install:' . $skillId . ':' . $agentId;
				$this->skills[$skillId]['data']['installedOn'][] = $agentId;
				return $this->entity(uuid: $skillId);
			}
		);
		$skills->method('uninstallFromAgent')->willReturnCallback(
			function (string $skillId, string $agentId): ObjectEntity {
				$this->moves[] = 'uninstall:' . $skillId . ':' . $agentId;
				$this->skills[$skillId]['data']['installedOn'] = array_values(array_diff($this->skills[$skillId]['data']['installedOn'], [$agentId]));
				return $this->entity(uuid: $skillId);
			}
		);

		return new SkillMergeService(objectService: $objects, skillService: $skills, logger: new NullLogger());
	}//end service()

	/**
	 * Two duplicate skills get one proposal naming the one that stays (the one
	 * on more agents) and the one that goes; both stay active, and an
	 * unrelated or archived skill is left alone.
	 *
	 * @return void
	 */
	public function testTwoDuplicateSkillsGetOneProposal(): void {
		$this->skill(uuid: 'a', data: ['name' => 'Woo triage', 'body' => 'Sort requests.', 'installedOn' => ['agent-1', 'agent-2']], created: '2026-03-01T00:00:00Z');
		$this->skill(uuid: 'b', data: ['name' => ' woo-TRIAGE! ', 'body' => 'Other words.', 'installedOn' => ['agent-3']]);
		$this->skill(uuid: 'c', data: ['name' => 'Minutes', 'body' => 'Write minutes.']);
		$this->skill(uuid: 'd', data: ['name' => 'Woo triage', 'state' => 'archived']);

		$summary = $this->service()->proposeMerges();

		self::assertSame(['scanned' => 4, 'proposed' => 1], $summary);
		self::assertSame('a', $this->skills['b']['data']['mergeProposal']['survivor']);
		self::assertSame('same-name', $this->skills['b']['data']['mergeProposal']['reason']);
		self::assertArrayNotHasKey('mergeProposal', $this->skills['a']['data']);
		self::assertArrayNotHasKey('mergeProposal', $this->skills['d']['data']);
		self::assertSame('active', $this->skills['a']['data']['state']);
		self::assertSame('active', $this->skills['b']['data']['state']);
		self::assertSame(['b'], $this->systemSaves, 'the curator writes the proposal only');
	}//end testTwoDuplicateSkillsGetOneProposal()

	/**
	 * The same instructions with other names are duplicates too; on equal use
	 * the older skill stays, and on equal age the lower uuid.
	 *
	 * @return void
	 */
	public function testTheSameInstructionsAreADuplicateAndTheOlderStays(): void {
		$this->skill(uuid: 'n', data: ['name' => 'Newer', 'body' => "Read  the CASE\nfirst."], created: '2026-05-01T00:00:00Z');
		$this->skill(uuid: 'o', data: ['name' => 'Older', 'body' => 'read the case first.'], created: '2026-02-01T00:00:00Z');
		$this->service()->proposeMerges();
		self::assertSame(['survivor' => 'o', 'reason' => 'same-instructions'], array_intersect_key($this->skills['n']['data']['mergeProposal'], ['survivor' => 1, 'reason' => 1]));

		$this->skills = [];
		$this->skill(uuid: 'y', data: ['name' => 'Same']);
		$this->skill(uuid: 'x', data: ['name' => 'Same']);
		$this->service()->proposeMerges();
		self::assertSame('x', $this->skills['y']['data']['mergeProposal']['survivor']);

		$this->skills = [];
		$this->skill(uuid: 'e', data: ['name' => 'Empty one', 'body' => '']);
		$this->skill(uuid: 'f', data: ['name' => 'Empty two', 'body' => '  ']);
		self::assertSame(0, $this->service()->proposeMerges()['proposed'], 'empty instructions say nothing');
	}//end testTheSameInstructionsAreADuplicateAndTheOlderStays()

	/**
	 * A pending pair is not proposed twice, and a rejected pair changes
	 * nothing and is not proposed again.
	 *
	 * @return void
	 */
	public function testARejectedMergeChangesNothingAndIsNotProposedAgain(): void {
		$this->skill(uuid: 'a', data: ['name' => 'Same', 'installedOn' => ['agent-1']]);
		$this->skill(uuid: 'b', data: ['name' => 'Same']);
		$service = $this->service();
		self::assertSame(1, $service->proposeMerges()['proposed']);
		self::assertSame(0, $service->proposeMerges()['proposed'], 'a pending proposal is not repeated');

		self::assertSame(['rejected' => true], $service->rejectMerge(duplicateId: 'b'));
		self::assertNull($this->skills['b']['data']['mergeProposal']);
		self::assertSame(['a'], $this->skills['b']['data']['mergeRejectedWith']);
		self::assertSame('active', $this->skills['a']['data']['state']);
		self::assertSame('active', $this->skills['b']['data']['state']);
		self::assertSame(['agent-1'], $this->skills['a']['data']['installedOn']);
		self::assertSame([], $this->moves);

		self::assertSame(0, $service->proposeMerges()['proposed'], 'a rejected pair is not proposed again');
		self::assertSame(['rejected' => false, 'error' => 'no_proposal'], $service->rejectMerge(duplicateId: 'b'));
	}//end testARejectedMergeChangesNothingAndIsNotProposedAgain()

	/**
	 * An approved merge moves the duplicate's agents to the survivor and
	 * archives the duplicate with a pointer to it; nothing is deleted.
	 *
	 * @return void
	 */
	public function testAnApprovedMergeArchivesTheDuplicateAndMovesItsAgents(): void {
		$this->skill(uuid: 'a', data: ['name' => 'Same', 'installedOn' => ['agent-1', 'agent-2']]);
		$this->skill(uuid: 'b', data: ['name' => 'Same', 'installedOn' => ['agent-3']]);
		$service = $this->service();
		$service->proposeMerges();

		$result = $service->approveMerge(duplicateId: 'b');

		self::assertSame(['merged' => true, 'survivor' => 'a', 'agentsMoved' => 1], $result);
		self::assertSame(['install:a:agent-3', 'uninstall:b:agent-3'], $this->moves);
		self::assertSame(['agent-1', 'agent-2', 'agent-3'], $this->skills['a']['data']['installedOn']);
		self::assertSame('active', $this->skills['a']['data']['state']);
		$archived = $this->skills['b']['data'];
		self::assertSame('archived', $archived['state']);
		self::assertSame('a', $archived['mergedInto']);
		self::assertNull($archived['mergeProposal']);
		self::assertSame([], $archived['installedOn']);
		self::assertNotEmpty($archived['archivedAt']);
		self::assertSame('Same', $archived['name'], 'the archived skill keeps its content');
		self::assertArrayHasKey('b', $this->skills, 'the duplicate still exists');
	}//end testAnApprovedMergeArchivesTheDuplicateAndMovesItsAgents()

	/**
	 * A decision needs a skill with a pending proposal and a survivor that is
	 * still there.
	 *
	 * @return void
	 */
	public function testADecisionNeedsAPendingProposalAndASurvivor(): void {
		$this->skill(uuid: 'b', data: ['name' => 'Same', 'mergeProposal' => ['survivor' => 'gone']]);
		$this->skill(uuid: 'c', data: ['name' => 'Plain']);
		$this->skill(uuid: 'd', data: ['name' => 'Dup', 'mergeProposal' => ['survivor' => 'e']]);
		$this->skill(uuid: 'e', data: ['name' => 'Dup', 'state' => 'archived']);
		$service = $this->service();

		self::assertSame(['merged' => false, 'error' => 'not_found'], $service->approveMerge(duplicateId: 'nope'));
		self::assertSame(['rejected' => false, 'error' => 'not_found'], $service->rejectMerge(duplicateId: 'nope'));
		self::assertSame(['merged' => false, 'error' => 'no_proposal'], $service->approveMerge(duplicateId: 'c'));
		self::assertSame(['merged' => false, 'error' => 'survivor_gone'], $service->approveMerge(duplicateId: 'b'));
		self::assertSame(['merged' => false, 'error' => 'survivor_gone'], $service->approveMerge(duplicateId: 'd'));
		self::assertSame('active', $this->skills['b']['data']['state']);
		self::assertSame([], $this->moves);
	}//end testADecisionNeedsAPendingProposalAndASurvivor()

	/**
	 * The pending proposals list the skill that goes and the one that stays with
	 * their agent counts, newest first; a decided proposal and a proposal whose
	 * survivor is gone are not listed.
	 *
	 * @return void
	 */
	public function testPendingMergesListTheUndecidedProposalsNewestFirst(): void {
		$this->skill(uuid: 'keep-1', data: ['name' => 'Woo triage', 'description' => 'Sorts requests', 'installedOn' => ['agent-1', 'agent-2']]);
		$this->skill(uuid: 'go-1', data: ['name' => 'woo triage', 'description' => 'Copy', 'installedOn' => ['agent-3'], 'mergeProposal' => ['survivor' => 'keep-1', 'reason' => 'same-name', 'proposedAt' => '2026-11-01T10:00:00+00:00']]);
		$this->skill(uuid: 'keep-2', data: ['name' => 'Mail']);
		$this->skill(uuid: 'go-2', data: ['name' => 'Mail copy', 'mergeProposal' => ['survivor' => 'keep-2', 'reason' => 'same-instructions', 'proposedAt' => '2026-11-02T10:00:00+00:00']]);
		$this->skill(uuid: 'decided', data: ['name' => 'Old', 'mergeProposal' => null, 'mergedInto' => 'keep-1', 'state' => 'archived']);
		$this->skill(uuid: 'orphan', data: ['name' => 'Orphan', 'mergeProposal' => ['survivor' => 'gone', 'reason' => 'same-name', 'proposedAt' => '2026-11-03T10:00:00+00:00']]);

		$pending = $this->service()->pendingMerges();

		self::assertSame(
			[
				[
					'duplicate' => ['id' => 'go-2', 'name' => 'Mail copy', 'description' => '', 'agents' => 0],
					'survivor' => ['id' => 'keep-2', 'name' => 'Mail', 'description' => '', 'agents' => 0],
					'reason' => 'same-instructions',
					'proposedAt' => '2026-11-02T10:00:00+00:00',
				],
				[
					'duplicate' => ['id' => 'go-1', 'name' => 'woo triage', 'description' => 'Copy', 'agents' => 1],
					'survivor' => ['id' => 'keep-1', 'name' => 'Woo triage', 'description' => 'Sorts requests', 'agents' => 2],
					'reason' => 'same-name',
					'proposedAt' => '2026-11-01T10:00:00+00:00',
				],
			],
			$pending
		);
		self::assertSame([], $this->systemSaves);
	}//end testPendingMergesListTheUndecidedProposalsNewestFirst()
}//end class
