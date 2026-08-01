<?php

/**
 * Unit tests for SkillController's GitHub search/install endpoints (hermiq-github-store).
 *
 * A close port of AgentTemplateControllerTest's githubSearch()/githubInstall() coverage,
 * scoped to `GitHubTemplateCatalogService::KIND_SKILL`: authentication guards (401);
 * owner/repo pattern validation before any outbound call (400); graceful degradation on a
 * catalog-service failure (never a 5xx); and install dispatching to the UNCHANGED
 * `SkillMarketplaceService::installFromSource(source: 'hub')` quarantine gate (design.md
 * Decision 2 — no new quarantine/scan logic).
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
 * @spec openspec/changes/hermiq-github-store/specs/agent-template-github-store/spec.md#requirement-the-system-must-install-a-discovered-skill-through-the-skill-quarantine-gate
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Controller;

use OCA\Hermiq\Controller\SkillController;
use OCA\Hermiq\Service\GitHubTemplateCatalogService;
use OCA\Hermiq\Service\GitHubTemplatePushService;
use OCA\Hermiq\Service\SkillBundleInstaller;
use OCA\Hermiq\Service\SkillBundleSerializer;
use OCA\Hermiq\Service\SkillMarketplaceService;
use OCA\Hermiq\Service\SkillSerializer;
use OCA\Hermiq\Service\SkillService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Tests for {@see SkillController}'s hermiq-github-store endpoints.
 *
 * @spec openspec/changes/hermiq-github-store/specs/agent-template-github-store/spec.md#requirement-the-system-must-install-a-discovered-skill-through-the-skill-quarantine-gate
 */
class SkillControllerTest extends TestCase
{
    /**
     * A Skill ObjectEntity in the given state.
     *
     * @param string               $state    The skill lifecycle state.
     * @param array<string, mixed> $overrides Payload fields to override/add.
     *
     * @return ObjectEntity
     */
    private function skill(string $state='quarantined', array $overrides=[]): ObjectEntity
    {
        $entity = new ObjectEntity();
        $entity->setUuid('skill-1');
        $entity->setObject(array_merge(['name' => 'Example skill', 'state' => $state], $overrides));
        return $entity;

    }//end skill()

    /**
     * A request mock returning the given params.
     *
     * @param array<string, mixed> $params The request params keyed by name.
     *
     * @return IRequest
     */
    private function request(array $params=[]): IRequest
    {
        $request = $this->createMock(IRequest::class);
        $request->method('getParam')->willReturnCallback(
            function (string $key, $default=null) use ($params) {
                return $params[$key] ?? $default;
            }
        );
        return $request;

    }//end request()

    /**
     * A session with the given (or no) user.
     *
     * @param string|null $uid The UID, or null for unauthenticated.
     *
     * @return IUserSession
     */
    private function session(?string $uid): IUserSession
    {
        $session = $this->createMock(IUserSession::class);
        if ($uid === null) {
            $session->method('getUser')->willReturn(null);
            return $session;
        }

        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn($uid);
        $session->method('getUser')->willReturn($user);
        return $session;

    }//end session()

    /**
     * Build the controller with the given collaborators.
     *
     * @param IUserSession                       $session      The user session.
     * @param IRequest|null                      $request      An optional request mock (defaults to no params).
     * @param SkillService|null                  $skillService An optional SkillService mock.
     * @param GitHubTemplateCatalogService|null  $catalog      An optional GitHub catalog service mock.
     * @param SkillMarketplaceService|null       $marketplace  An optional marketplace service mock.
     *
     * @return SkillController
     */
    private function controller(
        IUserSession $session,
        ?IRequest $request=null,
        ?SkillService $skillService=null,
        ?GitHubTemplateCatalogService $catalog=null,
        ?SkillMarketplaceService $marketplace=null,
        ?SkillBundleSerializer $bundle=null,
        ?GitHubTemplatePushService $push=null
    ): SkillController {
        $catalog     = ($catalog ?? $this->createMock(GitHubTemplateCatalogService::class));
        $marketplace = ($marketplace ?? $this->createMock(SkillMarketplaceService::class));
        // A REAL bundle serialiser: its whole job is composing with
        // SkillSerializer, which a mock would hide.
        $bundle = ($bundle ?? new SkillBundleSerializer(new SkillSerializer()));

        return new SkillController(
            ($request ?? $this->request()),
            ($skillService ?? $this->createMock(SkillService::class)),
            $session,
            $this->createMock(LoggerInterface::class),
            $catalog,
            $marketplace,
            $bundle,
            ($push ?? $this->createMock(GitHubTemplatePushService::class)),
            // A REAL installer over the SAME collaborators. Mocking it here would
            // silently gut every bundle-install count assertion in this file — the
            // per-skill install path is precisely what they exist to check.
            new SkillBundleInstaller(
                $catalog,
                $bundle,
                $marketplace,
                $this->createMock(LoggerInterface::class)
            )
        );

    }//end controller()

    /**
     * githubSearch() returns 401 for an unauthenticated caller, never reaching the catalog service.
     *
     * @return void
     */
    public function testGithubSearchUnauthenticated(): void
    {
        $catalog = $this->createMock(GitHubTemplateCatalogService::class);
        $catalog->expects($this->never())->method('search');

        $response = $this->controller($this->session(null), null, null, $catalog)->githubSearch();

        $this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());

    }//end testGithubSearchUnauthenticated()

    /**
     * githubSearch() calls the catalog service with `kind: KIND_SKILL` and returns 200
     * with its cards for an authenticated caller.
     *
     * @return void
     */
    public function testGithubSearchQueriesSkillKindAndReturnsCards(): void
    {
        $catalog = $this->createMock(GitHubTemplateCatalogService::class);
        $catalog->expects($this->once())
            ->method('search')
            ->with(null, 'alice', null, GitHubTemplateCatalogService::KIND_SKILL)
            ->willReturn([
                'outcome'     => 'ok',
                'cards'       => [['owner' => 'acme', 'repo' => 'demo-skill', 'kind' => 'skill']],
                'brokerUsed'  => false,
                'rateLimited' => false,
            ]);
        $catalog->method('isBrokerAvailable')->willReturn(true);

        $response = $this->controller($this->session('alice'), null, null, $catalog)->githubSearch();

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $this->assertCount(1, $response->getData()['cards']);
        $this->assertTrue($response->getData()['brokerCredentialAvailable']);

    }//end testGithubSearchQueriesSkillKindAndReturnsCards()

    /**
     * githubSearch() degrades to 200 (never a 5xx) when the catalog service throws.
     *
     * @return void
     */
    public function testGithubSearchDegradesOnFailure(): void
    {
        $catalog = $this->createMock(GitHubTemplateCatalogService::class);
        $catalog->method('search')->willThrowException(new RuntimeException('boom'));

        $response = $this->controller($this->session('alice'), null, null, $catalog)->githubSearch();

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $this->assertSame([], $response->getData()['cards']);
        $this->assertSame(GitHubTemplateCatalogService::OUTCOME_UNREACHABLE, $response->getData()['outcome']);

    }//end testGithubSearchDegradesOnFailure()

    /**
     * githubInstall() returns 401 for an unauthenticated caller, never reaching the catalog service.
     *
     * @return void
     */
    public function testGithubInstallUnauthenticated(): void
    {
        $catalog = $this->createMock(GitHubTemplateCatalogService::class);
        $catalog->expects($this->never())->method('fetchPackageFile');

        $response = $this->controller($this->session(null), null, null, $catalog)->githubInstall();

        $this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());

    }//end testGithubInstallUnauthenticated()

    /**
     * githubInstall() rejects an invalid owner/repo with 400, never calling the catalog service.
     *
     * @return void
     */
    public function testGithubInstallInvalidRepoIsBadRequest(): void
    {
        $catalog = $this->createMock(GitHubTemplateCatalogService::class);
        $catalog->expects($this->never())->method('fetchPackageFile');

        $request  = $this->request(['owner' => '../evil', 'repo' => 'demo-skill']);
        $response = $this->controller($this->session('alice'), $request, null, $catalog)->githubInstall();

        $this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
        $this->assertSame('invalid_repo', $response->getData()['error']);

    }//end testGithubInstallInvalidRepoIsBadRequest()

    /**
     * githubInstall() returns 404 when the repo's skill package file cannot be fetched.
     *
     * @return void
     */
    public function testGithubInstallMissingPackageIsNotFound(): void
    {
        $marketplace = $this->createMock(SkillMarketplaceService::class);
        $marketplace->expects($this->never())->method('installFromSource');

        $catalog = $this->createMock(GitHubTemplateCatalogService::class);
        $catalog->method('fetchPackageFile')->willReturn(null);

        $request  = $this->request(['owner' => 'acme', 'repo' => 'demo-skill']);
        $response = $this->controller($this->session('alice'), $request, null, $catalog, $marketplace)->githubInstall();

        $this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());

    }//end testGithubInstallMissingPackageIsNotFound()

    /**
     * githubInstall() fetches the package with `kind: KIND_SKILL` and installs it through
     * the UNCHANGED `installFromSource(source: 'hub')` path, landing quarantined — no new
     * quarantine/scan logic (design.md Decision 2).
     *
     * @return void
     */
    public function testGithubInstallInstallsThroughUnchangedQuarantineGate(): void
    {
        $marketplace = $this->createMock(SkillMarketplaceService::class);
        $marketplace->expects($this->once())
            ->method('installFromSource')
            ->with("---\nname: Demo skill\n---\nBody.", 'hub', 'alice')
            ->willReturn($this->skill('quarantined'));

        $catalog = $this->createMock(GitHubTemplateCatalogService::class);
        $catalog->expects($this->once())
            ->method('fetchPackageFile')
            ->with(GitHubTemplateCatalogService::KIND_SKILL, 'acme', 'demo-skill')
            ->willReturn("---\nname: Demo skill\n---\nBody.");

        $request  = $this->request(['owner' => 'acme', 'repo' => 'demo-skill']);
        $response = $this->controller($this->session('alice'), $request, null, $catalog, $marketplace)->githubInstall();

        $this->assertSame(Http::STATUS_CREATED, $response->getStatus());
        $this->assertSame('quarantined', $response->getData()['state']);

    }//end testGithubInstallInstallsThroughUnchangedQuarantineGate()

    /**
     * skill-package-multifile: githubInstall() fetches the repo's AUXILIARY files and
     * hands them to installFromSource().
     *
     * Deliberately captures the argument rather than using `->with(...)`: PHPUnit
     * permits FEWER constraints than actual arguments, so a `with()` listing only the
     * first three parameters passes whether or not auxFiles is supplied — which is
     * exactly how this path stayed silently lossy. Asserting on the captured value is
     * the only form that actually pins the behaviour.
     *
     * @return void
     *
     * @spec openspec/changes/skill-package-multifile/specs/skills-marketplace/spec.md#requirement-a-multi-file-skill-survives-the-install-round-trip-intact
     */
    public function testGithubInstallCarriesAuxiliaryFiles(): void
    {
        $aux = [
            ['name' => 'references/local-checks.md', 'content' => "1. composer check:strict\n"],
            ['name' => 'learnings.md', 'content' => "- a vetted learning\n"],
        ];

        $capturedAux = null;
        $marketplace = $this->createMock(SkillMarketplaceService::class);
        $marketplace->expects($this->once())
            ->method('installFromSource')
            ->willReturnCallback(
                function (string $package, string $source, string $createdBy, array $auxFiles=[]) use (&$capturedAux): ObjectEntity {
                    $capturedAux = $auxFiles;
                    return $this->skill('quarantined');
                }
            );

        $catalog = $this->createMock(GitHubTemplateCatalogService::class);
        $catalog->method('fetchPackageFile')->willReturn("---\nname: Demo skill\n---\nBody.");
        $catalog->expects($this->once())
            ->method('fetchAuxFiles')
            ->with(GitHubTemplateCatalogService::KIND_SKILL, 'acme', 'demo-skill')
            ->willReturn($aux);

        $request  = $this->request(['owner' => 'acme', 'repo' => 'demo-skill']);
        $response = $this->controller($this->session('alice'), $request, null, $catalog, $marketplace)->githubInstall();

        $this->assertSame(Http::STATUS_CREATED, $response->getStatus());
        $this->assertSame(
            $aux,
            $capturedAux,
            'githubInstall() MUST forward the repo auxiliary files — otherwise a published '
            .'multi-file skill re-installs as a bare SKILL.md while reporting success.'
        );

    }//end testGithubInstallCarriesAuxiliaryFiles()

    /**
     * A bundle tree built from two skills, one of which carries auxiliary files.
     *
     * @return array<string, string> The bundle tree.
     */
    private function bundleTree(): array
    {
        return (new SkillBundleSerializer(new SkillSerializer()))->toBundle(
            skills: [
                [
                    'name'        => 'alpha-skill',
                    'frontmatter' => 'name: alpha-skill',
                    'body'        => "alpha body\n",
                    'files'       => [['name' => 'references/a.md', 'content' => "aux a\n"]],
                ],
                [
                    'name'        => 'beta-skill',
                    'frontmatter' => 'name: beta-skill',
                    'body'        => "beta body\n",
                    'files'       => [],
                ],
            ]
        );

    }//end bundleTree()

    /**
     * bundleInstall() installs EVERY skill in the bundle through the unchanged
     * quarantine gate, one installFromSource() call per skill.
     *
     * @return void
     *
     * @spec openspec/changes/skill-bundle-publish/specs/skills-marketplace/spec.md#requirement-a-bundle-installs-as-many-individually-quarantined-skills
     */
    public function testBundleInstallQuarantinesEverySkill(): void
    {
        $catalog = $this->createMock(GitHubTemplateCatalogService::class);
        $catalog->method('fetchBundle')->willReturn(['files' => $this->bundleTree(), 'truncated' => false]);

        $seen        = [];
        $marketplace = $this->createMock(SkillMarketplaceService::class);
        $marketplace->method('installFromSource')->willReturnCallback(
            function (string $package, string $source, string $createdBy, array $auxFiles=[]) use (&$seen) {
                $seen[] = ['package' => $package, 'aux' => count($auxFiles)];
                return $this->skill('quarantined');
            }
        );

        $request  = $this->request(['owner' => 'acme', 'repo' => 'bundle-repo']);
        $response = $this->controller($this->session('alice'), $request, null, $catalog, $marketplace)->bundleInstall();
        $data     = $response->getData();

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $this->assertSame(2, $data['installed']);
        $this->assertSame(0, $data['failed']);
        $this->assertFalse($data['truncated']);
        $this->assertCount(2, $seen, 'One installFromSource() call per skill — quarantine is inherited, not bypassed.');
        $this->assertSame(1, $seen[0]['aux'], 'The multi-file skill keeps its auxiliary file through the bundle.');
        $this->assertSame(0, $seen[1]['aux']);

        foreach ($data['skills'] as $entry) {
            $this->assertSame('quarantined', $entry['state']);
        }

    }//end testBundleInstallQuarantinesEverySkill()

    /**
     * One failing skill does not abort the bundle, and the failure is REPORTED
     * rather than folded into a blanket error — installing one of two is a
     * materially different result from installing none.
     *
     * @return void
     *
     * @spec openspec/changes/skill-bundle-publish/specs/skills-marketplace/spec.md#requirement-a-bundle-installs-as-many-individually-quarantined-skills
     */
    public function testBundleInstallReportsPartialFailure(): void
    {
        $catalog = $this->createMock(GitHubTemplateCatalogService::class);
        $catalog->method('fetchBundle')->willReturn(['files' => $this->bundleTree(), 'truncated' => false]);

        $calls       = 0;
        $marketplace = $this->createMock(SkillMarketplaceService::class);
        $marketplace->method('installFromSource')->willReturnCallback(
            function () use (&$calls) {
                $calls++;
                if ($calls === 1) {
                    throw new RuntimeException('scan backend unavailable');
                }

                return $this->skill('quarantined');
            }
        );

        $request  = $this->request(['owner' => 'acme', 'repo' => 'bundle-repo']);
        $response = $this->controller($this->session('alice'), $request, null, $catalog, $marketplace)->bundleInstall();
        $data     = $response->getData();

        $this->assertSame(Http::STATUS_OK, $response->getStatus(), 'A partial failure is 200 + counts, never a blanket 500.');
        $this->assertSame(1, $data['installed']);
        $this->assertSame(1, $data['failed']);
        $this->assertSame('failed', $data['skills'][0]['outcome']);
        $this->assertSame('installed', $data['skills'][1]['outcome']);

    }//end testBundleInstallReportsPartialFailure()

    /**
     * A repository without the bundle manifest is a 404, never a mis-parsed
     * single skill.
     *
     * @return void
     *
     * @spec openspec/changes/skill-bundle-publish/specs/skills-marketplace/spec.md#requirement-many-skills-publish-to-a-single-repository
     */
    public function testBundleInstallRejectsNonBundle(): void
    {
        $catalog = $this->createMock(GitHubTemplateCatalogService::class);
        $catalog->method('fetchBundle')->willReturn(null);

        $marketplace = $this->createMock(SkillMarketplaceService::class);
        $marketplace->expects($this->never())->method('installFromSource');

        $request  = $this->request(['owner' => 'acme', 'repo' => 'plain-repo']);
        $response = $this->controller($this->session('alice'), $request, null, $catalog, $marketplace)->bundleInstall();

        $this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
        $this->assertSame('not_a_bundle', $response->getData()['error']);

    }//end testBundleInstallRejectsNonBundle()

    /**
     * bundlePublish() rejects an empty skill set before any GitHub call.
     *
     * @return void
     *
     * @spec openspec/changes/skill-bundle-publish/contract.md
     */
    public function testBundlePublishRejectsEmptySkillIds(): void
    {
        $push = $this->createMock(GitHubTemplatePushService::class);
        $push->expects($this->never())->method('publishBundle');

        $request  = $this->request(['owner' => 'acme', 'repo' => 'bundle-repo', 'skillIds' => []]);
        $response = $this->controller($this->session('alice'), $request, null, null, null, null, $push)->bundlePublish();

        $this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());

    }//end testBundlePublishRejectsEmptySkillIds()

    /**
     * bundlePublish() applies the publish-time file selection, so
     * `learning-candidates.md` never leaves the instance inside a bundle.
     *
     * @return void
     *
     * @spec openspec/changes/skill-bundle-publish/specs/skills-marketplace/spec.md#requirement-many-skills-publish-to-a-single-repository
     */
    public function testBundlePublishAppliesTheLearningCandidatesStrip(): void
    {
        $skillService = $this->createMock(SkillService::class);
        $skillService->method('getSkill')->willReturn(
            $this->skill('active', ['name' => 'tender-summary', 'frontmatter' => 'name: tender-summary', 'body' => "b\n"])
        );
        // The selection is the ONE place the strip happens; the controller must use it.
        $skillService->method('publishFileSelection')->willReturn(
            [['name' => 'learnings.md', 'content' => "vetted\n"]]
        );

        $captured = null;
        $push     = $this->createMock(GitHubTemplatePushService::class);
        $push->method('publishBundle')->willReturnCallback(
            function (array $files) use (&$captured): array {
                $captured = $files;
                return ['repoUrl' => 'https://github.com/acme/bundle-repo', 'commitSha' => 'deadbeef', 'created' => true];
            }
        );

        $request  = $this->request(['owner' => 'acme', 'repo' => 'bundle-repo', 'skillIds' => ['s1']]);
        $response = $this->controller($this->session('alice'), $request, $skillService, null, null, null, $push)->bundlePublish();

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $this->assertTrue($response->getData()['created']);

        $paths = array_keys($captured ?? []);
        $this->assertContains('skills/tender-summary/learnings.md', $paths);
        $this->assertNotContains('skills/tender-summary/learning-candidates.md', $paths);

    }//end testBundlePublishAppliesTheLearningCandidatesStrip()

    /**
     * A skill the serialiser DROPPED must never be reported as published.
     *
     * The defect this pins, observed on the first real bundle: 94 skills were sent,
     * the serialiser capped at 64, and the API reported all 94 as
     * `outcome: "published"`. The artefact was internally consistent — manifest and
     * tree both listed 64 — so nothing in the repository revealed the loss. Only
     * comparing against what was requested exposed it.
     *
     * @return void
     *
     * @spec openspec/changes/skill-bundle-publish/specs/skills-marketplace/spec.md#requirement-many-skills-publish-to-a-single-repository
     */
    public function testDroppedSkillsAreNotReportedAsPublished(): void
    {
        $skillService = $this->createMock(SkillService::class);
        $skillService->method('getSkill')->willReturnCallback(
            function (string $skillId): ObjectEntity {
                return $this->skill('active', ['name' => $skillId, 'frontmatter' => 'name: '.$skillId, 'body' => "b\n"]);
            }
        );
        $skillService->method('publishFileSelection')->willReturn([]);

        // A serialiser that keeps the first and drops the second.
        $bundle = $this->createMock(SkillBundleSerializer::class);
        $bundle->method('toBundle')->willReturnCallback(
            function (array $skills, ?array &$dropped=null): array {
                $dropped = [['name' => 'beta-skill', 'reason' => 'cap_reached']];
                return ['hermiq-skills.json' => '{}', 'skills/alpha-skill/SKILL.md' => 'x'];
            }
        );

        $push = $this->createMock(GitHubTemplatePushService::class);
        $push->method('publishBundle')->willReturn(
            ['repoUrl' => 'https://github.com/acme/b', 'commitSha' => 'deadbeef', 'created' => true]
        );

        $request  = $this->request(['owner' => 'acme', 'repo' => 'bundle-repo', 'skillIds' => ['alpha-skill', 'beta-skill']]);
        $response = $this->controller($this->session('alice'), $request, $skillService, null, null, $bundle, $push)->bundlePublish();
        $data     = $response->getData();

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $this->assertSame(1, $data['published'], 'Only the skill that reached the tree counts as published.');
        $this->assertSame(1, $data['dropped']);
        $this->assertTrue($data['truncated']);

        $byName = [];
        foreach ($data['skills'] as $entry) {
            $byName[$entry['name']] = $entry;
        }

        $this->assertSame('published', $byName['alpha-skill']['outcome']);
        $this->assertSame('dropped', $byName['beta-skill']['outcome'], 'A dropped skill MUST NOT read as published.');
        $this->assertSame('cap_reached', $byName['beta-skill']['reason']);

    }//end testDroppedSkillsAreNotReportedAsPublished()
}//end class