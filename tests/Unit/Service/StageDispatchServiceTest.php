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

use OCA\Hermiq\Service\Llm\RunTokenService;
use OCA\Hermiq\Service\StageDispatchService;
use OCP\ICache;
use OCP\ICacheFactory;
use OCP\Security\ISecureRandom;
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

    /**
     * Expose the payload builder.
     *
     * `buildParams()` is where the run token and the push declaration are put
     * into the request, and neither is visible from `mapResult()`. A field that
     * exists on both sides of a boundary and not IN it is the failure this
     * codebase has already paid for once (`toolRepo`).
     *
     * @param string $repo         Target repository.
     * @param string $ref          Ref.
     * @param array  $command      Command argv.
     * @param int    $ceiling      Stage ceiling in ms.
     * @param array  $push         Push declaration, or [].
     *
     * @return array The payload.
     */
    public function buildParamsPublic(
        string $repo,
        string $ref,
        array $command,
        int $ceiling,
        array $push=[]
    ): array {
        return $this->buildParams(
            repo: $repo,
            ref: $ref,
            command: $command,
            uid: 'admin',
            credentialId: '',
            ceiling: $ceiling,
            toolRepo: '',
            toolRef: '',
            push: $push
        );

    }//end buildParamsPublic()
}//end class

/**
 * `@uses RunTokenService` because the dispatcher is given a REAL one over a stub
 * cache rather than a mock. `beStrictAboutCoverageMetadata="true"` fails any test
 * that executes an undeclared class, and this is that declaration — not a
 * loosening. The real service is deliberate: a mock returning a fixed string
 * asserts that the call happens and nothing about what is made, and what is made
 * is exactly where the TTL bug lived (the turn's 150 s against a 30-minute stage).
 *
 * @covers \OCA\Hermiq\Service\StageDispatchService
 *
 * @uses \OCA\Hermiq\Service\Llm\RunTokenService
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
        // A REAL RunTokenService over a stub cache, not a mock of it.
        //
        // The payload builder mints a token on every dispatch, and the reason it
        // does is that a stage behind the governed proxy has no route out
        // without one. A mock returning a fixed string would assert that the
        // call is made and nothing about the thing being made, which is where
        // the TTL bug lives: the turn default is 150 seconds and a stage runs
        // for thirty minutes.
        $cacheFactory = $this->createMock(ICacheFactory::class);
        $cacheFactory->method('createDistributed')->willReturn($this->createMock(ICache::class));

        $secureRandom = $this->createMock(ISecureRandom::class);
        $secureRandom->method('generate')->willReturn('stub-run-token-for-the-mapping-tests');

        $this->service = new ExposedStageDispatchService(
            new NullLogger(),
            new RunTokenService($cacheFactory, $secureRandom)
        );

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

    /**
     * Every stage carries a per-run egress identity.
     *
     * `/run` has built a per-run proxy URL since governed egress shipped;
     * `/stage` never did. Behind the CONNECT proxy that is not a smaller fence
     * but no route at all — the PDP refuses a token-less CONNECT with
     * `no_run_token` before it evaluates any policy, and the symptom is a
     * `git clone` failure that points at the forge rather than at policy.
     *
     * @return void
     */
    public function testEveryStageCarriesARunToken(): void
    {
        $params = $this->service->buildParamsPublic(
            'https://github.com/ConductionNL/hydra',
            'development',
            ['scripts/run-hydra-gates.sh'],
            60000
        );

        $this->assertArrayHasKey('runToken', $params, 'a stage without a run token cannot get out');
        $this->assertNotSame('', $params['runToken']);

    }//end testEveryStageCarriesARunToken()

    /**
     * A stage that declares no push sends no push.
     *
     * The default posture is read-only, and it must be the ABSENCE of the key
     * rather than an empty one: the runner treats any object as "this stage may
     * write", which withholds the credential from the command child and changes
     * how the whole stage behaves.
     *
     * @return void
     */
    public function testAStageWithoutAPushDeclarationSendsNone(): void
    {
        $params = $this->service->buildParamsPublic(
            'https://github.com/ConductionNL/hydra',
            'development',
            ['scripts/run-hydra-gates.sh'],
            60000
        );

        $this->assertArrayNotHasKey('push', $params);

    }//end testAStageWithoutAPushDeclarationSendsNone()

    /**
     * A declared push reaches the runner intact.
     *
     * @return void
     */
    public function testADeclaredPushIsForwardedVerbatim(): void
    {
        $push = [
            'branch'      => 'feature/493/x',
            'issue'       => 493,
            'scope'       => ['lib'],
            'allowedRepo' => 'https://github.com/ConductionNL/hydra',
        ];

        $params = $this->service->buildParamsPublic(
            'https://github.com/ConductionNL/hydra',
            'development',
            ['scripts/run-hydra-gates.sh'],
            60000,
            $push
        );

        $this->assertSame($push, $params['push']);

    }//end testADeclaredPushIsForwardedVerbatim()

}//end class
