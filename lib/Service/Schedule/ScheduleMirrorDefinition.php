<?php

/**
 * The shape of a schedule's mirror flow.
 *
 * One place owns what a mirror LOOKS like: the three nodes
 * (`openregister.trigger-schedule` -> `hermiq.schedule-dispatch` ->
 * `openregister.end`), the two edges between them, the uuid a fresh mirror
 * row gets, and how to read the declared runAs back out of a stored node
 * list. The ScheduleFlowBridge decides WHEN a mirror exists; this class says
 * what it contains.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\Hermiq\Service\Schedule
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/schedules-onto-engine-triggers/specs/schedule-engine-delegation/spec.md#requirement-eligible-schedules-delegate-their-clock-to-the-engine
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service\Schedule;

/**
 * Builds and reads the mirror flow's node and edge lists.
 *
 * @spec openspec/changes/schedules-onto-engine-triggers/specs/schedule-engine-delegation/spec.md#requirement-eligible-schedules-delegate-their-clock-to-the-engine
 */
class ScheduleMirrorDefinition {

	/**
	 * The mirror flow's three nodes.
	 *
	 * @param string $scheduleUuid The schedule uuid the dispatch node fires.
	 * @param string $cron The 5-field cron expression.
	 * @param string $runAs The declared acting identity.
	 *
	 * @return array<int,array<string,mixed>> The nodes.
	 *
	 * @spec openspec/changes/schedules-onto-engine-triggers/specs/schedule-engine-delegation/spec.md#requirement-eligible-schedules-delegate-their-clock-to-the-engine
	 */
	public function nodes(string $scheduleUuid, string $cron, string $runAs): array {
		return [
			[
				'id' => 'trigger',
				'position' => ['x' => 0, 'y' => 0],
				'type' => 'openregister.trigger-schedule',
				'config' => ['cron' => $cron, 'runAs' => $runAs],
			],
			[
				'id' => 'dispatch',
				'position' => ['x' => 260, 'y' => 0],
				'type' => 'hermiq.schedule-dispatch',
				'config' => ['scheduleId' => $scheduleUuid],
			],
			[
				'id' => 'done',
				'position' => ['x' => 520, 'y' => 0],
				'type' => 'openregister.end',
				'config' => [],
			],
		];
	}//end nodes()

	/**
	 * The mirror flow's two edges.
	 *
	 * @return array<int,array<string,string>> The edges.
	 *
	 * @spec openspec/changes/schedules-onto-engine-triggers/specs/schedule-engine-delegation/spec.md#requirement-eligible-schedules-delegate-their-clock-to-the-engine
	 */
	public function edges(): array {
		return [
			[
				'id' => 'trigger-dispatch',
				'from' => 'trigger',
				'to' => 'dispatch',
				'title' => 'due',
			],
			[
				'id' => 'dispatch-done',
				'from' => 'dispatch',
				'to' => 'done',
				'title' => 'dispatched',
			],
		];
	}//end edges()

	/**
	 * The trigger node's declared runAs in a stored node list.
	 *
	 * @param mixed $nodes The stored nodes.
	 *
	 * @return string The declared runAs, or ''.
	 *
	 * @spec openspec/changes/schedules-onto-engine-triggers/specs/schedule-engine-delegation/spec.md#requirement-a-mirrored-run-declares-and-re-enters-its-governed-identity
	 */
	public function triggerRunAs(mixed $nodes): string {
		if (is_array($nodes) === false) {
			return '';
		}

		foreach ($nodes as $node) {
			if (is_array($node) === true && ($node['type'] ?? null) === 'openregister.trigger-schedule') {
				$config = ($node['config'] ?? []);
				if (is_array($config) === true) {
					return trim((string)($config['runAs'] ?? ''));
				}
			}
		}

		return '';
	}//end triggerRunAs()

	/**
	 * A fresh v4 uuid for a mirror flow row.
	 *
	 * @return string The uuid.
	 *
	 * @spec openspec/changes/schedules-onto-engine-triggers/specs/schedule-engine-delegation/spec.md#requirement-eligible-schedules-delegate-their-clock-to-the-engine
	 */
	public function newUuid(): string {
		$bytes = random_bytes(16);
		$bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
		$bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);

		return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
	}//end newUuid()
}//end class
