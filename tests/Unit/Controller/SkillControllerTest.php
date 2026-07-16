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
use OCA\Hermiq\Service\SkillMarketplaceService;
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
     * @param string $state The skill lifecycle state.
     *
     * @return ObjectEntity
     */
    private function skill(string $state='quarantined'): ObjectEntity
    {
        $entity = new ObjectEntity();
        $entity->setUuid('skill-1');
        $entity->setObject(['name' => 'Example skill', 'state' => $state]);
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
        ?SkillMarketplaceService $marketplace=null
    ): SkillController {
        return new SkillController(
            ($request ?? $this->request()),
            ($skillService ?? $this->createMock(SkillService::class)),
            $session,
            $this->createMock(LoggerInterface::class),
            ($catalog ?? $this->createMock(GitHubTemplateCatalogService::class)),
            ($marketplace ?? $this->createMock(SkillMarketplaceService::class))
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
}//end class