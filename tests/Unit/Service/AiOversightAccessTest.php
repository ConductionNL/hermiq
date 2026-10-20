<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category  Test
 * @package   OCA\Hermiq\Tests\Unit\Service
 * @author    Conduction B.V. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 * @link      https://github.com/ConductionNL/hermiq
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service;

use OCA\Hermiq\Service\AiOversightAccess;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use OCP\IGroupManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Covers who may read the advisory Approvals about a subject.
 *
 * @spec openspec/changes/ai-oversight-subject-access/specs/ai-oversight/spec.md
 */
class AiOversightAccessTest extends TestCase {

    private const SUBJECT = '0b9d6c1e-3f4a-4c2b-9a51-7e2f3d4c5b6a';

    /**
     * @var ObjectService&MockObject
     */
    private $objectService;

    /**
     * @var IGroupManager&MockObject
     */
    private $groupManager;

    /**
     * @var AiOversightAccess
     */
    private AiOversightAccess $access;


    /**
     * Set up the subject under test.
     *
     * @return void
     */
    protected function setUp(): void {
        parent::setUp();
        $this->objectService = $this->createMock(ObjectService::class);
        $this->groupManager  = $this->createMock(IGroupManager::class);
        $this->access        = new AiOversightAccess(
            $this->objectService,
            $this->groupManager,
            $this->createMock(LoggerInterface::class)
        );

    }//end setUp()


    /**
     * A reader who may open the subject may read its records, and the lookup
     * runs with RBAC and multitenancy on.
     *
     * @return void
     */
    public function testReaderOfTheSubjectIsAllowed(): void {
        $this->groupManager->method('isAdmin')->willReturn(false);
        $this->groupManager->method('isInGroup')->willReturn(false);
        $this->objectService->expects($this->once())
            ->method('find')
            ->with(self::SUBJECT, $this->anything(), $this->anything(), $this->anything(), $this->anything(), true, true)
            ->willReturn($this->createMock(ObjectEntity::class));

        $this->assertTrue($this->access->maySeeSubject(userId: 'alice', subjectId: self::SUBJECT));

    }//end testReaderOfTheSubjectIsAllowed()


    /**
     * A reader OpenRegister refuses the subject to is denied.
     *
     * @return void
     */
    public function testReaderRefusedTheSubjectIsDenied(): void {
        $this->groupManager->method('isAdmin')->willReturn(false);
        $this->groupManager->method('isInGroup')->willReturn(false);
        $this->objectService->method('find')->willThrowException(new \RuntimeException('Not authorized'));

        $this->assertFalse($this->access->maySeeSubject(userId: 'mallory', subjectId: self::SUBJECT));

    }//end testReaderRefusedTheSubjectIsDenied()


    /**
     * A subject that is not found grants nothing.
     *
     * @return void
     */
    public function testMissingSubjectIsDenied(): void {
        $this->groupManager->method('isAdmin')->willReturn(false);
        $this->groupManager->method('isInGroup')->willReturn(false);
        $this->objectService->method('find')->willReturn(null);

        $this->assertFalse($this->access->maySeeSubject(userId: 'mallory', subjectId: self::SUBJECT));

    }//end testMissingSubjectIsDenied()


    /**
     * A subject id that is not a uuid is never looked up for a plain reader.
     *
     * @return void
     */
    public function testNonUuidSubjectIsDeniedWithoutLookup(): void {
        $this->groupManager->method('isAdmin')->willReturn(false);
        $this->groupManager->method('isInGroup')->willReturn(false);
        $this->objectService->expects($this->never())->method('find');

        $this->assertFalse($this->access->maySeeSubject(userId: 'alice', subjectId: '42'));

    }//end testNonUuidSubjectIsDeniedWithoutLookup()


    /**
     * A member of the oversight group reads every subject without a lookup.
     *
     * @return void
     */
    public function testOversightRoleIsAllowedWithoutLookup(): void {
        $this->groupManager->method('isAdmin')->willReturn(false);
        $this->groupManager->method('isInGroup')
            ->with('olga', AiOversightAccess::OVERSIGHT_GROUP)
            ->willReturn(true);
        $this->objectService->expects($this->never())->method('find');

        $this->assertTrue($this->access->maySeeSubject(userId: 'olga', subjectId: '42'));

    }//end testOversightRoleIsAllowedWithoutLookup()


    /**
     * An administrator reads every subject.
     *
     * @return void
     */
    public function testAdminIsAllowed(): void {
        $this->groupManager->method('isAdmin')->with('root')->willReturn(true);
        $this->objectService->expects($this->never())->method('find');

        $this->assertTrue($this->access->maySeeSubject(userId: 'root', subjectId: self::SUBJECT));

    }//end testAdminIsAllowed()


    /**
     * An empty user id holds no role.
     *
     * @return void
     */
    public function testEmptyUserHasNoRole(): void {
        $this->groupManager->expects($this->never())->method('isAdmin');

        $this->assertFalse($this->access->hasOversightRole(userId: ''));

    }//end testEmptyUserHasNoRole()


}//end class
