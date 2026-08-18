<?php

/**
 * Hermiq SkillBundleSerializer.
 *
 * Turns N skills into ONE repository tree and back — the bundle format that lets a
 * skill set ship as a single artefact (skill-bundle-publish) instead of as one
 * repository per skill.
 *
 * Layout mirrors how skills already live on disk, so a real skill directory
 * round-trips without any path rewriting:
 *
 *     hermiq-skills.json          the manifest describing the set
 *     skills/<name>/SKILL.md      the skill's frontmatter + body
 *     skills/<name>/<aux path>    its auxiliary files, nesting preserved
 *
 * Deliberately compositional: every skill is delegated to SkillSerializer's
 * directory form, so the byte-for-byte frontmatter guarantee and the auxiliary
 * path-safety rules are INHERITED rather than restated. A second copy of
 * isSafeAuxPath() would be a second place to get path safety wrong.
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
 * @spec openspec/changes/skill-bundle-publish/specs/skills-marketplace/spec.md#requirement-many-skills-publish-to-a-single-repository
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service;

use Psr\Log\LoggerInterface;

/**
 * Bundle (de)serialiser — many skills in one repository tree.
 *
 * @spec openspec/changes/skill-bundle-publish/specs/skills-marketplace/spec.md#requirement-many-skills-publish-to-a-single-repository
 */
class SkillBundleSerializer {

	/**
	 * The bundle manifest at the repository root. Its presence is what makes a
	 * repository a bundle; a repo without it is explicitly NOT parsed as one.
	 *
	 * @var string
	 */
	public const MANIFEST_FILE = 'hermiq-skills.json';

	/**
	 * The directory every bundled skill lives under.
	 *
	 * @var string
	 */
	public const SKILLS_PREFIX = 'skills/';

	/**
	 * The directory every bundled agent lives under. Sibling to SKILLS_PREFIX —
	 * an agent is one JSON object (name/description/prompt/tools/…), not a
	 * SKILL.md-plus-auxiliary-files package, so it gets a single file rather
	 * than a per-entry directory.
	 *
	 * @var string
	 */
	public const AGENTS_PREFIX = 'agents/';

	/**
	 * The bundle layout version stamped on every emitted manifest. Bumped to
	 * 1.1 with the addition of `agents[]` — a 1.0 reader ignores an unknown
	 * manifest key, so this stays a MINOR bump (majorOf() gates on the major
	 * component only, so 1.0 bundles still parse under 1.1 code unchanged).
	 *
	 * @var string
	 */
	public const FORMAT_VERSION = '1.1';

	/**
	 * Maximum agents in one bundle — much smaller than MAX_SKILLS: an agent is a
	 * reasoning persona with real operational authority (tools, approval gates),
	 * not a documentation package, so a large count is itself a signal something
	 * is wrong rather than a legitimate large set to accommodate.
	 *
	 * @var int
	 */
	public const MAX_AGENTS = 64;

	/**
	 * Maximum skills in one bundle (design.md §Security 3 — fan-out bound).
	 *
	 * Sized at 512 rather than 64 after the first real bundle: hydra's set is 94
	 * skills, so a 64 cap silently discarded 30 of them. The bound exists to stop
	 * a runaway export, not to constrain a legitimate skill set — and 64 was
	 * picked before any real set had been measured. It is still a hard cap:
	 * anything beyond it is reported as dropped, never silently omitted.
	 *
	 * @var int
	 */
	public const MAX_SKILLS = 512;

	/**
	 * A valid bundled skill directory name: kebab-case, no path syntax at all.
	 * Validated BEFORE the value is ever used as a path component, so a crafted
	 * manifest name cannot escape the bundle.
	 *
	 * @var string
	 */
	private const NAME_PATTERN = '/^[a-z0-9]+(?:-[a-z0-9]+)*$/';

	/**
	 * Constructor.
	 *
	 * @param SkillSerializer $skillSerializer The per-skill directory-form (de)serialiser.
	 * @param LoggerInterface|null $logger PSR logger for rejected-entry diagnostics.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly SkillSerializer $skillSerializer,
		private readonly ?LoggerInterface $logger = null,
	) {
	}//end __construct()

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
	 * @return array<string, string> The `path => contents` bundle tree.
	 *
	 * @spec openspec/changes/skill-bundle-publish/specs/skills-marketplace/spec.md#requirement-many-skills-publish-to-a-single-repository
	 */
	public function toBundle(array $skills, ?array &$dropped = null, array $agents = [], ?array &$droppedAgents = null): array {
		$tree = [];
		$entries = [];
		$agentEntries = [];
		$dropped = [];
		$droppedAgents = [];

		foreach ($this->selectEntries(items: $skills, cap: self::MAX_SKILLS, kind: 'skill', dropped: $dropped) as $accepted) {
			$name = $accepted['name'];

			$files = $this->skillSerializer->toPackageFiles(skill: $accepted['item']);
			foreach ($files as $path => $contents) {
				$tree[self::SKILLS_PREFIX . $name . '/' . $path] = $contents;
			}

			$entries[] = [
				'name' => $name,
				'files' => (count($files) - 1),
			];
		}//end foreach

		foreach ($this->selectEntries(items: $agents, cap: self::MAX_AGENTS, kind: 'agent', dropped: $droppedAgents) as $accepted) {
			// The whole payload is opaque data to this class — verbatim through,
			// same as a skill's frontmatter/body. Only `uuid`/OR envelope fields
			// are stripped: a bundle installed onto a DIFFERENT instance must get
			// a fresh identity, not silently collide with (or overwrite) whatever
			// already holds that uuid there.
			$payload = $accepted['item'];
			unset($payload['uuid'], $payload['id'], $payload['@self']);
			$tree[self::AGENTS_PREFIX . $accepted['name'] . '.json'] = ($this->encode(value: $payload));

			$agentEntries[] = ['name' => $accepted['name']];
		}//end foreach

		$manifest = [
			'formatVersion' => self::FORMAT_VERSION,
			'skills' => $entries,
			'agents' => $agentEntries,
		];

		return array_merge([self::MANIFEST_FILE => $this->encode(value: $manifest)], $tree);

	}//end toBundle()

	/**
	 * Validate, de-duplicate and cap a set of publishable items.
	 *
	 * Skills and agents were selected by two loops that differed only in the
	 * noun in their log lines. One implementation, not two: the two channels
	 * enforce the SAME three rules — a name that survives {@see normaliseName()},
	 * a cap, and no two items landing on one path — and a rule that has to be
	 * re-typed per channel is a rule that will eventually only hold on one of
	 * them.
	 *
	 * @param array<int,mixed> $items The requested payloads; a non-array entry is skipped silently.
	 * @param int $cap The maximum number of accepted entries for this channel.
	 * @param string $kind The channel noun, for diagnostics only ('skill' / 'agent').
	 * @param array<int,array<string,string>> $dropped OUT: every item NOT accepted, as `{name, reason}`.
	 *
	 * @return array<int,array{name:string,item:array<string,mixed>}> The accepted items, in request order.
	 */
	private function selectEntries(array $items, int $cap, string $kind, array &$dropped): array {
		$accepted = [];
		$usedNames = [];

		foreach ($items as $item) {
			if (is_array($item) === false) {
				continue;
			}

			$rawName = (string)($item['name'] ?? '');
			$name = $this->normaliseName(name: $rawName);
			if ($name === null) {
				$this->reject(what: $rawName, why: 'not a valid bundled ' . $kind . ' name');
				$dropped[] = ['name' => $rawName, 'reason' => 'invalid_name'];
				continue;
			}

			if (count($accepted) >= $cap) {
				$this->reject(what: $name, why: 'bundle ' . $kind . ' cap of ' . $cap . ' reached');
				$dropped[] = ['name' => $name, 'reason' => 'cap_reached'];
				continue;
			}

			// Sanitising a name to a safe path component can map two different
			// items onto one path. Writing both would silently overwrite the
			// first — a dropped item wearing a successful publish. Reported.
			if (isset($usedNames[$name]) === true) {
				$this->reject(what: $rawName, why: $kind . ' path name "' . $name . '" already taken in this bundle');
				$dropped[] = ['name' => $rawName, 'reason' => 'duplicate_directory_name'];
				continue;
			}

			$usedNames[$name] = true;
			$accepted[] = ['name' => $name, 'item' => $item];
		}//end foreach

		return $accepted;
	}//end selectEntries()

	/**
	 * Encode a bundle payload in the one JSON shape every bundle file uses.
	 *
	 * @param array<string,mixed> $value The payload.
	 *
	 * @return string The encoded JSON, newline-terminated.
	 */
	private function encode(array $value): string {
		return (json_encode($value, (JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) . "\n");
	}//end encode()

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
	 * @spec openspec/changes/skill-bundle-publish/specs/skills-marketplace/spec.md#requirement-bundle-entries-are-validated-before-use-as-paths
	 */
	public function fromBundle(array $files): array {
		return $this->parseDeclared(
			declared: $this->readManifest(files: $files),
			cap: self::MAX_SKILLS,
			kind: 'skill',
			load: function (string $name) use ($files): ?array {
				$own = $this->entriesFor(name: $name, files: $files);
				if (isset($own[SkillSerializer::SKILL_FILE]) === false) {
					$this->reject(what: $name, why: 'declared in the manifest but has no ' . SkillSerializer::SKILL_FILE);
					return null;
				}

				return $this->skillSerializer->fromPackageFiles(files: $own);
			}
		);
	}//end fromBundle()

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
	public function agentsFromBundle(array $files): array {
		return $this->parseDeclared(
			declared: $this->readAgentManifest(files: $files),
			cap: self::MAX_AGENTS,
			kind: 'agent',
			load: function (string $name) use ($files): ?array {
				$path = self::AGENTS_PREFIX . $name . '.json';
				$raw = ($files[$path] ?? null);
				if (is_string($raw) === false || $raw === '') {
					$this->reject(what: $name, why: 'declared in the manifest but ' . $path . ' is missing');
					return null;
				}

				$decoded = json_decode($raw, true);
				if (is_array($decoded) === false) {
					$this->reject(what: $name, why: $path . ' is not valid JSON');
					return null;
				}

				return $decoded;
			}
		);
	}//end agentsFromBundle()

	/**
	 * Walk a manifest's declared names, load each one, and cap the result.
	 *
	 * The skills channel and the agents channel read back through the SAME three
	 * rules — the declared name must survive {@see normaliseName()}, the channel
	 * cap applies, and an entry that will not load is dropped rather than half
	 * parsed — and differ only in HOW one entry's bytes become a payload. That
	 * difference is the `$load` callback; everything around it is shared, so a
	 * name that is unsafe to use as a path is rejected identically on both
	 * channels instead of in two places that have to be kept in step.
	 *
	 * @param array<int,string>|null $declared The manifest's declared names, or null when the manifest is absent/unsupported.
	 * @param int $cap The channel's maximum entry count.
	 * @param string $kind The channel noun, for diagnostics only ('skill' / 'agent').
	 * @param callable $load Loader taking the validated name and returning the payload, or null when it rejected the entry itself.
	 *
	 * @return array<int, array<string, mixed>> One parsed payload per accepted entry.
	 */
	private function parseDeclared(?array $declared, int $cap, string $kind, callable $load): array {
		if ($declared === null) {
			return [];
		}

		$parsed = [];
		foreach ($declared as $name) {
			$safeName = $this->normaliseName(name: $name);
			if ($safeName === null) {
				$this->reject(what: $name, why: 'manifest name is not a safe bundled ' . $kind . ' name');
				continue;
			}

			if (count($parsed) >= $cap) {
				$this->reject(what: $safeName, why: 'bundle ' . $kind . ' cap of ' . $cap . ' reached');
				continue;
			}

			$payload = $load($safeName);
			if ($payload === null) {
				continue;
			}

			if ((string)($payload['name'] ?? '') === '') {
				$payload['name'] = $safeName;
			}

			$payload['bundleName'] = $safeName;

			$parsed[] = $payload;
		}//end foreach

		return $parsed;
	}//end parseDeclared()

	/**
	 * Collect one bundled skill's own entries, stripped of its `skills/<name>/`
	 * prefix and re-validated.
	 *
	 * Defence in depth: the prefix matching alone is not enough — the remainder
	 * must be a safe relative path in its own right, checked by the SAME
	 * `isSafeAuxPath()` the single-skill install uses. An entry that escapes its
	 * own prefix is dropped and logged, never rewritten to a safe form.
	 *
	 * @param string $name The validated bundled skill name.
	 * @param array<string, string> $files The whole bundle tree.
	 *
	 * @return array<string, string> The skill's own `relative path => contents` map.
	 *
	 * @spec openspec/changes/skill-bundle-publish/specs/skills-marketplace/spec.md#requirement-bundle-entries-are-validated-before-use-as-paths
	 */
	private function entriesFor(string $name, array $files): array {
		$prefix = self::SKILLS_PREFIX . $name . '/';
		$own = [];

		foreach ($files as $path => $contents) {
			$path = (string)$path;
			if (str_starts_with($path, $prefix) === false) {
				continue;
			}

			$relative = substr($path, strlen($prefix));
			if ($relative !== SkillSerializer::SKILL_FILE
				&& $this->skillSerializer->isSafeAuxPath(path: $relative) === false
			) {
				$this->reject(what: $path, why: 'entry escapes its own skills/<name>/ prefix');
				continue;
			}

			$own[$relative] = (string)$contents;
		}//end foreach

		return $own;
	}//end entriesFor()

	/**
	 * Rebuild the single-skill package string for a parsed bundle entry.
	 *
	 * The bundle install fans out to the ordinary `installFromSource()`, which takes
	 * a package string plus auxiliary files. Delegated to SkillSerializer so the
	 * fenced form is produced in exactly one place.
	 *
	 * @param array<string, mixed> $skill A skill payload from {@see fromBundle()}.
	 *
	 * @return string The agentskills.io package string.
	 *
	 * @spec openspec/changes/skill-bundle-publish/specs/skills-marketplace/spec.md#requirement-a-bundle-installs-as-many-individually-quarantined-skills
	 */
	public function packageOf(array $skill): string {
		return $this->skillSerializer->toPackage(skill: $skill);
	}//end packageOf()

	/**
	 * Read + validate the manifest, returning the declared skill names.
	 *
	 * @param array<string, string> $files The repository tree.
	 *
	 * @return array<int, string>|null The declared names, or null when absent/unsupported.
	 */
	private function readManifest(array $files): ?array {
		return $this->readManifestNames(files: $files, key: 'skills');
	}//end readManifest()

	/**
	 * Read + validate the manifest, returning the declared AGENT names.
	 *
	 * Sibling to {@see readManifest()} — same version-gate, same shape — kept
	 * separate because the two lists are independently declared in the
	 * manifest (`skills[]` / `agents[]`) and a caller may want only one.
	 *
	 * @param array<string, string> $files The repository tree.
	 *
	 * @return array<int, string>|null The declared names, or null when the manifest itself is absent/unsupported.
	 */
	private function readAgentManifest(array $files): ?array {
		return $this->readManifestNames(files: $files, key: 'agents');
	}//end readAgentManifest()

	/**
	 * Read + version-gate the manifest, returning the names declared under one key.
	 *
	 * The skills list and the agents list are read from the SAME manifest under
	 * the SAME version gate; only the key differs. Two copies of that meant two
	 * copies of the version check, and a version gate that exists twice is a
	 * version gate that can be tightened once.
	 *
	 * @param array<string, string> $files The repository tree.
	 * @param string $key The manifest key to read (`skills` / `agents`).
	 *
	 * @return array<int, string>|null The declared names, or null when the manifest itself is absent/unsupported.
	 */
	private function readManifestNames(array $files, string $key): ?array {
		$raw = ($files[self::MANIFEST_FILE] ?? null);
		if (is_string($raw) === false || $raw === '') {
			return null;
		}

		$decoded = json_decode($raw, true);
		if (is_array($decoded) === false) {
			return null;
		}

		$version = (string)($decoded['formatVersion'] ?? '');
		if ($this->majorOf(version: $version) !== $this->majorOf(version: self::FORMAT_VERSION)) {
			$this->reject(what: $version, why: 'unsupported bundle formatVersion');
			return null;
		}

		$names = [];
		foreach ((array)($decoded[$key] ?? []) as $entry) {
			if (is_array($entry) === true) {
				$names[] = (string)($entry['name'] ?? '');
				continue;
			}

			$names[] = (string)$entry;
		}

		return $names;
	}//end readManifestNames()

	/**
	 * The major component of a dotted version string.
	 *
	 * @param string $version The version.
	 *
	 * @return string The major component.
	 */
	private function majorOf(string $version): string {
		$parts = explode('.', $version);
		return (string)($parts[0] ?? '');
	}//end majorOf()

	/**
	 * Derive a bundled skill's DIRECTORY name from its name.
	 *
	 * The directory name and the skill's own name are separate things: the skill
	 * keeps whatever it calls itself in its frontmatter, and `fromBundle()` records
	 * the directory separately as `bundleName`. Only the directory has to be
	 * filesystem-safe.
	 *
	 * Every character outside `[a-z0-9-]` is therefore replaced with `-` rather
	 * than the whole skill being rejected. Total rejection was chosen originally so
	 * that `..`, `/`, `\` and absolute paths could never reach a path
	 * concatenation — that guarantee is PRESERVED HERE BY CONSTRUCTION and is in
	 * fact stronger: those characters cannot survive the allowlist, and the result
	 * is still validated against NAME_PATTERN before it is returned, so a value
	 * that does not match can never escape this method.
	 *
	 * The change matters because rejection was silently costing real skills. A
	 * skill legitimately named `intelligence:update` — the `/namespace:command`
	 * convention — was dropped from the bundle entirely for a character that only
	 * ever mattered to a directory name. Losing a skill is a far worse outcome
	 * than renaming its folder.
	 *
	 * @param string $name The candidate name.
	 *
	 * @return string|null The directory name, or null when nothing usable remains.
	 */
	private function normaliseName(string $name): ?string {
		$candidate = strtolower(trim($name));

		// A PATH is never a name. Anything carrying a separator or a traversal
		// sequence is rejected outright, exactly as before — laundering
		// `../../etc/passwd` into a tidy `etc` would accept a hostile value under
		// a clean-looking folder, which is worse than refusing it. Only genuinely
		// punctuated names (`intelligence:update`) reach the sanitiser below.
		if (str_contains($candidate, '/') === true
			|| str_contains($candidate, '\\') === true
			|| str_contains($candidate, '..') === true
		) {
			return null;
		}

		// Allowlist, not denylist: anything not explicitly safe becomes a dash,
		// so no separator character can survive into a path even if one slips
		// past the check above.
		$candidate = preg_replace('/[^a-z0-9]+/', '-', $candidate);
		$candidate = trim((string)$candidate, '-');

		if ($candidate === '' || preg_match(self::NAME_PATTERN, $candidate) !== 1) {
			return null;
		}

		return $candidate;
	}//end normaliseName()

	/**
	 * Log a rejected bundle entry. Names/paths are logged; content never is.
	 *
	 * @param string $what The rejected name or path.
	 * @param string $why The reason.
	 *
	 * @return void
	 */
	private function reject(string $what, string $why): void {
		if ($this->logger === null) {
			return;
		}

		$this->logger->warning(
			'Hermiq skill bundle: rejected entry — ' . $why . '.',
			['entry' => $what]
		);

	}//end reject()
}//end class
