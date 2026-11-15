<?php

/**
 * Hermiq CheckoutRequest.
 *
 * What a run asks to open: the repository and ref it names, and the clone URL
 * and history depth the server resolved for it.
 *
 * @category Service
 * @package  OCA\Hermiq\Service\Workspace
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
 * @spec openspec/specs/agent-workspace-git-tools/spec.md#scenario-the-model-never-learns-a-filesystem-path
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service\Workspace;

/**
 * One checkout a run asks for.
 *
 * @spec openspec/specs/agent-workspace-git-tools/spec.md#scenario-the-model-never-learns-a-filesystem-path
 */
final class CheckoutRequest {

	/**
	 * The server-resolved clone URL.
	 *
	 * @var string
	 */
	public string $cloneUrl = '';

	/**
	 * The history depth asked for (clamped when cloning).
	 *
	 * @var int
	 */
	public int $depth = 1;

	/**
	 * Build the request.
	 *
	 * @param string $repository The forge-relative slug.
	 * @param string $ref        The branch or tag.
	 */
	public function __construct(
		public readonly string $repository,
		public readonly string $ref,
	) {
	}//end __construct()
}//end class
