<?php

/**
 * Hermiq SkillIdentityResolver tests
 *
 * The properties that stop a re-install duplicating a skill, and — just as
 * important — the property that stops it MERGING two skills that only share a name.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Service
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

namespace OCA\Hermiq\Tests\Unit\Service;

use OCA\Hermiq\Service\SkillIdentityResolver;
use PHPUnit\Framework\TestCase;

/**
 * Tests for canonical skill identity.
 */
class SkillIdentityResolverTest extends TestCase
{

    /**
     * The canonical URL of the example skill used throughout.
     *
     * @var string
     */
    private const URL = 'https://github.com/OWNER/REPO/skills/example-skill';

    /**
     * Subject under test.
     *
     * @var SkillIdentityResolver
     */
    private SkillIdentityResolver $resolver;


    /**
     * Build the resolver.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->resolver = new SkillIdentityResolver();

    }//end setUp()


    /**
     * The canonical URL carries no git ref — a branch is not identity.
     *
     * @return void
     */
    public function testCanonicalUrlCarriesNoGitRef(): void
    {
        $url = $this->resolver->canonicalUrl(owner: 'OWNER', repo: 'REPO', bundleName: 'example-skill');

        self::assertSame(self::URL, $url);
        self::assertStringNotContainsString('/tree/', $url);
        self::assertStringNotContainsString('/blob/', $url);

    }//end testCanonicalUrlCarriesNoGitRef()


    /**
     * An exact URL match wins.
     *
     * @return void
     */
    public function testExactSourceUrlMatchWins(): void
    {
        $result = $this->resolver->resolve(
            sourceUrl: self::URL,
            name: 'Anything At All',
            existing: [['id' => 'a', 'name' => 'Unrelated', 'sourceUrl' => self::URL]]
        );

        self::assertSame('a', $result['match']['id']);
        self::assertSame(SkillIdentityResolver::MATCH_SOURCE_URL, $result['matchedBy']);

    }//end testExactSourceUrlMatchWins()


    /**
     * A skill installed before identity existed is matched once by name.
     *
     * @return void
     */
    public function testSkillWithoutIdentityIsMatchedByName(): void
    {
        $result = $this->resolver->resolve(
            sourceUrl: self::URL,
            name: 'Example Skill',
            existing: [['id' => 'legacy', 'name' => 'example-skill']]
        );

        self::assertSame('legacy', $result['match']['id']);
        self::assertSame(SkillIdentityResolver::MATCH_NAME_FALLBACK, $result['matchedBy']);

    }//end testSkillWithoutIdentityIsMatchedByName()


    /**
     * A name collision against a skill that ALREADY carries a different identity is
     * two different skills. Merging them would silently lose one, so the fallback
     * must not reach it.
     *
     * @return void
     */
    public function testNameFallbackDoesNotMatchAnAlreadyIdentifiedSkill(): void
    {
        $result = $this->resolver->resolve(
            sourceUrl: self::URL,
            name: 'example-skill',
            existing: [
                [
                    'id'        => 'other',
                    'name'      => 'example-skill',
                    'sourceUrl' => 'https://github.com/OWNER/OTHER-REPO/skills/example-skill',
                ],
            ]
        );

        self::assertNull($result['match']);
        self::assertSame(SkillIdentityResolver::MATCH_NONE, $result['matchedBy']);

    }//end testNameFallbackDoesNotMatchAnAlreadyIdentifiedSkill()


    /**
     * The same skill fetched from a mirror is the same skill. Without this the
     * duplication defect returns through a second host.
     *
     * @return void
     */
    public function testMirrorHostResolvesToTheCanonicalOne(): void
    {
        $result = $this->resolver->resolve(
            sourceUrl: self::URL,
            name: 'example-skill',
            existing: [
                [
                    'id'        => 'mirrored',
                    'name'      => 'example-skill',
                    'sourceUrl' => 'https://codeberg.org/OWNER/REPO/skills/example-skill',
                ],
            ]
        );

        self::assertSame('mirrored', $result['match']['id']);
        self::assertSame(SkillIdentityResolver::MATCH_SOURCE_URL, $result['matchedBy']);

    }//end testMirrorHostResolvesToTheCanonicalOne()


    /**
     * A genuinely new skill matches nothing.
     *
     * @return void
     */
    public function testAnUnknownSkillMatchesNothing(): void
    {
        $result = $this->resolver->resolve(
            sourceUrl: self::URL,
            name: 'brand-new',
            existing: [['id' => 'x', 'name' => 'something-else', 'sourceUrl' => '']]
        );

        self::assertNull($result['match']);

    }//end testAnUnknownSkillMatchesNothing()


}//end class
