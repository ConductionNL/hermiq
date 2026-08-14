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
class ExposedStageDispatchService extends StageDispatchService {

	/**
	 * @param string $body The body.
	 *
	 * @return array The mapped result.
	 */
	public function mapResultPublic(string $body): array {
		return $this->mapResult(body: $body);
	}//end mapResultPublic()

	/**
	 * @param string $body The body.
	 *
	 * @return string The reason.
	 */
	public function reasonFromPublic(string $body): string {
		return $this->reasonFrom(body: $body);
	}//end reasonFromPublic()

	/**
	 * Expose the async acknowledgement mapper.
	 *
	 * `mapAccepted()` is the seam where a 202 becomes a HANDLE rather than a
	 * result. It has to be reachable on its own: the shape it must NOT produce
	 * is a stage result, and only a direct test can assert that.
	 *
	 * @param string $body The body.
	 *
	 * @return array The mapped handle.
	 */
	public function mapAcceptedPublic(string $body): array {
		return $this->mapAccepted(body: $body);
	}//end mapAcceptedPublic()

	/**
	 * Expose the payload builder.
	 *
	 * `buildParams()` is where the run token and the push declaration are put
	 * into the request, and neither is visible from `mapResult()`. A field that
	 * exists on both sides of a boundary and not IN it is the failure this
	 * codebase has already paid for once (`toolRepo`).
	 *
	 * @param string $repo Target repository.
	 * @param string $ref Ref.
	 * @param array $command Command argv.
	 * @param int $ceiling Stage ceiling in ms.
	 * @param array $push Push declaration, or [].
	 *
	 * @return array The payload.
	 */
	public function buildParamsPublic(
		string $repo,
		string $ref,
		array $command,
		int $ceiling,
		array $push = [],
		string $credentialId = '',
		string $toolRepo = '',
		string $pushCredentialId = '',
		string $llmCredentialId = '',
		bool $async = false,
	): array {
		return $this->buildParams(
			repo: $repo,
			ref: $ref,
			command: $command,
			uid: 'admin',
			credentialId: $credentialId,
			ceiling: $ceiling,
			toolRepo: $toolRepo,
			toolRef: '',
			push: $push,
			pushCredentialId: $pushCredentialId,
			llmCredentialId: $llmCredentialId,
			async: $async
		);

	}//end buildParamsPublic()

	/**
	 * The credential id each broker call was made with, in order of use.
	 *
	 * @var array<string, string>
	 */
	public array $brokerCalls = [];

	/**
	 * Record which credential the TOOL ARCHIVE was fetched with.
	 *
	 * Stubbed rather than mocked: the real method reaches `OCP\Server::get()`,
	 * which is not available in a unit test, so the choice is between a seam
	 * here and not testing the decision at all.
	 *
	 * @param string $credentialId The broker credential.
	 * @param string|null $uid The acting user.
	 * @param string $repo The tool repository.
	 * @param string $ref The ref.
	 *
	 * @return string|null Always null — this test is about the id, not the bytes.
	 */
	protected function fetchToolArchive(string $credentialId, ?string $uid, string $repo, string $ref): ?string {
		$this->brokerCalls['fetch'] = $credentialId;

		return null;
	}//end fetchToolArchive()

	/**
	 * Record which credential was asked to INJECT.
	 *
	 * @param string $credentialId The broker credential.
	 * @param string|null $uid The acting user.
	 *
	 * @return string|null A recognisable stand-in for a resolved token.
	 */
	protected function resolveForgeToken(string $credentialId, ?string $uid): ?string {
		$this->brokerCalls['inject'] = $credentialId;

		return 'resolved-token';
	}//end resolveForgeToken()
}//end class

/**
 * The dispatcher is given a REAL RunTokenService over a stub cache rather than a
 * mock, so it must be declared below or `beStrictAboutCoverageMetadata="true"`
 * fails every test in this file for executing an undeclared class. That
 * declaration is bookkeeping, not a loosening.
 *
 * The real service is deliberate: a mock returning a fixed string asserts that
 * the call happens and nothing about what is made, and what is made is exactly
 * where the TTL bug lived — the turn's 150 seconds against a 30-minute stage.
 *
 * ⚠️ Do not write the declaration's tag name inside prose, backticks or not.
 * PHPUnit parses it as a real annotation wherever it appears in the block, and a
 * trailing backtick makes it "invalid" — which fails all twelve tests here with
 * a message that names none of them.
 *
 * @covers \OCA\Hermiq\Service\StageDispatchService
 *
 * @uses \OCA\Hermiq\Service\Llm\RunTokenService
 */
class StageDispatchServiceTest extends TestCase {

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
	protected function setUp(): void {
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
	public function testAWellFormedResultIsMapped(): void {
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
	public function testAZeroExitCodeIsAResultInItsOwnRight(): void {
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
	public function testAMalformedBodyIsNeverAPass(string $body): void {
		$this->expectException(RuntimeException::class);
		$this->service->mapResultPublic($body);

	}//end testAMalformedBodyIsNeverAPass()

	/**
	 * Bodies that are not stage results.
	 *
	 * @return array The cases.
	 */
	public static function malformedBodies(): array {
		return [
			'empty' => [''],
			'not json' => ['<html>502 Bad Gateway</html>'],
			'json but a list' => ['[1,2,3]'],
			'json but no code' => ['{"output":"something happened"}'],
			'an error object' => ['{"error":"command not allowed: sh"}'],
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
	public function testTheRunnersReasonIsSurfaced(): void {
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
	public function testAReasonlessBodyFallsBack(): void {
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
	public function testEveryStageCarriesARunToken(): void {
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
	public function testAStageWithoutAPushDeclarationSendsNone(): void {
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
	public function testADeclaredPushIsForwardedVerbatim(): void {
		$push = [
			'branch' => 'feature/493/x',
			'issue' => 493,
			'scope' => ['lib'],
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

	/**
	 * The two credentials go to the two different broker calls.
	 *
	 * THE ASSERTION THIS FILE EXISTS FOR, now that a stage can write. The tool
	 * tarball is fetched by the broker SERVER-SIDE, which only a host-locked
	 * proxy credential can do — and `resolveInjectable()` refuses that exact
	 * shape by design. A push needs the opposite: the token inside the
	 * container, i.e. `inject_only`. So one id cannot serve both calls, and code
	 * that passes one to both is not a smaller version of this feature, it is a
	 * stage that either cannot fetch its tools or cannot push.
	 *
	 * Both ids reach the broker either way, so nothing about the payload
	 * distinguishes the fixed code from the collapsed code. Only the id each
	 * CALL was made with does.
	 *
	 * @return void
	 */
	public function testTheBrokerCredentialAndThePushCredentialAreNotTheSameCall(): void {
		$this->service->buildParamsPublic(
			'https://github.com/ConductionNL/openregister',
			'development',
			['scripts/run-hydra-gates.sh'],
			60000,
			['branch' => 'feature/493/x', 'issue' => 493],
			'35327e7a-cafe-4a21-8ffe-6195d52f9579',
			'https://github.com/ConductionNL/hydra',
			'55003b23-6262-495e-b0ab-2e221ba5e17c'
		);

		$this->assertSame('35327e7a-cafe-4a21-8ffe-6195d52f9579', $this->service->brokerCalls['fetch']);
		$this->assertSame('55003b23-6262-495e-b0ab-2e221ba5e17c', $this->service->brokerCalls['inject']);

	}//end testTheBrokerCredentialAndThePushCredentialAreNotTheSameCall()

	/**
	 * With no push credential the broker credential still does the injecting.
	 *
	 * The fallback is what keeps every read-only stage that shipped before this
	 * parameter on the path it was already on. A new parameter that changes
	 * behaviour when it is absent is a breaking change wearing an optional
	 * argument's clothes.
	 *
	 * @return void
	 */
	public function testWithoutAPushCredentialTheBrokerCredentialStillInjects(): void {
		$this->service->buildParamsPublic(
			'https://github.com/ConductionNL/openregister',
			'development',
			['scripts/run-hydra-gates.sh'],
			60000,
			[],
			'35327e7a-cafe-4a21-8ffe-6195d52f9579'
		);

		$this->assertSame('35327e7a-cafe-4a21-8ffe-6195d52f9579', $this->service->brokerCalls['inject']);

	}//end testWithoutAPushCredentialTheBrokerCredentialStillInjects()

	/**
	 * The push OUTCOME survives the response boundary.
	 *
	 * `mapResult()` is an allowlist, so a key the runner returns and it does not
	 * name is a key no flow can ever see. Without this the runner could push and
	 * the run would record only `exitCode: 0` — leaving "it pushed" and "it
	 * found nothing to push" indistinguishable, which is the conflation the rest
	 * of this class is written to prevent.
	 *
	 * @return void
	 */
	public function testThePushOutcomeIsCarriedBackToTheFlow(): void {
		$result = $this->service->mapResultPublic(
			'{"exitCode":0,"output":"ok","ref":"r",'
			. '"push":{"pushed":true,"branch":"feature/493/x","commit":"deadbeef","files":["lib/A.php"]}}'
		);

		$this->assertTrue($result['push']['pushed']);
		$this->assertSame('feature/493/x', $result['push']['branch']);
		$this->assertSame('deadbeef', $result['push']['commit']);
		$this->assertSame(['lib/A.php'], $result['push']['files']);

	}//end testThePushOutcomeIsCarriedBackToTheFlow()

	/**
	 * A read-only stage's result carries NO push key at all.
	 *
	 * Absent rather than `false`, so a consumer can tell "this stage does not
	 * write" from "this stage wrote nothing".
	 *
	 * @return void
	 */
	public function testAReadOnlyStageResultHasNoPushKey(): void {
		$this->assertArrayNotHasKey(
			'push',
			$this->service->mapResultPublic('{"exitCode":0,"output":"ok","ref":"r"}')
		);

	}//end testAReadOnlyStageResultHasNoPushKey()

	// ── The model credential (exapp-stage-workload) ──────────────────────

	/**
	 * A stage that names no model credential gets none.
	 *
	 * This is every stage that existed before the parameter, so it is the arm
	 * that proves the addition is opt-in rather than ambient. Without it, a test
	 * asserting the token IS forwarded would pass just as happily against an
	 * implementation that forwarded it unconditionally.
	 *
	 * @return void
	 */
	public function testAStageThatNamesNoModelCredentialCarriesNone(): void {
		$params = $this->service->buildParamsPublic(
			repo: 'https://example.invalid/target',
			ref: 'development',
			command: ['scripts/run-hydra-gates.sh'],
			ceiling: 1000
		);

		$this->assertArrayNotHasKey('credentialEnv', $params);

	}//end testAStageThatNamesNoModelCredentialCarriesNone()

	/**
	 * A named model credential reaches the runner under the key the CLI reads.
	 *
	 * The key matters as much as the value: the runner drops anything the
	 * command's own allowlist does not name, so a token forwarded under the
	 * wrong key is silently discarded and the CLI reports having no credential.
	 *
	 * @return void
	 */
	public function testANamedModelCredentialIsForwardedAsCredentialEnv(): void {
		$params = $this->service->buildParamsPublic(
			repo: 'https://example.invalid/target',
			ref: 'development',
			command: ['claude', '-p', 'do the thing'],
			ceiling: 1000,
			llmCredentialId: 'anthropic-cli-uuid'
		);

		$this->assertSame(
			['CLAUDE_CODE_OAUTH_TOKEN' => 'resolved-token'],
			($params['credentialEnv'] ?? null)
		);
		$this->assertSame('anthropic-cli-uuid', ($this->service->brokerCalls['inject'] ?? null));

	}//end testANamedModelCredentialIsForwardedAsCredentialEnv()


	/**
	 * An async dispatch is acknowledged with a HANDLE, never a result.
	 *
	 * The shapes are deliberately disjoint. A 202 says the stage was accepted
	 * and has produced no verdict at all; if it shared a field with a stage
	 * result, a gate reading `exitCode` would treat "accepted" as "exited 0"
	 * — the single most dangerous confusion this transport can make.
	 *
	 * @return void
	 */
	public function testAnAcceptedAsyncDispatchMapsToAHandleAndNotAResult(): void {
		$mapped = $this->service->mapAcceptedPublic(
			body: json_encode(['jobId' => '11111111-2222-4333-8444-555555555555', 'status' => 'running'])
		);

		$this->assertSame(expected: '11111111-2222-4333-8444-555555555555', actual: $mapped['job']['id']);
		$this->assertSame(expected: 'running', actual: $mapped['job']['status']);
		$this->assertArrayNotHasKey(
			key: 'exitCode',
			array: $mapped,
			message: 'an acknowledgement must not carry the field a verdict is read from'
		);
	}//end testAnAcceptedAsyncDispatchMapsToAHandleAndNotAResult()

	/**
	 * A 202 with no job id is a stage running with nothing able to collect it.
	 *
	 * Worse than a stage that never started: it holds a slot and spends a model
	 * budget while being invisible. So it throws rather than returning an empty
	 * handle that a later collect would report as `unknown`.
	 *
	 * @return void
	 */
	public function testAnAcknowledgementWithoutAJobIdIsRefused(): void {
		$this->expectException(exception: RuntimeException::class);
		$this->expectExceptionMessageMatches(regularExpression: '/no job id/');

		$this->service->mapAcceptedPublic(body: json_encode(['status' => 'running']));
	}//end testAnAcknowledgementWithoutAJobIdIsRefused()

	/**
	 * The async flag reaches the payload, and only when asked for.
	 *
	 * `buildParams()` is an allowlist, and this codebase has already paid for a
	 * field that existed on both sides of that boundary and not IN it
	 * (`toolRepo`, one whole release). The absent-by-default half matters just
	 * as much: every existing caller must keep sending exactly the payload it
	 * always did.
	 *
	 * @return void
	 */
	public function testTheAsyncFlagCrossesTheParameterBoundaryAndOnlyWhenSet(): void {
		// The ABSENT-by-default half, at the payload builder.
		$without = $this->service->buildParamsPublic(
			repo: 'https://example.test/t',
			ref: 'development',
			command: ['scripts/run-hydra-gates.sh'],
			ceiling: 1000
		);
		$this->assertArrayNotHasKey(
			key: 'async',
			array: $without,
			message: 'a synchronous dispatch must send the payload it always sent'
		);

		$with = $this->service->buildParamsPublic(
			repo: 'https://example.test/t',
			ref: 'development',
			command: ['scripts/run-hydra-gates.sh'],
			ceiling: 1000,
			async: true
		);
		$this->assertTrue(
			condition: ($with['async'] ?? false),
			message: 'the async flag must reach the runner, or the stage runs synchronously and blocks the worker'
		);

		// ⚠️ AND THE BOUNDARY THE ABOVE CANNOT SEE.
		//
		// `buildParamsPublic()` calls `buildParams()` DIRECTLY, so it proves
		// the builder honours the flag and says nothing about whether
		// `dispatch()` ever passes it. Measured: deleting `async: $async` from
		// that call site left this test green — the flag would have been
		// dropped between the two, which is the exact shape `toolRepo` failed
		// in for a whole release, and the reason that comment exists.
		//
		// Reaching `dispatch()` itself means standing up AppAPI, so the seam is
		// asserted on the SOURCE instead — the same technique the runner's
		// route test uses for its own allowlist.
		$source = file_get_contents(__DIR__ . '/../../../lib/Service/StageDispatchService.php');
		$call = preg_match('/\$params\s*=\s*\$this->buildParams\((.*?)\);/s', (string)$source, $m) === 1 ? $m[1] : '';

		$this->assertNotSame(expected: '', actual: $call, message: 'could not find the buildParams call in dispatch()');
		$this->assertStringContainsString(
			needle: 'async:',
			haystack: $call,
			message: 'dispatch() does not pass async to buildParams — the flag exists on both sides of the boundary and not IN it'
		);
	}//end testTheAsyncFlagCrossesTheParameterBoundaryAndOnlyWhenSet()

}//end class
