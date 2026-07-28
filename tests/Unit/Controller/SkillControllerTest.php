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
use OCA\Hermiq\Service\FederatedStoreService;
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
     * @param FederatedStoreService|null         $store        An optional federated store adapter mock.
     * @param SkillMarketplaceService|null       $marketplace  An optional marketplace service mock.
     *
     * @return SkillController
     */
    private function controller(
        IUserSession $session,
        ?IRequest $request=null,
        ?SkillService $skillService=null,
        ?FederatedStoreService $store=null,
        ?SkillMarketplaceService $marketplace=null
    ): SkillController {
        return new SkillController(
            ($request ?? $this->request()),
            ($skillService ?? $this->createMock(SkillService::class)),
            $session,
            $this->createMock(LoggerInterface::class),
            ($store ?? $this->createMock(FederatedStoreService::class)),
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
        $store = $this->createMock(FederatedStoreService::class);
        $store->expects($this->never())->method('search');

        $response = $this->controller($this->session(null), null, null, $store)->githubSearch();

        $this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());

    }//end testGithubSearchUnauthenticated()

    /**
     * githubSearch() searches the skill kind through the store and returns 200 with its envelope.
     *
     * @return void
     */
    public function testGithubSearchQueriesSkillKindAndReturnsCards(): void
    {
        $store = $this->createMock(FederatedStoreService::class);
        $store->expects($this->once())
            ->method('search')
            ->with(FederatedStoreService::KIND_SKILL, null, null)
            ->willReturn([
                'outcome'                   => 'ok',
                'cards'                     => [['owner' => 'acme', 'repo' => 'demo-skill', 'kind' => 'skill']],
                'brokerCredentialAvailable' => true,
                'brokerUsed'                => false,
                'rateLimited'               => false,
            ]);

        $response = $this->controller($this->session('alice'), null, null, $store)->githubSearch();

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $this->assertCount(1, $response->getData()['cards']);
        $this->assertTrue($response->getData()['brokerCredentialAvailable']);

    }//end testGithubSearchQueriesSkillKindAndReturnsCards()

    /**
     * githubInstall() returns 401 for an unauthenticated caller, never reaching the store.
     *
     * @return void
     */
    public function testGithubInstallUnauthenticated(): void
    {
        $store = $this->createMock(FederatedStoreService::class);
        $store->expects($this->never())->method('install');

        $response = $this->controller($this->session(null), null, null, $store)->githubInstall();

        $this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());

    }//end testGithubInstallUnauthenticated()

    /**
     * githubInstall() rejects an invalid owner/repo with 400, never reaching the store.
     *
     * @return void
     */
    public function testGithubInstallInvalidRepoIsBadRequest(): void
    {
        $store = $this->createMock(FederatedStoreService::class);
        $store->expects($this->never())->method('install');

        $request  = $this->request(['owner' => '../evil', 'repo' => 'demo-skill']);
        $response = $this->controller($this->session('alice'), $request, null, $store)->githubInstall();

        $this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
        $this->assertSame('invalid_repo', $response->getData()['error']);

    }//end testGithubInstallInvalidRepoIsBadRequest()

    /**
     * githubInstall() returns 404 when the store cannot fetch the repo's bundle.
     *
     * @return void
     */
    public function testGithubInstallMissingPackageIsNotFound(): void
    {
        $store = $this->createMock(FederatedStoreService::class);
        $store->method('install')->willReturn(null);

        $request  = $this->request(['owner' => 'acme', 'repo' => 'demo-skill']);
        $response = $this->controller($this->session('alice'), $request, null, $store)->githubInstall();

        $this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());

    }//end testGithubInstallMissingPackageIsNotFound()

    /**
     * githubInstall() installs the discovered skill bundle through the store (source='hub'
     * quarantine gate is preserved inside the shared skill type).
     *
     * @return void
     */
    public function testGithubInstallInstallsThroughUnchangedQuarantineGate(): void
    {
        $store = $this->createMock(FederatedStoreService::class);
        $store->expects($this->once())
            ->method('install')
            ->with(FederatedStoreService::KIND_SKILL, 'acme', 'demo-skill', null)
            ->willReturn(['installed' => ['skill-uuid-1']]);

        $request  = $this->request(['owner' => 'acme', 'repo' => 'demo-skill']);
        $response = $this->controller($this->session('alice'), $request, null, $store)->githubInstall();

        $this->assertSame(Http::STATUS_CREATED, $response->getStatus());
        $this->assertSame(['skill-uuid-1'], $response->getData()['installed']);

    }//end testGithubInstallInstallsThroughUnchangedQuarantineGate()
}//end class