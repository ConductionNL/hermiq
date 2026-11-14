<?php

/**
 * Hermiq SkillMergeService.
 *
 * The curator's second job: besides moving unused skills from active to stale to
 * archived, it finds active skills that duplicate each other and proposes merging
 * them, one proposal per pair. A person decides. On approval the surviving skill
 * stays active, the duplicate is archived (never deleted) and every agent that
 * used the duplicate is moved to the survivor; on rejection nothing changes and
 * the pair is not proposed again.
 *
 * WHAT COUNTS AS A DUPLICATE. Two active skills duplicate each other when their
 * names are the same after case, punctuation and spacing are set aside, or when
 * their instructions are the same after case and spacing are set aside. Both are
 * exact, explainable rules: a person approving the merge can see why the pair was
 * proposed, and nothing is proposed on a guess.
 *
 * WHICH ONE STAYS. The skill installed on more agents, so the fewest agents move;
 * on a tie the older skill; on a tie still the lower uuid, so a pass is
 * deterministic.
 *
 * The proposal lives on the duplicate skill (`mergeProposal`), the rejected
 * partners in `mergeRejectedWith`, and the survivor of an approved merge in
 * `mergedInto`: OpenRegister objects only, no tables of hermiq's own.
 *
 * @category Service
 * @package  OCA\Hermiq\Service
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

namespace OCA\Hermiq\Service;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use OCA\Hermiq\Kernel\Contracts\SkillMergeServiceInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use Psr\Log\LoggerInterface;

/**
 * Proposes, approves and rejects merges of duplicate skills.
 *
 * @spec openspec/changes/skills-curator-merges-duplicates/specs/skills-marketplace/spec.md#requirement-the-curator-shall-propose-merging-duplicate-skills
 */
class SkillMergeService implements SkillMergeServiceInterface {

	/**
	 * The register skills live in.
	 *
	 * @var string
	 */
	private const REGISTER_SLUG = 'hermiq';

	/**
	 * The skill schema.
	 *
	 * @var string
	 */
	private const SKILL_SCHEMA = 'agentskill';

	/**
	 * Constructor.
	 *
	 * @param ObjectService   $objectService Reads and writes skills.
	 * @param SkillService    $skillService  Installs and uninstalls skills on agents, keeping both sides in sync.
	 * @param LoggerInterface $logger        Logger.
	 */
	public function __construct(
		private readonly ObjectService $objectService,
		private readonly SkillService $skillService,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Propose one merge for each pair of active skills that duplicate each other.
	 *
	 * A skill already in a pending proposal, on either side, waits for that one to
	 * be decided; a pair a person rejected is never proposed again. Both skills
	 * stay active until a person decides.
	 *
	 * @return array{scanned: int, proposed: int}
	 *
	 * @spec openspec/changes/skills-curator-merges-duplicates/specs/skills-marketplace/spec.md#requirement-the-curator-shall-propose-merging-duplicate-skills
	 */
	public function proposeMerges(): array {
		$skills = $this->loadSkills();
		$busy = [];
		foreach ($skills as $skill) {
			$proposal = self::pendingProposal(data: $skill->getObject());
			if ($proposal !== null) {
				$busy[(string)$skill->getUuid()] = true;
				$busy[$proposal['survivor']] = true;
			}
		}

		$active = array_values(array_filter($skills, static fn (ObjectEntity $s): bool => self::isActive(data: $s->getObject())));
		$proposed = 0;
		$count = count($active);
		for ($i = 0; $i < $count; $i++) {
			for ($j = ($i + 1); $j < $count; $j++) {
				$one = $active[$i];
				$two = $active[$j];
				if (isset($busy[(string)$one->getUuid()]) === true || isset($busy[(string)$two->getUuid()]) === true) {
					continue;
				}

				$reason = self::duplicateReason(one: $one->getObject(), two: $two->getObject());
				if ($reason === null || self::wasRejected(one: $one, two: $two) === true) {
					continue;
				}

				[$survivor, $duplicate] = self::survivorFirst(one: $one, two: $two);
				$this->propose(duplicate: $duplicate, survivor: $survivor, reason: $reason);
				$busy[(string)$one->getUuid()] = true;
				$busy[(string)$two->getUuid()] = true;
				$proposed++;
			}
		}//end for

		$summary = ['scanned' => count($skills), 'proposed' => $proposed];
		$this->logger->debug('Hermiq skill curator merge pass complete', $summary);

		return $summary;
	}//end proposeMerges()

	/**
	 * Approve the merge proposed on a duplicate skill: every agent that used it
	 * moves to the survivor, and the duplicate is archived with a pointer to the
	 * survivor. Nothing is deleted.
	 *
	 * @param string $duplicateId The duplicate skill's uuid.
	 *
	 * @return array{merged: bool, survivor?: string, agentsMoved?: int, error?: string}
	 *
	 * @spec openspec/changes/skills-curator-merges-duplicates/specs/skills-marketplace/spec.md#requirement-the-curator-shall-propose-merging-duplicate-skills
	 */
	public function approveMerge(string $duplicateId): array {
		$duplicate = $this->skillService->getSkill(skillId: $duplicateId);
		if ($duplicate === null) {
			return ['merged' => false, 'error' => 'not_found'];
		}

		$proposal = self::pendingProposal(data: $duplicate->getObject());
		if ($proposal === null) {
			return ['merged' => false, 'error' => 'no_proposal'];
		}

		$survivor = $this->skillService->getSkill(skillId: $proposal['survivor']);
		if ($survivor === null || (string)($survivor->getObject()['state'] ?? 'active') === 'archived') {
			return ['merged' => false, 'error' => 'survivor_gone'];
		}

		$agents = self::idList(value: ($duplicate->getObject()['installedOn'] ?? []));
		foreach ($agents as $agentId) {
			$this->skillService->installOnAgent(skillId: $proposal['survivor'], agentId: $agentId);
			$this->skillService->uninstallFromAgent(skillId: $duplicateId, agentId: $agentId);
		}

		// Read it again: uninstalling rewrote its installedOn.
		$archived = ($this->skillService->getSkill(skillId: $duplicateId) ?? $duplicate)->getObject();
		$archived['state'] = 'archived';
		$archived['archivedAt'] = self::now();
		$archived['mergedInto'] = $proposal['survivor'];
		$archived['mergeProposal'] = null;
		$this->save(data: $archived, uuid: $duplicateId);

		$this->logger->info(
			'Hermiq merged a duplicate skill into its survivor',
			['duplicate' => $duplicateId, 'survivor' => $proposal['survivor'], 'agentsMoved' => count($agents)]
		);

		return ['merged' => true, 'survivor' => $proposal['survivor'], 'agentsMoved' => count($agents)];
	}//end approveMerge()

	/**
	 * Reject the merge proposed on a duplicate skill. Both skills stay as they
	 * are; the pair is remembered so the curator does not propose it again.
	 *
	 * @param string $duplicateId The duplicate skill's uuid.
	 *
	 * @return array{rejected: bool, error?: string}
	 *
	 * @spec openspec/changes/skills-curator-merges-duplicates/specs/skills-marketplace/spec.md#requirement-the-curator-shall-propose-merging-duplicate-skills
	 */
	public function rejectMerge(string $duplicateId): array {
		$duplicate = $this->skillService->getSkill(skillId: $duplicateId);
		if ($duplicate === null) {
			return ['rejected' => false, 'error' => 'not_found'];
		}

		$data = $duplicate->getObject();
		$proposal = self::pendingProposal(data: $data);
		if ($proposal === null) {
			return ['rejected' => false, 'error' => 'no_proposal'];
		}

		$rejected = self::idList(value: ($data['mergeRejectedWith'] ?? []));
		$rejected[] = $proposal['survivor'];
		$data['mergeRejectedWith'] = array_values(array_unique($rejected));
		$data['mergeProposal'] = null;
		$this->save(data: $data, uuid: $duplicateId);

		return ['rejected' => true];
	}//end rejectMerge()

	/**
	 * The merges proposed and not yet decided, newest first: per proposal the skill
	 * that goes and the skill that stays (id, name, description and how many agents
	 * use it), why the pair was proposed and when.
	 *
	 * @return array<int, array{duplicate: array<string, mixed>, survivor: array<string, mixed>, reason: string, proposedAt: string}>
	 *
	 * @spec openspec/changes/skills-curator-merges-duplicates/specs/skills-marketplace/spec.md#scenario-two-duplicate-skills-get-one-merge-proposal
	 */
	public function pendingMerges(): array {
		$byId = [];
		foreach ($this->loadSkills() as $skill) {
			$byId[(string)$skill->getUuid()] = $skill->getObject();
		}

		$pending = [];
		foreach ($byId as $id => $data) {
			$proposal = self::pendingProposal(data: $data);
			if ($proposal === null || isset($byId[$proposal['survivor']]) === false) {
				continue;
			}

			$pending[] = [
				'duplicate' => self::summary(id: $id, data: $data),
				'survivor' => self::summary(id: $proposal['survivor'], data: $byId[$proposal['survivor']]),
				'reason' => (string)($data['mergeProposal']['reason'] ?? ''),
				'proposedAt' => (string)($data['mergeProposal']['proposedAt'] ?? ''),
			];
		}

		usort($pending, static fn (array $one, array $two): int => strcmp($two['proposedAt'], $one['proposedAt']));

		return $pending;
	}//end pendingMerges()

	/**
	 * What a merge proposal shows of one skill.
	 *
	 * @param string               $id   The skill's uuid.
	 * @param array<string, mixed> $data The skill.
	 *
	 * @return array{id: string, name: string, description: string, agents: int}
	 */
	private static function summary(string $id, array $data): array {
		return [
			'id' => $id,
			'name' => (string)($data['name'] ?? ''),
			'description' => (string)($data['description'] ?? ''),
			'agents' => count(self::idList(value: ($data['installedOn'] ?? []))),
		];
	}//end summary()

	/**
	 * Write a proposal on the duplicate.
	 *
	 * @param ObjectEntity $duplicate The skill that would go.
	 * @param ObjectEntity $survivor  The skill that would stay.
	 * @param string       $reason    Why the pair was proposed.
	 *
	 * @return void
	 */
	private function propose(ObjectEntity $duplicate, ObjectEntity $survivor, string $reason): void {
		$data = $duplicate->getObject();
		$data['mergeProposal'] = [
			'survivor' => (string)$survivor->getUuid(),
			'reason' => $reason,
			'proposedAt' => self::now(),
		];
		$this->objectService->saveObject(
			object: $data,
			register: self::REGISTER_SLUG,
			schema: self::SKILL_SCHEMA,
			uuid: (string)$duplicate->getUuid(),
			_rbac: false,
			_multitenancy: false
		);
	}//end propose()

	/**
	 * Save a decided skill as the person deciding.
	 *
	 * @param array<string, mixed> $data The skill.
	 * @param string               $uuid Its uuid.
	 *
	 * @return void
	 */
	private function save(array $data, string $uuid): void {
		$this->objectService->saveObject(
			object: $data,
			register: self::REGISTER_SLUG,
			schema: self::SKILL_SCHEMA,
			uuid: $uuid
		);
	}//end save()

	/**
	 * Every skill, across organisations, as the curator sees them.
	 *
	 * @return array<int, ObjectEntity>
	 */
	private function loadSkills(): array {
		$objects = $this->objectService
			->setRegister(self::REGISTER_SLUG)
			->setSchema(self::SKILL_SCHEMA)
			->findAll(config: ['limit' => 1000], _rbac: false, _multitenancy: false);

		return array_values(array_filter($objects, static fn (mixed $o): bool => $o instanceof ObjectEntity));
	}//end loadSkills()

	/**
	 * Why two skills duplicate each other, or null when they do not.
	 *
	 * @param array<string, mixed> $one A skill.
	 * @param array<string, mixed> $two Another skill.
	 *
	 * @return string|null `same-name` or `same-instructions`.
	 */
	private static function duplicateReason(array $one, array $two): ?string {
		$name = self::nameKey(value: ($one['name'] ?? null));
		if ($name !== '' && $name === self::nameKey(value: ($two['name'] ?? null))) {
			return 'same-name';
		}

		$body = self::bodyKey(value: ($one['body'] ?? null));
		if ($body !== '' && $body === self::bodyKey(value: ($two['body'] ?? null))) {
			return 'same-instructions';
		}

		return null;
	}//end duplicateReason()

	/**
	 * A name with case, punctuation and spacing set aside.
	 *
	 * @param mixed $value The name.
	 *
	 * @return string
	 */
	private static function nameKey(mixed $value): string {
		if (is_string($value) === false) {
			return '';
		}

		return trim((string)preg_replace('/[^\p{L}\p{N}]+/u', ' ', mb_strtolower($value)));
	}//end nameKey()

	/**
	 * Instructions with case and spacing set aside.
	 *
	 * @param mixed $value The body.
	 *
	 * @return string
	 */
	private static function bodyKey(mixed $value): string {
		if (is_string($value) === false) {
			return '';
		}

		return trim((string)preg_replace('/\s+/u', ' ', mb_strtolower($value)));
	}//end bodyKey()

	/**
	 * Whether a person rejected merging these two, in either direction.
	 *
	 * @param ObjectEntity $one A skill.
	 * @param ObjectEntity $two Another skill.
	 *
	 * @return bool
	 */
	private static function wasRejected(ObjectEntity $one, ObjectEntity $two): bool {
		return in_array((string)$two->getUuid(), self::idList(value: ($one->getObject()['mergeRejectedWith'] ?? [])), true) === true
			|| in_array((string)$one->getUuid(), self::idList(value: ($two->getObject()['mergeRejectedWith'] ?? [])), true) === true;
	}//end wasRejected()

	/**
	 * The pair with the skill that stays first.
	 *
	 * @param ObjectEntity $one A skill.
	 * @param ObjectEntity $two Another skill.
	 *
	 * @return array{0: ObjectEntity, 1: ObjectEntity}
	 */
	private static function survivorFirst(ObjectEntity $one, ObjectEntity $two): array {
		$agentsOfOne = count(self::idList(value: ($one->getObject()['installedOn'] ?? [])));
		$agentsOfTwo = count(self::idList(value: ($two->getObject()['installedOn'] ?? [])));
		$order = ($agentsOfTwo <=> $agentsOfOne);
		if ($order === 0) {
			$order = (self::createdAt(skill: $one) <=> self::createdAt(skill: $two));
		}

		if ($order === 0) {
			$order = strcmp((string)$one->getUuid(), (string)$two->getUuid());
		}

		if ($order <= 0) {
			return [$one, $two];
		}

		return [$two, $one];
	}//end survivorFirst()

	/**
	 * When a skill was created, as a timestamp; the far future when unknown.
	 *
	 * @param ObjectEntity $skill The skill.
	 *
	 * @return int
	 */
	private static function createdAt(ObjectEntity $skill): int {
		$created = $skill->getCreated();
		if ($created instanceof DateTimeInterface) {
			return $created->getTimestamp();
		}

		return PHP_INT_MAX;
	}//end createdAt()

	/**
	 * The pending proposal on a skill, or null.
	 *
	 * @param array<string, mixed> $data The skill.
	 *
	 * @return array{survivor: string}|null
	 */
	private static function pendingProposal(array $data): ?array {
		$proposal = ($data['mergeProposal'] ?? null);
		if (is_array($proposal) === false || is_string(($proposal['survivor'] ?? null)) === false || $proposal['survivor'] === '') {
			return null;
		}

		return ['survivor' => $proposal['survivor']];
	}//end pendingProposal()

	/**
	 * Whether a skill is active; a skill without a state is.
	 *
	 * @param array<string, mixed> $data The skill.
	 *
	 * @return bool
	 */
	private static function isActive(array $data): bool {
		return (string)($data['state'] ?? 'active') === 'active';
	}//end isActive()

	/**
	 * The non-empty strings of a list.
	 *
	 * @param mixed $value The list.
	 *
	 * @return array<int, string>
	 */
	private static function idList(mixed $value): array {
		if (is_array($value) === false) {
			return [];
		}

		return array_values(array_filter($value, static fn (mixed $id): bool => is_string($id) === true && $id !== ''));
	}//end idList()

	/**
	 * Now, in UTC.
	 *
	 * @return string
	 */
	private static function now(): string {
		return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('c');
	}//end now()
}//end class
