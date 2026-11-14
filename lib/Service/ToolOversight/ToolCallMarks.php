<?php

/**
 * Hermiq ToolCallMarks.
 *
 * An operator marks one of an agent's tool calls as misuse, with a reason.
 * The mark is kept on the agent object, so OpenRegister's audit trail of the
 * agent records who marked which call and why, and the agent's tool activity
 * shows the mark on the call.
 *
 * @category Service
 * @package  OCA\Hermiq\Service\ToolOversight
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
 * @spec openspec/changes/verify-row-scenarios/specs/agent-tool-governance/spec.md#requirement-per-agent-tool-invocation-oversight-surface-ai-act-art-12-14
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service\ToolOversight;

use DateTimeImmutable;
use DateTimeInterface;
use InvalidArgumentException;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;

/**
 * Writes a misuse mark on an agent's tool call and lays the marks over the activity rows.
 *
 * @spec openspec/changes/verify-row-scenarios/specs/agent-tool-governance/spec.md#requirement-per-agent-tool-invocation-oversight-surface-ai-act-art-12-14
 */
class ToolCallMarks {

	/**
	 * The agent property the marks are kept in.
	 *
	 * @var string
	 */
	public const PROPERTY = 'toolCallMarks';

	/**
	 * The label a marked call carries.
	 *
	 * @var string
	 */
	public const MISUSE = 'misuse';

	/**
	 * The longest reason kept.
	 *
	 * @var integer
	 */
	private const MAX_REASON = 1000;

	/**
	 * Constructor.
	 *
	 * @param ObjectService $objectService Saves the agent, which writes its audit trail.
	 */
	public function __construct(
		private readonly ObjectService $objectService,
	) {
	}//end __construct()

	/**
	 * Mark one tool call of an agent as misuse. A second mark on the same call
	 * replaces the first; the audit trail keeps both.
	 *
	 * @param ObjectEntity           $agent    The agent the call was made by.
	 * @param string                 $callId   The call, as its activity row names it.
	 * @param string                 $reason   Why it is misuse.
	 * @param string                 $operator The operator's user id.
	 * @param DateTimeInterface|null $now      When; the current time when null.
	 *
	 * @return array{callId: string, label: string, reason: string, markedBy: string, markedAt: string} The mark.
	 *
	 * @throws InvalidArgumentException When the call or the reason is missing.
	 *
	 * @spec openspec/changes/verify-row-scenarios/specs/agent-tool-governance/spec.md#requirement-per-agent-tool-invocation-oversight-surface-ai-act-art-12-14
	 */
	public function mark(ObjectEntity $agent, string $callId, string $reason, string $operator, ?DateTimeInterface $now = null): array {
		$callId = trim($callId);
		$reason = trim($reason);
		if ($callId === '') {
			throw new InvalidArgumentException('Name the tool call to mark.');
		}

		if ($reason === '') {
			throw new InvalidArgumentException('A mark needs a reason.');
		}

		$mark = [
			'callId' => $callId,
			'label' => self::MISUSE,
			'reason' => mb_substr($reason, 0, self::MAX_REASON),
			'markedBy' => $operator,
			'markedAt' => ($now ?? new DateTimeImmutable())->format(DateTimeInterface::ATOM),
		];

		$marks = $this->marksOf(agent: $agent);
		$marks[$callId] = $mark;

		// The whole agent is written, as the grant write does: the save
		// replaces the object, so a partial payload would drop its other fields.
		$payload = $agent->getObject();
		unset($payload['@self']);
		foreach ($payload as $key => $value) {
			if ($value === null || $value === []) {
				unset($payload[$key]);
			}
		}

		$payload[self::PROPERTY] = array_values($marks);
		$this->objectService->saveObject(
			object: $payload,
			register: 'hermiq',
			schema: 'agent',
			uuid: (string)$agent->getUuid()
		);

		return $mark;
	}//end mark()

	/**
	 * The activity rows with each marked call's mark laid over it.
	 *
	 * @param array<int, array<string, mixed>> $rows  The tool activity rows.
	 * @param ObjectEntity                     $agent The agent.
	 *
	 * @return array<int, array<string, mixed>> The rows; a marked one carries `mark`.
	 *
	 * @spec openspec/changes/verify-row-scenarios/specs/agent-tool-governance/spec.md#requirement-per-agent-tool-invocation-oversight-surface-ai-act-art-12-14
	 */
	public function overlay(array $rows, ObjectEntity $agent): array {
		$marks = $this->marksOf(agent: $agent);
		if ($marks === []) {
			return $rows;
		}

		foreach ($rows as $index => $row) {
			$callId = (string)($row['callId'] ?? '');
			if ($callId !== '' && isset($marks[$callId]) === true) {
				$rows[$index]['mark'] = $marks[$callId];
			}
		}

		return $rows;
	}//end overlay()

	/**
	 * The agent's marks, by call.
	 *
	 * @param ObjectEntity $agent The agent.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function marksOf(ObjectEntity $agent): array {
		$stored = ($agent->getObject()[self::PROPERTY] ?? []);
		if (is_array($stored) === false) {
			return [];
		}

		$marks = [];
		foreach ($stored as $mark) {
			if (is_array($mark) === true && (string)($mark['callId'] ?? '') !== '') {
				$marks[(string)$mark['callId']] = $mark;
			}
		}

		return $marks;
	}//end marksOf()
}//end class
