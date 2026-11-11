<?php

/**
 * Hermiq WebFetchRun.
 *
 * One `web.fetch` call: the URL asked for and the web-research settings it is
 * fetched under. Built and read only inside WebFetchService.
 *
 * @category Service
 * @package  OCA\Hermiq\Service\WebResearch
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/web-research-tool/specs/web-research-tool/spec.md#scenario-an-agent-fetches-an-html-page
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service\WebResearch;

/**
 * One web fetch.
 *
 * @spec openspec/changes/web-research-tool/specs/web-research-tool/spec.md#scenario-an-agent-fetches-an-html-page
 */
final class WebFetchRun {

	/**
	 * Constructor.
	 *
	 * @param string               $url    The URL to fetch.
	 * @param array<string, mixed> $config The web-research settings.
	 */
	public function __construct(
		public readonly string $url,
		public readonly array $config,
	) {
	}//end __construct()
}//end class
