<?php

/**
 * Hermiq CatalogSearch.
 *
 * One store search as GitHubTemplateCatalogService runs it: the discovery kind it
 * searches and the caller it reads for. Built by that service and never handed
 * out of it.
 *
 * @category Service
 * @package  OCA\Hermiq\Service
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
 * @spec openspec/specs/agent-template-github-store/spec.md#requirement-the-system-must-provide-a-server-backed-search-for-hermiq-agent-template-repos
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service;

/**
 * One catalogue search.
 *
 * @spec openspec/specs/agent-template-github-store/spec.md#requirement-the-system-must-provide-a-server-backed-search-for-hermiq-agent-template-repos
 */
final class CatalogSearch {

	/**
	 * Start the search.
	 *
	 * @param string        $kind   The discovery kind (`KIND_AGENT_TEMPLATE`|`KIND_SKILL`).
	 * @param CatalogCaller $caller Who the search reads for.
	 */
	public function __construct(
		public readonly string $kind,
		public readonly CatalogCaller $caller,
	) {
	}//end __construct()
}//end class
