<?php

/**
 * Contract of SeedFreshnessService
 *
 * The public surface of SeedFreshnessService that other components call, so they depend
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
 * Contract of SeedFreshnessService.
 *
 * @category Kernel
 * @package  OCA\Hermiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/hermiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface SeedFreshnessServiceInterface {

	/**
	 * The refreshed payload for an existing seed on a repair re-run, or null when
	 * the object MUST NOT be touched.
	 *
	 * Refreshes `lastActivityAt` and flips a `stale` seed back to `active`
	 * (dropping `staleSince`) — but ONLY for `__system__`-owned objects still in
	 * state `active` or `stale`. Human-owned objects and curator/human terminal
	 * states (`archived`, `quarantined`) return null: those decisions win.
	 *
	 * @param ObjectEntity $skill The existing seed object.
	 *
	 * @return array<string, mixed>|null The payload to save, or null when untouchable.
	 *
	 * @spec openspec/specs/skill-maturity/spec.md#requirement-seeded-example-skills-demonstrate-distinct-maturity-levels
	 */
	public function refreshedPayload(ObjectEntity $skill): ?array;

	/**
	 * Stamp `lastActivityAt = now` on a seed payload about to be created, so the
	 * Curator's staleness clock starts at seed time.
	 *
	 * @param array<string, mixed> $seed The seed object payload.
	 *
	 * @return array<string, mixed> The payload with a fresh `lastActivityAt`.
	 *
	 * @spec openspec/specs/skill-maturity/spec.md#requirement-seeded-example-skills-demonstrate-distinct-maturity-levels
	 */
	public function stampFresh(array $seed): array;
}//end interface
