<?php

/**
 * Unit tests for ComplianceController (compliance-control-packs).
 *
 * Covers the ADR-023 action-auth gate on dashboard/export (403 for a caller lacking
 * the action), and the factsheet's ownership-or-action-auth IDOR guard: an agent's
 * own owner or actingUser may view it, a DPO/admin holding compliance.view-factsheet
 * may view any agent's factsheet, and everyone else — including a caller for a
 * non-existent agent — gets 404 (never 403, anti-probing).
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
 * @spec openspec/changes/compliance-control-packs/tasks.md#task-5-compliancecontroller-routes-action-auth-gating
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Controller;

use OCA\Hermiq\Controller\ComplianceController;
use OCA\Hermiq\Service\ActionAuthService;
use OCA\Hermiq\Service\ComplianceService;
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
 * Tests for the compliance-control-packs ComplianceController.
 *
 * @spec openspec/changes/compliance-control-packs/tasks.md#task-5-compliancecontroller-routes-action-auth-gating
 */
class ComplianceControllerTest extends TestCase
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
     * An OrganisationMapper resolving every uid to the given organisation.
     *
     * @param string $organisation The organisation to resolve to.
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
     * An ActionAuthService that allows or denies every action uniformly.
     *
     * @param bool $allowed Whether every requireAction()/can() call should pass.
     *
     * @return ActionAuthService
     */
    private function actionAuth(bool $allowed): ActionAuthService
    {
        $service = $this->createMock(ActionAuthService::class);
        if ($allowed === false) {
            $service->method('requireAction')->willThrowException(new OCSForbiddenException('Forbidden'));
            $service->method('can')->willReturn(false);
            return $service;
        }

        $service->method('requireAction');
        $service->method('can')->willReturn(true);
        return $service;

    }//end actionAuth()

    /**
     * Build the controller with the given collaborators.
     *
     * @param ComplianceService  $complianceService The compliance service.
     * @param IUserSession       $session           The user session.
     * @param ActionAuthService  $actionAuth        The action-auth gate.
     * @param OrganisationMapper|null $organisationMapper Optional custom mapper.
     *
     * @return ComplianceController
     */
    private function controller(
        ComplianceService $complianceService,
        IUserSession $session,
        ActionAuthService $actionAuth,
        ?OrganisationMapper $organisationMapper=null
    ): ComplianceController {
        return new ComplianceController(
            $this->createMock(IRequest::class),
            $complianceService,
            $session,
            $actionAuth,
            ($organisationMapper ?? $this->organisationMapper()),
            $this->createMock(LoggerInterface::class)
        );

    }//end controller()

    /**
     * dashboard() refuses an unauthenticated caller with 401.
     *
     * @return void
     */
    public function testDashboardRefusesUnauthenticated(): void
    {
        $response = $this->controller(
            $this->createMock(ComplianceService::class),
            $this->session(null),
            $this->actionAuth(allowed: true)
        )->dashboard();

        $this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());

    }//end testDashboardRefusesUnauthenticated()

    /**
     * dashboard() refuses a caller lacking compliance.view-dashboard with 403.
     *
     * @return void
     *
     * @spec openspec/changes/compliance-control-packs/tasks.md#requirement-dashboard-export-and-factsheet-access-are-restricted-by-role-and-ownership
     */
    public function testDashboardRefusesUnauthorizedWith403(): void
    {
        $response = $this->controller(
            $this->createMock(ComplianceService::class),
            $this->session('bob'),
            $this->actionAuth(allowed: false)
        )->dashboard();

        $this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());

    }//end testDashboardRefusesUnauthorizedWith403()

    /**
     * dashboard() returns the computed dashboard for an authorized caller.
     *
     * @return void
     */
    public function testDashboardReturnsPayloadForAuthorizedCaller(): void
    {
        $service = $this->createMock(ComplianceService::class);
        $service->method('dashboard')->with('org-a')->willReturn(['frameworks' => [], 'gaps' => []]);

        $response = $this->controller(
            $service,
            $this->session('admin'),
            $this->actionAuth(allowed: true)
        )->dashboard();

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $this->assertSame(['frameworks' => [], 'gaps' => []], $response->getData());

    }//end testDashboardReturnsPayloadForAuthorizedCaller()

    /**
     * export() refuses a caller lacking compliance.export-pack with 403.
     *
     * @return void
     *
     * @spec openspec/changes/compliance-control-packs/tasks.md#requirement-dashboard-export-and-factsheet-access-are-restricted-by-role-and-ownership
     */
    public function testExportRefusesUnauthorizedWith403(): void
    {
        $response = $this->controller(
            $this->createMock(ComplianceService::class),
            $this->session('bob'),
            $this->actionAuth(allowed: false)
        )->export();

        $this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());

    }//end testExportRefusesUnauthorizedWith403()

    /**
     * export() returns the auditor's pack for an authorized caller.
     *
     * @return void
     *
     * @spec openspec/changes/compliance-control-packs/tasks.md#requirement-the-auditors-pack-export-extends-the-existing-art-12-export
     */
    public function testExportReturnsAuditorPack(): void
    {
        $service = $this->createMock(ComplianceService::class);
        $service->method('auditorPack')->with('org-a')->willReturn(['auditTrail' => [], 'complianceCoverage' => [], 'generatedAt' => 'now']);

        $response = $this->controller(
            $service,
            $this->session('admin'),
            $this->actionAuth(allowed: true)
        )->export();

        $this->assertSame(Http::STATUS_OK, $response->getStatus());

    }//end testExportReturnsAuditorPack()

    /**
     * factsheet() returns 404 (not 403) when the agent does not exist.
     *
     * @return void
     */
    public function testFactsheetRefusesMissingAgentWith404(): void
    {
        $service = $this->createMock(ComplianceService::class);
        $service->method('findAgent')->willReturn(null);

        $response = $this->controller(
            $service,
            $this->session('bob'),
            $this->actionAuth(allowed: false)
        )->factsheet('missing-agent');

        $this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());

    }//end testFactsheetRefusesMissingAgentWith404()

    /**
     * factsheet() refuses a non-owner, non-authorized caller with 404 (anti-probing
     * — never 403, per the requirement's IDOR guard).
     *
     * @return void
     *
     * @spec openspec/changes/compliance-control-packs/tasks.md#requirement-dashboard-export-and-factsheet-access-are-restricted-by-role-and-ownership
     */
    public function testFactsheetRefusesNonOwnerNonAuthorizedWith404(): void
    {
        $agent = new ObjectEntity();
        $agent->setUuid('agent-1');
        $agent->setOwner('alice');
        $agent->setObject(['actingUser' => '']);

        $service = $this->createMock(ComplianceService::class);
        $service->method('findAgent')->willReturn($agent);
        $service->expects($this->never())->method('factsheet');

        $response = $this->controller(
            $service,
            $this->session('bob'),
            $this->actionAuth(allowed: false)
        )->factsheet('agent-1');

        $this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());

    }//end testFactsheetRefusesNonOwnerNonAuthorizedWith404()

    /**
     * factsheet() is returned to the agent's own owner.
     *
     * @return void
     *
     * @spec openspec/changes/compliance-control-packs/tasks.md#requirement-an-ai-factsheet-summarises-an-agents-governance-lifecycle
     */
    public function testFactsheetReturnedToOwner(): void
    {
        $agent = new ObjectEntity();
        $agent->setUuid('agent-1');
        $agent->setOwner('alice');
        $agent->setObject(['actingUser' => '']);

        $service = $this->createMock(ComplianceService::class);
        $service->method('findAgent')->willReturn($agent);
        $service->method('factsheet')->with('agent-1')->willReturn(['agent' => ['id' => 'agent-1']]);

        $response = $this->controller(
            $service,
            $this->session('alice'),
            $this->actionAuth(allowed: false)
        )->factsheet('agent-1');

        $this->assertSame(Http::STATUS_OK, $response->getStatus());

    }//end testFactsheetReturnedToOwner()

    /**
     * factsheet() is returned to a DPO/admin holding compliance.view-factsheet even
     * though they are neither the owner nor the actingUser.
     *
     * @return void
     *
     * @spec openspec/changes/compliance-control-packs/tasks.md#requirement-dashboard-export-and-factsheet-access-are-restricted-by-role-and-ownership
     */
    public function testFactsheetReturnedToAuthorizedNonOwner(): void
    {
        $agent = new ObjectEntity();
        $agent->setUuid('agent-1');
        $agent->setOwner('alice');
        $agent->setObject(['actingUser' => '']);

        $service = $this->createMock(ComplianceService::class);
        $service->method('findAgent')->willReturn($agent);
        $service->method('factsheet')->with('agent-1')->willReturn(['agent' => ['id' => 'agent-1']]);

        $response = $this->controller(
            $service,
            $this->session('dpo-carol'),
            $this->actionAuth(allowed: true)
        )->factsheet('agent-1');

        $this->assertSame(Http::STATUS_OK, $response->getStatus());

    }//end testFactsheetReturnedToAuthorizedNonOwner()
}//end class
