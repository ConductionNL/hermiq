<?php

/**
 * Hermiq manageable-organisation source (contract)
 *
 * @category Tenant
 * @package  OCA\Hermiq\Tenant
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tenant;

/**
 * Resolves the organisations a user may govern the kill-switch for.
 *
 * ## Why this interface exists (ADR-083 rule 3)
 *
 * The app's DEFAULT ROUTE must stay core-only: an instance without OpenRegister
 * has to reach a start screen that EXPLAINS itself, rather than a 500 from a
 * controller the container could not construct. `DashboardController` used to
 * constructor-inject `OCA\OpenRegister\Db\OrganisationMapper` directly, which
 * made the whole start screen unconstructable without OpenRegister — the exact
 * failure rule 3 exists to prevent, and ADR-083 names this controller as the
 * app that "was most likely to have got it right and did not".
 *
 * So the controller depends on THIS hermiq-owned contract instead, and the
 * OpenRegister reach moves behind an availability guard in the implementation
 * (rule 1's optional-capability exception). The tenant model is unchanged: an
 * organisation is still an OpenRegister organisation identified by its UUID —
 * the same value schedules carry in `_organisation`.
 *
 * The endpoints remain the real authorization boundary; everything this
 * produces is UX gating only.
 *
 * @spec openspec/changes/human-approval-gate-ui/tasks.md#task-4-1
 */
interface ManageableOrganisations {

	/**
	 * List the organisations the given user may govern.
	 *
	 * An instance admin governs every organisation; a plain user governs only
	 * the organisations they own (`Organisation.owner`).
	 *
	 * MUST NOT throw. When the organisation store is unavailable — OpenRegister
	 * absent, disabled, or erroring — the honest answer is "no manageable
	 * organisation", because the toggle endpoint re-checks server-side anyway.
	 *
	 * @param string $userId  The Nextcloud user id.
	 * @param bool   $isAdmin Whether the user is an instance admin.
	 *
	 * @return array<int, array{id: string, label: string}> Organisation id/label
	 *                                                      pairs, possibly empty.
	 *
	 * @spec openspec/changes/human-approval-gate-ui/tasks.md#task-4-1
	 */
	public function forUser(string $userId, bool $isAdmin): array;
}//end interface
