<?php

/**
 * Contract of WebFetchService
 *
 * The public surface of WebFetchService that other components call, so they depend
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

/**
 * Contract of WebFetchService.
 *
 * @category Kernel
 * @package  OCA\Hermiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/hermiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface WebFetchServiceInterface {

	/**
	 * Fetch `$url` and return the tool result, or a structured error envelope.
	 * Never throws.
	 *
	 * @param string $url The URL to fetch.
	 *
	 * @return array<string, mixed> `{url, truncated, content}` on success, or
	 *                              `{error: {code, message}}` (plus, for a redirect,
	 *                              a `location` field).
	 *
	 * @spec openspec/specs/web-research-tool/spec.md#scenario-an-agent-fetches-an-html-page
	 */
	public function fetch(string $url): array;
}//end interface
