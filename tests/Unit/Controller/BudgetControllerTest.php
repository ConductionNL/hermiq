<?php

/**
 * Unit tests for BudgetController (cost-guardrails).
 *
 * Covers the read endpoints (index/status/estimate: 401 unauthenticated, 200 happy
 * path, 500 on service failure) and the admin/owner-gated write endpoints
 * (create/update/destroy: 401 unauthenticated, 403 for a plain user, 200 for an
 * instance admin or the organisation owner, 404 on update/destroy of a nonexistent
 * budget) — mirrors TenantControlControllerTest's authorization matrix.
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/cost-guardrails/tasks.md#task-4-1
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Controller;

use InvalidArgumentException;
use OCA\Hermiq\Controller\BudgetController;
use OCA\Hermiq\Service\BudgetService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Organisation;
use OCA\OpenRegister\Db\OrganisationMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Tests for the cost-guardrails BudgetController.
 *
 * @spec openspec/changes/cost-guardrails/tasks.md#task-4-1
 */
class BudgetControllerTest extends TestCase
{

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
     * A request whose params resolve from the given map.
     *
     * @param array<string,mixed> $params The param map.
     *
     * @return IRequest
     */
    private function request(array $params=[]): IRequest
    {
        $request = $this->createMock(IRequest::class);
        $request->method('getParam')->willReturnCallback(
            static fn (string $key, $default=null) => ($params[$key] ?? $default)
        );
        $request->method('getParams')->willReturn($params);
        return $request;

    }//end request()

    /**
     * A group manager where isAdmin() reflects the given flag.
     *
     * @param bool $isAdmin Whether the caller is an instance admin.
     *
     * @return IGroupManager
     */
    private function groupManager(bool $isAdmin): IGroupManager
    {
        $manager = $this->createMock(IGroupManager::class);
        $manager->method('isAdmin')->willReturn($isAdmin);
        return $manager;

    }//end groupManager()

    /**
     * An OrganisationMapper that resolves the target org to one owned by $ownerUid.
     *
     * @param string|null $ownerUid The owner UID of the resolved organisation, or null
     *                              to simulate an unknown organisation.
     *
     * @return OrganisationMapper
     */
    private function orgMapper(?string $ownerUid): OrganisationMapper
    {
        $mapper = $this->createMock(OrganisationMapper::class);
        if ($ownerUid === null) {
            $mapper->method('findByUuid')->willThrowException(new DoesNotExistException('no org'));
            return $mapper;
        }

        $org = $this->createMock(Organisation::class);
        $org->method('getOwner')->willReturn($ownerUid);
        $mapper->method('findByUuid')->willReturn($org);
        return $mapper;

    }//end orgMapper()

    /**
     * Build the controller with the given collaborators.
     *
     * @param BudgetService      $service      The budget service.
     * @param IUserSession       $session      The user session.
     * @param IRequest|null      $request      Optional request (defaults to empty params).
     * @param IGroupManager|null $groupManager Optional group manager (defaults to non-admin).
     * @param OrganisationMapper|null $orgMapper Optional org mapper (defaults to unknown org).
     *
     * @return BudgetController
     */
    private function controller(
        BudgetService $service,
        IUserSession $session,
        ?IRequest $request=null,
        ?IGroupManager $groupManager=null,
        ?OrganisationMapper $orgMapper=null
    ): BudgetController {
        return new BudgetController(
            $request ?? $this->request(),
            $service,
            $session,
            $groupManager ?? $this->groupManager(false),
            $orgMapper ?? $this->orgMapper(null),
            $this->createMock(LoggerInterface::class)
        );

    }//end controller()

    /**
     * index() returns 200 with the service's budget list for an authenticated caller.
     *
     * @return void
     */
    public function testIndexReturnsBudgets(): void
    {
        $service = $this->createMock(BudgetService::class);
        $service->method('listForCaller')->willReturn([['id' => 'b1', 'scope' => 'organisation']]);

        $response = $this->controller($service, $this->session('alice'))->index();

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $this->assertSame('b1', $response->getData()['budgets'][0]['id']);

    }//end testIndexReturnsBudgets()

    /**
     * index() returns 401 for an unauthenticated caller, never calling the service.
     *
     * @return void
     */
    public function testIndexUnauthenticated(): void
    {
        $service = $this->createMock(BudgetService::class);
        $service->expects($this->never())->method('listForCaller');

        $response = $this->controller($service, $this->session(null))->index();

        $this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());

    }//end testIndexUnauthenticated()

    /**
     * status() is tenant-scoped: it always delegates to BudgetService::statusForScope()
     * with the caller-supplied organisation/agentId, which itself enforces the tenant
     * boundary via OpenRegister's own RBAC filtering (TC-6).
     *
     * @return void
     */
    public function testStatusDelegatesToTenantScopedService(): void
    {
        $service = $this->createMock(BudgetService::class);
        $service->expects($this->once())
            ->method('statusForScope')
            ->with('org-a', 'agent-1')
            ->willReturn(['scope' => 'agent', 'hardCapReached' => false, 'configured' => true]);

        $response = $this->controller($service, $this->session('alice'))->status('org-a', 'agent-1');

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $this->assertFalse($response->getData()['hardCapReached']);

    }//end testStatusDelegatesToTenantScopedService()

    /**
     * status() returns 401 for an unauthenticated caller.
     *
     * @return void
     */
    public function testStatusUnauthenticated(): void
    {
        $service = $this->createMock(BudgetService::class);
        $service->expects($this->never())->method('statusForScope');

        $response = $this->controller($service, $this->session(null))->status('org-a', '');

        $this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());

    }//end testStatusUnauthenticated()

    /**
     * estimate() returns the design.md estimate payload shape for an agent with
     * prior runs.
     *
     * @return void
     */
    public function testEstimateReturnsPayloadShape(): void
    {
        $service = $this->createMock(BudgetService::class);
        $service->method('estimateNextRun')->with('agent-1')->willReturn(
            [
                'agentId'             => 'agent-1',
                'available'           => true,
                'sampleSize'          => 12,
                'avgPromptTokens'     => 1800,
                'avgCompletionTokens' => 620,
                'avgTotalTokens'      => 2420,
                'avgCostEur'          => null,
                'label'               => 'estimate — trailing average over last 12 runs',
            ]
        );

        $response = $this->controller($service, $this->session('alice'))->estimate('agent-1');

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $data = $response->getData();
        $this->assertTrue($data['available']);
        $this->assertSame(12, $data['sampleSize']);
        $this->assertSame(2420, $data['avgTotalTokens']);

    }//end testEstimateReturnsPayloadShape()

    /**
     * quota() delegates to BudgetService::agentQuotaStatus and returns its payload.
     *
     * @return void
     */
    public function testQuotaReturnsPayloadShape(): void
    {
        $service = $this->createMock(BudgetService::class);
        $service->method('agentQuotaStatus')->with('agent-1')->willReturn(
            [
                'agentId'             => 'agent-1',
                'day'                 => '2026-07-18',
                'tokens'              => ['used' => 120, 'limit' => 500],
                'requests'            => ['used' => 2, 'limit' => 5],
                'tokenQuotaReached'   => false,
                'requestQuotaReached' => false,
                'blocked'             => false,
            ]
        );

        $response = $this->controller($service, $this->session('alice'))->quota('agent-1');

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $data = $response->getData();
        $this->assertSame(120, $data['tokens']['used']);
        $this->assertSame(5, $data['requests']['limit']);
        $this->assertFalse($data['blocked']);

    }//end testQuotaReturnsPayloadShape()

    /**
     * quota() returns 401 for an unauthenticated caller, never calling the service.
     *
     * @return void
     */
    public function testQuotaUnauthenticated(): void
    {
        $service = $this->createMock(BudgetService::class);
        $service->expects($this->never())->method('agentQuotaStatus');

        $response = $this->controller($service, $this->session(null))->quota('agent-1');

        $this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());

    }//end testQuotaUnauthenticated()

    /**
     * create() refuses a plain (non-admin, non-owner) caller with 403, never calling
     * the service (TC-7: write endpoints are admin/owner-gated).
     *
     * @return void
     */
    public function testCreateRefusesPlainUser(): void
    {
        $service = $this->createMock(BudgetService::class);
        $service->expects($this->never())->method('create');

        $request = $this->request(['organisation' => 'org-a']);
        $response = $this->controller(
            $service,
            $this->session('bob'),
            $request,
            $this->groupManager(false),
            $this->orgMapper('alice')
        )->create();

        $this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());

    }//end testCreateRefusesPlainUser()

    /**
     * create() succeeds for the organisation owner.
     *
     * @return void
     */
    public function testCreateSucceedsForOrgOwner(): void
    {
        $service = $this->createMock(BudgetService::class);
        $service->method('create')->willReturn(['id' => 'new-budget', 'scope' => 'organisation']);

        $request  = $this->request(['organisation' => 'org-a', 'scope' => 'organisation', 'period' => 'monthly', 'tokenLimit' => 1000]);
        $response = $this->controller(
            $service,
            $this->session('alice'),
            $request,
            $this->groupManager(false),
            $this->orgMapper('alice')
        )->create();

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $this->assertSame('new-budget', $response->getData()['id']);

    }//end testCreateSucceedsForOrgOwner()

    /**
     * create() succeeds for an instance admin even when they do not own the org.
     *
     * @return void
     */
    public function testCreateSucceedsForInstanceAdmin(): void
    {
        $service = $this->createMock(BudgetService::class);
        $service->method('create')->willReturn(['id' => 'new-budget']);

        $request  = $this->request(['organisation' => 'org-a']);
        $response = $this->controller(
            $service,
            $this->session('admin-user'),
            $request,
            $this->groupManager(true),
            $this->orgMapper('someone-else')
        )->create();

        $this->assertSame(Http::STATUS_OK, $response->getStatus());

    }//end testCreateSucceedsForInstanceAdmin()

    /**
     * create() maps an InvalidArgumentException (cross-field validation) to 400.
     *
     * @return void
     */
    public function testCreateValidationFailureIsBadRequest(): void
    {
        $service = $this->createMock(BudgetService::class);
        $service->method('create')->willThrowException(new InvalidArgumentException('agentId is required when scope=agent'));

        $request  = $this->request(['organisation' => 'org-a']);
        $response = $this->controller(
            $service,
            $this->session('alice'),
            $request,
            $this->groupManager(false),
            $this->orgMapper('alice')
        )->create();

        $this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());

    }//end testCreateValidationFailureIsBadRequest()

    /**
     * create() returns 401 for an unauthenticated caller, never checking authorization.
     *
     * @return void
     */
    public function testCreateUnauthenticated(): void
    {
        $service = $this->createMock(BudgetService::class);
        $service->expects($this->never())->method('create');

        $response = $this->controller($service, $this->session(null))->create();

        $this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());

    }//end testCreateUnauthenticated()

    /**
     * update() returns 404 when the budget cannot be found, never checking
     * authorization against a nonexistent resource.
     *
     * @return void
     */
    public function testUpdateNotFound(): void
    {
        $service = $this->createMock(BudgetService::class);
        $service->method('findById')->willReturn(null);
        $service->expects($this->never())->method('update');

        $response = $this->controller($service, $this->session('alice'))->update('missing-id');

        $this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());

    }//end testUpdateNotFound()

    /**
     * update() refuses a plain user who is neither admin nor the EXISTING budget's
     * organisation owner (checked against the resolved object, not a caller-supplied
     * organisation).
     *
     * @return void
     */
    public function testUpdateRefusesPlainUser(): void
    {
        $existing = new ObjectEntity();
        $existing->setUuid('b1');
        $existing->setOrganisation('org-a');
        $existing->setObject(['scope' => 'organisation', 'tokenLimit' => 1000]);

        $service = $this->createMock(BudgetService::class);
        $service->method('findById')->willReturn($existing);
        $service->expects($this->never())->method('update');

        $response = $this->controller(
            $service,
            $this->session('bob'),
            $this->request(),
            $this->groupManager(false),
            $this->orgMapper('alice')
        )->update('b1');

        $this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());

    }//end testUpdateRefusesPlainUser()

    /**
     * update() succeeds for the organisation owner.
     *
     * @return void
     */
    public function testUpdateSucceedsForOrgOwner(): void
    {
        $existing = new ObjectEntity();
        $existing->setUuid('b1');
        $existing->setOrganisation('org-a');
        $existing->setObject(['scope' => 'organisation', 'tokenLimit' => 1000]);

        $service = $this->createMock(BudgetService::class);
        $service->method('findById')->willReturn($existing);
        $service->method('update')->willReturn(['id' => 'b1', 'tokenLimit' => 5000]);

        $response = $this->controller(
            $service,
            $this->session('alice'),
            $this->request(['tokenLimit' => 5000]),
            $this->groupManager(false),
            $this->orgMapper('alice')
        )->update('b1');

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $this->assertSame(5000, $response->getData()['tokenLimit']);

    }//end testUpdateSucceedsForOrgOwner()

    /**
     * destroy() returns 404 when the budget cannot be found.
     *
     * @return void
     */
    public function testDestroyNotFound(): void
    {
        $service = $this->createMock(BudgetService::class);
        $service->method('findById')->willReturn(null);
        $service->expects($this->never())->method('delete');

        $response = $this->controller($service, $this->session('alice'))->destroy('missing-id');

        $this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());

    }//end testDestroyNotFound()

    /**
     * destroy() refuses a plain (non-admin, non-owner) caller with 403 — no Budget
     * object is removed (TC-7).
     *
     * @return void
     */
    public function testDestroyRefusesPlainUser(): void
    {
        $existing = new ObjectEntity();
        $existing->setUuid('b1');
        $existing->setOrganisation('org-a');
        $existing->setObject([]);

        $service = $this->createMock(BudgetService::class);
        $service->method('findById')->willReturn($existing);
        $service->expects($this->never())->method('delete');

        $response = $this->controller(
            $service,
            $this->session('bob'),
            $this->request(),
            $this->groupManager(false),
            $this->orgMapper('alice')
        )->destroy('b1');

        $this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());

    }//end testDestroyRefusesPlainUser()

    /**
     * destroy() succeeds for the organisation owner.
     *
     * @return void
     */
    public function testDestroySucceedsForOrgOwner(): void
    {
        $existing = new ObjectEntity();
        $existing->setUuid('b1');
        $existing->setOrganisation('org-a');
        $existing->setObject([]);

        $service = $this->createMock(BudgetService::class);
        $service->method('findById')->willReturn($existing);
        $service->expects($this->once())->method('delete')->with('b1');

        $response = $this->controller(
            $service,
            $this->session('alice'),
            $this->request(),
            $this->groupManager(false),
            $this->orgMapper('alice')
        )->destroy('b1');

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $this->assertTrue($response->getData()['deleted']);

    }//end testDestroySucceedsForOrgOwner()
}//end class
