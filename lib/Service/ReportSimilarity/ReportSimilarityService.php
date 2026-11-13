<?php

/**
 * Hermiq ReportSimilarityService.
 *
 * Two hundred meldingen about one street-wide power cut are one event. Deciding that
 * is a similarity judgement over free text, which is hermiq's. Deciding what a group
 * then means, one case with two hundred reporters or two hundred cases under a
 * parent, is a statutory question about acknowledgement duties and archiving, and it
 * is not.
 *
 * So this service answers and does not act. It creates no case, merges nothing and
 * deletes nothing. A group is a set of references with scores: every report in it
 * stays a complete record of its own, with its own reporter, so removing a member
 * leaves that report standing alone and nothing has to be recovered to undo a
 * grouping.
 *
 * Two rules keep the judgement honest. A deterministic key the owning app supplies is
 * preferred over the model wherever it reaches, and the group records which decided
 * each membership. And the answer has three bands rather than two: above the upper
 * threshold it is the same event, below the lower it is not, and between them it is a
 * near-duplicate, attached and flagged, so a human sees it beside the group rather
 * than buried in it or lost from it.
 *
 * @category Service
 * @package  OCA\Hermiq\Service\ReportSimilarity
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
 * @spec openspec/specs/report-similarity/spec.md#requirement-hermiq-must-answer-which-group-a-report-belongs-to-and-must-not-act-on-the-answer
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service\ReportSimilarity;

use DateTimeImmutable;
use DateTimeZone;
use OCA\Hermiq\Kernel\Contracts\ReportSimilarityServiceInterface;
use OCA\OpenRegister\Db\AuditTrail\ObjectAuditEvent;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Answers which group an incoming report belongs to, and why.
 *
 * @spec openspec/specs/report-similarity/spec.md
 */
class ReportSimilarityService implements ReportSimilarityServiceInterface {

	/**
	 * OpenRegister register slug that holds Hermiq objects.
	 *
	 * @var string
	 */
	private const REGISTER_SLUG = 'hermiq';

	/**
	 * Schema slug for the report groups.
	 *
	 * @var string
	 */
	private const SCHEMA_SLUG = 'agentreportgroup';

	/**
	 * The audit action each similarity judgement is recorded under, so a grouping
	 * is auditable like any other model output.
	 *
	 * @var string
	 */
	public const AUDIT_ACTION = 'report-similarity';

	/**
	 * A membership the owning app's deterministic key decided.
	 *
	 * @var string
	 */
	public const DECIDED_BY_KEY = 'key';

	/**
	 * A membership the similarity judgement decided.
	 *
	 * @var string
	 */
	public const DECIDED_BY_MODEL = 'model';

	/**
	 * Constructor.
	 *
	 * @param ObjectService $objectService OpenRegister single read/write path.
	 * @param ReportSimilaritySettings $settings The thresholds and the windows.
	 * @param TermOverlapScorer $scorer The default similarity judgement.
	 * @param AuditTrailMapper $auditTrailMapper Records each judgement as a run.
	 * @param LoggerInterface $logger Logger.
	 */
	public function __construct(
		private readonly ObjectService $objectService,
		private readonly ReportSimilaritySettings $settings,
		private readonly TermOverlapScorer $scorer,
		private readonly AuditTrailMapper $auditTrailMapper,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Answer which group a report belongs to, or that it starts one.
	 *
	 * Nothing outside hermiq is created, merged, closed or deleted by this call,
	 * and the answer deliberately carries no instruction about acknowledgement:
	 * Awb 4:3a owes every electronic request a confirmation of receipt, and two
	 * hundred people who wrote to the gemeente are owed two hundred confirmations
	 * whatever a handler's screen shows them as.
	 *
	 * @param string $reportId The owning app's identifier for the incoming report.
	 * @param string $reportType The report type, as the owning app names it.
	 * @param string $text The report's text.
	 * @param string $deterministicKey The owning app's key for this report, when it has one.
	 * @param DateTimeImmutable|null $now The moment of evaluation, for a deterministic test.
	 *
	 * @return array<string, mixed> The group, the score, what decided, and why.
	 *
	 * @spec openspec/specs/report-similarity/spec.md#scenario-two-hundred-reports-of-one-power-cut-answer-as-one-group
	 */
	public function evaluate(
		string $reportId,
		string $reportType,
		string $text,
		string $deterministicKey = '',
		?DateTimeImmutable $now = null,
	): array {
		$evaluation = new ReportEvaluation(reportId: $reportId, text: $text);
		$evaluation->reportType = $reportType;
		$evaluation->deterministicKey = $deterministicKey;
		$evaluation->at = ($now ?? new DateTimeImmutable('now', new DateTimeZone('UTC')));

		return $this->evaluateReport(evaluation: $evaluation);
	}//end evaluate()

	/**
	 * Judge the report against the open groups of its type in the window, join the
	 * best match or start a group, and record the judgement.
	 *
	 * @param ReportEvaluation $evaluation The report under evaluation.
	 *
	 * @return array<string, mixed> The group, the score, what decided, and why.
	 *
	 * @spec openspec/changes/identical-reports-collapse-into-one/specs/report-similarity/spec.md#scenario-two-hundred-reports-of-one-power-cut-answer-as-one-group
	 */
	private function evaluateReport(ReportEvaluation $evaluation): array {
		$evaluation->windowMinutes = $this->settings->windowMinutes(reportType: $evaluation->reportType);

		$match = $this->bestMatch(candidates: $this->openGroups(evaluation: $evaluation), evaluation: $evaluation);

		$answer = $this->answerFor(evaluation: $evaluation, match: $match);
		$this->record(evaluation: $evaluation, answer: $answer);

		return $answer;
	}//end evaluateReport()

	/**
	 * Join the matched group, or start one when nothing matched.
	 *
	 * @param ReportEvaluation          $evaluation The report under evaluation.
	 * @param array<string, mixed>|null $match      The best match, or null.
	 *
	 * @return array<string, mixed> The answer.
	 */
	private function answerFor(ReportEvaluation $evaluation, ?array $match): array {
		if ($match === null) {
			return $this->startGroupFor(evaluation: $evaluation);
		}

		return $this->joinGroupFor(evaluation: $evaluation, match: $match);
	}//end answerFor()

	/**
	 * Take one report out of a group. It stands alone again, unchanged, and the
	 * group's count moves. Nothing was discarded to form the group, so nothing has
	 * to be recovered to undo it.
	 *
	 * @param string $groupId The group uuid.
	 * @param string $reportId The report to remove.
	 *
	 * @return array<string, mixed>|null The group as it now stands, or null when there is no such group.
	 *
	 * @spec openspec/specs/report-similarity/spec.md#scenario-pulling-one-report-out-costs-nothing
	 */
	public function removeMember(string $groupId, string $reportId): ?array {
		$group = $this->find(groupId: $groupId);
		if ($group === null) {
			return null;
		}

		$data = $group->getObject();
		$members = ($data['members'] ?? []);
		if (is_array($members) === false) {
			$members = [];
		}

		$data['members'] = array_values(
			array_filter(
				$members,
				static fn (mixed $member): bool => (is_array($member) === true && (string)($member['reportId'] ?? '') !== $reportId)
			)
		);

		$stored = $this->objectService->saveObject(
			object: $data,
			register: self::REGISTER_SLUG,
			schema: self::SCHEMA_SLUG,
			uuid: $groupId
		);

		return $this->shape(group: $stored);
	}//end removeMember()

	/**
	 * One group, with its reasons: the terms, the window, and per member the score
	 * and what decided it.
	 *
	 * @param string $groupId The group uuid.
	 *
	 * @return array<string, mixed>|null The group, or null.
	 *
	 * @spec openspec/specs/report-similarity/spec.md#scenario-why-these-are-one-thing-is-answerable
	 */
	public function group(string $groupId): ?array {
		$group = $this->find(groupId: $groupId);
		if ($group === null) {
			return null;
		}

		return $this->shape(group: $group);
	}//end group()

	/**
	 * The best group for a report: the deterministic key where it reaches,
	 * otherwise the highest similarity at or above the lower threshold.
	 *
	 * @param array<int, ObjectEntity> $candidates The open groups in the window.
	 * @param ReportEvaluation         $evaluation The report under evaluation.
	 *
	 * @return array{group: ObjectEntity, score: float, terms: array<int, string>, scorer: string, decidedBy: string}|null
	 *         The match, or null when the report starts its own group.
	 *
	 * @spec openspec/specs/report-similarity/spec.md#scenario-the-key-decides-where-it-can
	 */
	private function bestMatch(array $candidates, ReportEvaluation $evaluation): ?array {
		return ($this->keyMatch(candidates: $candidates, deterministicKey: $evaluation->deterministicKey)
			?? $this->scoredMatch(candidates: $candidates, text: $evaluation->text));
	}//end bestMatch()

	/**
	 * The first open group carrying the report's deterministic key, or null.
	 *
	 * @param array<int, ObjectEntity> $candidates       The open groups in the window.
	 * @param string                   $deterministicKey The owning app's key, '' when it has none.
	 *
	 * @return array{group: ObjectEntity, score: float, terms: array<int, string>, scorer: string, decidedBy: string}|null
	 *
	 * @spec openspec/changes/identical-reports-collapse-into-one/specs/report-similarity/spec.md#scenario-the-key-decides-where-it-can
	 */
	private function keyMatch(array $candidates, string $deterministicKey): ?array {
		if ($deterministicKey === '') {
			return null;
		}

		foreach ($candidates as $candidate) {
			if ((string)($candidate->getObject()['deterministicKey'] ?? '') === $deterministicKey) {
				return $this->keyedMatch(candidate: $candidate);
			}
		}

		return null;
	}//end keyMatch()

	/**
	 * A match the deterministic key decided: full score, no shared terms.
	 *
	 * @param ObjectEntity $candidate The group carrying the key.
	 *
	 * @return array{group: ObjectEntity, score: float, terms: array<int, string>, scorer: string, decidedBy: string}
	 */
	private function keyedMatch(ObjectEntity $candidate): array {
		return [
			'group' => $candidate,
			'score' => 1.0,
			'terms' => [],
			'scorer' => self::DECIDED_BY_KEY,
			'decidedBy' => self::DECIDED_BY_KEY,
		];
	}//end keyedMatch()

	/**
	 * The open group most similar to the text, at or above the lower threshold;
	 * on a tie the first such group wins.
	 *
	 * @param array<int, ObjectEntity> $candidates The open groups in the window.
	 * @param string                   $text       The incoming report's text.
	 *
	 * @return array{group: ObjectEntity, score: float, terms: array<int, string>, scorer: string, decidedBy: string}|null
	 *
	 * @spec openspec/changes/identical-reports-collapse-into-one/specs/report-similarity/spec.md#scenario-the-model-covers-what-the-key-misses
	 */
	private function scoredMatch(array $candidates, string $text): ?array {
		$best = null;
		foreach ($candidates as $candidate) {
			$judgement = $this->scorer->score(left: $text, right: $this->groupText(group: $candidate));
			if ($this->beats(judgement: $judgement, best: $best) === true) {
				$best = $this->scoredMatchOf(candidate: $candidate, judgement: $judgement);
			}
		}

		return $best;
	}//end scoredMatch()

	/**
	 * Whether a judgement reaches the lower threshold and scores above the best so far.
	 *
	 * @param array<string, mixed>      $judgement The scorer's judgement.
	 * @param array<string, mixed>|null $best      The best match so far, or null.
	 *
	 * @return bool
	 */
	private function beats(array $judgement, ?array $best): bool {
		if ($judgement['score'] < $this->settings->lower()) {
			return false;
		}

		return ($best === null || $judgement['score'] > $best['score']);
	}//end beats()

	/**
	 * A match the similarity score decided.
	 *
	 * @param ObjectEntity         $candidate The group.
	 * @param array<string, mixed> $judgement The scorer's judgement (score, terms, scorer).
	 *
	 * @return array{group: ObjectEntity, score: float, terms: array<int, string>, scorer: string, decidedBy: string}
	 */
	private function scoredMatchOf(ObjectEntity $candidate, array $judgement): array {
		return [
			'group' => $candidate,
			'score' => $judgement['score'],
			'terms' => $judgement['terms'],
			'scorer' => $judgement['scorer'],
			'decidedBy' => self::DECIDED_BY_MODEL,
		];
	}//end scoredMatchOf()

	/**
	 * Start a new group for a report nothing in the window matched.
	 *
	 * @param ReportEvaluation $evaluation The report under evaluation.
	 *
	 * @return array<string, mixed> The answer.
	 *
	 * @spec openspec/changes/identical-reports-collapse-into-one/specs/report-similarity/spec.md#scenario-a-clearly-separate-report-stands-alone
	 */
	private function startGroupFor(ReportEvaluation $evaluation): array {
		return $this->startGroup(
			reportId: $evaluation->reportId,
			reportType: $evaluation->reportType,
			text: $evaluation->text,
			deterministicKey: $evaluation->deterministicKey,
			windowMinutes: $evaluation->windowMinutes,
			at: $evaluation->at
		);
	}//end startGroupFor()

	/**
	 * Attach the report to the group it matched.
	 *
	 * @param ReportEvaluation     $evaluation The report under evaluation.
	 * @param array<string, mixed> $match      The match (group, score, terms, scorer, decidedBy).
	 *
	 * @return array<string, mixed> The answer.
	 *
	 * @spec openspec/changes/identical-reports-collapse-into-one/specs/report-similarity/spec.md#scenario-a-near-duplicate-is-visible-not-buried
	 */
	private function joinGroupFor(ReportEvaluation $evaluation, array $match): array {
		return $this->joinGroup(
			group: $match['group'],
			reportId: $evaluation->reportId,
			score: $match['score'],
			terms: $match['terms'],
			scorer: $match['scorer'],
			decidedBy: $match['decidedBy'],
			at: $evaluation->at
		);
	}//end joinGroupFor()

	/**
	 * Start a new group for a report nothing in the window matched.
	 *
	 * @param string $reportId The report.
	 * @param string $reportType The report type.
	 * @param string $text The report's text.
	 * @param string $deterministicKey The owning app's key, when it has one.
	 * @param int $windowMinutes The window in force.
	 * @param DateTimeImmutable $at The moment.
	 *
	 * @return array<string, mixed> The answer.
	 *
	 * @spec openspec/specs/report-similarity/spec.md#scenario-a-clearly-separate-report-stands-alone
	 */
	private function startGroup(
		string $reportId,
		string $reportType,
		string $text,
		string $deterministicKey,
		int $windowMinutes,
		DateTimeImmutable $at,
	): array {
		$decidedBy = self::DECIDED_BY_KEY;
		if ($deterministicKey === '') {
			$decidedBy = self::DECIDED_BY_MODEL;
		}

		$data = [
			'reportType' => $reportType,
			'deterministicKey' => $deterministicKey,
			'windowMinutes' => $windowMinutes,
			'terms' => $this->scorer->terms(text: $text),
			'members' => [
				[
					'reportId' => $reportId,
					'score' => 1.0,
					'decidedBy' => $decidedBy,
					'scorer' => TermOverlapScorer::NAME,
					'uncertain' => false,
					'joinedAt' => $at->format('c'),
				],
			],
		];

		$stored = $this->objectService->saveObject(
			object: $data,
			register: self::REGISTER_SLUG,
			schema: self::SCHEMA_SLUG
		);

		return array_merge(
			$this->shape(group: $stored),
			['newGroup' => true, 'score' => 1.0, 'uncertain' => false]
		);

	}//end startGroup()

	/**
	 * Attach a report to a group, flagging it as a near-duplicate when its score
	 * fell between the two thresholds.
	 *
	 * @param ObjectEntity $group The group.
	 * @param string $reportId The report.
	 * @param float $score The score.
	 * @param array<int, string> $terms The shared terms.
	 * @param string $scorer Which judgement produced the score.
	 * @param string $decidedBy Whether the key or the model decided.
	 * @param DateTimeImmutable $at The moment.
	 *
	 * @return array<string, mixed> The answer.
	 *
	 * @spec openspec/specs/report-similarity/spec.md#scenario-a-near-duplicate-is-visible-not-buried
	 */
	private function joinGroup(
		ObjectEntity $group,
		string $reportId,
		float $score,
		array $terms,
		string $scorer,
		string $decidedBy,
		DateTimeImmutable $at,
	): array {
		$uncertain = ($decidedBy === self::DECIDED_BY_MODEL && $score < $this->settings->upper());

		$data = $group->getObject();
		$members = ($data['members'] ?? []);
		if (is_array($members) === false) {
			$members = [];
		}

		$members[] = [
			'reportId' => $reportId,
			'score' => $score,
			'decidedBy' => $decidedBy,
			'scorer' => $scorer,
			'uncertain' => $uncertain,
			'joinedAt' => $at->format('c'),
		];

		$data['members'] = $members;

		if ($terms !== []) {
			// The group's terms narrow to what its members actually share, rather
			// than accumulating every word any member happened to use. That is both
			// the honest answer to "why are these one thing" and the reason a group
			// does not slowly fill up with one reporter's incidental wording.
			$data['terms'] = array_values(array_intersect(array_map('strval', (array)($data['terms'] ?? [])), $terms));
		}

		$stored = $this->objectService->saveObject(
			object: $data,
			register: self::REGISTER_SLUG,
			schema: self::SCHEMA_SLUG,
			uuid: (string)$group->getUuid()
		);

		return array_merge(
			$this->shape(group: $stored),
			['newGroup' => false, 'score' => $score, 'uncertain' => $uncertain]
		);

	}//end joinGroup()

	/**
	 * Shape one group for a reader: the count, the near-duplicates beside it, and
	 * the reasons.
	 *
	 * @param ObjectEntity $group The group object.
	 *
	 * @return array<string, mixed> The group.
	 *
	 * @spec openspec/specs/report-similarity/spec.md#scenario-why-these-are-one-thing-is-answerable
	 */
	private function shape(ObjectEntity $group): array {
		$data = $group->getObject();
		$members = ($data['members'] ?? []);
		if (is_array($members) === false) {
			$members = [];
		}

		$nearDuplicates = array_values(
			array_filter(
				$members,
				static fn (mixed $member): bool => (is_array($member) === true && ($member['uncertain'] ?? false) === true)
			)
		);

		return [
			'groupId' => (string)($group->getUuid() ?? ''),
			'reportType' => (string)($data['reportType'] ?? ''),
			'deterministicKey' => (string)($data['deterministicKey'] ?? ''),
			'windowMinutes' => (int)($data['windowMinutes'] ?? 0),
			'terms' => (array)($data['terms'] ?? []),
			'members' => $members,
			'count' => count($members),
			'nearDuplicates' => $nearDuplicates,
		];

	}//end shape()

	/**
	 * The open groups of the report's type: those that gained a member inside the window.
	 *
	 * @param ReportEvaluation $evaluation The report under evaluation.
	 *
	 * @return array<int, ObjectEntity> The candidate groups.
	 *
	 * @spec openspec/specs/report-similarity/spec.md#scenario-an-old-report-is-out-of-scope
	 */
	private function openGroups(ReportEvaluation $evaluation): array {
		$evaluation->cutoff = $evaluation->at->modify(sprintf('-%d minutes', $evaluation->windowMinutes));
		$objects = $this->groupsOfType(reportType: $evaluation->reportType);

		return array_values(
			array_filter(
				$objects,
				fn (mixed $object): bool => $this->isOpenGroup(object: $object, evaluation: $evaluation)
			)
		);
	}//end openGroups()

	/**
	 * The stored groups filtered to a report type (none when they cannot be read).
	 *
	 * @param string $reportType The report type.
	 *
	 * @return array<int|string, mixed> The stored objects.
	 */
	private function groupsOfType(string $reportType): array {
		try {
			return $this->objectService
				->setRegister(self::REGISTER_SLUG)
				->setSchema(self::SCHEMA_SLUG)
				->findAll(config: ['filters' => ['reportType' => $reportType], 'limit' => 200]);
		} catch (Throwable $e) {
			$this->logger->warning(
				'Hermiq could not read the report groups: ' . $e->getMessage(),
				['exception' => $e, 'reportType' => $reportType]
			);

			return [];
		}
	}//end groupsOfType()

	/**
	 * Whether an object is a group of the report's type that is still open.
	 *
	 * @param mixed            $object     A stored object.
	 * @param ReportEvaluation $evaluation The report under evaluation.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/identical-reports-collapse-into-one/specs/report-similarity/spec.md#scenario-an-old-report-is-out-of-scope
	 */
	private function isOpenGroup(mixed $object, ReportEvaluation $evaluation): bool {
		if (($object instanceof ObjectEntity) === false) {
			return false;
		}

		$data = $object->getObject();
		if ((string)($data['reportType'] ?? '') !== $evaluation->reportType) {
			return false;
		}

		return ($this->lastJoinedAt(data: $data) < $evaluation->cutoff) === false;
	}//end isOpenGroup()

	/**
	 * When a group last gained a member, which is what keeps it open. A group with
	 * no readable timestamp is treated as beginning of time and therefore closed:
	 * failing closed here means an extra group, never a wrong merge.
	 *
	 * @param array<string, mixed> $data The group's data.
	 *
	 * @return DateTimeImmutable The moment.
	 */
	private function lastJoinedAt(array $data): DateTimeImmutable {
		$latest = new DateTimeImmutable('@0');

		foreach ((array)($data['members'] ?? []) as $member) {
			if (is_array($member) === false) {
				continue;
			}

			$joinedAt = (string)($member['joinedAt'] ?? '');
			if ($joinedAt === '') {
				continue;
			}

			try {
				$moment = new DateTimeImmutable($joinedAt);
			} catch (Throwable $e) {
				continue;
			}

			if ($moment > $latest) {
				$latest = $moment;
			}
		}//end foreach

		return $latest;
	}//end lastJoinedAt()

	/**
	 * The text a group is compared against: the terms it already carries, which is
	 * what its members had in common. Comparing against the accumulated terms
	 * rather than one member's text keeps a group from drifting towards whichever
	 * report happened to arrive first.
	 *
	 * @param ObjectEntity $group The group.
	 *
	 * @return string The text to score against.
	 */
	private function groupText(ObjectEntity $group): string {
		return implode(' ', array_map('strval', (array)($group->getObject()['terms'] ?? [])));
	}//end groupText()

	/**
	 * One group by uuid.
	 *
	 * @param string $groupId The group uuid.
	 *
	 * @return ObjectEntity|null The group, or null.
	 */
	private function find(string $groupId): ?ObjectEntity {
		if ($groupId === '') {
			return null;
		}

		try {
			return $this->objectService->find(
				id: $groupId,
				register: self::REGISTER_SLUG,
				schema: self::SCHEMA_SLUG
			);
		} catch (Throwable $e) {
			return null;
		}

	}//end find()

	/**
	 * Record one judgement on the audit trail, like any other model output.
	 *
	 * Non-fatal by contract: a failed audit write never fails the judgement, in
	 * line with every other audit write in this app.
	 *
	 * @param ReportEvaluation     $evaluation The report judged.
	 * @param array<string, mixed> $answer     The answer given.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/report-similarity/spec.md#scenario-each-judgement-is-on-the-audit-trail
	 */
	private function record(ReportEvaluation $evaluation, array $answer): void {
		try {
			$this->auditTrailMapper->recordObjectEvent(event: $this->judgementEvent(evaluation: $evaluation, answer: $answer));
		} catch (Throwable $e) {
			$this->logger->warning(
				'Hermiq could not record a report-similarity judgement: ' . $e->getMessage(),
				['exception' => $e]
			);
		}

	}//end record()

	/**
	 * The judgement's audit event, on a marker for the group it answered with.
	 *
	 * @param ReportEvaluation     $evaluation The report judged.
	 * @param array<string, mixed> $answer     The answer given.
	 *
	 * @return ObjectAuditEvent
	 */
	private function judgementEvent(ReportEvaluation $evaluation, array $answer): ObjectAuditEvent {
		$marker = new ObjectEntity();
		$marker->setUuid((string)($answer['groupId'] ?? 'report-similarity'));

		return new ObjectAuditEvent(
			object: $marker,
			action: self::AUDIT_ACTION,
			context: $this->judgementContext(evaluation: $evaluation, answer: $answer)
		);
	}//end judgementEvent()

	/**
	 * What the audit entry records about one judgement.
	 *
	 * @param ReportEvaluation     $evaluation The report judged.
	 * @param array<string, mixed> $answer     The answer given.
	 *
	 * @return array<string, mixed>
	 */
	private function judgementContext(ReportEvaluation $evaluation, array $answer): array {
		return [
			'reportId' => $evaluation->reportId,
			'reportType' => $evaluation->reportType,
			'groupId' => (string)($answer['groupId'] ?? ''),
			'score' => ($answer['score'] ?? null),
			'uncertain' => ($answer['uncertain'] ?? false),
			'newGroup' => ($answer['newGroup'] ?? false),
			'windowMinutes' => $evaluation->windowMinutes,
			'terms' => ($answer['terms'] ?? []),
		];
	}//end judgementContext()
}//end class
