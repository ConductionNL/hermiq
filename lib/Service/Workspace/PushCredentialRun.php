<?php

/**
 * Hermiq PushCredentialRun.
 *
 * One push credential resolution: the run owner, the run's agent, its data and its pinned credentials.
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
 * @spec openspec/specs/agent-workspace-git-tools/spec.md#scenario-mismatched-credential-owners-are-refused
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service\Workspace;

use OCA\OpenRegister\Db\ObjectEntity;

/**
 * One push credential resolution in progress; it never leaves ForgeCredentialResolver.
 *
 * @spec openspec/specs/agent-workspace-git-tools/spec.md#scenario-mismatched-credential-owners-are-refused
 */
final class PushCredentialRun {

	/**
	 * The agent object (provider, credentialIds).
	 *
	 * @var array<string, mixed>
	 */
	public array $agentData = [];

	/**
	 * The agent's pinned credentials, or null when it pins none.
	 *
	 * @var array<string, string>|null
	 */
	public ?array $pins = null;

	/**
	 * Start the resolution.
	 *
	 * @param string            $ownerUid The resolved run owner.
	 * @param ObjectEntity|null $agent    The run's agent.
	 */
	public function __construct(
		public readonly string $ownerUid,
		public readonly ?ObjectEntity $agent,
	) {
	}//end __construct()
}//end class
