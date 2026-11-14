<?php

/**
 * Contract of GrantWaiverAudit
 *
 * The public surface of GrantWaiverAudit that other components call, so they depend
 * on this contract instead of the implementation.
 *
 * @category  Kernel
 * @package   OCA\Hermiq\Kernel\Contracts
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/hermiq
 *
 * @spec exclude Structural contract, no functional requirement.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Hermiq\Kernel\Contracts;

use OCA\OpenRegister\Db\ObjectEntity;

/**
 * Contract of GrantWaiverAudit.
 *
 * @category Kernel
 * @package  OCA\Hermiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/hermiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface GrantWaiverAuditInterface {

	/**
	 * AuditTrail action recorded when an approval waiver is added or removed.
	 *
	 * @var string
	 */
	public const ACTION = 'tool-grant-approval-waiver';

	/**
	 * Record the waivers an agent's write added or removed. Writes nothing when none moved.
	 *
	 * Never throws: an audit write failing must not turn a successful write into
	 * an error. The failure is logged with the agent it concerns.
	 *
	 * @param ObjectEntity $agent The agent as stored after the write.
	 * @param array<int, string> $before The waived entries before the write.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/agent-capability-reach/spec.md#requirement-waiving-approval-is-recorded-as-a-distinct-audited-event
	 */
	public function record(ObjectEntity $agent, array $before): void;

	/**
	 * The grant entries in a stored `tools` value that carry a `#noapproval` fragment.
	 *
	 * Read through ToolGrantSet, so both stored shapes (a list, or the app,
	 * subject, action structure) are seen. Iterating a structure directly would
	 * yield app names rather than grants, and every waiver would go unseen.
	 *
	 * @param mixed $tools The stored `tools` value.
	 *
	 * @return array<int, string> The waived entries, verbatim.
	 *
	 * @spec openspec/specs/agent-capability-reach/spec.md#requirement-waiving-approval-is-recorded-as-a-distinct-audited-event
	 */
	public function waivers(mixed $tools): array;
}//end interface
