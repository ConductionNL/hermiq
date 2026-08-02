<?php

/**
 * Hermiq SkillBundleInstaller
 *
 * Installs a published skill bundle, either from repository coordinates
 * (fetch → parse → install) or from an already-parsed bundle.
 *
 * This logic previously lived as a PRIVATE method on SkillController, which meant
 * the only way to install a bundle was to be an HTTP request. OpenBuild needs to
 * install the `skills/` channel of a published app repo as part of applying that
 * app, and must NOT reimplement skill installation to do it: frontmatter
 * byte-fidelity, aux-file placement and the ADR-068 §3 rule that
 * `learning-candidates.md` never leaves the instance all have to keep living in
 * exactly one implementation. Extracting the seam is what keeps that true.
 *
 * SkillController now delegates here, so the HTTP route and the cross-app caller
 * run identical code rather than two copies that drift.
 *
 * Installing is per-skill best effort: one skill that fails to persist never
 * aborts the rest, and every skill appears in the returned outcomes.
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
 * @spec openspec/changes/skill-bundle-publish/specs/skill-bundle-publish/spec.md
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service;

use OCP\AppFramework\Db\DoesNotExistException;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * Installs published skill bundles.
 *
 * @spec openspec/changes/skill-bundle-publish/specs/skills-marketplace/spec.md#requirement-a-bundle-installs-as-many-individually-quarantined-skills
 */
class SkillBundleInstaller
{
    /**
     * Constructor.
     *
     * @param GitHubTemplateCatalogService $catalogService     Bundle fetch.
     * @param SkillBundleSerializer        $bundleSerializer   Bundle parse.
     * @param SkillMarketplaceService      $marketplaceService Per-skill install.
     * @param SkillIdentityResolver        $identityResolver   Canonical skill identity.
     * @param LoggerInterface              $logger             PSR logger.
     *
     * @return void
     */
    public function __construct(
        private readonly GitHubTemplateCatalogService $catalogService,
        private readonly SkillBundleSerializer $bundleSerializer,
        private readonly SkillMarketplaceService $marketplaceService,
        private readonly SkillIdentityResolver $identityResolver,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Fetch, parse and install a bundle from repository coordinates.
     *
     * @param string      $owner        Repo owner.
     * @param string      $repo         Repo name.
     * @param string|null $ref          Optional git ref.
     * @param string|null $actingUserId The acting user (broker identity + owner).
     * @param string|null $credentialId Optional broker credential UUID.
     *
     * @return array{installed:int,updated:int,unchanged:int,skipped:int,failed:int,truncated:bool,skills:array<int,array<string,mixed>>}
     *
     * @throws RuntimeException When the repository does not carry a bundle.
     *
     * @spec openspec/changes/skill-bundle-publish/specs/skills-marketplace/spec.md#requirement-a-bundle-installs-as-many-individually-quarantined-skills
     */
    public function installFromRepo(
        string $owner,
        string $repo,
        ?string $ref=null,
        ?string $actingUserId=null,
        ?string $credentialId=null
    ): array {
        $bundle = $this->catalogService->fetchBundle(
            owner: $owner,
            repo: $repo,
            ref: $ref,
            actingUserId: $actingUserId,
            credentialId: $credentialId
        );

        if ($bundle === null) {
            throw new RuntimeException('Hermiq bundle install: "'.$owner.'/'.$repo.'" does not carry a skill bundle.');
        }

        $parsed = $this->bundleSerializer->fromBundle(files: $bundle['files']);
        $result = $this->installParsed(
            parsed: $parsed,
            createdBy: (string) $actingUserId,
            owner: $owner,
            repo: $repo
        );

        return [
            'installed' => $result['counts']['installed'],
            'updated'   => $result['counts']['updated'],
            'unchanged' => $result['counts']['unchanged'],
            'skipped'   => $result['counts']['skipped'],
            'failed'    => $result['counts']['failed'],
            // A FLAG, not a count — fetchBundle knows truncation happened but not
            // how many blobs it did not read. Passed through as the bool it is,
            // exactly as the HTTP route already reported it; coercing it to 1
            // would claim a precise "one skill dropped" that nobody measured.
            'truncated' => $bundle['truncated'],
            'skills'    => $result['outcomes'],
        ];

    }//end installFromRepo()

    /**
     * Install every skill of an already-parsed bundle.
     *
     * Per-skill best effort: a skill that cannot be persisted is recorded as
     * failed and the remaining skills are still installed.
     *
     * @param array<int,array<string,mixed>> $parsed    The parsed bundle skills.
     * @param string                         $createdBy The owning user id.
     * @param string                         $owner     Repo owner, for skill identity.
     * @param string                         $repo      Repo name, for skill identity.
     *
     * @return array{outcomes:array<int,array<string,mixed>>,counts:array<string,int>}
     *
     * @spec openspec/changes/skill-bundle-publish/specs/skills-marketplace/spec.md#requirement-a-bundle-installs-as-many-individually-quarantined-skills
     */
    public function installParsed(array $parsed, string $createdBy, string $owner='', string $repo=''): array
    {
        $outcomes = [];
        $counts   = ['installed' => 0, 'updated' => 0, 'unchanged' => 0, 'skipped' => 0, 'failed' => 0];

        foreach ($parsed as $skill) {
            $name = (string) ($skill['bundleName'] ?? ($skill['name'] ?? ''));

            // The canonical identity of this skill. Without it installFromSource
            // cannot tell a re-install from a new skill and duplicates it.
            $sourceUrl = $this->identityResolver->canonicalUrl(
                owner: $owner,
                repo: $repo,
                bundleName: $name
            );

            $outcome = null;

            try {
                $installed = $this->marketplaceService->installFromSource(
                    package: $this->bundleSerializer->packageOf(skill: $skill),
                    source: 'hub',
                    createdBy: $createdBy,
                    auxFiles: ($skill['files'] ?? []),
                    sourceUrl: $sourceUrl,
                    outcome: $outcome
                );

                $object     = $installed->getObject();
                $verdict    = (string) ($outcome['outcome'] ?? 'installed');
                $outcomes[] = [
                    'name'          => $name,
                    'outcome'       => $verdict,
                    'state'         => (string) ($object['state'] ?? ''),
                    'severity'      => (string) (($object['scanReport'] ?? [])['severity'] ?? ''),
                    'learningsKept' => (bool) ($outcome['learningsKept'] ?? false),
                    'matchedBy'     => (string) ($outcome['matchedBy'] ?? ''),
                    'sourceUrl'     => (string) ($outcome['sourceUrl'] ?? ''),
                ];

                // An unrecognised verdict counts as an install rather than being
                // dropped: a count that silently loses an item is the defect this
                // whole change exists to remove.
                if (array_key_exists($verdict, $counts) === false) {
                    $verdict = 'installed';
                }

                $counts[$verdict]++;
            } catch (DoesNotExistException $e) {
                // OpenRegister re-throws this from the write path when the hermiq
                // register/schema cannot be resolved. Recorded per skill so one
                // failure never aborts the remaining installs.
                $this->logger->error(
                    'Hermiq bundle install: skill "'.$name.'" could not be persisted: '.$e->getMessage(),
                    ['exception' => $e]
                );
                $outcomes[] = ['name' => $name, 'outcome' => 'failed'];
                $counts['failed']++;
            } catch (Throwable $e) {
                $this->logger->error(
                    'Hermiq bundle install: skill "'.$name.'" failed: '.$e->getMessage(),
                    ['exception' => $e]
                );
                $outcomes[] = ['name' => $name, 'outcome' => 'failed'];
                $counts['failed']++;
            }//end try
        }//end foreach

        return ['outcomes' => $outcomes, 'counts' => $counts];

    }//end installParsed()
}//end class
