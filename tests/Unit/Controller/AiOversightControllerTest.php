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
use OCP\AppFramework\Http\DataDownloadResponse;
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
        $this->userSession = $this->createMock(originalClassName: IUserSession::class);
        $this->access      = $this->createMock(originalClassName: AiOversightAccess::class);
        $this->oversight   = $this->createMock(originalClassName: AiOversightService::class);
        $this->controller  = new AiOversightController(
            request: $this->createMock(originalClassName: IRequest::class),
            userSession: $this->userSession,
            access: $this->access,
            oversight: $this->oversight,
            logger: $this->createMock(originalClassName: LoggerInterface::class)
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
        $user = $this->createMock(originalClassName: IUser::class);
        $user->method('getUID')->willReturn($uid);
        $this->userSession->method('getUser')->willReturn($user);

    }//end signIn()


    /**
     * An allowed reader gets the subject's records.
     *
     * @return void
     */
    public function testAllowedReaderGetsTheRecords(): void {
        $this->signIn(uid: 'alice');
        $this->access->method('maySeeSubject')->with('alice', 'case-1')->willReturn(true);
        $this->oversight->expects($this->once())
            ->method('listForSubject')
            ->with('dossiq', 'case-1', 'case', 25, 0)
            ->willReturn(['results' => [['status' => 'unused']], 'total' => 1]);

        $response = $this->controller->subject(originApp: 'dossiq', subjectId: 'case-1', subjectType: 'case');

        $this->assertSame(expected: Http::STATUS_OK, actual: $response->getStatus());
        $this->assertSame(expected: 1, actual: $response->getData()['total']);

    }//end testAllowedReaderGetsTheRecords()


    /**
     * A denied reader gets 403 and nothing is read.
     *
     * @return void
     */
    public function testDeniedReaderGetsForbiddenAndNothingIsRead(): void {
        $this->signIn(uid: 'mallory');
        $this->access->method('maySeeSubject')->willReturn(false);
        $this->oversight->expects($this->never())->method('listForSubject');

        $response = $this->controller->subject(originApp: 'dossiq', subjectId: 'case-1');

        $this->assertSame(expected: Http::STATUS_FORBIDDEN, actual: $response->getStatus());

    }//end testDeniedReaderGetsForbiddenAndNothingIsRead()


    /**
     * The subject is required; nothing is checked or read without it.
     *
     * @return void
     */
    public function testMissingSubjectIsABadRequest(): void {
        $this->signIn(uid: 'alice');
        $this->access->expects($this->never())->method('maySeeSubject');
        $this->oversight->expects($this->never())->method('listForSubject');

        $response = $this->controller->subject(originApp: 'dossiq');

        $this->assertSame(expected: Http::STATUS_BAD_REQUEST, actual: $response->getStatus());

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

        $this->assertSame(expected: Http::STATUS_UNAUTHORIZED, actual: $response->getStatus());

    }//end testAnonymousIsUnauthorized()


    /**
     * A read failure answers 500 without leaking the cause.
     *
     * @return void
     */
    public function testReadFailureIsAServerError(): void {
        $this->signIn(uid: 'olga');
        $this->access->method('maySeeSubject')->willReturn(true);
        $this->oversight->method('listForSubject')->willThrowException(new \RuntimeException('db gone'));

        $response = $this->controller->subject(originApp: 'dossiq', subjectId: 'case-1');

        $this->assertSame(expected: Http::STATUS_INTERNAL_SERVER_ERROR, actual: $response->getStatus());
        $this->assertStringNotContainsString(needle: 'db gone', haystack: (string) json_encode($response->getData()));

    }//end testReadFailureIsAServerError()

    /**
     * An oversight holder who may open the subject downloads its records as
     * CSV, newest first, with a formula-like cell neutralised.
     *
     * @return void
     */
    public function testOversightHolderDownloadsTheRecordsAsCsv(): void {
        $this->signIn(uid: 'olga');
        $this->access->method('hasOversightRole')->with('olga')->willReturn(true);
        $this->access->method('maySeeSubject')->with('olga', 'case-1')->willReturn(true);
        $this->oversight->expects($this->once())
            ->method('listForSubject')
            ->with('dossiq', 'case-1', '', 200, 0)
            ->willReturn(
                [
                    'results' => [
                        [
                            'decidedAt'       => '2026-10-08T10:14:00+00:00',
                            'decidedBy'       => 'pieter',
                            'prompt'          => 'Welke documenten moeten nog beoordeeld?',
                            'status'          => 'approved',
                            'advisoryContext' => ['model' => 'Hermiq, lokaal'],
                        ],
                        ['decidedAt' => '2026-10-07T15:41:00+00:00', 'decidedBy' => 'anouk', 'prompt' => '=HYPERLINK("x")', 'status' => 'denied'],
                    ],
                    'total'   => 2,
                ]
            );

        $response = $this->controller->subjectExport(originApp: 'dossiq', subjectId: 'case-1');

        $this->assertInstanceOf(expected: DataDownloadResponse::class, actual: $response);
        $this->assertSame(expected: Http::STATUS_OK, actual: $response->getStatus());
        $lines = explode("\n", trim($response->render()));
        $this->assertSame(expected: 'decidedAt,decidedBy,prompt,status,model', actual: $lines[0]);
        $first = '2026-10-08T10:14:00+00:00,pieter,"Welke documenten moeten nog beoordeeld?",approved,"Hermiq, lokaal"';
        $this->assertSame(expected: $first, actual: $lines[1]);
        $this->assertStringContainsString(needle: "'=HYPERLINK", haystack: $lines[2]);
        $this->assertCount(expectedCount: 3, haystack: $lines);

    }//end testOversightHolderDownloadsTheRecordsAsCsv()


    /**
     * A reader of the subject without the oversight role may not download.
     *
     * @return void
     */
    public function testReaderWithoutTheOversightRoleMayNotDownload(): void {
        $this->signIn(uid: 'alice');
        $this->access->method('hasOversightRole')->willReturn(false);
        $this->access->method('maySeeSubject')->willReturn(true);
        $this->oversight->expects($this->never())->method('listForSubject');

        $response = $this->controller->subjectExport(originApp: 'dossiq', subjectId: 'case-1');

        $this->assertSame(expected: Http::STATUS_FORBIDDEN, actual: $response->getStatus());

    }//end testReaderWithoutTheOversightRoleMayNotDownload()


    /**
     * The oversight role does not open a subject its holder may not see.
     *
     * @return void
     */
    public function testOversightHolderWhoMayNotSeeTheSubjectMayNotDownload(): void {
        $this->signIn(uid: 'olga');
        $this->access->method('hasOversightRole')->willReturn(true);
        $this->access->method('maySeeSubject')->willReturn(false);
        $this->oversight->expects($this->never())->method('listForSubject');

        $response = $this->controller->subjectExport(originApp: 'dossiq', subjectId: 'case-1');

        $this->assertSame(expected: Http::STATUS_FORBIDDEN, actual: $response->getStatus());

    }//end testOversightHolderWhoMayNotSeeTheSubjectMayNotDownload()


    /**
     * The download needs a session and a subject.
     *
     * @return void
     */
    public function testDownloadNeedsASessionAndASubject(): void {
        $this->userSession->method('getUser')->willReturn(null);
        $this->oversight->expects($this->never())->method('listForSubject');

        $response = $this->controller->subjectExport(originApp: 'dossiq', subjectId: 'case-1');
        $this->assertSame(expected: Http::STATUS_UNAUTHORIZED, actual: $response->getStatus());

    }//end testDownloadNeedsASessionAndASubject()


}//end class
