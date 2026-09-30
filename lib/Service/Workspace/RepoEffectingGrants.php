<?php

/**
 * Hermiq RepoEffectingGrants.
 *
 * A tool that changes the REMOTE repository (the push) resolves only from an
 * argument-scoped grant that pins the repository and constrains the branch,
 * for example `hermiq.workspacePush?repository=org/app&branch=in:feature-a,feature-b`.
 * A bare `hermiq.workspacePush` grant resolves to nothing, so the tool is not
 * advertised and the misconfiguration shows at configuration time, not in the
 * middle of an unattended run. The constraints themselves are enforced at
 * invocation by the tool invoker, before any network operation; narrowing a
 * grant never changes the tool's classification (it stays destructive).
 *
 * @category Service
 * @package  OCA\Hermiq\Service\Workspace
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/hermiq-runner-git-capability/specs/agent-workspace-git-tools/spec.md#requirement-repo-effecting-tools-resolve-only-from-an-argument-scoped-grant
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service\Workspace;

use OCA\Hermiq\Mcp\WorkspaceToolDescriptors;

/**
 * Drops a repo-effecting tool that no argument-scoped grant pins.
 *
 * @spec openspec/changes/hermiq-runner-git-capability/specs/agent-workspace-git-tools/spec.md#requirement-repo-effecting-tools-resolve-only-from-an-argument-scoped-grant
 */
final class RepoEffectingGrants {

	/**
	 * The tools that change the remote repository.
	 *
	 * @var array<int, string>
	 */
	public const REPO_EFFECTING = [WorkspaceToolDescriptors::PUSH];

	/**
	 * Keep a resolved tool id unless it is repo-effecting and not scoped enough.
	 *
	 * @param array<int, string>                                                     $resolvedIds The resolved tool ids.
	 * @param array<string, array<int, array<string, array{mode: string, values: array<int, string>}>>> $constraints The grant constraints per tool id.
	 *
	 * @return array<int, string>
	 *
	 * @spec openspec/changes/hermiq-runner-git-capability/specs/agent-workspace-git-tools/spec.md#scenario-a-bare-push-grant-does-not-resolve
	 */
	public static function filterIds(array $resolvedIds, array $constraints): array {
		return array_values(
			array_filter(
				$resolvedIds,
				static fn (string $id): bool => self::resolves(toolId: $id, constraints: $constraints)
			)
		);
	}//end filterIds()

	/**
	 * Keep a descriptor unless its tool is repo-effecting and not scoped enough.
	 *
	 * @param array<int, mixed>                                                      $descriptors The resolved descriptors.
	 * @param array<string, array<int, array<string, array{mode: string, values: array<int, string>}>>> $constraints The grant constraints per tool id.
	 *
	 * @return array<int, mixed>
	 *
	 * @spec openspec/changes/hermiq-runner-git-capability/specs/agent-workspace-git-tools/spec.md#scenario-a-bare-push-grant-does-not-resolve
	 */
	public static function filterDescriptors(array $descriptors, array $constraints): array {
		return array_values(
			array_filter(
				$descriptors,
				static function (mixed $descriptor) use ($constraints): bool {
					if (is_array($descriptor) === false) {
						return true;
					}

					$id = (string)($descriptor['mcpId'] ?? ($descriptor['name'] ?? ''));
					return self::resolves(toolId: $id, constraints: $constraints);
				}
			)
		);
	}//end filterDescriptors()

	/**
	 * Whether a tool id may resolve under these constraints.
	 *
	 * Every alternative grant for a repo-effecting tool has to pin the repository
	 * to one value and constrain the branch; one alternative without them would
	 * let the tool run unpinned, so it disqualifies the lot.
	 *
	 * @param string                                                                 $toolId      The tool id.
	 * @param array<string, array<int, array<string, array{mode: string, values: array<int, string>}>>> $constraints The grant constraints per tool id.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/hermiq-runner-git-capability/specs/agent-workspace-git-tools/spec.md#scenario-an-argument-scoped-push-grant-resolves-and-is-enforced
	 */
	public static function resolves(string $toolId, array $constraints): bool {
		if (in_array($toolId, self::REPO_EFFECTING, true) === false) {
			return true;
		}

		$sets = ($constraints[$toolId] ?? []);
		if ($sets === []) {
			return false;
		}

		foreach ($sets as $set) {
			$repository = ($set['repository'] ?? null);
			$branch = ($set['branch'] ?? null);
			if ($repository === null || $repository['mode'] !== 'pin' || $branch === null || $branch['values'] === []) {
				return false;
			}
		}

		return true;
	}//end resolves()
}//end class
