<?php

/**
 * The stage dispatcher's response mapping.
 *
 * The whole value of this class is that it does NOT hand a malformed answer on
 * as a stage result. `exitCode: 0` reads downstream as a PASS, so anything the
 * runner sends that is not a stage result must become a thrown step failure
 * rather than a default zero — a green tick that never ran is the one outcome
 * worse than a red one.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service;

use OCA\Hermiq\Service\StageDispatchService;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * Exposes the mapping seams. The same pattern `ProviderFactory` uses for
 * `cliDispatchOptions()`: a shape check nothing exercises is a shape check that
 * silently stops holding.
 */
class ExposedStageDispatchService extends StageDispatchService
{

    /**
     * @param string $body The body.
     *
     * @return array The mapped result.
     */
    public function mapResultPublic(string $body): array
    {
        return $this->mapResult(body: $body);

    }//end mapResultPublic()

    /**
     * @param string $body The body.
     *
     * @return string The reason.
     */
    public function reasonFromPublic(string $body): string
    {
        return $this->reasonFrom(body: $body);

    }//end reasonFromPublic()
}//end class

/**
 * @covers \OCA\Hermiq\Service\StageDispatchService
 */
class StageDispatchServiceTest extends TestCase
{

    /**
     * The service under test.
     *
     * @var ExposedStageDispatchService
     */
    private ExposedStageDispatchService $service;

    /**
     * Set up.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->service = new ExposedStageDispatchService(new NullLogger());

    }//end setUp()

    /**
     * A well-formed result is mapped and typed.
     *
     * @return void
     */
    public function testAWellFormedResultIsMapped(): void
    {
        $result = $this->service->mapResultPublic('{"exitCode":18,"output":"18 gate(s) failed","ref":"abc"}');

        $this->assertSame(18, $result['exitCode']);
        $this->assertSame('18 gate(s) failed', $result['output']);
        $this->assertSame('abc', $result['ref']);

    }//end testAWellFormedResultIsMapped()

    /**
     * A zero exit code is preserved — it is the PASS case, not a fallback.
     *
     * @return void
     */
    public function testAZeroExitCodeIsAResultInItsOwnRight(): void
    {
        $this->assertSame(0, $this->service->mapResultPublic('{"exitCode":0,"output":"ok","ref":"r"}')['exitCode']);

    }//end testAZeroExitCodeIsAResultInItsOwnRight()

    /**
     * Anything that is not a stage result THROWS rather than defaulting to 0.
     *
     * This is the test the class exists for: every one of these bodies would
     * become `exitCode: 0` under a lenient mapping, and a flow would read that
     * as gates that passed.
     *
     * @param string $body A body the runner should never send.
     *
     * @return void
     *
     * @dataProvider malformedBodies
     */
    public function testAMalformedBodyIsNeverAPass(string $body): void
    {
        $this->expectException(RuntimeException::class);
        $this->service->mapResultPublic($body);

    }//end testAMalformedBodyIsNeverAPass()

    /**
     * Bodies that are not stage results.
     *
     * @return array The cases.
     */
    public static function malformedBodies(): array
    {
        return [
            'empty'            => [''],
            'not json'         => ['<html>502 Bad Gateway</html>'],
            'json but a list'  => ['[1,2,3]'],
            'json but no code' => ['{"output":"something happened"}'],
            'an error object'  => ['{"error":"command not allowed: sh"}'],
        ];

    }//end malformedBodies()

    /**
     * The runner's own reason is surfaced when it sends one.
     *
     * The operator needs to see `command not allowed: sh` rather than a
     * generic failure — it is the difference between a misconfigured flow and
     * a broken ExApp.
     *
     * @return void
     */
    public function testTheRunnersReasonIsSurfaced(): void
    {
        $this->assertSame(
            'command not allowed: sh',
            $this->service->reasonFromPublic('{"error":"command not allowed: sh"}')
        );

    }//end testTheRunnersReasonIsSurfaced()

    /**
     * A body with no reason falls back rather than showing an empty message.
     *
     * @return void
     */
    public function testAReasonlessBodyFallsBack(): void
    {
        $this->assertSame('the runner gave no reason', $this->service->reasonFromPublic('<html>502</html>'));
        $this->assertSame('the runner gave no reason', $this->service->reasonFromPublic('{"error":"  "}'));

    }//end testAReasonlessBodyFallsBack()
}//end class
