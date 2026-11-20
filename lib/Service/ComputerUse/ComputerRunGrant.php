<?php

/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\Hermiq\Service\ComputerUse
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/tools-browser-and-computer-use/specs/computer-use/spec.md#requirement-every-computer-action-passes-the-approval-gate-req-computer-003
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service\ComputerUse;

use DateTimeImmutable;
use DateTimeZone;
use OCA\Hermiq\Mcp\ComputerToolDescriptors;
use Throwable;

/**
 * The rules of a run-scoped computer pre-authorisation, without storage.
 *
 * Tools-browser-and-computer-use D5: a reviewer may approve the actions on one
 * machine for one run for at most fifteen minutes. This class says which
 * approval may be widened that way, what the `computer-run` Approval it records
 * holds, its correlation key, and whether a stored one is still live. Reading and
 * writing the Approvals stays with ApprovalService.
 *
 * @spec openspec/changes/tools-browser-and-computer-use/specs/computer-use/spec.md#requirement-every-computer-action-passes-the-approval-gate-req-computer-003
 */
final class ComputerRunGrant {

	/**
	 * The Approval source type of a run-scoped computer pre-authorisation.
	 */
	public const SOURCE_TYPE = 'computer-run';

	/**
	 * How long a run-scoped computer pre-authorisation lasts, in minutes.
	 */
	public const MINUTES = 15;

	/**
	 * The correlation key of a machine's pre-authorisation in one run.
	 *
	 * @param string $agentId The agent UUID.
	 * @param string $runId   The run id.
	 * @param string $target  The machine.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/tools-browser-and-computer-use/specs/computer-use/spec.md#requirement-every-computer-action-passes-the-approval-gate-req-computer-003
	 */
	public static function key(string $agentId, string $runId, string $target): string {
		return hash('sha256', self::SOURCE_TYPE . '|' . $agentId . '|' . $runId . '|' . $target);
	}//end key()

	/**
	 * Whether a computer-run pre-authorisation is approved, names its decision
	 * maker and has not yet ended at the given moment.
	 *
	 * @param array<string, mixed> $data The Approval's data.
	 * @param DateTimeImmutable    $now  The moment of the call.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/tools-browser-and-computer-use/specs/computer-use/spec.md#requirement-every-computer-action-passes-the-approval-gate-req-computer-003
	 */
	public static function isLive(array $data, DateTimeImmutable $now): bool {
		if (($data['status'] ?? '') !== 'approved' || (string)($data['decidedBy'] ?? '') === '') {
			return false;
		}

		$ends = self::moment(value: (string)($data['expiresAt'] ?? ''));
		return $ends !== null && $ends > $now;
	}//end isLive()

	/**
	 * The `computer-run` Approval data that widens an approved computer action
	 * to its machine for the rest of its run, or null when this approval cannot
	 * carry one (not an approved `toolcall` approval of a computer action, or
	 * without a run, machine, agent or reviewer).
	 *
	 * @param array<string, mixed> $data       The approved computer-action approval's data.
	 * @param string               $deciderUid The reviewer.
	 * @param DateTimeImmutable    $now        The moment of the decision.
	 *
	 * @return array<string, mixed>|null
	 *
	 * @spec openspec/changes/tools-browser-and-computer-use/specs/computer-use/spec.md#requirement-every-computer-action-passes-the-approval-gate-req-computer-003
	 */
	public static function recordFor(array $data, string $deciderUid, DateTimeImmutable $now): ?array {
		$runId  = (string)($data['runId'] ?? '');
		$target = (string)($data['toolArguments']['target'] ?? '');
		$agent  = (string)($data['agentId'] ?? '');
		if (self::isApprovedAction(data: $data) === false
			|| in_array('', [$runId, $target, $agent, $deciderUid], true) === true
		) {
			return null;
		}

		$utc = $now->setTimezone(new DateTimeZone('UTC'));
		return [
			'status' => 'approved',
			'sourceType' => self::SOURCE_TYPE,
			'correlationId' => self::key(agentId: $agent, runId: $runId, target: $target),
			'agentId' => $agent,
			'toolId' => (string)$data['toolId'],
			'toolArguments' => ['target' => $target],
			'runId' => $runId,
			'prompt' => 'Actions on this machine for the rest of this run, for at most ' . self::MINUTES . ' minutes.',
			'requestedAt' => $utc->format('c'),
			'reviewer' => (string)($data['reviewer'] ?? ''),
			'reviewerType' => (string)($data['reviewerType'] ?? 'user'),
			'decidedAt' => $utc->format('c'),
			'decidedBy' => $deciderUid,
			'expiresAt' => $utc->modify('+' . self::MINUTES . ' minutes')->format('c'),
		];
	}//end recordFor()

	/**
	 * Whether an Approval is an approved `toolcall` approval of a computer action.
	 *
	 * @param array<string, mixed> $data The Approval's data.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/tools-browser-and-computer-use/specs/computer-use/spec.md#requirement-every-computer-action-passes-the-approval-gate-req-computer-003
	 */
	private static function isApprovedAction(array $data): bool {
		return ($data['status'] ?? '') === 'approved'
			&& ($data['sourceType'] ?? '') === 'toolcall'
			&& in_array((string)($data['toolId'] ?? ''), ComputerToolDescriptors::ACTION_IDS, true) === true;
	}//end isApprovedAction()

	/**
	 * A stored moment, or null when it does not read.
	 *
	 * @param string $value An ISO 8601 moment.
	 *
	 * @return DateTimeImmutable|null
	 */
	private static function moment(string $value): ?DateTimeImmutable {
		if ($value === '') {
			return null;
		}

		try {
			return new DateTimeImmutable($value);
		} catch (Throwable $e) {
			return null;
		}
	}//end moment()
}//end class
