<?php

/**
 * Unit tests for ScheduleWebhookSecretController (delivery-channels).
 *
 * Focuses on the ADR-005 IDOR guard (mirrors RunNowControllerTest/
 * AgentWebhookControllerTest): only the schedule owner may mint/rotate/revoke/
 * read their schedule's webhook signing secret; a non-owner (or an unknown
 * schedule) gets a 404 that leaks nothing, an unauthenticated caller gets 401,
 * and create() returns 409 when a secret already exists (rotate() returns 404
 * when none exists yet).
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
 * @spec openspec/changes/delivery-channels/tasks.md#task-3-schedulewebhooksecretcontroller-owner-guarded-crud
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Controller;

use OCA\Hermiq\Controller\ScheduleWebhookSecretController;
use OCA\Hermiq\Service\ScheduleWebhookSecretService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Tests for the delivery-channels ScheduleWebhookSecretController.
 *
 * @spec openspec/changes/delivery-channels/tasks.md#task-3-schedulewebhooksecretcontroller-owner-guarded-crud
 */
class ScheduleWebhookSecretControllerTest extends TestCase
{

    /**
     * Mock ObjectService.
     *
     * @var ObjectService&\PHPUnit\Framework\MockObject\MockObject
     */
    private ObjectService $objectService;

    /**
     * Mock ScheduleWebhookSecretService.
     *
     * @var ScheduleWebhookSecretService&\PHPUnit\Framework\MockObject\MockObject
     */
    private ScheduleWebhookSecretService $scheduleWebhookSecretService;

    /**
     * Build a Schedule ObjectEntity owned by $owner.
     *
     * @param string $owner The owner UID.
     *
     * @return ObjectEntity
     */
    private function schedule(string $owner): ObjectEntity
    {
        $entity = new ObjectEntity();
        $entity->setUuid('sched-1');
        $entity->setOwner($owner);
        $entity->setObject(['name' => 'Daily briefing', 'deliver' => 'webhook']);
        return $entity;

    }//end schedule()

    /**
     * Build the controller with the given collaborators.
     *
     * @param IUserSession $userSession The user session.
     *
     * @return ScheduleWebhookSecretController
     */
    private function controller(IUserSession $userSession): ScheduleWebhookSecretController
    {
        return new ScheduleWebhookSecretController(
            $this->createMock(IRequest::class),
            $this->objectService,
            $userSession,
            $this->scheduleWebhookSecretService,
            $this->createMock(LoggerInterface::class)
        );

    }//end controller()

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
     * Wire fresh mocks before each test.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->objectService                = $this->createMock(ObjectService::class);
        $this->scheduleWebhookSecretService = $this->createMock(ScheduleWebhookSecretService::class);

    }//end setUp()

    /**
     * An unauthenticated caller gets 401 on every endpoint.
     *
     * @return void
     *
     * @spec openspec/changes/delivery-channels/specs/talk-delivery/spec.md#requirement-a-per-schedule-webhook-signing-secret-can-be-minted-rotated-and-revoked-mvp
     */
    public function testUnauthenticatedGets401(): void
    {
        $controller = $this->controller($this->session(null));

        $this->assertSame(Http::STATUS_UNAUTHORIZED, $controller->create('sched-1')->getStatus());
        $this->assertSame(Http::STATUS_UNAUTHORIZED, $controller->rotate('sched-1')->getStatus());
        $this->assertSame(Http::STATUS_UNAUTHORIZED, $controller->revoke('sched-1')->getStatus());
        $this->assertSame(Http::STATUS_UNAUTHORIZED, $controller->show('sched-1')->getStatus());

    }//end testUnauthenticatedGets401()

    /**
     * A non-owner gets 404 (never 403) for every endpoint, so they cannot
     * confirm the schedule's existence — mirrors RunNowController's IDOR guard.
     *
     * @return void
     *
     * @spec openspec/changes/delivery-channels/specs/talk-delivery/spec.md#requirement-a-non-owner-cannot-manage-another-owners-schedule-webhook-secret
     */
    public function testNonOwnerGets404OnEveryEndpoint(): void
    {
        $this->objectService->method('find')->willReturn($this->schedule('alice'));
        $this->scheduleWebhookSecretService->expects($this->never())->method('mint');
        $this->scheduleWebhookSecretService->expects($this->never())->method('rotate');
        $this->scheduleWebhookSecretService->expects($this->never())->method('revoke');

        $controller = $this->controller($this->session('mallory'));

        $this->assertSame(Http::STATUS_NOT_FOUND, $controller->create('sched-1')->getStatus());
        $this->assertSame(Http::STATUS_NOT_FOUND, $controller->rotate('sched-1')->getStatus());
        $this->assertSame(Http::STATUS_NOT_FOUND, $controller->revoke('sched-1')->getStatus());
        $this->assertSame(Http::STATUS_NOT_FOUND, $controller->show('sched-1')->getStatus());

    }//end testNonOwnerGets404OnEveryEndpoint()

    /**
     * An unknown schedule id gets 404 on every endpoint.
     *
     * @return void
     *
     * @spec openspec/changes/delivery-channels/specs/talk-delivery/spec.md#requirement-a-non-owner-cannot-manage-another-owners-schedule-webhook-secret
     */
    public function testUnknownScheduleGets404(): void
    {
        $this->objectService->method('find')->willReturn(null);

        $controller = $this->controller($this->session('alice'));

        $this->assertSame(Http::STATUS_NOT_FOUND, $controller->create('nope')->getStatus());
        $this->assertSame(Http::STATUS_NOT_FOUND, $controller->show('nope')->getStatus());

    }//end testUnknownScheduleGets404()

    /**
     * The owner can mint a secret: 201 with the plaintext secret, never re-displayed.
     *
     * @return void
     *
     * @spec openspec/changes/delivery-channels/specs/talk-delivery/spec.md#requirement-a-per-schedule-webhook-signing-secret-can-be-minted-rotated-and-revoked-mvp
     */
    public function testOwnerCanMintSecret(): void
    {
        $this->objectService->method('find')->willReturn($this->schedule('alice'));
        $this->scheduleWebhookSecretService->method('mint')
            ->willReturn(['secret' => 'hws_plaintext', 'rotatedAt' => '2026-07-13T00:00:00+00:00']);

        $response = $this->controller($this->session('alice'))->create('sched-1');

        $this->assertSame(Http::STATUS_CREATED, $response->getStatus());
        $this->assertSame('hws_plaintext', $response->getData()['secret']);
        $this->assertTrue($response->getData()['configured']);

    }//end testOwnerCanMintSecret()

    /**
     * A mint request when a secret already exists gets 409, instructing the
     * caller to rotate instead.
     *
     * @return void
     *
     * @spec openspec/changes/delivery-channels/specs/talk-delivery/spec.md#requirement-a-per-schedule-webhook-signing-secret-can-be-minted-rotated-and-revoked-mvp
     */
    public function testMintConflictsWhenSecretAlreadyExists(): void
    {
        $this->objectService->method('find')->willReturn($this->schedule('alice'));
        $this->scheduleWebhookSecretService->method('mint')->willThrowException(new RuntimeException('exists'));

        $response = $this->controller($this->session('alice'))->create('sched-1');

        $this->assertSame(Http::STATUS_CONFLICT, $response->getStatus());

    }//end testMintConflictsWhenSecretAlreadyExists()

    /**
     * Rotating a non-configured secret returns 404.
     *
     * @return void
     *
     * @spec openspec/changes/delivery-channels/specs/talk-delivery/spec.md#requirement-a-per-schedule-webhook-signing-secret-can-be-minted-rotated-and-revoked-mvp
     */
    public function testRotateWithoutExistingSecretReturns404(): void
    {
        $this->objectService->method('find')->willReturn($this->schedule('alice'));
        $this->scheduleWebhookSecretService->method('rotate')->willThrowException(new RuntimeException('nothing to rotate'));

        $response = $this->controller($this->session('alice'))->rotate('sched-1');

        $this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());

    }//end testRotateWithoutExistingSecretReturns404()

    /**
     * The owner can revoke a secret: never a secret in the response.
     *
     * @return void
     *
     * @spec openspec/changes/delivery-channels/specs/talk-delivery/spec.md#requirement-a-per-schedule-webhook-signing-secret-can-be-minted-rotated-and-revoked-mvp
     */
    public function testOwnerCanRevokeSecret(): void
    {
        $schedule = $this->schedule('alice');
        $this->objectService->method('find')->willReturn($schedule);
        $this->scheduleWebhookSecretService->method('revoke')->willReturn($schedule);
        $this->scheduleWebhookSecretService->method('status')->willReturn(['configured' => false, 'rotatedAt' => null]);

        $response = $this->controller($this->session('alice'))->revoke('sched-1');

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $this->assertSame(['configured' => false, 'rotatedAt' => null], $response->getData());

    }//end testOwnerCanRevokeSecret()

    /**
     * show() reports the status payload for the owner, never a secret.
     *
     * @return void
     *
     * @spec openspec/changes/delivery-channels/specs/talk-delivery/spec.md#requirement-a-per-schedule-webhook-signing-secret-can-be-minted-rotated-and-revoked-mvp
     */
    public function testShowReportsStatusForOwner(): void
    {
        $this->objectService->method('find')->willReturn($this->schedule('alice'));
        $this->scheduleWebhookSecretService->method('status')->willReturn(['configured' => true, 'rotatedAt' => '2026-07-13T00:00:00+00:00']);

        $response = $this->controller($this->session('alice'))->show('sched-1');

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $this->assertTrue($response->getData()['configured']);
        $this->assertArrayNotHasKey('secret', $response->getData());

    }//end testShowReportsStatusForOwner()
}//end class
