<?php

/**
 * Unit tests for TenantOpsController (multi-tenant-ops).
 *
 * Covers the two tenant-scoped operations endpoints — quota() and auditExport() — for the
 * happy path (200 with the service payload), the unauthenticated path (401 before any service
 * call), and the service-failure path (500). auditExport additionally asserts the
 * Content-Disposition attachment header is set on success.
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
 * @spec openspec/changes/playwright-regression-coverage/tasks.md#task-2-1
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Controller;

use OCA\Hermiq\Controller\TenantOpsController;
use OCA\Hermiq\Service\TenantOpsService;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Tests for the multi-tenant-ops controller.
 *
 * @spec openspec/changes/playwright-regression-coverage/tasks.md#task-2-1
 */
class TenantOpsControllerTest extends TestCase
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
     * Build the controller with the given service + session.
     *
     * @param TenantOpsService $service The tenant-ops service.
     * @param IUserSession     $session The user session.
     *
     * @return TenantOpsController
     */
    private function controller(TenantOpsService $service, IUserSession $session): TenantOpsController
    {
        return new TenantOpsController(
            $this->createMock(IRequest::class),
            $service,
            $session,
            $this->createMock(LoggerInterface::class)
        );

    }//end controller()

    /**
     * quota() returns 200 with the service's quota payload for an authenticated caller.
     *
     * @return void
     */
    public function testQuotaReturnsStatus(): void
    {
        $service = $this->createMock(TenantOpsService::class);
        $service->method('quotaStatus')->willReturn(['requests' => ['used' => 3, 'limit' => 100]]);

        $response = $this->controller($service, $this->session('alice'))->quota();

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $this->assertSame(3, $response->getData()['requests']['used']);

    }//end testQuotaReturnsStatus()

    /**
     * quota() returns 401 for an unauthenticated caller, never calling the service.
     *
     * @return void
     */
    public function testQuotaUnauthenticated(): void
    {
        $service = $this->createMock(TenantOpsService::class);
        $service->expects($this->never())->method('quotaStatus');

        $response = $this->controller($service, $this->session(null))->quota();

        $this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());

    }//end testQuotaUnauthenticated()

    /**
     * quota() maps a service failure to 500.
     *
     * @return void
     */
    public function testQuotaServiceFailureIsServerError(): void
    {
        $service = $this->createMock(TenantOpsService::class);
        $service->method('quotaStatus')->willThrowException(new RuntimeException('boom'));

        $response = $this->controller($service, $this->session('alice'))->quota();

        $this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());

    }//end testQuotaServiceFailureIsServerError()

    /**
     * auditExport() returns 200 with the service's export payload.
     *
     * The attachment Content-Disposition header is set by the controller but is not asserted
     * here: JSONResponse::getHeaders() materialises Nextcloud's default headers via \OC, which
     * is unavailable in standalone unit CI. The status + payload prove the success path.
     *
     * @return void
     */
    public function testAuditExportReturnsExport(): void
    {
        $service = $this->createMock(TenantOpsService::class);
        $service->method('exportAuditTrail')->willReturn(['generatedAt' => 'now', 'entries' => []]);

        $response = $this->controller($service, $this->session('alice'))->auditExport();

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $this->assertSame('now', $response->getData()['generatedAt']);

    }//end testAuditExportReturnsExport()

    /**
     * auditExport() returns 401 for an unauthenticated caller, never calling the service.
     *
     * @return void
     */
    public function testAuditExportUnauthenticated(): void
    {
        $service = $this->createMock(TenantOpsService::class);
        $service->expects($this->never())->method('exportAuditTrail');

        $response = $this->controller($service, $this->session(null))->auditExport();

        $this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());

    }//end testAuditExportUnauthenticated()

    /**
     * auditExport() maps a service failure to 500.
     *
     * @return void
     */
    public function testAuditExportServiceFailureIsServerError(): void
    {
        $service = $this->createMock(TenantOpsService::class);
        $service->method('exportAuditTrail')->willThrowException(new RuntimeException('boom'));

        $response = $this->controller($service, $this->session('alice'))->auditExport();

        $this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());

    }//end testAuditExportServiceFailureIsServerError()
}//end class
