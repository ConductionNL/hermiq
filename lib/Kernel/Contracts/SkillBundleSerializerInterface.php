<?php

/**
 * Contract of SkillBundleSerializer
 *
 * The public surface of SkillBundleSerializer that other components call, so they depend
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
 * Contract of SkillBundleSerializer.
 *
 * @category Kernel
 * @package  OCA\Hermiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/hermiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface SkillBundleSerializerInterface {

	/**
	 * Parse a bundle tree's AGENTS back into payloads.
	 *
	 * Separate method rather than folding into `fromBundle()`'s return shape,
	 * so every existing caller of `fromBundle()` (skills-only) is unaffected —
	 * this is purely additive. Returns an empty array for a 1.0 bundle (no
	 * `agents` manifest key) or one with no declared agents; both are valid.
	 *
	 * @param array<string, string> $files The `path => contents` repository tree.
	 *
	 * @return array<int, array<string, mixed>> One parsed agent payload per entry.
	 *
	 * @spec openspec/specs/skills-marketplace/spec.md#requirement-a-skill-bundle-may-additionally-carry-agent-definitions
	 */
	public function agentsFromBundle(array $files): array;

	/**
	 * Parse a bundle tree back into skill payloads.
	 *
	 * Returns an empty array when the manifest is absent or its major version is
	 * unrecognised — a bundle that half-parses is worse than one that refuses, so
	 * the caller surfaces "not a bundle" rather than a best-effort partial read.
	 *
	 * @param array<string, string> $files The `path => contents` repository tree.
	 *
	 * @return array<int, array<string, mixed>> One parsed skill payload per entry.
	 *
	 * @spec openspec/changes/archive/2026-09-06-skill-bundle-publish/specs/skills-marketplace/spec.md#requirement-bundle-entries-are-validated-before-use-as-paths
	 */
	public function fromBundle(array $files): array;

	/**
	 * Build a bundle tree from a set of skills, and optionally agents.
	 *
	 * @param array $skills The skill payloads (each needs `name`, `frontmatter`,
	 *                      `body` and optionally `files`). Typed loosely because
	 *                      callers hand through OpenRegister object payloads.
	 * @param array|null $dropped OUT: every skill this call did NOT bundle, as
	 *                            `{name, reason}`. A caller that reports success
	 *                            without reading this is publishing an incomplete
	 *                            artefact and saying otherwise — which is exactly
	 *                            what happened on the first real bundle, where a
	 *                            64-skill cap silently discarded 30 of hydra's 94
	 *                            while the API reported all 94 as published.
	 * @param array $agents The agent payloads (each needs `name`; every other
	 *                      field — description/prompt/tools/model/… — is opaque
	 *                      to this class and carried through verbatim). Empty by
	 *                      default: a bundle with no agents is a normal, valid
	 *                      skills-only bundle (backward compatible with every
	 *                      caller that predates this parameter).
	 * @param array|null $droppedAgents OUT: every agent this call did NOT
	 *                                  bundle, same shape as `$dropped`.
	 *
	 * Both OUT params are initialised to `[]` on entry and never set back to
	 * null, so on return they are always arrays. Saying so lets callers use
	 * `count($dropped)` without a null check — without these tags the inferred
	 * `array|null` leaked into `SkillController::bundlePublish()`'s response
	 * literal and PHPStan 2 reported the whole JSONResponse payload as an
	 * unresolvable type.
	 *
	 * @param-out array<int, array<string, string>> $dropped
	 * @param-out array<int, array<string, string>> $droppedAgents
	 *
	 * @return array<string, string> The `path => contents` bundle tree.
	 *
	 * @spec openspec/changes/archive/2026-09-06-skill-bundle-publish/specs/skills-marketplace/spec.md#requirement-many-skills-publish-to-a-single-repository
	 */
	public function toBundle(array $skills, ?array &$dropped = null, array $agents = [], ?array &$droppedAgents = null): array;
}//end interface
