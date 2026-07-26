<?php

/**
 * Unit tests for AgentTemplateController (agent-template-gallery).
 *
 * Focuses on: authentication guards (401) on every route; approve()'s ADR-023 action-auth
 * gate (`agenttemplate.approve-quarantined`, plus the stricter
 * `agenttemplate.override-scan-verdict` on the `force=true` path — mirrors
 * SkillMarketplaceControllerTest exactly); import()'s empty-package validation; and
 * instantiate()'s active-organisation resolution.
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
 * @spec openspec/changes/agent-template-gallery/tasks.md#task-5-agenttemplatecontroller-routes-adr-023-action-seed
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Controller;

use OCA\Hermiq\Controller\AgentTemplateController;
use OCA\Hermiq\Service\ActionAuthService;
use OCA\Hermiq\Service\AgentTemplateService;
use OCA\Hermiq\Service\FederatedStoreService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\OrganisationMapper;
use OCP\AppFramework\Http;
use OCP\AppFramework\OCS\OCSForbiddenException;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Tests for the agent-template-gallery controller's auth + action-auth gates.
 *
 * @spec openspec/changes/agent-template-gallery/tasks.md#task-5-agenttemplatecontroller-routes-adr-023-action-seed
 */
class AgentTemplateControllerTest extends TestCase
{
    /**
     * An AgentTemplate ObjectEntity in the given state.
     *
     * @param string $state The template lifecycle state.
     *
     * @return ObjectEntity
     */
    private function template(string $state='active'): ObjectEntity
    {
        $entity = new ObjectEntity();
        $entity->setUuid('template-1');
        $entity->setObject(['name' => 'Example template', 'state' => $state]);
        return $entity;

    }//end template()

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
        $request->method('getParams')->willReturn($params);
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
     * An OrganisationMapper resolving every uid to the given organisation.
     *
     * @param string $organisation The organisation to resolve.
     *
     * @return OrganisationMapper
     */
    private function organisationMapper(string $organisation='org-a'): OrganisationMapper
    {
        $mapper = $this->createMock(OrganisationMapper::class);
        $mapper->method('getActiveOrganisationWithFallback')->willReturn($organisation);
        return $mapper;

    }//end organisationMapper()

    /**
     * Build the controller with the given collaborators.
     *
     * @param AgentTemplateService              $service    The template service.
     * @param ActionAuthService                 $actionAuth The action-auth service.
     * @param IUserSession                      $session    The user session.
     * @param IRequest|null                      $request    An optional request mock (defaults to no params).
     * @param OrganisationMapper|null            $mapper     An optional organisation mapper.
     * @param FederatedStoreService|null         $store      An optional federated store adapter mock.
     *
     * @return AgentTemplateController
     */
    private function controller(
        AgentTemplateService $service,
        ActionAuthService $actionAuth,
        IUserSession $session,
        ?IRequest $request=null,
        ?OrganisationMapper $mapper=null,
        ?FederatedStoreService $store=null
    ): AgentTemplateController {
        return new AgentTemplateController(
            ($request ?? $this->request()),
            $service,
            $actionAuth,
            $session,
            ($mapper ?? $this->organisationMapper()),
            $this->createMock(LoggerInterface::class),
            ($store ?? $this->createMock(FederatedStoreService::class))
        );

    }//end controller()

    /**
     * index() returns 401 for an unauthenticated caller, never reaching the service.
     *
     * @return void
     */
    public function testIndexUnauthenticated(): void
    {
        $service = $this->createMock(AgentTemplateService::class);
        $service->expects($this->never())->method('list');

        $response = $this->controller($service, $this->createMock(ActionAuthService::class), $this->session(null))->index();

        $this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());

    }//end testIndexUnauthenticated()

    /**
     * index() returns 200 with the tenant's templates for an authenticated caller.
     *
     * @return void
     */
    public function testIndexReturnsTemplates(): void
    {
        $service = $this->createMock(AgentTemplateService::class);
        $service->method('list')->willReturn([$this->template()]);

        $response = $this->controller($service, $this->createMock(ActionAuthService::class), $this->session('alice'))->index();

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $this->assertCount(1, $response->getData()['results']);

    }//end testIndexReturnsTemplates()

    /**
     * import() rejects an empty package with 400, never reaching the service.
     *
     * @return void
     */
    public function testImportEmptyPackageIsBadRequest(): void
    {
        $service = $this->createMock(AgentTemplateService::class);
        $service->expects($this->never())->method('importPackage');

        $request  = $this->request(['package' => '   ']);
        $response = $this->controller($service, $this->createMock(ActionAuthService::class), $this->session('alice'), $request)->import();

        $this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());

    }//end testImportEmptyPackageIsBadRequest()

    /**
     * import() returns 201 with the quarantined template for a valid org-sourced package.
     *
     * @return void
     */
    public function testImportReturnsQuarantinedTemplate(): void
    {
        $service = $this->createMock(AgentTemplateService::class);
        $service->method('importPackage')->willReturn($this->template('quarantined'));

        $request  = $this->request(['package' => '{"name":"Demo"}', 'source' => 'org']);
        $response = $this->controller($service, $this->createMock(ActionAuthService::class), $this->session('alice'), $request)->import();

        $this->assertSame(Http::STATUS_CREATED, $response->getStatus());
        $this->assertSame('quarantined', $response->getData()['state']);

    }//end testImportReturnsQuarantinedTemplate()

    /**
     * An unauthenticated caller gets 401 on approve(), never reaching action-auth.
     *
     * @return void
     */
    public function testApproveUnauthenticated(): void
    {
        $actionAuth = $this->createMock(ActionAuthService::class);
        $actionAuth->expects($this->never())->method('requireAction');

        $response = $this->controller(
            $this->createMock(AgentTemplateService::class),
            $actionAuth,
            $this->session(null)
        )->approve('template-1');

        $this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());

    }//end testApproveUnauthenticated()

    /**
     * Approve gates on requireAction('agenttemplate.approve-quarantined') then succeeds.
     *
     * @return void
     */
    public function testApproveCallsRequireActionAndSucceeds(): void
    {
        $service = $this->createMock(AgentTemplateService::class);
        $service->method('approveQuarantined')->willReturn($this->template());

        $actionAuth = $this->createMock(ActionAuthService::class);
        $actionAuth->expects($this->once())
            ->method('requireAction')
            ->with($this->anything(), 'agenttemplate.approve-quarantined');

        $response = $this->controller($service, $actionAuth, $this->session('admin'))->approve('template-1');

        $this->assertSame(Http::STATUS_OK, $response->getStatus());

    }//end testApproveCallsRequireActionAndSucceeds()

    /**
     * A caller with no matrix entry is refused (403) on approve(), never reaching the service.
     *
     * @return void
     */
    public function testApproveForbiddenForNonAdmin(): void
    {
        $service = $this->createMock(AgentTemplateService::class);
        $service->expects($this->never())->method('approveQuarantined');

        $actionAuth = $this->createMock(ActionAuthService::class);
        $actionAuth->method('requireAction')->willThrowException(new OCSForbiddenException('nope'));

        $response = $this->controller($service, $actionAuth, $this->session('mallory'))->approve('template-1');

        $this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());

    }//end testApproveForbiddenForNonAdmin()

    /**
     * A caller granted `agenttemplate.approve-quarantined` but NOT
     * `agenttemplate.override-scan-verdict` is refused (403) on approve(force: true).
     *
     * @return void
     */
    public function testApproveForceForbiddenWithoutOverrideAction(): void
    {
        $service = $this->createMock(AgentTemplateService::class);
        $service->expects($this->never())->method('approveQuarantined');

        $actionAuth = $this->createMock(ActionAuthService::class);
        $actionAuth->method('requireAction')->willReturnCallback(
            function ($user, string $action) {
                if ($action === 'agenttemplate.override-scan-verdict') {
                    throw new OCSForbiddenException('nope');
                }
            }
        );

        $request  = $this->request(['force' => true]);
        $response = $this->controller($service, $actionAuth, $this->session('curator'), $request)->approve('template-1');

        $this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());

    }//end testApproveForceForbiddenWithoutOverrideAction()

    /**
     * A caller granted both actions succeeds on approve(force: true).
     *
     * @return void
     */
    public function testApproveForceSucceedsWithOverrideAction(): void
    {
        $service = $this->createMock(AgentTemplateService::class);
        $service->method('approveQuarantined')->willReturn($this->template());

        $actionAuth = $this->createMock(ActionAuthService::class);
        $actionAuth->expects($this->exactly(2))->method('requireAction');

        $request  = $this->request(['force' => true]);
        $response = $this->controller($service, $actionAuth, $this->session('admin'), $request)->approve('template-1');

        $this->assertSame(Http::STATUS_OK, $response->getStatus());

    }//end testApproveForceSucceedsWithOverrideAction()

    /**
     * instantiate() resolves the caller's active organisation and passes it to the
     * service, returning 201 with the instantiate result.
     *
     * @return void
     */
    public function testInstantiateResolvesOrganisationAndSucceeds(): void
    {
        $service = $this->createMock(AgentTemplateService::class);
        $service->expects($this->once())
            ->method('instantiate')
            ->with('template-1', 'org-a', [])
            ->willReturn(['agent' => ['uuid' => 'agent-1'], 'modelCoerced' => false]);

        $response = $this->controller(
            $service,
            $this->createMock(ActionAuthService::class),
            $this->session('alice'),
            null,
            $this->organisationMapper('org-a')
        )->instantiate('template-1');

        $this->assertSame(Http::STATUS_CREATED, $response->getStatus());
        $this->assertSame('agent-1', $response->getData()['agent']['uuid']);

    }//end testInstantiateResolvesOrganisationAndSucceeds()

    /**
     * instantiate() returns 404 when the template does not exist.
     *
     * @return void
     */
    public function testInstantiateNotFound(): void
    {
        $service = $this->createMock(AgentTemplateService::class);
        $service->method('instantiate')->willReturn(null);

        $response = $this->controller(
            $service,
            $this->createMock(ActionAuthService::class),
            $this->session('alice')
        )->instantiate('missing');

        $this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());

    }//end testInstantiateNotFound()

    /**
     * instantiate() returns 401 for an unauthenticated caller, never reaching the service.
     *
     * @return void
     */
    public function testInstantiateUnauthenticated(): void
    {
        $service = $this->createMock(AgentTemplateService::class);
        $service->expects($this->never())->method('instantiate');

        $response = $this->controller(
            $service,
            $this->createMock(ActionAuthService::class),
            $this->session(null)
        )->instantiate('template-1');

        $this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());

    }//end testInstantiateUnauthenticated()

    /**
     * githubSearch() returns 401 for an unauthenticated caller, never reaching the store.
     *
     * @return void
     */
    public function testGithubSearchUnauthenticated(): void
    {
        $store = $this->createMock(FederatedStoreService::class);
        $store->expects($this->never())->method('search');

        $response = $this->controller(
            $this->createMock(AgentTemplateService::class),
            $this->createMock(ActionAuthService::class),
            $this->session(null),
            null,
            null,
            $store
        )->githubSearch();

        $this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());

    }//end testGithubSearchUnauthenticated()

    /**
     * githubSearch() returns 200 with the store's envelope for an authenticated caller.
     *
     * @return void
     */
    public function testGithubSearchReturnsCards(): void
    {
        $store = $this->createMock(FederatedStoreService::class);
        $store->method('search')->willReturn([
            'outcome'                   => 'ok',
            'cards'                     => [['owner' => 'acme', 'repo' => 'demo', 'kind' => 'agent-template']],
            'brokerCredentialAvailable' => true,
            'brokerUsed'                => false,
            'rateLimited'               => false,
        ]);

        $response = $this->controller(
            $this->createMock(AgentTemplateService::class),
            $this->createMock(ActionAuthService::class),
            $this->session('alice'),
            null,
            null,
            $store
        )->githubSearch();

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $this->assertCount(1, $response->getData()['cards']);
        $this->assertTrue($response->getData()['brokerCredentialAvailable']);

    }//end testGithubSearchReturnsCards()

    /**
     * githubInstall() returns 401 for an unauthenticated caller, never reaching the store.
     *
     * @return void
     */
    public function testGithubInstallUnauthenticated(): void
    {
        $store = $this->createMock(FederatedStoreService::class);
        $store->expects($this->never())->method('install');

        $response = $this->controller(
            $this->createMock(AgentTemplateService::class),
            $this->createMock(ActionAuthService::class),
            $this->session(null),
            null,
            null,
            $store
        )->githubInstall();

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

        $request  = $this->request(['owner' => '../evil', 'repo' => 'demo']);
        $response = $this->controller(
            $this->createMock(AgentTemplateService::class),
            $this->createMock(ActionAuthService::class),
            $this->session('alice'),
            $request,
            null,
            $store
        )->githubInstall();

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

        $request  = $this->request(['owner' => 'acme', 'repo' => 'demo']);
        $response = $this->controller($this->createMock(AgentTemplateService::class), $this->createMock(ActionAuthService::class), $this->session('alice'), $request, null, $store)->githubInstall();

        $this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());

    }//end testGithubInstallMissingPackageIsNotFound()

    /**
     * githubInstall() installs the discovered bundle through the store (source='hub' quarantine).
     *
     * @return void
     */
    public function testGithubInstallImportsWithHubSource(): void
    {
        $store = $this->createMock(FederatedStoreService::class);
        $store->expects($this->once())
            ->method('install')
            ->with(FederatedStoreService::KIND_AGENT_TEMPLATE, 'acme', 'demo', null)
            ->willReturn(['installed' => ['uuid-1']]);

        $request  = $this->request(['owner' => 'acme', 'repo' => 'demo']);
        $response = $this->controller($this->createMock(AgentTemplateService::class), $this->createMock(ActionAuthService::class), $this->session('alice'), $request, null, $store)->githubInstall();

        $this->assertSame(Http::STATUS_CREATED, $response->getStatus());
        $this->assertSame(['uuid-1'], $response->getData()['installed']);

    }//end testGithubInstallImportsWithHubSource()

    /**
     * publishGithub() returns 401 for an unauthenticated caller, never reaching the template service.
     *
     * @return void
     */
    public function testPublishGithubUnauthenticated(): void
    {
        $service = $this->createMock(AgentTemplateService::class);
        $service->expects($this->never())->method('exportTemplate');

        $response = $this->controller(
            $service,
            $this->createMock(ActionAuthService::class),
            $this->session(null)
        )->publishGithub('template-1');

        $this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());

    }//end testPublishGithubUnauthenticated()

    /**
     * publishGithub() rejects an invalid owner/repo with 400, never reaching the template service.
     *
     * @return void
     */
    public function testPublishGithubInvalidRepoIsBadRequest(): void
    {
        $service = $this->createMock(AgentTemplateService::class);
        $service->expects($this->never())->method('exportTemplate');

        $request  = $this->request(['owner' => 'ok', 'repo' => 'bad repo', 'credentialId' => 'cred-1']);
        $response = $this->controller($service, $this->createMock(ActionAuthService::class), $this->session('alice'), $request)->publishGithub('template-1');

        $this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
        $this->assertSame('invalid_repo', $response->getData()['error']);

    }//end testPublishGithubInvalidRepoIsBadRequest()

    /**
     * publishGithub() requires a credentialId (422), never reaching the template service.
     *
     * @return void
     */
    public function testPublishGithubRequiresCredential(): void
    {
        $service = $this->createMock(AgentTemplateService::class);
        $service->expects($this->never())->method('exportTemplate');

        $request  = $this->request(['owner' => 'acme', 'repo' => 'demo']);
        $response = $this->controller($service, $this->createMock(ActionAuthService::class), $this->session('alice'), $request)->publishGithub('template-1');

        $this->assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $response->getStatus());

    }//end testPublishGithubRequiresCredential()

    /**
     * publishGithub() returns 404 for a template outside the caller's tenant visibility
     * (exportTemplate() is the same tenant-scoped read show()/export() already use).
     *
     * @return void
     */
    public function testPublishGithubNotFound(): void
    {
        $service = $this->createMock(AgentTemplateService::class);
        $service->method('exportTemplate')->willReturn(null);

        $store = $this->createMock(FederatedStoreService::class);
        $store->expects($this->never())->method('publish');

        $request  = $this->request(['owner' => 'acme', 'repo' => 'demo', 'credentialId' => 'cred-1']);
        $response = $this->controller($service, $this->createMock(ActionAuthService::class), $this->session('alice'), $request, null, $store)->publishGithub('template-1');

        $this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());

    }//end testPublishGithubNotFound()

    /**
     * publishGithub() fails closed (503) when the credential broker is unavailable.
     *
     * @return void
     */
    public function testPublishGithubFailsClosedWithoutBroker(): void
    {
        $service = $this->createMock(AgentTemplateService::class);
        $service->method('exportTemplate')->willReturn('{"name":"Demo"}');

        $store = $this->createMock(FederatedStoreService::class);
        $store->method('isBrokerAvailable')->willReturn(false);
        $store->expects($this->never())->method('publish');

        $request  = $this->request(['owner' => 'acme', 'repo' => 'demo', 'credentialId' => 'cred-1']);
        $response = $this->controller($service, $this->createMock(ActionAuthService::class), $this->session('alice'), $request, null, $store)->publishGithub('template-1');

        $this->assertSame(Http::STATUS_SERVICE_UNAVAILABLE, $response->getStatus());

    }//end testPublishGithubFailsClosedWithoutBroker()

    /**
     * publishGithub() pushes the exported package and records provenance on success.
     *
     * @return void
     */
    public function testPublishGithubSucceedsAndRecordsProvenance(): void
    {
        $service = $this->createMock(AgentTemplateService::class);
        $service->method('exportTemplate')->willReturn('{"name":"Demo"}');
        $service->expects($this->once())
            ->method('update')
            ->with(
                'template-1',
                $this->callback(static function (array $payload): bool {
                    return ($payload['githubOwner'] ?? null) === 'acme'
                        && ($payload['githubRepo'] ?? null) === 'demo'
                        && is_string($payload['publishedAt'] ?? null) === true;
                })
            )
            ->willReturn($this->template());

        $store = $this->createMock(FederatedStoreService::class);
        $store->method('isBrokerAvailable')->willReturn(true);
        $store->expects($this->once())
            ->method('publish')
            ->with(FederatedStoreService::KIND_AGENT_TEMPLATE, 'template-1', 'acme', 'demo', 'cred-1')
            ->willReturn(['repoUrl' => 'https://github.com/acme/demo', 'commitSha' => 'abc123', 'status' => 201]);

        $request  = $this->request(['owner' => 'acme', 'repo' => 'demo', 'credentialId' => 'cred-1']);
        $response = $this->controller($service, $this->createMock(ActionAuthService::class), $this->session('alice'), $request, null, $store)->publishGithub('template-1');

        $this->assertSame(Http::STATUS_CREATED, $response->getStatus());
        $this->assertSame('https://github.com/acme/demo', $response->getData()['repoUrl']);

    }//end testPublishGithubSucceedsAndRecordsProvenance()

    /**
     * publishGithub() surfaces a RuntimeException from the push service (e.g. "already
     * exists") as 422, never recording provenance.
     *
     * @return void
     */
    public function testPublishGithubRefusedByPushServiceIsUnprocessable(): void
    {
        $service = $this->createMock(AgentTemplateService::class);
        $service->method('exportTemplate')->willReturn('{"name":"Demo"}');
        $service->expects($this->never())->method('update');

        $store = $this->createMock(FederatedStoreService::class);
        $store->method('isBrokerAvailable')->willReturn(true);
        $store->method('publish')->willThrowException(new RuntimeException('Repository acme/demo already exists'));

        $request  = $this->request(['owner' => 'acme', 'repo' => 'demo', 'credentialId' => 'cred-1']);
        $response = $this->controller($service, $this->createMock(ActionAuthService::class), $this->session('alice'), $request, null, $store)->publishGithub('template-1');

        $this->assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $response->getStatus());

    }//end testPublishGithubRefusedByPushServiceIsUnprocessable()
}//end class
