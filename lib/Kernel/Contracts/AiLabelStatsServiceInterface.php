<?php

/**
 * Contract of AiLabelStatsService
 *
 * The part of AiLabelStatsService that callers outside its component use, so they depend on this contract instead of the implementation.
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

/**
 * Contract of AiLabelStatsService.
 *
 * @category Kernel
 * @package  OCA\Hermiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/hermiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface AiLabelStatsServiceInterface {

	/**
	 * The label counts for one viewer.
	 *
	 * @param string $uid The viewer.
	 *
	 * @return array{files: int, reviewed: int, reviewedShare: int, tagId: string|null, truncated: bool,
	 *     contacts: int, calendarItems: int, objectsTruncated: bool}
	 *         `reviewedShare` is a whole percentage of `files`; `tagId` is the agent-authored
	 *         tag, for the link that opens Files filtered on it; `truncated` says more tagged
	 *         files exist than were checked; `objectsTruncated` says more contacts or calendar
	 *         items exist than were read.
	 *
	 * @spec openspec/changes/agent-authored-marking/specs/nc-native-tools/spec.md#requirement-a-person-can-mark-a-labelled-file-as-reviewed-and-the-oversight-page-counts-the-labels-req-ailbl-002
	 */
	public function stats(string $uid): array;
}//end interface
