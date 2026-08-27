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

use OCA\Hermiq\Service\AiOversightService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Covers the advisory oversight record: what it writes, and what it refuses.
 *
 * @spec openspec/changes/ai-oversight-advisory-approvals/specs/ai-oversight/spec.md
 */
class AiOversightServiceTest extends TestCase {

    /**
     * @var ObjectService&MockObject
     */
    private $objectService;

    /**
     * @var LoggerInterface&MockObject
     */
    private $logger;

    /**
     * @var AiOversightService
     */
    private AiOversightService $service;


    /**
     * Set up the subject under test.
     *
     * @return void
     */
    protected function setUp(): void {
        parent::setUp();
        $this->objectService = $this->createMock(ObjectService::class);
        $this->logger        = $this->createMock(LoggerInterface::class);
        $this->service       = new AiOversightService($this->objectService, $this->logger);

    }//end setUp()


    /**
     * A complete record with a well-formed set of keys.
     *
     * @param array<string, mixed> $overrides Keys to replace.
     *
     * @return array<string, mixed> The record.
     */
    private function record(array $overrides=[]): array {
        return array_merge(
            [
                'originApp'      => 'procest',
                'subjectType'    => 'case',
                'subjectId'      => 'Z-2026-0042',
                'humanAction'    => 'accepted',
                'userId'         => 'alice',
                'decidedAt'      => '2026-08-22T10:25:00+00:00',
                'suggestionType' => 'classification',
                'action'         => 'classify-document',
                'model'          => 'llama3.1:8b',
                'prompt'         => 'Classify this document.',
                'suggestion'     => 'Bezwaar',
                'confidence'     => 0.71,
                'responseTimeMs' => 840,
            ],
            $overrides
        );

    }//end record()


    /**
     * Capture the object handed to saveObject and return a stub uuid.
     *
     * @param array<string, mixed>|null $captured Receives the written object.
     *
     * @return void
     */
    private function expectSave(?array &$captured): void {
        $entity = $this->createMock(ObjectEntity::class);
        $entity->method('getUuid')->willReturn('written-uuid');

        $this->objectService->expects($this->once())
            ->method('saveObject')
            ->willReturnCallback(
                function (...$args) use (&$captured, $entity) {
                    $captured = $args[0];
                    return $entity;
                }
            );

    }//end expectSave()


    /**
     * An accepted suggestion becomes a terminal `approved` advisory Approval.
     *
     * @return void
     */
    public function testAcceptedIsWrittenAsApprovedAdvisory(): void {
        $captured = null;
        $this->expectSave($captured);

        $uuid = $this->service->record($this->record());

        $this->assertSame('written-uuid', $uuid);
        $this->assertSame('approved', $captured['status']);
        $this->assertSame('advisory', $captured['sourceType']);
        // Terminal at creation: no interval between asked and decided, so both
        // stamps carry the decision time and no inbox query sees a null.
        $this->assertSame('2026-08-22T10:25:00+00:00', $captured['requestedAt']);
        $this->assertSame('2026-08-22T10:25:00+00:00', $captured['decidedAt']);
        $this->assertSame('alice', $captured['decidedBy']);
        $this->assertSame('origin-app', $captured['decidedVia']);

    }//end testAcceptedIsWrittenAsApprovedAdvisory()


    /**
     * The whole point of the variant: `overridden` survives as its own state.
     *
     * Flattening it into `denied` would erase the difference between "the human
     * ignored the model" and "the human corrected it", which is the single most
     * useful signal in an Art. 14 audit.
     *
     * @return void
     */
    public function testOverriddenKeepsItsOwnStatusAndActualValue(): void {
        $captured = null;
        $this->expectSave($captured);

        $this->service->record(
            $this->record(['humanAction' => 'overridden', 'actualValue' => 'Klacht'])
        );

        $this->assertSame('overridden', $captured['status']);
        $this->assertSame('Klacht', $captured['advisoryContext']['actualValue']);
        $this->assertSame('Bezwaar', $captured['advisoryContext']['suggestion']);

    }//end testOverriddenKeepsItsOwnStatusAndActualValue()


    /**
     * A rejected suggestion maps onto the gate's `denied`.
     *
     * @return void
     */
    public function testRejectedIsWrittenAsDenied(): void {
        $captured = null;
        $this->expectSave($captured);

        $this->service->record($this->record(['humanAction' => 'rejected']));

        $this->assertSame('denied', $captured['status']);

    }//end testRejectedIsWrittenAsDenied()


    /**
     * The origin app's subject is carried verbatim, not resolved.
     *
     * Hermiq must never need another app's register to render its own audit
     * trail — that is what keeps the log readable when the origin app is
     * uninstalled.
     *
     * @return void
     */
    public function testSubjectIsCarriedVerbatim(): void {
        $captured = null;
        $this->expectSave($captured);

        $this->service->record($this->record());

        $this->assertSame('procest', $captured['advisoryContext']['originApp']);
        $this->assertSame('case', $captured['advisoryContext']['subjectType']);
        $this->assertSame('Z-2026-0042', $captured['advisoryContext']['subjectId']);

    }//end testSubjectIsCarriedVerbatim()


    /**
     * A record missing a required key is refused, not stored half-formed.
     *
     * @param string $missing The key to drop.
     *
     * @return void
     *
     * @dataProvider requiredKeyProvider
     */
    public function testRecordMissingARequiredKeyIsRefused(string $missing): void {
        $this->objectService->expects($this->never())->method('saveObject');

        $record = $this->record();
        unset($record[$missing]);

        $this->assertNull($this->service->record($record));

    }//end testRecordMissingARequiredKeyIsRefused()


    /**
     * The keys without which the record is evidence of nothing.
     *
     * @return array<string, string[]> The data set.
     */
    public static function requiredKeyProvider(): array {
        return [
            'originApp'   => ['originApp'],
            'subjectType' => ['subjectType'],
            'subjectId'   => ['subjectId'],
            'humanAction' => ['humanAction'],
        ];

    }//end requiredKeyProvider()


    /**
     * An unrecognised humanAction is refused rather than guessed at.
     *
     * @return void
     */
    public function testUnknownHumanActionIsRefused(): void {
        $this->objectService->expects($this->never())->method('saveObject');

        $this->assertNull($this->service->record($this->record(['humanAction' => 'maybe'])));

    }//end testUnknownHumanActionIsRefused()


    /**
     * A storage failure returns null instead of raising.
     *
     * The origin app has already completed the user's action; failing its
     * request after the fact would turn an audit outage into a functional one.
     *
     * @return void
     */
    public function testStorageFailureIsSwallowedAndReported(): void {
        $this->objectService->method('saveObject')
            ->willThrowException(new \RuntimeException('register unavailable'));
        $this->logger->expects($this->once())->method('error');

        $this->assertNull($this->service->record($this->record()));

    }//end testStorageFailureIsSwallowedAndReported()


    /**
     * A record without a decidedAt is stamped rather than stored undated.
     *
     * @return void
     */
    public function testMissingDecidedAtIsStamped(): void {
        $captured = null;
        $this->expectSave($captured);

        $record = $this->record();
        unset($record['decidedAt']);
        $this->service->record($record);

        $this->assertNotSame('', $captured['decidedAt']);
        $this->assertSame($captured['decidedAt'], $captured['requestedAt']);

    }//end testMissingDecidedAtIsStamped()


}//end class
