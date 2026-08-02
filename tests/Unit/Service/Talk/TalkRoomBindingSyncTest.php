<?php

declare(strict_types=1);

/**
 * TalkRoomBinding::syncParticipants() unit tests (talk-agent-sessions).
 *
 * 🔴 What these protect is an AUTHORIZATION path, not bookkeeping. Permission to
 * take a turn is read from the STORED roster and deliberately never from live
 * Talk room membership (talk-shared-sessions), so this method is the only thing
 * that turns "invited to the room" into "allowed to speak". Get it wrong in
 * either direction and it is either a lockout or a privilege leak:
 *
 * - too little: a late joiner is refused their first message;
 * - too much: someone removed from the room keeps their turn rights.
 *
 * @category Tests
 * @package  OCA\Hermiq\Tests\Unit\Service\Talk
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

namespace OCA\Hermiq\Tests\Unit\Service\Talk;

use OCA\Hermiq\Service\Talk\TalkRoomBinding;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\Hermiq\Service\Talk\TalkRoomBinding::syncParticipants
 */
class TalkRoomBindingSyncTest extends TestCase
{

    private ObjectService&MockObject $objectService;

    private TalkRoomBinding $binding;

    /**
     * The payload handed to saveObject(), or null when nothing was written.
     *
     * @var array<string,mixed>|null
     */
    private ?array $saved = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->saved         = null;
        $this->objectService = $this->createMock(ObjectService::class);
        // Variadic on purpose. The real saveObject() takes
        // (object, extend, register, schema, uuid, ...) and the caller uses
        // NAMED arguments, so a callback that redeclares a plausible-looking
        // positional signature binds $register to $extend and silently records
        // nothing — which reads as "no write happened" and would have made the
        // assertions below pass for the wrong reason.
        $this->objectService->method('saveObject')->willReturnCallback(
            function (mixed ...$args): ObjectEntity {
                $this->saved = $args[0];
                $entity      = new ObjectEntity();
                $entity->setUuid('conv-1');
                $entity->setObject($args[0]);

                return $entity;
            }
        );

        $this->binding = new TalkRoomBinding(
            $this->objectService,
            $this->createMock(LoggerInterface::class)
        );

    }//end setUp()

    /**
     * A bound session with the given roster.
     *
     * @param array<int,string> $participants The stored roster.
     *
     * @return ObjectEntity The session.
     */
    private function session(array $participants): ObjectEntity
    {
        $entity = new ObjectEntity();
        $entity->setUuid('conv-1');
        $entity->setObject(
            [
                'title'         => 'Talk conversation',
                'userId'        => 'alice',
                'agentId'       => 'agent-1',
                'talkRoomToken' => 'room-1',
                'participants'  => $participants,
            ]
        );

        return $entity;

    }//end session()

    /**
     * A user invited after the bind lands on the roster — the case that decides
     * whether their first message is accepted or refused.
     */
    public function testALateJoinerIsAddedToTheRoster(): void
    {
        $this->binding->syncParticipants($this->session([]), ['alice', 'bob']);

        $this->assertNotNull($this->saved, 'A changed roster must be written.');
        $this->assertSame(['bob'], $this->saved['participants']);

    }//end testALateJoinerIsAddedToTheRoster()

    /**
     * The owner is implicit and stays OFF the roster, so a sync must not start
     * listing them — the roster means "in addition to the owner".
     */
    public function testTheOwnerIsNeverAddedToTheRoster(): void
    {
        $this->binding->syncParticipants($this->session([]), ['alice']);

        $this->assertNull($this->saved, 'Owner-only membership is no change at all.');

    }//end testTheOwnerIsNeverAddedToTheRoster()

    /**
     * Someone who LEFT the room loses their turn rights by the same path. The
     * roster is the permission, so this direction matters as much as adding.
     */
    public function testAUserWhoLeftTheRoomIsRemoved(): void
    {
        $this->binding->syncParticipants($this->session(['bob', 'carol']), ['alice', 'bob']);

        $this->assertNotNull($this->saved);
        $this->assertSame(['bob'], $this->saved['participants']);

    }//end testAUserWhoLeftTheRoomIsRemoved()

    /**
     * An unchanged roster costs a comparison, not a write — this runs on every
     * inbound turn.
     */
    public function testAnUnchangedRosterIsNotWritten(): void
    {
        $this->binding->syncParticipants($this->session(['bob']), ['alice', 'bob']);

        $this->assertNull($this->saved, 'No write when membership did not move.');

    }//end testAnUnchangedRosterIsNotWritten()

    /**
     * Order must not count as a change, or every turn would write.
     */
    public function testRosterOrderIsNotAChange(): void
    {
        $this->binding->syncParticipants($this->session(['carol', 'bob']), ['bob', 'carol', 'alice']);

        $this->assertNull($this->saved);

    }//end testRosterOrderIsNotAChange()

    /**
     * 🔴 saveObject() is PUT-semantic: every field must be carried forward, or
     * writing the roster silently deletes the rest of the session.
     */
    public function testTheWholePayloadIsCarriedForward(): void
    {
        $this->binding->syncParticipants($this->session([]), ['alice', 'bob']);

        $this->assertNotNull($this->saved);
        $this->assertSame('Talk conversation', $this->saved['title']);
        $this->assertSame('alice', $this->saved['userId']);
        $this->assertSame('agent-1', $this->saved['agentId']);
        $this->assertSame('room-1', $this->saved['talkRoomToken']);

    }//end testTheWholePayloadIsCarriedForward()

    /**
     * A sync failure must cost nothing: the caller is mid-turn, and the session
     * it was handed is still perfectly usable.
     */
    public function testAFailedSyncReturnsTheSessionUnchanged(): void
    {
        $objectService = $this->createMock(ObjectService::class);
        $objectService->method('saveObject')->willThrowException(new \RuntimeException('db down'));

        $binding = new TalkRoomBinding($objectService, $this->createMock(LoggerInterface::class));
        $session = $this->session([]);

        $this->assertSame($session, $binding->syncParticipants($session, ['alice', 'bob']));

    }//end testAFailedSyncReturnsTheSessionUnchanged()
}//end class
