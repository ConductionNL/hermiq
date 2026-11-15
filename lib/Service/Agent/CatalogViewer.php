<?php

/**
 * Hermiq CatalogViewer
 *
 * Who reads the agent catalog, and whether as organisation admin.
 *
 * @category Service
 * @package  OCA\Hermiq\Service\Agent
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/agents-sharing-and-catalog-columns/specs/agent-management-ui/spec.md#requirement-the-agent-catalog-shows-owner-sharing-and-status-req-agshare-003
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service\Agent;

/**
 * The reader of one catalog page or row.
 *
 * @spec openspec/changes/agents-sharing-and-catalog-columns/specs/agent-management-ui/spec.md#requirement-the-agent-catalog-shows-owner-sharing-and-status-req-agshare-003
 */
final class CatalogViewer {

	/**
	 * Build the viewer.
	 *
	 * @param string $uid     The user.
	 * @param bool   $asAdmin Whether the user reads as organisation admin.
	 *
	 * @spec openspec/changes/agents-sharing-and-catalog-columns/specs/agent-management-ui/spec.md#requirement-the-agent-catalog-shows-owner-sharing-and-status-req-agshare-003
	 */
	public function __construct(
		public readonly string $uid,
		public readonly bool $asAdmin,
	) {
	}//end __construct()
}//end class
