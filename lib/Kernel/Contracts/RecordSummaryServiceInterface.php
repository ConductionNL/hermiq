<?php

/**
 * Contract of RecordSummaryService
 *
 * The public surface of RecordSummaryService that other components call, so they depend
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

use OCA\Hermiq\Service\GuardrailBlockedException;
use RuntimeException;

/**
 * Contract of RecordSummaryService.
 *
 * @category Kernel
 * @package  OCA\Hermiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/hermiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface RecordSummaryServiceInterface {

	/**
	 * The AI feature that switches record summaries on and off.
	 *
	 * @var string
	 */
	public const FEATURE_SLUG = 'record-summary';

	/**
	 * The assistant prompt scope whose prompt writes the summary.
	 *
	 * @var string
	 */
	public const PROMPT_SCOPE = 'record-summary';

	/**
	 * The prompt used when the library holds none for the scope (design.md, seed data).
	 *
	 * @var string
	 */
	public const DEFAULT_PROMPT = 'Summarise this record in at most five sentences for a colleague who has not '
		. 'seen it. Name its status, the last change and anything that needs action.';

	/**
	 * What the leaf may show for a record, without calling a model.
	 *
	 * @param string $userId The caller.
	 * @param string $register The record's register (id or slug).
	 * @param string $schema The record's schema (id or slug).
	 * @param string $objectId The record's id.
	 *
	 * @return array{enabled: bool, agent: array{id: string, name: string}|null, summary: array<string, mixed>|null}
	 *
	 * @throws RuntimeException 404 when the caller cannot read the record.
	 *
	 * @spec openspec/specs/agent-object-leaf/spec.md#scenario-the-summary-is-not-rewritten-until-the-record-changes
	 */
	public function status(string $userId, string $register, string $schema, string $objectId): array;

	/**
	 * The summary of a record: the stored one while the record is unchanged,
	 * else a new one written by the app's assistant and stored.
	 *
	 * @param string $userId The caller.
	 * @param string $register The record's register (id or slug).
	 * @param string $schema The record's schema (id or slug).
	 * @param string $objectId The record's id.
	 *
	 * @return array{summary: string, generatedAt: string, objectVersion: string, agentId: string, agentName: string, cached: bool}
	 *
	 * @throws RuntimeException 404 unreadable record, 403 feature off, 409 no agent answers.
	 * @throws GuardrailBlockedException When the organisation's guardrail refuses the record text.
	 *
	 * @spec openspec/specs/agent-object-leaf/spec.md#scenario-a-case-handler-reads-a-summary-of-a-long-application
	 * @spec openspec/specs/agent-object-leaf/spec.md#scenario-no-summary-for-a-record-the-user-cannot-read
	 */
	public function summarise(string $userId, string $register, string $schema, string $objectId): array;
}//end interface
