<?php

/**
 * Hermiq SkillSerializer.
 *
 * Converts a Skill object to an agentskills.io package string and back. Fidelity is
 * guaranteed by preserving the raw frontmatter block verbatim (never re-dumping YAML), so
 * a serialise → deserialise round trip reproduces the frontmatter and body byte-for-byte.
 * Deliberately dependency-free (no Symfony Yaml) so it runs in the CI stub environment.
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
 * @spec openspec/changes/skills-catalog/tasks.md#2-skillserializer-lossless-round-trip
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service;

use Psr\Log\LoggerInterface;

/**
 * Lossless agentskills.io package (de)serialiser for Skill objects.
 *
 * @spec openspec/changes/skills-catalog/tasks.md#2-skillserializer-lossless-round-trip
 * @spec openspec/changes/skill-package-multifile/specs/skills-marketplace/spec.md#requirement-a-multi-file-skill-survives-the-install-round-trip-intact
 */
class SkillSerializer {

	/**
	 * The frontmatter fence delimiter.
	 *
	 * @var string
	 */
	private const FENCE = '---';

	/**
	 * The directory-form entry holding the frontmatter + body.
	 *
	 * @var string
	 */
	public const SKILL_FILE = 'SKILL.md';

	/**
	 * Maximum length of an auxiliary file path, mirroring
	 * GitHubTemplatePushService::isSafeRepoPath()'s bound so a package that
	 * published cleanly also installs cleanly.
	 *
	 * @var int
	 */
	private const MAX_PATH_LENGTH = 200;

	/**
	 * Constructor.
	 *
	 * The logger is optional so the existing dependency-free construction used by
	 * the CI stub environment and by `new SkillSerializer()` in tests keeps working;
	 * rejected auxiliary paths are simply not logged when it is absent.
	 *
	 * @param LoggerInterface|null $logger PSR logger for rejected-path diagnostics.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ?LoggerInterface $logger = null,
	) {
	}//end __construct()

	/**
	 * Serialise a Skill into an agentskills.io package string.
	 *
	 * Format: a `---` fenced frontmatter block followed by the body. The frontmatter is
	 * emitted verbatim (the raw block stored on import), so a round trip is byte-for-byte.
	 *
	 * @param array<string, mixed> $skill The skill data (needs `frontmatter` + `body`).
	 *
	 * @return string The agentskills.io package string.
	 *
	 * @spec openspec/changes/skills-catalog/tasks.md#2-skillserializer-lossless-round-trip
	 */
	public function toPackage(array $skill): string {
		$frontmatter = (string)($skill['frontmatter'] ?? '');
		$body = (string)($skill['body'] ?? '');

		return self::FENCE . "\n" . $frontmatter . "\n" . self::FENCE . "\n" . $body;
	}//end toPackage()

	/**
	 * Parse an agentskills.io package string into skill fields.
	 *
	 * Splits on the leading `---`…`---` fence: everything between the fences is the raw
	 * frontmatter block (returned verbatim), everything after is the body. `name` and
	 * `description` are extracted from the frontmatter for browsing but are never used to
	 * reconstruct the block.
	 *
	 * @param string $package The agentskills.io package string.
	 *
	 * @return array<string, string> The parsed { frontmatter, body, name, description }.
	 *
	 * @spec openspec/changes/skills-catalog/tasks.md#2-skillserializer-lossless-round-trip
	 */
	public function fromPackage(string $package): array {
		$normalised = str_replace("\r\n", "\n", $package);

		$frontmatter = '';
		$body = $normalised;

		$fenceOpen = self::FENCE . "\n";
		if (str_starts_with($normalised, $fenceOpen) === true) {
			$rest = substr($normalised, strlen($fenceOpen));
			$closePos = strpos($rest, "\n" . self::FENCE . "\n");
			if ($closePos !== false) {
				$frontmatter = substr($rest, 0, $closePos);
				$body = substr($rest, ($closePos + strlen("\n" . self::FENCE . "\n")));
			}
		}

		return [
			'frontmatter' => $frontmatter,
			'body' => $body,
			'name' => $this->extractField(frontmatter: $frontmatter, field: 'name'),
			'description' => $this->extractField(frontmatter: $frontmatter, field: 'description'),
		];

	}//end fromPackage()

	/**
	 * Serialise a Skill into DIRECTORY form: a `path => contents` map carrying
	 * `SKILL.md` plus one entry per auxiliary file at its own (possibly nested)
	 * relative path.
	 *
	 * The `SKILL.md` entry is produced by {@see toPackage()}, so the byte-for-byte
	 * frontmatter guarantee is inherited rather than reimplemented. Auxiliary
	 * entries with an unsafe path are dropped (never rewritten to a safe form) so a
	 * crafted name fails visibly instead of silently relocating.
	 *
	 * The map shape deliberately matches GitHubTemplatePushService's tree entries
	 * and OpenBuild's AppRepoSerializer::serialize() return shape, so a skill can be
	 * embedded in a larger repo tree without a further translation layer.
	 *
	 * @param array<string, mixed> $skill The skill data (`frontmatter`, `body`, `files`).
	 *
	 * @return array<string, string> The `path => contents` map, `SKILL.md` first.
	 *
	 * @spec openspec/changes/skill-package-multifile/specs/skills-marketplace/spec.md#requirement-a-multi-file-skill-survives-the-install-round-trip-intact
	 */
	public function toPackageFiles(array $skill): array {
		$out = [self::SKILL_FILE => $this->toPackage(skill: $skill)];

		$files = ($skill['files'] ?? []);
		if (is_array($files) === false) {
			return $out;
		}

		foreach ($files as $file) {
			if (is_array($file) === false) {
				continue;
			}

			$name = (string)($file['name'] ?? '');
			if ($name === self::SKILL_FILE || $this->isSafeAuxPath(path: $name) === false) {
				$this->rejectPath(path: $name, direction: 'serialise');
				continue;
			}

			$out[$name] = (string)($file['content'] ?? '');
		}

		return $out;
	}//end toPackageFiles()

	/**
	 * Parse a DIRECTORY-form package into skill fields, including `files`.
	 *
	 * `SKILL.md` is delegated verbatim to {@see fromPackage()}; every other entry
	 * becomes a `files[]` element after path validation. A package whose auxiliary
	 * entries are all unsafe still yields its body and frontmatter with an empty
	 * `files` — a bad auxiliary path must not deny installation of a valid skill.
	 *
	 * @param array<string, string> $files The `path => contents` map.
	 *
	 * @return array<string, mixed> The parsed { frontmatter, body, name, description, files }.
	 *
	 * @spec openspec/changes/skill-package-multifile/specs/skills-marketplace/spec.md#requirement-auxiliary-file-paths-are-validated-on-install
	 */
	public function fromPackageFiles(array $files): array {
		$parsed = $this->fromPackage(package: (string)($files[self::SKILL_FILE] ?? ''));
		$parsed['files'] = [];

		foreach ($files as $path => $contents) {
			$name = (string)$path;
			if ($name === self::SKILL_FILE) {
				continue;
			}

			if ($this->isSafeAuxPath(path: $name) === false) {
				$this->rejectPath(path: $name, direction: 'install');
				continue;
			}

			$parsed['files'][] = [
				'name' => $name,
				'content' => (string)$contents,
			];
		}

		return $parsed;
	}//end fromPackageFiles()

	/**
	 * Normalise the wire form the install route speaks — a `package` string plus a
	 * list of `{name, content}` entries — into the `path => contents` directory map
	 * {@see fromPackageFiles()} consumes.
	 *
	 * Kept here rather than in each caller so both install paths (SkillService and
	 * SkillMarketplaceService) share one normalisation point and cannot drift.
	 * Path safety is NOT applied here — it belongs to fromPackageFiles(), so that a
	 * rejected path is rejected exactly once, in one place.
	 *
	 * @param string $package The SKILL.md contents.
	 * @param array $auxFiles The auxiliary entries, each expected to be
	 *                        `{name, content}`. Deliberately typed loosely: this
	 *                        value arrives straight off an HTTP request, so the
	 *                        shape is asserted at runtime, not assumed.
	 *
	 * @return array<string, string> The `path => contents` map.
	 *
	 * @spec openspec/changes/skill-package-multifile/specs/skills-marketplace/spec.md#requirement-a-multi-file-skill-survives-the-install-round-trip-intact
	 */
	public function toDirectoryMap(string $package, array $auxFiles = []): array {
		$map = [self::SKILL_FILE => $package];

		foreach ($auxFiles as $file) {
			if (is_array($file) === false) {
				continue;
			}

			$name = (string)($file['name'] ?? '');
			if ($name === '' || $name === self::SKILL_FILE) {
				continue;
			}

			$map[$name] = (string)($file['content'] ?? '');
		}

		return $map;
	}//end toDirectoryMap()

	/**
	 * Whether an auxiliary path is safe to accept.
	 *
	 * Mirrors GitHubTemplatePushService::isSafeRepoPath(): no absolute path, no
	 * backslash, no empty/`.`/`..` segment, bounded length. These paths are stored
	 * as `files[].name` strings and never resolved against the filesystem here, but
	 * a downstream consumer (the app-repo install path) does materialise them —
	 * so this is defence in depth, validated at the point of entry.
	 *
	 * @param string $path The candidate auxiliary path.
	 *
	 * @return bool True when the path is safe to persist.
	 *
	 * @spec openspec/changes/skill-package-multifile/specs/skills-marketplace/spec.md#requirement-auxiliary-file-paths-are-validated-on-install
	 */
	public function isSafeAuxPath(string $path): bool {
		if ($path === '' || strlen($path) > self::MAX_PATH_LENGTH) {
			return false;
		}

		if (str_starts_with($path, '/') === true || str_contains($path, '\\') === true) {
			return false;
		}

		foreach (explode('/', $path) as $segment) {
			if ($segment === '' || $segment === '.' || $segment === '..') {
				return false;
			}
		}

		return true;
	}//end isSafeAuxPath()

	/**
	 * Record a rejected auxiliary path. The path is logged so a dropped entry is
	 * traceable rather than silent; the content is never logged.
	 *
	 * @param string $path The rejected path.
	 * @param string $direction Either `serialise` or `install`.
	 *
	 * @return void
	 */
	private function rejectPath(string $path, string $direction): void {
		if ($this->logger === null) {
			return;
		}

		$this->logger->warning(
			'Hermiq skill package: rejected unsafe auxiliary path on ' . $direction . '.',
			['path' => $path]
		);

	}//end rejectPath()

	/**
	 * Extract a scalar `field: value` from a raw frontmatter block.
	 *
	 * @param string $frontmatter The raw frontmatter block.
	 * @param string $field The field name.
	 *
	 * @return string The trimmed, unquoted value (empty when absent).
	 */
	private function extractField(string $frontmatter, string $field): string {
		$pattern = '/^' . preg_quote($field, '/') . ':\s*(.*)$/m';
		if (preg_match($pattern, $frontmatter, $matches) !== 1) {
			return '';
		}

		$value = trim($matches[1]);
		// Strip a single pair of surrounding quotes, if present.
		if (strlen($value) >= 2) {
			$first = $value[0];
			$last = $value[(strlen($value) - 1)];
			if (($first === '"' && $last === '"') || ($first === "'" && $last === "'")) {
				$value = substr($value, 1, -1);
			}
		}

		return $value;
	}//end extractField()
}//end class
