<?php

/**
 * Hermiq ResearchToolbox.
 *
 * The two tools a research run gathers with, `hermiq.webSearch` and
 * `hermiq.webFetch`, as one agent may call them in one conversation. A refused
 * call answers an `error` envelope, never a result, so the ledger cannot list
 * what the governed chain did not allow.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\Hermiq\Service\Research
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/tools-deep-research-report/specs/deep-research/spec.md#requirement-the-research-goes-through-the-agents-governed-tools-and-records-every-source-req-research-002
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service\Research;

/**
 * Searches and reads the web for one research run.
 *
 * @spec openspec/changes/tools-deep-research-report/specs/deep-research/spec.md#requirement-the-research-goes-through-the-agents-governed-tools-and-records-every-source-req-research-002
 */
interface ResearchToolbox {
	/**
	 * Search the web.
	 *
	 * @param string $query The search query.
	 *
	 * @return list<array{title: string, url: string}> The results, empty when refused or none.
	 *
	 * @spec openspec/changes/tools-deep-research-report/specs/deep-research/spec.md#requirement-the-research-goes-through-the-agents-governed-tools-and-records-every-source-req-research-002
	 */
	public function search(string $query): array;

	/**
	 * Read one page.
	 *
	 * @param string $url The page.
	 *
	 * @return array<string, mixed> The webFetch result, or an `error` envelope when refused or failed.
	 *
	 * @spec openspec/changes/tools-deep-research-report/specs/deep-research/spec.md#requirement-the-research-goes-through-the-agents-governed-tools-and-records-every-source-req-research-002
	 */
	public function fetch(string $url): array;
}//end interface
