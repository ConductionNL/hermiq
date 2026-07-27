<?php

/**
 * Unit tests for SkillVersionController (skill-self-improvement).
 *
 * Covers the owner guard on the version endpoints (missing skill and non-owner
 * are indistinguishable — both 404, never 403); rollback as an explicit action
 * returning the NEW version id; and the republish carve-out: the push targets
 * EXACTLY the skill's stamped `githubOwner`/`githubRepo` provenance (client
 * coordinates are structurally impossible), the committed selection is the
 * `learning-candidates.md`-stripped `publishFileSelection()`, a missing broker
 * is a fail-closed 503, an unpublished skill is a 400 (no carve-out without
 * provenance), and `publishedAt` is restamped on success.
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Controller
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
 * @spec openspec/specs/skills-marketplace/spec.md#requirement-a-skill-can-be-published-to-a-tagged-github-repository-as-the-primary-path
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Controller;

use OCA\Hermiq\Controller\SkillVersionController;
use OCA\Hermiq\Service\ActionAuthService;
use OCA\Hermiq\Service\GitHubTemplatePushService;
use OCA\Hermiq\Service\SkillService;
use OCA\Hermiq\Service\SkillVersionService;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * SkillVersionController guard + republish tests (skill-self-improvement).
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) The controller's own collaborator set.
 *
 * @spec openspec/specs/skill-self-improvement/spec.md#requirement-skills-have-version-history-diff-and-rollback-mirroring-agent-versioning
 */
class SkillVersionControllerTest extends TestCase
{

    /**
     * The prepared skill-service mock.
     *
     * @var SkillService&MockObject
     */
    private SkillService&MockObject $skillService;

    /**
     * The prepared version-service mock.
     *
     * @var SkillVersionService&MockObject
     */
    private SkillVersionService&MockObject $versionService;

    /**
     * The prepared push-service mock.
     *
     * @var GitHubTemplatePushService&MockObject
     */
    private GitHubTemplatePushService&MockObject $pushService;

    /**
     * The prepared request mock.
     *
     * @var IRequest&MockObject
     */
    private IRequest&MockObject $request;

    /**
     * A user session resolving to $uid.
     *
     * @param string $uid The UID.
     *
     * @return IUserSession
     */
    private function session(string $uid): IUserSession
    {
        $session = $this->createMock(IUserSession::class);
        $user    = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn($uid);
        $session->method('getUser')->willReturn($user);
        return $session;

    }//end session()

    /**
     * Build the controller under test.
     *
     * @param IUserSession $session The user session.
     *
     * @return SkillVersionController
     */
    private function controller(IUserSession $session): SkillVersionController
    {
        $this->skillService   = $this->createMock(SkillService::class);
        $this->versionService = $this->createMock(SkillVersionService::class);
        $this->pushService    = $this->createMock(GitHubTemplatePushService::class);
        $this->request        = $this->createMock(IRequest::class);

        return new SkillVersionController(
            $this->request,
            $this->skillService,
            $this->versionService,
            $this->pushService,
            $this->createMock(ActionAuthService::class),
            $this->createMock(AuditTrailMapper::class),
            $session,
            $this->createMock(LoggerInterface::class)
        );

    }//end controller()

    /**
     * A skill entity with GitHub provenance stamped.
     *
     * @param string $owner The owner uid.
     *
     * @return ObjectEntity
     */
    private function publishedSkill(string $owner='alice'): ObjectEntity
    {
        $entity = new ObjectEntity();
        $entity->setUuid('skill-1');
        $entity->setOwner($owner);
        $entity->setObject(
            [
                'name'        => 'tender-summary',
                'githubOwner' => 'YOUR_OWNER_HERE',
                'githubRepo'  => 'hermiq-skill-example',
                'publishedAt' => '2026-07-01T00:00:00+00:00',
            ]
        );
        return $entity;

    }//end publishedSkill()

    /**
     * The version endpoints are owner-guarded: a visible-but-not-owned skill and a
     * missing skill are BOTH 404 — never a 403 that confirms existence.
     *
     * @return void
     *
     * @spec openspec/specs/skill-self-improvement/spec.md#requirement-skills-have-version-history-diff-and-rollback-mirroring-agent-versioning
     */
    public function testVersionEndpointsOwnerGuardWith404Never403(): void
    {
        $controller = $this->controller(session: $this->session('mallory'));
        $this->skillService->method('getSkill')->willReturn($this->publishedSkill(owner: 'alice'));
        $this->versionService->expects($this->never())->method('listVersions');
        $this->versionService->expects($this->never())->method('rollback');

        $this->assertSame(Http::STATUS_NOT_FOUND, $controller->index('skill-1')->getStatus());
        $this->assertSame(Http::STATUS_NOT_FOUND, $controller->rollback('skill-1')->getStatus());

    }//end testVersionEndpointsOwnerGuardWith404Never403()

    /**
     * Rollback (owner) delegates to the version service and returns the NEW
     * version id.
     *
     * @return void
     *
     * @spec openspec/specs/skill-self-improvement/spec.md#requirement-skills-have-version-history-diff-and-rollback-mirroring-agent-versioning
     */
    public function testRollbackReturnsTheNewVersionId(): void
    {
        $controller = $this->controller(session: $this->session('alice'));
        $skill      = $this->publishedSkill(owner: 'alice');
        $this->skillService->method('getSkill')->willReturn($skill);
        $this->request->method('getParam')->willReturnMap([['versionId', '', 'v2']]);
        $this->versionService->expects($this->once())->method('rollback')->willReturn($skill);
        $this->versionService->method('currentVersionId')->willReturn('v-new');

        $response = $controller->rollback('skill-1');

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $this->assertSame('v-new', $response->getData()['versionId']);

    }//end testRollbackReturnsTheNewVersionId()

    /**
     * Republish pushes to EXACTLY the stamped provenance repo with the STRIPPED
     * file selection aboard, and restamps publishedAt on success.
     *
     * @return void
     *
     * @spec openspec/specs/skills-marketplace/spec.md#requirement-a-skill-can-be-published-to-a-tagged-github-repository-as-the-primary-path
     * @spec openspec/specs/skill-self-improvement/spec.md#requirement-an-accepted-version-behind-the-published-copy-raises-an-explicit-republish-signal
     */
    public function testRepublishTargetsTheProvenanceRepoWithStrippedSelection(): void
    {
        $controller = $this->controller(session: $this->session('alice'));
        $this->skillService->method('getSkill')->willReturn($this->publishedSkill());
        $this->skillService->method('exportSkill')->willReturn("---\nname: tender-summary\n---\nBODY");
        $selection = [
            [
                'name'    => 'learnings.md',
                'content' => '# Learnings',
            ],
        ];
        $this->skillService->method('publishFileSelection')->willReturn($selection);
        $this->request->method('getParam')->willReturnMap([['credentialId', null, 'cred-1']]);
        $this->pushService->method('isBrokerAvailable')->willReturn(true);

        $captured = [];
        $this->pushService->expects($this->once())->method('pushUpdate')->willReturnCallback(
            function (
                string $package,
                string $owner,
                string $repo,
                string $credentialId,
                ?string $actingUserId=null,
                string $kind='skill',
                array $auxFiles=[]
            ) use (&$captured): array {
                unset($package, $credentialId, $actingUserId, $kind);
                $captured = [
                    'owner'    => $owner,
                    'repo'     => $repo,
                    'auxFiles' => $auxFiles,
                ];
                return [
                    'repoUrl'   => 'https://github.com/YOUR_OWNER_HERE/hermiq-skill-example',
                    'commitSha' => 'abc123',
                ];
            }
        );
        $this->skillService->expects($this->once())->method('stampGithubPublish');

        $response = $controller->republish('skill-1');

        $this->assertSame(Http::STATUS_CREATED, $response->getStatus());
        $this->assertSame('YOUR_OWNER_HERE', $captured['owner'], 'The target is the stamped provenance owner — never client input.');
        $this->assertSame('hermiq-skill-example', $captured['repo']);
        $this->assertSame($selection, $captured['auxFiles'], 'The committed selection is the stripped publishFileSelection().');

    }//end testRepublishTargetsTheProvenanceRepoWithStrippedSelection()

    /**
     * A missing broker fails CLOSED (503) with no push attempted; an unpublished
     * skill is a 400 (no carve-out without provenance).
     *
     * @return void
     *
     * @spec openspec/specs/skills-marketplace/spec.md#requirement-a-skill-can-be-published-to-a-tagged-github-repository-as-the-primary-path
     */
    public function testRepublishFailsClosedWithoutBrokerAndRefusesUnpublishedSkills(): void
    {
        $controller = $this->controller(session: $this->session('alice'));
        $this->skillService->method('getSkill')->willReturn($this->publishedSkill());
        $this->request->method('getParam')->willReturnMap([['credentialId', null, 'cred-1']]);
        $this->pushService->method('isBrokerAvailable')->willReturn(false);
        $this->pushService->expects($this->never())->method('pushUpdate');

        $this->assertSame(Http::STATUS_SERVICE_UNAVAILABLE, $controller->republish('skill-1')->getStatus());

        // Unpublished skill: no provenance → no carve-out.
        $controller  = $this->controller(session: $this->session('alice'));
        $unpublished = new ObjectEntity();
        $unpublished->setUuid('skill-2');
        $unpublished->setOwner('alice');
        $unpublished->setObject(['name' => 'local-skill']);
        $this->skillService->method('getSkill')->willReturn($unpublished);
        $this->pushService->expects($this->never())->method('pushUpdate');

        $response = $controller->republish('skill-2');
        $this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
        $this->assertSame('not_published', $response->getData()['error']);

    }//end testRepublishFailsClosedWithoutBrokerAndRefusesUnpublishedSkills()
}//end class
