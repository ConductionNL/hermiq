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
class SkillBundleSerializer
{

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
     * The bundle layout version stamped on every emitted manifest.
     *
     * @var string
     */
    public const FORMAT_VERSION = '1.0';

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
     * @param SkillSerializer      $skillSerializer The per-skill directory-form (de)serialiser.
     * @param LoggerInterface|null $logger          PSR logger for rejected-entry diagnostics.
     *
     * @return void
     */
    public function __construct(
        private readonly SkillSerializer $skillSerializer,
        private readonly ?LoggerInterface $logger=null,
    ) {
    }//end __construct()

    /**
     * Build a bundle tree from a set of skills.
     *
     * @param array      $skills  The skill payloads (each needs `name`, `frontmatter`,
     *                            `body` and optionally `files`). Typed loosely because
     *                            callers hand through OpenRegister object payloads.
     * @param array|null $dropped OUT: every skill this call did NOT bundle, as
     *                            `{name, reason}`. A caller that reports success
     *                            without reading this is publishing an incomplete
     *                            artefact and saying otherwise — which is exactly
     *                            what happened on the first real bundle, where a
     *                            64-skill cap silently discarded 30 of hydra's 94
     *                            while the API reported all 94 as published.
     *
     * @return array<string, string> The `path => contents` bundle tree.
     *
     * @spec openspec/changes/skill-bundle-publish/specs/skills-marketplace/spec.md#requirement-many-skills-publish-to-a-single-repository
     */
    public function toBundle(array $skills, ?array &$dropped=null): array
    {
        $tree    = [];
        $entries = [];
        $dropped = [];

        foreach ($skills as $skill) {
            if (is_array($skill) === false) {
                continue;
            }

            $rawName = (string) ($skill['name'] ?? '');
            $name    = $this->normaliseName(name: $rawName);
            if ($name === null) {
                $this->reject(what: $rawName, why: 'not a valid bundled skill name');
                $dropped[] = ['name' => $rawName, 'reason' => 'invalid_name'];
                continue;
            }

            if (count($entries) >= self::MAX_SKILLS) {
                $this->reject(what: $name, why: 'bundle skill cap of '.self::MAX_SKILLS.' reached');
                $dropped[] = ['name' => $name, 'reason' => 'cap_reached'];
                continue;
            }

            $files = $this->skillSerializer->toPackageFiles(skill: $skill);
            foreach ($files as $path => $contents) {
                $tree[self::SKILLS_PREFIX.$name.'/'.$path] = $contents;
            }

            $entries[] = [
                'name'  => $name,
                'files' => (count($files) - 1),
            ];
        }//end foreach

        $manifest = [
            'formatVersion' => self::FORMAT_VERSION,
            'skills'        => $entries,
        ];

        return array_merge(
            [self::MANIFEST_FILE => (json_encode($manifest, (JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE))."\n")],
            $tree
        );

    }//end toBundle()

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
    public function fromBundle(array $files): array
    {
        $manifest = $this->readManifest(files: $files);
        if ($manifest === null) {
            return [];
        }

        $skills = [];
        foreach ($manifest as $declared) {
            $name = $this->normaliseName(name: $declared);
            if ($name === null) {
                $this->reject(what: $declared, why: 'manifest name is not a safe bundled skill name');
                continue;
            }

            if (count($skills) >= self::MAX_SKILLS) {
                $this->reject(what: $name, why: 'bundle skill cap of '.self::MAX_SKILLS.' reached');
                continue;
            }

            $own = $this->entriesFor(name: $name, files: $files);

            if (isset($own[SkillSerializer::SKILL_FILE]) === false) {
                $this->reject(what: $name, why: 'declared in the manifest but has no '.SkillSerializer::SKILL_FILE);
                continue;
            }

            $parsed = $this->skillSerializer->fromPackageFiles(files: $own);
            if ((string) ($parsed['name'] ?? '') === '') {
                $parsed['name'] = $name;
            }

            $parsed['bundleName'] = $name;

            $skills[] = $parsed;
        }//end foreach

        return $skills;

    }//end fromBundle()

    /**
     * Collect one bundled skill's own entries, stripped of its `skills/<name>/`
     * prefix and re-validated.
     *
     * Defence in depth: the prefix matching alone is not enough — the remainder
     * must be a safe relative path in its own right, checked by the SAME
     * `isSafeAuxPath()` the single-skill install uses. An entry that escapes its
     * own prefix is dropped and logged, never rewritten to a safe form.
     *
     * @param string                $name  The validated bundled skill name.
     * @param array<string, string> $files The whole bundle tree.
     *
     * @return array<string, string> The skill's own `relative path => contents` map.
     *
     * @spec openspec/changes/skill-bundle-publish/specs/skills-marketplace/spec.md#requirement-bundle-entries-are-validated-before-use-as-paths
     */
    private function entriesFor(string $name, array $files): array
    {
        $prefix = self::SKILLS_PREFIX.$name.'/';
        $own    = [];

        foreach ($files as $path => $contents) {
            $path = (string) $path;
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

            $own[$relative] = (string) $contents;
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
    public function packageOf(array $skill): string
    {
        return $this->skillSerializer->toPackage(skill: $skill);

    }//end packageOf()

    /**
     * Read + validate the manifest, returning the declared skill names.
     *
     * @param array<string, string> $files The repository tree.
     *
     * @return array<int, string>|null The declared names, or null when absent/unsupported.
     */
    private function readManifest(array $files): ?array
    {
        $raw = ($files[self::MANIFEST_FILE] ?? null);
        if (is_string($raw) === false || $raw === '') {
            return null;
        }

        $decoded = json_decode($raw, true);
        if (is_array($decoded) === false) {
            return null;
        }

        $version = (string) ($decoded['formatVersion'] ?? '');
        if ($this->majorOf(version: $version) !== $this->majorOf(version: self::FORMAT_VERSION)) {
            $this->reject(what: $version, why: 'unsupported bundle formatVersion');
            return null;
        }

        $names = [];
        foreach ((array) ($decoded['skills'] ?? []) as $entry) {
            if (is_array($entry) === true) {
                $names[] = (string) ($entry['name'] ?? '');
                continue;
            }

            $names[] = (string) $entry;
        }

        return $names;

    }//end readManifest()

    /**
     * The major component of a dotted version string.
     *
     * @param string $version The version.
     *
     * @return string The major component.
     */
    private function majorOf(string $version): string
    {
        $parts = explode('.', $version);
        return (string) ($parts[0] ?? '');

    }//end majorOf()

    /**
     * Validate a bundled skill directory name.
     *
     * Rejects anything that is not plain kebab-case — which means `..`, `/`, `\`
     * and absolute paths can never reach a path concatenation, because they never
     * match in the first place. Rejection is total: no sanitising, no coercion.
     *
     * @param string $name The candidate name.
     *
     * @return string|null The validated name, or null when unusable.
     */
    private function normaliseName(string $name): ?string
    {
        $candidate = strtolower(trim($name));
        $candidate = str_replace(' ', '-', $candidate);

        if ($candidate === '' || preg_match(self::NAME_PATTERN, $candidate) !== 1) {
            return null;
        }

        return $candidate;

    }//end normaliseName()

    /**
     * Log a rejected bundle entry. Names/paths are logged; content never is.
     *
     * @param string $what The rejected name or path.
     * @param string $why  The reason.
     *
     * @return void
     */
    private function reject(string $what, string $why): void
    {
        if ($this->logger === null) {
            return;
        }

        $this->logger->warning(
            'Hermiq skill bundle: rejected entry — '.$why.'.',
            ['entry' => $what]
        );

    }//end reject()
}//end class
