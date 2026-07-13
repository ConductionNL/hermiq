<?php

/**
 * Unit tests for ScheduleWebhookSecretService (delivery-channels).
 *
 * Exercises the per-schedule OUTBOUND webhook signing secret lifecycle without
 * a live Nextcloud/OpenRegister:
 *   - mint() generates an hws_-prefixed secret with >= 32 bytes of entropy,
 *     stores it via ICredentialsManager (never a Schedule object field), stamps
 *     deliverWebhookSecretConfigured=true + deliverWebhookSecretRotatedAt, and
 *     returns the plaintext exactly once; refuses (throws) when already configured
 *   - rotate() overwrites the stored secret (no grace window) and refuses
 *     (throws) when nothing is configured yet
 *   - revoke() deletes the stored credential and flips the configured flag to
 *     false, idempotently (safe even when nothing was configured)
 *   - status() never includes the plaintext secret
 *   - retrieveSecret() returns the stored plaintext, or null on any failure/absence
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/delivery-channels/tasks.md#task-2-webhooksecretservice-icredentialsmanager-backed-mintrotaterevoke
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service;

use OCA\Hermiq\Service\ScheduleWebhookSecretService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use OCP\Security\ICredentialsManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Tests for the delivery-channels ScheduleWebhookSecretService.
 *
 * @spec openspec/changes/delivery-channels/tasks.md#task-2-webhooksecretservice-icredentialsmanager-backed-mintrotaterevoke
 */
class ScheduleWebhookSecretServiceTest extends TestCase
{

    /**
     * Mock ICredentialsManager.
     *
     * @var ICredentialsManager&\PHPUnit\Framework\MockObject\MockObject
     */
    private ICredentialsManager $credentialsManager;

    /**
     * Mock ObjectService.
     *
     * @var ObjectService&\PHPUnit\Framework\MockObject\MockObject
     */
    private ObjectService $objectService;

    /**
     * Mock IUserSession.
     *
     * @var IUserSession&\PHPUnit\Framework\MockObject\MockObject
     */
    private IUserSession $userSession;

    /**
     * Mock IUserManager.
     *
     * @var IUserManager&\PHPUnit\Framework\MockObject\MockObject
     */
    private IUserManager $userManager;

    /**
     * Service under test.
     *
     * @var ScheduleWebhookSecretService
     */
    private ScheduleWebhookSecretService $service;

    /**
     * Objects persisted via saveObject() during a test, in call order.
     *
     * @var array<int, array<string, mixed>>
     */
    private array $saved = [];

    /**
     * Credentials stored via ICredentialsManager::store() during a test,
     * keyed by "userId|identifier".
     *
     * @var array<string, mixed>
     */
    private array $stored = [];

    /**
     * Wire fresh mocks before each test.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->stored             = [];
        $this->credentialsManager = $this->createMock(ICredentialsManager::class);
        $this->credentialsManager->method('store')->willReturnCallback(
            function (string $userId, string $identifier, mixed $credentials): void {
                $this->stored[$userId.'|'.$identifier] = $credentials;
            }
        );
        $this->credentialsManager->method('retrieve')->willReturnCallback(
            fn (string $userId, string $identifier): mixed => ($this->stored[$userId.'|'.$identifier] ?? null)
        );
        $this->credentialsManager->method('delete')->willReturnCallback(
            function (string $userId, string $identifier): int {
                $key = $userId.'|'.$identifier;
                if (isset($this->stored[$key]) === false) {
                    return 0;
                }

                unset($this->stored[$key]);
                return 1;
            }
        );

        $this->saved         = [];
        $this->objectService = $this->createMock(ObjectService::class);
        $this->objectService->method('saveObject')->willReturnCallback(
            function (array $object, mixed $register=null, mixed $schema=null, ?string $uuid=null) {
                $this->saved[] = $object;
                $entity        = new ObjectEntity();
                $entity->setUuid($uuid ?? 'sched-1');
                $entity->setObject($object);
                $entity->setOwner('alice');
                return $entity;
            }
        );

        $this->userSession = $this->createMock(IUserSession::class);
        $this->userManager = $this->createMock(IUserManager::class);

        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('alice');
        $this->userManager->method('get')->with('alice')->willReturn($user);

        $this->service = new ScheduleWebhookSecretService(
            credentialsManager: $this->credentialsManager,
            objectService: $this->objectService,
            userSession: $this->userSession,
            userManager: $this->userManager,
            logger: $this->createMock(LoggerInterface::class)
        );

    }//end setUp()

    /**
     * Build a Schedule ObjectEntity.
     *
     * @param array<string, mixed> $data  The object's data.
     * @param string               $owner The owner UID.
     *
     * @return ObjectEntity
     */
    private function schedule(array $data, string $owner='alice'): ObjectEntity
    {
        $entity = new ObjectEntity();
        $entity->setUuid('sched-1');
        $entity->setOwner($owner);
        $entity->setObject($data);
        return $entity;

    }//end schedule()

    /**
     * mint() generates an hws_-prefixed secret with >= 32 bytes of entropy
     * (64 hex chars after the prefix), stores it via ICredentialsManager, and
     * returns the plaintext exactly once while stamping the derived fields.
     *
     * @return void
     *
     * @spec openspec/changes/delivery-channels/specs/talk-delivery/spec.md#requirement-a-per-schedule-webhook-signing-secret-can-be-minted-rotated-and-revoked-mvp
     */
    public function testMintGeneratesSecretAndStoresViaCredentialsManager(): void
    {
        $result = $this->service->mint(schedule: $this->schedule(['deliver' => 'webhook']));

        $this->assertStringStartsWith('hws_', $result['secret']);
        $this->assertSame(4 + 64, strlen($result['secret']));
        $this->assertNotNull($result['rotatedAt']);

        $this->assertSame($result['secret'], $this->stored['alice|hermiq/webhook-secret/sched-1']);
        $this->assertCount(1, $this->saved);
        $this->assertTrue($this->saved[0]['deliverWebhookSecretConfigured']);
        $this->assertArrayNotHasKey('deliverWebhookSecret', $this->saved[0]);

    }//end testMintGeneratesSecretAndStoresViaCredentialsManager()

    /**
     * mint() refuses (throws) when a secret already exists — use rotate() instead.
     *
     * @return void
     *
     * @spec openspec/changes/delivery-channels/specs/talk-delivery/spec.md#requirement-a-per-schedule-webhook-signing-secret-can-be-minted-rotated-and-revoked-mvp
     */
    public function testMintRefusesWhenAlreadyConfigured(): void
    {
        $this->expectException(RuntimeException::class);
        $this->service->mint(schedule: $this->schedule(['deliver' => 'webhook', 'deliverWebhookSecretConfigured' => true]));

    }//end testMintRefusesWhenAlreadyConfigured()

    /**
     * rotate() overwrites the stored secret immediately — the previous secret
     * can no longer be used to reproduce a valid signature.
     *
     * @return void
     *
     * @spec openspec/changes/delivery-channels/specs/talk-delivery/spec.md#requirement-a-per-schedule-webhook-signing-secret-can-be-minted-rotated-and-revoked-mvp
     */
    public function testRotateOverwritesPreviousSecretImmediately(): void
    {
        $minted = $this->service->mint(schedule: $this->schedule(['deliver' => 'webhook']));

        $configured = $this->schedule(['deliver' => 'webhook', 'deliverWebhookSecretConfigured' => true]);
        $rotated    = $this->service->rotate(schedule: $configured);

        $this->assertNotSame($minted['secret'], $rotated['secret']);
        $this->assertSame($rotated['secret'], $this->stored['alice|hermiq/webhook-secret/sched-1']);
        $this->assertNotSame($minted['secret'], $this->stored['alice|hermiq/webhook-secret/sched-1']);

    }//end testRotateOverwritesPreviousSecretImmediately()

    /**
     * rotate() refuses (throws) when nothing is configured yet — use mint() instead.
     *
     * @return void
     *
     * @spec openspec/changes/delivery-channels/specs/talk-delivery/spec.md#requirement-a-per-schedule-webhook-signing-secret-can-be-minted-rotated-and-revoked-mvp
     */
    public function testRotateRefusesWhenNotConfigured(): void
    {
        $this->expectException(RuntimeException::class);
        $this->service->rotate(schedule: $this->schedule(['deliver' => 'webhook']));

    }//end testRotateRefusesWhenNotConfigured()

    /**
     * revoke() deletes the stored credential and flips deliverWebhookSecretConfigured to false.
     *
     * @return void
     *
     * @spec openspec/changes/delivery-channels/specs/talk-delivery/spec.md#requirement-a-per-schedule-webhook-signing-secret-can-be-minted-rotated-and-revoked-mvp
     */
    public function testRevokeDeletesCredentialAndClearsFlag(): void
    {
        $this->service->mint(schedule: $this->schedule(['deliver' => 'webhook']));
        $configured = $this->schedule(['deliver' => 'webhook', 'deliverWebhookSecretConfigured' => true]);

        $updated = $this->service->revoke(schedule: $configured);

        $this->assertArrayNotHasKey('alice|hermiq/webhook-secret/sched-1', $this->stored);
        $this->assertFalse($updated->getObject()['deliverWebhookSecretConfigured']);

    }//end testRevokeDeletesCredentialAndClearsFlag()

    /**
     * revoke() is idempotent: calling it when nothing is configured still succeeds.
     *
     * @return void
     *
     * @spec openspec/changes/delivery-channels/specs/talk-delivery/spec.md#requirement-a-per-schedule-webhook-signing-secret-can-be-minted-rotated-and-revoked-mvp
     */
    public function testRevokeIsIdempotentWhenNothingConfigured(): void
    {
        $updated = $this->service->revoke(schedule: $this->schedule(['deliver' => 'webhook']));

        $this->assertFalse($updated->getObject()['deliverWebhookSecretConfigured']);

    }//end testRevokeIsIdempotentWhenNothingConfigured()

    /**
     * status() never includes the plaintext secret.
     *
     * @return void
     *
     * @spec openspec/changes/delivery-channels/specs/talk-delivery/spec.md#requirement-a-per-schedule-webhook-signing-secret-can-be-minted-rotated-and-revoked-mvp
     */
    public function testStatusNeverIncludesPlaintextSecret(): void
    {
        $result   = $this->service->mint(schedule: $this->schedule(['deliver' => 'webhook']));
        $status   = $this->service->status(
            schedule: $this->schedule(
                ['deliver' => 'webhook', 'deliverWebhookSecretConfigured' => true, 'deliverWebhookSecretRotatedAt' => $result['rotatedAt']]
            )
        );

        $this->assertTrue($status['configured']);
        $this->assertSame($result['rotatedAt'], $status['rotatedAt']);
        $this->assertArrayNotHasKey('secret', $status);

    }//end testStatusNeverIncludesPlaintextSecret()

    /**
     * status() reads configured=false / rotatedAt=null for an unconfigured schedule.
     *
     * @return void
     *
     * @spec openspec/changes/delivery-channels/specs/talk-delivery/spec.md#requirement-a-per-schedule-webhook-signing-secret-can-be-minted-rotated-and-revoked-mvp
     */
    public function testStatusReportsUnconfigured(): void
    {
        $status = $this->service->status(schedule: $this->schedule(['deliver' => 'webhook']));

        $this->assertFalse($status['configured']);
        $this->assertNull($status['rotatedAt']);

    }//end testStatusReportsUnconfigured()

    /**
     * retrieveSecret() returns the stored plaintext for a configured schedule.
     *
     * @return void
     *
     * @spec openspec/changes/delivery-channels/specs/talk-delivery/spec.md#requirement-deliver-run-output-via-a-signed-outbound-webhook-mvp
     */
    public function testRetrieveSecretReturnsStoredPlaintext(): void
    {
        $result = $this->service->mint(schedule: $this->schedule(['deliver' => 'webhook']));

        $secret = $this->service->retrieveSecret(
            schedule: $this->schedule(['deliver' => 'webhook', 'deliverWebhookSecretConfigured' => true])
        );

        $this->assertSame($result['secret'], $secret);

    }//end testRetrieveSecretReturnsStoredPlaintext()

    /**
     * retrieveSecret() returns null when nothing is configured — the caller
     * (DeliveryService::deliverWebhook()) fails closed on this.
     *
     * @return void
     *
     * @spec openspec/changes/delivery-channels/specs/talk-delivery/spec.md#requirement-deliver-run-output-via-a-signed-outbound-webhook-mvp
     */
    public function testRetrieveSecretReturnsNullWhenUnconfigured(): void
    {
        $secret = $this->service->retrieveSecret(schedule: $this->schedule(['deliver' => 'webhook']));

        $this->assertNull($secret);

    }//end testRetrieveSecretReturnsNullWhenUnconfigured()

    /**
     * retrieveSecret() returns null (never throws) when the credentials
     * manager itself fails.
     *
     * @return void
     *
     * @spec openspec/changes/delivery-channels/specs/talk-delivery/spec.md#requirement-deliver-run-output-via-a-signed-outbound-webhook-mvp
     */
    public function testRetrieveSecretReturnsNullOnCredentialsManagerFailure(): void
    {
        $this->credentialsManager = $this->createMock(ICredentialsManager::class);
        $this->credentialsManager->method('retrieve')->willThrowException(new RuntimeException('store unavailable'));

        $service = new ScheduleWebhookSecretService(
            credentialsManager: $this->credentialsManager,
            objectService: $this->objectService,
            userSession: $this->userSession,
            userManager: $this->userManager,
            logger: $this->createMock(LoggerInterface::class)
        );

        $secret = $service->retrieveSecret(
            schedule: $this->schedule(['deliver' => 'webhook', 'deliverWebhookSecretConfigured' => true])
        );

        $this->assertNull($secret);

    }//end testRetrieveSecretReturnsNullOnCredentialsManagerFailure()
}//end class
