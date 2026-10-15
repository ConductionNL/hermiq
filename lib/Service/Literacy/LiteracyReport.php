<?php

/**
 * Hermiq report on Working with AI (compliance-ai-literacy).
 *
 * Completion per person in an organisation, as rows and as CSV, and the
 * evidence for the EU AI Act article 4 control: of the people who used an agent
 * in the last 90 days, how many finished the current lessons. The control's
 * status is computed here and never set by hand. Callers check that the reader
 * may administer the organisation before calling.
 *
 * @category Service
 * @package  OCA\Hermiq\Service\Literacy
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/specs/compliance-control-packs/spec.md#requirement-the-ai-act-article-4-control-reads-its-status-from-completions-req-ailit-003
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service\Literacy;

use DateTime;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;

/**
 * Completion per person and the article 4 evidence.
 *
 * @spec openspec/specs/compliance-control-packs/spec.md#requirement-the-ai-act-article-4-control-reads-its-status-from-completions-req-ailit-003
 */
class LiteracyReport {

	/**
	 * The chat session schema: a session with a person's userId means they used an agent.
	 */
	private const SESSION_SCHEMA = 'agentsession';

	/**
	 * How far back "recently used an agent" reaches.
	 */
	private const RECENT_DAYS = 90;

	/**
	 * Constructor.
	 *
	 * @param ObjectService $objects OpenRegister's object service.
	 * @param LiteracyCourse $course The course, for current lesson versions.
	 */
	public function __construct(
		private readonly ObjectService $objects,
		private readonly LiteracyCourse $course,
	) {
	}//end __construct()

	/**
	 * Completion per person who did at least one lesson in the organisation.
	 *
	 * @param string $organisation The organisation.
	 *
	 * @return array<int, array{userId: string, done: int, total: int, complete: bool}> The rows, by user id.
	 *
	 * @spec openspec/specs/compliance-control-packs/spec.md#requirement-an-organisation-admin-sees-completion-and-may-require-the-course-req-ailit-002
	 */
	public function overview(string $organisation): array {
		$total = $this->course->lessonCount();
		$rows = [];
		foreach ($this->completionsByUser(organisation: $organisation) as $uid => $completions) {
			$done = $this->course->doneCount(completions: $completions);
			$rows[] = ['userId' => (string)$uid, 'done' => $done, 'total' => $total, 'complete' => $total > 0 && $done === $total];
		}

		usort($rows, static fn (array $a, array $b): int => strcmp($a['userId'], $b['userId']));

		return $rows;
	}//end overview()

	/**
	 * The overview as CSV.
	 *
	 * @param string $organisation The organisation.
	 *
	 * @return string The CSV text.
	 *
	 * @spec openspec/specs/compliance-control-packs/spec.md#requirement-an-organisation-admin-sees-completion-and-may-require-the-course-req-ailit-002
	 */
	public function csv(string $organisation): string {
		$lines = ['userId,done,total,complete'];
		foreach ($this->overview(organisation: $organisation) as $row) {
			$complete = 'no';
			if ($row['complete'] === true) {
				$complete = 'yes';
			}

			$lines[] = implode(',', [$this->csvField(value: $row['userId']), $row['done'], $row['total'], $complete]);
		}

		return implode("\n", $lines) . "\n";
	}//end csv()

	/**
	 * The article 4 status: satisfied when every recent agent user is complete,
	 * partial when some are, unevidenced when none are or nobody used an agent.
	 *
	 * @param string $organisation The organisation.
	 *
	 * @return array{status: string, detail: string} The status and the counts.
	 *
	 * @spec openspec/specs/compliance-control-packs/spec.md#requirement-the-ai-act-article-4-control-reads-its-status-from-completions-req-ailit-003
	 */
	public function evidence(string $organisation): array {
		$recent = $this->recentAgentUsers(organisation: $organisation);
		if ($recent === []) {
			return ['status' => 'unevidenced', 'detail' => 'Nobody in this organisation used an agent in the last 90 days.'];
		}

		$complete = 0;
		$byUser = $this->completionsByUser(organisation: $organisation);
		$total = $this->course->lessonCount();
		foreach ($recent as $uid) {
			if ($total > 0 && $this->course->doneCount(completions: ($byUser[$uid] ?? [])) === $total) {
				$complete++;
			}
		}

		$detail = sprintf('%d of %d people who used an agent in the last 90 days completed the course.', $complete, count($recent));
		if ($complete === count($recent)) {
			return ['status' => 'satisfied', 'detail' => $detail];
		}

		if ($complete > 0) {
			return ['status' => 'partial', 'detail' => $detail];
		}

		return ['status' => 'unevidenced', 'detail' => $detail];
	}//end evidence()

	/**
	 * Completion data in the organisation, grouped by person.
	 *
	 * @param string $organisation The organisation.
	 *
	 * @return array<string, array<int, array<string, mixed>>> Completions by user id.
	 */
	private function completionsByUser(string $organisation): array {
		$byUser = [];
		foreach ($this->inOrganisation(schema: LiteracyCourse::COMPLETION_SCHEMA, organisation: $organisation) as $object) {
			$data = $object->getObject();
			$byUser[(string)($data['userId'] ?? '')][] = $data;
		}

		unset($byUser['']);

		return $byUser;
	}//end completionsByUser()

	/**
	 * The people who used an agent in the organisation in the last 90 days.
	 *
	 * @param string $organisation The organisation.
	 *
	 * @return array<int, string> Their user ids.
	 */
	private function recentAgentUsers(string $organisation): array {
		$since = new DateTime('-' . self::RECENT_DAYS . ' days');
		$users = [];
		foreach ($this->inOrganisation(schema: self::SESSION_SCHEMA, organisation: $organisation) as $object) {
			$uid = (string)($object->getObject()['userId'] ?? '');
			if ($uid !== '' && $object->getUpdated() !== null && $object->getUpdated() >= $since) {
				$users[$uid] = true;
			}
		}

		return array_keys($users);
	}//end recentAgentUsers()

	/**
	 * The objects of one schema in the organisation.
	 *
	 * @param string $schema The schema slug.
	 * @param string $organisation The organisation.
	 *
	 * @return array<int, ObjectEntity> The objects.
	 */
	private function inOrganisation(string $schema, string $organisation): array {
		$out = [];
		$objects = $this->objects->setRegister(LiteracyCourse::REGISTER_SLUG)->setSchema($schema)
			->findAll(config: ['limit' => 10000], _rbac: false, _multitenancy: false);
		foreach ($objects as $object) {
			if ($object instanceof ObjectEntity && (string)($object->getOrganisation() ?? '') === $organisation) {
				$out[] = $object;
			}
		}

		return $out;
	}//end inOrganisation()

	/**
	 * Quote a CSV field when it holds a separator, a quote or a formula lead.
	 *
	 * @param string $value The field.
	 *
	 * @return string The safe field.
	 */
	private function csvField(string $value): string {
		if (preg_match('/^[=+\-@]/', $value) === 1) {
			$value = "'" . $value;
		}

		if (strpbrk($value, ",\"\n") === false) {
			return $value;
		}

		return '"' . str_replace('"', '""', $value) . '"';
	}//end csvField()

}//end class
