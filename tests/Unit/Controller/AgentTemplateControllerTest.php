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
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\OrganisationMapper;
use OCP\AppFramework\Http;
use OCP\AppFramework\OCS\OCSForbiddenException;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

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
     * @param AgentTemplateService    $service    The template service.
     * @param ActionAuthService       $actionAuth The action-auth service.
     * @param IUserSession            $session    The user session.
     * @param IRequest|null           $request    An optional request mock (defaults to no params).
     * @param OrganisationMapper|null $mapper     An optional organisation mapper.
     *
     * @return AgentTemplateController
     */
    private function controller(
        AgentTemplateService $service,
        ActionAuthService $actionAuth,
        IUserSession $session,
        ?IRequest $request=null,
        ?OrganisationMapper $mapper=null
    ): AgentTemplateController {
        return new AgentTemplateController(
            ($request ?? $this->request()),
            $service,
            $actionAuth,
            $session,
            ($mapper ?? $this->organisationMapper()),
            $this->createMock(LoggerInterface::class)
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
}//end class
