<?php

/**
 * Hermiq SkillIdentityResolver
 *
 * Gives a bundle-installed skill a durable identity, and finds the skill an
 * incoming bundle entry already corresponds to.
 *
 * Until this existed, `installFromSource()` called `saveObject()` with no uuid and
 * no existence check, so every install created a new object and re-installing an app
 * duplicated every skill it ships. That had already happened on the shared instance:
 * one skill held an `active` (approved) row AND a `quarantined` shadow created three
 * weeks later, competing with it.
 *
 * Identity is a canonical URL — `https://<host>/<owner>/<repo>/skills/<bundleName>`:
 *
 *  - **No git ref.** A branch is not identity. Pinning one would make the same skill
 *    on `main` and on a feature branch two different skills, reintroducing exactly
 *    the duplication this class exists to end.
 *  - **Mirror hosts normalised.** These repositories are mirrored, so without
 *    normalisation the same skill fetched from the mirror and from the origin would
 *    be two objects — the same defect returning through a side door.
 *
 * Resolution is three steps: an exact `sourceUrl` match; failing that a ONE-TIME
 * fallback to the normalised name, restricted to skills that carry NO `sourceUrl`;
 * then the caller stamps the URL so the fallback is never needed twice. The
 * restriction matters: a name collision against a skill that already carries a
 * DIFFERENT `sourceUrl` is two genuinely different skills, and merging them would
 * lose one.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\Hermiq\Service
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/skill-install-idempotency/specs/skills-marketplace/spec.md
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service;

/**
 * Resolves the canonical identity of a bundle skill, and the local skill it matches.
 */
class SkillIdentityResolver
{

    /**
     * How an incoming skill matched an existing one.
     *
     * @var string
     */
    public const MATCH_SOURCE_URL = 'source-url';

    /**
     * Matched by name because the existing skill predates identity.
     *
     * @var string
     */
    public const MATCH_NAME_FALLBACK = 'name-fallback';

    /**
     * No existing skill corresponds to this one.
     *
     * @var string
     */
    public const MATCH_NONE = 'none';

    /**
     * The host every known mirror is normalised to.
     *
     * @var string
     */
    private const CANONICAL_HOST = 'github.com';

    /**
     * Hosts that mirror the canonical one. The same repository is served from all of
     * them, so a skill installed from any of them is the SAME skill.
     *
     * @var array<int,string>
     */
    private const MIRROR_HOSTS = ['codeberg.org', 'www.github.com'];

    /**
     * Build the canonical identity URL for a bundle skill.
     *
     * @param string $owner      The repo owner.
     * @param string $repo       The repo name.
     * @param string $bundleName The skill's directory name inside `skills/`.
     * @param string $host       The host it was fetched from.
     *
     * @return string The canonical URL, or '' when the coordinates are unusable.
     *
     * @spec openspec/changes/skill-install-idempotency/specs/skills-marketplace/spec.md#requirement-the-same-skill-from-a-mirror-is-the-same-skill
     */
    public function canonicalUrl(
        string $owner,
        string $repo,
        string $bundleName,
        string $host=self::CANONICAL_HOST
    ): string {
        $owner      = trim($owner);
        $repo       = trim($repo);
        $bundleName = trim($bundleName);

        if ($owner === '' || $repo === '' || $bundleName === '') {
            return '';
        }

        return 'https://'.$this->canonicalHost(host: $host).'/'.$owner.'/'.$repo.'/skills/'.$bundleName;

    }//end canonicalUrl()

    /**
     * Normalise a mirror host to the canonical one.
     *
     * @param string $host The host as fetched from.
     *
     * @return string The canonical host.
     *
     * @spec openspec/changes/skill-install-idempotency/specs/skills-marketplace/spec.md#requirement-the-same-skill-from-a-mirror-is-the-same-skill
     */
    public function canonicalHost(string $host): string
    {
        $candidate = strtolower(trim($host));
        if ($candidate === '' || in_array($candidate, self::MIRROR_HOSTS, true) === true) {
            return self::CANONICAL_HOST;
        }

        return $candidate;

    }//end canonicalHost()

    /**
     * Find the existing skill an incoming bundle entry corresponds to.
     *
     * @param string                         $sourceUrl The incoming skill's canonical URL.
     * @param string                         $name      The incoming skill's name.
     * @param array<int,array<string,mixed>> $existing  Existing skills as payload arrays,
     *                                                  each optionally carrying `sourceUrl`,
     *                                                  `name` and an `id`.
     *
     * @return array{match:array<string,mixed>|null,matchedBy:string} The match and how.
     *
     * @spec openspec/changes/skill-install-idempotency/specs/skills-marketplace/spec.md#requirement-installing-a-skill-that-is-already-present-updates-it
     */
    public function resolve(string $sourceUrl, string $name, array $existing): array
    {
        if ($sourceUrl !== '') {
            foreach ($existing as $skill) {
                if ($this->normaliseUrl(url: (string) ($skill['sourceUrl'] ?? '')) === $sourceUrl) {
                    return ['match' => $skill, 'matchedBy' => self::MATCH_SOURCE_URL];
                }
            }
        }

        // ONE-TIME fallback for skills installed before identity existed. Restricted
        // to skills with NO sourceUrl on purpose: a name collision against a skill
        // that already carries a DIFFERENT url is two different skills, and merging
        // them would silently lose one.
        $wanted = $this->normaliseName(name: $name);
        if ($wanted === '') {
            return ['match' => null, 'matchedBy' => self::MATCH_NONE];
        }

        foreach ($existing as $skill) {
            if ((string) ($skill['sourceUrl'] ?? '') !== '') {
                continue;
            }

            if ($this->normaliseName(name: (string) ($skill['name'] ?? '')) === $wanted) {
                return ['match' => $skill, 'matchedBy' => self::MATCH_NAME_FALLBACK];
            }
        }

        return ['match' => null, 'matchedBy' => self::MATCH_NONE];

    }//end resolve()

    /**
     * Normalise a stored URL for comparison — mirrors collapse to the canonical host
     * and a trailing slash is insignificant.
     *
     * @param string $url The stored URL.
     *
     * @return string The comparable form, or '' when unusable.
     *
     * @spec openspec/changes/skill-install-idempotency/specs/skills-marketplace/spec.md#requirement-the-same-skill-from-a-mirror-is-the-same-skill
     */
    public function normaliseUrl(string $url): string
    {
        $candidate = rtrim(trim($url), '/');
        if ($candidate === '') {
            return '';
        }

        $parts = parse_url($candidate);
        if (is_array($parts) === false || isset($parts['host']) === false || isset($parts['path']) === false) {
            return '';
        }

        return 'https://'.$this->canonicalHost(host: (string) $parts['host']).rtrim((string) $parts['path'], '/');

    }//end normaliseUrl()

    /**
     * Normalise a skill name for the fallback comparison.
     *
     * Mirrors SkillBundleSerializer's bundle-directory normalisation so a skill named
     * "Meeting Summariser" in frontmatter matches the `meeting-summariser` directory
     * it ships in.
     *
     * @param string $name The skill name.
     *
     * @return string The normalised name, or '' when unusable.
     *
     * @spec openspec/changes/skill-install-idempotency/specs/skills-marketplace/spec.md#requirement-installing-a-skill-that-is-already-present-updates-it
     */
    public function normaliseName(string $name): string
    {
        $candidate = strtolower(trim($name));

        return str_replace(' ', '-', $candidate);

    }//end normaliseName()
}//end class
