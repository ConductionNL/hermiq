<?php

/**
 * Hermiq GitHubCatalogPayload
 *
 * Reads the bodies GitHub returned to the template catalogue: search hits,
 * store cards, contents-API files and git-tree entries. It makes no outbound
 * call, so the fixed-host read path stays whole in GitHubTemplateCatalogService.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\Hermiq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/agent-template-github-store/spec.md#requirement-the-system-must-provide-a-server-backed-search-for-hermiq-agent-template-repos
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service;

/**
 * Pure readers over GitHub response bodies for the template catalogue.
 *
 * @spec openspec/specs/agent-template-github-store/spec.md#requirement-the-system-must-provide-a-server-backed-search-for-hermiq-agent-template-repos
 */
final class GitHubCatalogPayload {
	/**
	 * The search outcome for a failed search call: rate-limited or unreachable.
	 *
	 * @param array{ok:bool,status:int,body:string,rateLimited:bool,brokerUsed:bool} $result The GET result.
	 *
	 * @return array{outcome:string,cards:array<int,array<string,mixed>>,brokerUsed:bool,rateLimited:bool}
	 *
	 * @spec openspec/specs/agent-template-github-store/spec.md#requirement-the-system-must-degrade-gracefully-when-github-is-rate-limited-or-unreachable
	 */
	public function searchFailure(array $result): array {
		$failure = GitHubTemplateCatalogService::OUTCOME_UNREACHABLE;
		if ($result['rateLimited'] === true) {
			$failure = GitHubTemplateCatalogService::OUTCOME_RATE_LIMITED;
		}

		return [
			'outcome' => $failure,
			'cards' => [],
			'brokerUsed' => $result['brokerUsed'],
			'rateLimited' => $result['rateLimited'],
		];
	}//end searchFailure()

	/**
	 * The hit items of a search body, at most `$max` of them; none when the body is unusable.
	 *
	 * @param string $body The raw search response body.
	 * @param int $max The most hits to keep.
	 *
	 * @return array<int, mixed> The raw hit items.
	 *
	 * @spec openspec/specs/agent-template-github-store/spec.md#requirement-the-system-must-provide-a-server-backed-search-for-hermiq-agent-template-repos
	 */
	public function searchItems(string $body, int $max): array {
		$decoded = json_decode($body, true);
		if (is_array($decoded) === false || is_array($decoded['items'] ?? null) === false) {
			return [];
		}

		return array_slice($decoded['items'], 0, $max);
	}//end searchItems()

	/**
	 * The owner and repo a search hit names, or null when either is missing.
	 *
	 * @param array<string,mixed> $item A GitHub search-result item.
	 *
	 * @return array{owner:string,repo:string}|null The coordinates.
	 *
	 * @spec openspec/specs/agent-template-github-store/spec.md#requirement-the-system-must-provide-a-server-backed-search-for-hermiq-agent-template-repos
	 */
	public function hitCoordinates(array $item): ?array {
		$nameParts = explode('/', (string)($item['full_name'] ?? ''));
		$owner = (string)($item['owner']['login'] ?? '');
		if ($owner === '') {
			$owner = (string)($nameParts[0] ?? '');
		}

		$repo = (string)($item['name'] ?? '');
		if ($repo === '') {
			$repo = (string)($nameParts[1] ?? '');
		}

		if ($owner === '' || $repo === '') {
			return null;
		}

		return ['owner' => $owner, 'repo' => $repo];
	}//end hitCoordinates()

	/**
	 * A store card from its base fields and the package file's contents.
	 *
	 * A missing or unparseable package gives a non-installable card: the hit is
	 * surfaced, never dropped.
	 *
	 * @param array{owner:string,repo:string,stars:int,kind:string} $base The card's base fields.
	 * @param string|null $contents The package file, or null when it could not be read.
	 *
	 * @return array<string,mixed> The card.
	 *
	 * @spec openspec/specs/agent-template-github-store/spec.md#requirement-the-system-must-provide-a-server-backed-search-for-hermiq-agent-template-repos
	 */
	public function card(array $base, ?string $contents): array {
		$fields = null;
		if ($contents !== null) {
			$fields = $this->packageFields(base: $base, contents: $contents);
		}

		if ($fields === null) {
			return $base + ['installable' => false, 'unparseable' => true];
		}

		return $base + ['installable' => true, 'unparseable' => false] + $fields;

	}//end card()

	/**
	 * The name, description, category and version a package file declares.
	 *
	 * @param array{owner:string,repo:string,stars:int,kind:string} $base     The card's base fields.
	 * @param string                                                $contents The package file.
	 *
	 * @return array{name:string,description:string,category:string,version:string}|null Null when it does not parse.
	 *
	 * @spec openspec/specs/agent-template-github-store/spec.md#requirement-the-system-must-provide-a-server-backed-search-for-hermiq-agent-template-repos
	 */
	private function packageFields(array $base, string $contents): ?array {
		if ($base['kind'] === GitHubTemplateCatalogService::KIND_SKILL) {
			return $this->skillFields(repo: $base['repo'], contents: $contents);
		}

		$decoded = json_decode($contents, true);
		if (is_array($decoded) === false) {
			return null;
		}

		return [
			'name' => (string)($decoded['name'] ?? $base['repo']),
			'description' => (string)($decoded['description'] ?? ''),
			'category' => (string)($decoded['category'] ?? ''),
			'version' => (string)($decoded['version'] ?? ''),
		];

	}//end packageFields()

	/**
	 * The fields of an agentskills.io package, named after the repo when it carries no name.
	 *
	 * An agentskills.io package never fails to parse: a missing fence yields an empty name.
	 *
	 * @param string $repo     The repo the package came from.
	 * @param string $contents The SKILL.md package.
	 *
	 * @return array{name:string,description:string,category:string,version:string}
	 *
	 * @spec openspec/specs/agent-template-github-store/spec.md#requirement-the-system-must-provide-a-server-backed-search-for-hermiq-agent-template-repos
	 */
	private function skillFields(string $repo, string $contents): array {
		$parsed = (new SkillSerializer())->fromPackage(package: $contents);
		$name = $parsed['name'];
		if ($name === '') {
			$name = $repo;
		}

		return ['name' => $name, 'description' => $parsed['description'], 'category' => '', 'version' => ''];

	}//end skillFields()

	/**
	 * The file a contents-API body carries, or null when it is absent or unreadable.
	 *
	 * @param string $body The raw contents-API response body.
	 *
	 * @return string|null The decoded file contents.
	 *
	 * @spec openspec/specs/agent-template-github-store/spec.md#requirement-the-system-must-install-a-discovered-template-through-the-existing-quarantine-gate
	 */
	public function fileContents(string $body): ?string {
		$decoded = json_decode($body, true);
		if (is_array($decoded) === false) {
			return null;
		}

		$content = (string)($decoded['content'] ?? '');
		if ((string)($decoded['encoding'] ?? '') === 'base64') {
			$raw = base64_decode(str_replace("\n", '', $content), true);
			if ($raw === false) {
				return null;
			}

			return $raw;
		}

		if ($content === '') {
			return null;
		}

		return $content;
	}//end fileContents()

	/**
	 * The blob paths of a git tree that the predicate accepts, in tree order.
	 *
	 * @param array<int, mixed> $tree The raw git-tree entries.
	 * @param callable $accept Predicate deciding whether a blob path is wanted.
	 *
	 * @return array<int, string> The wanted blob paths.
	 *
	 * @spec openspec/changes/skill-package-multifile/specs/skills-marketplace/spec.md#requirement-a-multi-file-skill-survives-the-install-round-trip-intact
	 */
	public function wantedBlobPaths(array $tree, callable $accept): array {
		$paths = [];
		foreach ($tree as $entry) {
			if (is_array($entry) === false || (string)($entry['type'] ?? '') !== 'blob') {
				continue;
			}

			$path = (string)($entry['path'] ?? '');
			if ($path !== '' && $accept($path) === true) {
				$paths[] = $path;
			}
		}

		return $paths;
	}//end wantedBlobPaths()
}//end class
