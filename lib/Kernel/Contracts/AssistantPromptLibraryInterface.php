<?php

/**
 * Contract of AssistantPromptLibrary
 *
 * The public surface of AssistantPromptLibrary that other components call, so they depend
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
 * Contract of AssistantPromptLibrary.
 *
 * @category Kernel
 * @package  OCA\Hermiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/hermiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface AssistantPromptLibraryInterface {

	/**
	 * Every prompt, in the administrator's order.
	 *
	 * @return array<int, array<string, mixed>> The library.
	 *
	 * @spec openspec/specs/ai-feature-admin-surface/spec.md#scenario-order-is-the-administrators
	 */
	public function all(): array;

	/**
	 * Disable every prompt, wholesale or within one scope, in a single act, and
	 * record who did it and when.
	 *
	 * @param string|null $usageScope The scope to disable, or null for every prompt.
	 * @param string $actor The administrator performing the act.
	 *
	 * @return int How many prompts were switched off.
	 *
	 * @spec openspec/specs/ai-feature-admin-surface/spec.md#scenario-everything-stops-in-one-act
	 */
	public function disableAll(?string $usageScope, string $actor): int;

	/**
	 * The prompts offered on one record type: enabled, in the administrator's
	 * order, and scoped. A prompt scoped to another type is not offered here, and
	 * a prompt with no scope is offered everywhere.
	 *
	 * @param string $usageScope The record type the surface is open on.
	 *
	 * @return array<int, array<string, mixed>> The prompts to offer.
	 *
	 * @spec openspec/specs/ai-feature-admin-surface/spec.md#scenario-scope-decides-where-a-prompt-appears
	 */
	public function forScope(string $usageScope): array;

	/**
	 * Install a consuming app's initial library. A prompt an administrator has
	 * touched is left exactly as it is: the edited state lives here, and the
	 * shipping app neither holds nor restores it.
	 *
	 * @param string $appId The app shipping the prompts.
	 * @param array<int, array<string, mixed>> $prompts The prompts it ships.
	 *
	 * @return array{installed: int, skipped: int} What the seed did.
	 *
	 * @orphaned-write-capability exclude this is the CONSUMER-FACING half of
	 *   `a-consuming-app-may-ship-an-initial-library`, and the caller is by
	 *   definition another app: hermiq holds the library, a shipping app hands
	 *   it the prompts it ships. No app in the fleet ships prompts yet, so
	 *   there is no caller for gate-57 to find, and there would be none in a
	 *   single-repo checkout even after one existed — the gate says so itself
	 *   when it declines to judge a foundation repo (hydra#106). Deleting the
	 *   method would delete an implemented spec requirement; giving it an HTTP
	 *   route would invent a write endpoint the spec does not ask for. It
	 *   stays, unreachable and deliberate, until a consuming app calls it.
	 *
	 * @spec openspec/specs/ai-feature-admin-surface/spec.md#requirement-a-consuming-app-may-ship-an-initial-library-and-must-not-hold-the-edited-state
	 */
	public function seed(string $appId, array $prompts): array;

	/**
	 * Create or update a prompt an administrator is editing.
	 *
	 * Marks the prompt `administered`, which is what tells a shipping app's
	 * seed to leave it alone: the edited state lives here, and the app that
	 * shipped the original neither holds nor restores it.
	 *
	 * @param string|null $id The prompt id, or null to create.
	 * @param array<string, mixed> $payload The prompt fields.
	 *
	 * @return array<string, mixed> The stored prompt, with its id.
	 *
	 * @spec openspec/specs/ai-feature-admin-surface/spec.md#requirement-a-consuming-app-may-ship-an-initial-library-and-must-not-hold-the-edited-state
	 */
	public function upsert(?string $id, array $payload): array;
}//end interface
