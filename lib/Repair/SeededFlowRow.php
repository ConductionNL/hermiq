<?php

/**
 * Hermiq SeededFlowRow.
 *
 * The flow-store row a repair step seeds from a flow definition. One builder for every
 * seeder, because the rules are the same for all of them: a fresh uuid, owned by this
 * app, tagged with its application, and DISABLED WITHOUT AN OWNER. A trigger fires
 * with no acting user, so a flow's owner is the person who enables it; a repair step
 * runs as no one and cannot be that person. The organisation is the caller's to set,
 * and to refuse to write without (hermiq#140).
 *
 * @category Repair
 * @package  OCA\Hermiq\Repair
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
 * @spec openspec/changes/hermiq-seed-hydra-flows/tasks.md
 */

declare(strict_types=1);

namespace OCA\Hermiq\Repair;

use DateTime;
use OCA\Hermiq\Kernel\AppIdentity;
use OCA\OpenRegister\Db\Flow;

/**
 * Builds a seeded flow row: disabled, ownerless, organisation left to the caller.
 *
 * @spec openspec/changes/hermiq-seed-hydra-flows/tasks.md
 */
final class SeededFlowRow {

	/**
	 * The row for a definition.
	 *
	 * @param array<string, mixed> $data            The definition (description, trigger, cron, nodes, edges, limits).
	 * @param string               $name            The flow's name, its idempotency key.
	 * @param string               $applicationSlug The application the flow belongs to.
	 *
	 * @return Flow The row, disabled and without an owner, whatever the definition says.
	 *
	 * @spec openspec/changes/hermiq-seed-hydra-flows/tasks.md
	 */
	public function from(array $data, string $name, string $applicationSlug): Flow {
		$flow = new Flow();
		$flow->setUuid($this->newUuid());
		$flow->setApp(AppIdentity::APP_ID);
		$flow->setName($name);
		$flow->setDescription((string)($data['description'] ?? ''));
		$flow->setTrigger((string)($data['trigger'] ?? ''));
		$flow->setTriggerRegister(($data['triggerRegister'] ?? null));
		$flow->setTriggerSchema(($data['triggerSchema'] ?? null));
		$flow->setCron(($data['cron'] ?? null));
		$flow->setNodes((array)($data['nodes'] ?? []));
		$flow->setEdges((array)($data['edges'] ?? []));
		$flow->setLimits((array)($data['limits'] ?? []));
		$flow->setApplicationSlug($applicationSlug);
		$flow->setCreated(new DateTime());
		$flow->setUpdated(new DateTime());
		$flow->setEnabled(false);
		$flow->setOwner(null);

		return $flow;
	}//end from()

	/**
	 * A v4 uuid.
	 *
	 * @return string The uuid.
	 *
	 * @spec openspec/changes/hermiq-seed-hydra-flows/tasks.md
	 */
	private function newUuid(): string {
		$data = random_bytes(16);
		$data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
		$data[8] = chr((ord($data[8]) & 0x3f) | 0x80);

		return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
	}//end newUuid()
}//end class
