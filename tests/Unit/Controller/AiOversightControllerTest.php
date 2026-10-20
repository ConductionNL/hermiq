<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category  Test
 * @package   OCA\Hermiq\Tests\Unit\Controller
 * @author    Conduction B.V. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 * @link      https://github.com/ConductionNL/hermiq
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Controller;

use OCA\Hermiq\Controller\AiOversightController;
use OCA\Hermiq\Service\AiOversightAccess;
use OCA\Hermiq\Service\AiOversightService;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Covers the subject route: access is decided before anything is read.
 *
 * @spec openspec/changes/ai-oversight-subject-access/specs/ai-oversight/spec.md
 */
class AiOversightControllerTest extends TestCase {

    /**
     * @var IUserSession&MockObject
     */
    private $userSession;

    /**
     * @var AiOversightAccess&MockObject
     */
    private $access;

    /**
     * @var AiOversightService&MockObject
     */
    private $oversight;

    /**
     * @var AiOversightController
     */
    private AiOversightController $controller;


    /**
     * Set up the subject under test.
     *
     * @return void
     */
    protected function setUp(): void {
        parent::setUp();
        $this->userSession = $this->createMock(IUserSession::class);
        $this->access      = $this->createMock(AiOversightAccess::class);
        $this->oversight   = $this->createMock(AiOversightService::class);
        $this->controller  = new AiOversightController(
            $this->createMock(IRequest::class),
            $this->userSession,
            $this->access,
            $this->oversight,
            $this->createMock(LoggerInterface::class)
        );

    }//end setUp()


    /**
     * Sign a user in.
     *
     * @param string $uid The user id.
     *
     * @return void
     */
    private function signIn(string $uid): void {
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn($uid);
        $this->userSession->method('getUser')->willReturn($user);

    }//end signIn()


    /**
     * An allowed reader gets the subject's records.
     *
     * @return void
     */
    public function testAllowedReaderGetsTheRecords(): void {
        $this->signIn('alice');
        $this->access->method('maySeeSubject')->with('alice', 'case-1')->willReturn(true);
        $this->oversight->expects($this->once())
            ->method('listForSubject')
            ->with('dossiq', 'case-1', 'case', 25, 0)
            ->willReturn(['results' => [['status' => 'unused']], 'total' => 1]);

        $response = $this->controller->subject(originApp: 'dossiq', subjectId: 'case-1', subjectType: 'case');

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $this->assertSame(1, $response->getData()['total']);

    }//end testAllowedReaderGetsTheRecords()


    /**
     * A denied reader gets 403 and nothing is read.
     *
     * @return void
     */
    public function testDeniedReaderGetsForbiddenAndNothingIsRead(): void {
        $this->signIn('mallory');
        $this->access->method('maySeeSubject')->willReturn(false);
        $this->oversight->expects($this->never())->method('listForSubject');

        $response = $this->controller->subject(originApp: 'dossiq', subjectId: 'case-1');

        $this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());

    }//end testDeniedReaderGetsForbiddenAndNothingIsRead()


    /**
     * The subject is required; nothing is checked or read without it.
     *
     * @return void
     */
    public function testMissingSubjectIsABadRequest(): void {
        $this->signIn('alice');
        $this->access->expects($this->never())->method('maySeeSubject');
        $this->oversight->expects($this->never())->method('listForSubject');

        $response = $this->controller->subject(originApp: 'dossiq');

        $this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());

    }//end testMissingSubjectIsABadRequest()


    /**
     * Without a session the route answers 401.
     *
     * @return void
     */
    public function testAnonymousIsUnauthorized(): void {
        $this->userSession->method('getUser')->willReturn(null);
        $this->oversight->expects($this->never())->method('listForSubject');

        $response = $this->controller->subject(originApp: 'dossiq', subjectId: 'case-1');

        $this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());

    }//end testAnonymousIsUnauthorized()


    /**
     * A read failure answers 500 without leaking the cause.
     *
     * @return void
     */
    public function testReadFailureIsAServerError(): void {
        $this->signIn('olga');
        $this->access->method('maySeeSubject')->willReturn(true);
        $this->oversight->method('listForSubject')->willThrowException(new \RuntimeException('db gone'));

        $response = $this->controller->subject(originApp: 'dossiq', subjectId: 'case-1');

        $this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
        $this->assertStringNotContainsString('db gone', (string) json_encode($response->getData()));

    }//end testReadFailureIsAServerError()


}//end class
