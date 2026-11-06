<?php

/**
 * Unit tests for RunTokenService (cli-runner-governed-mcp-and-egress).
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Service\Llm
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service\Llm;

use OCA\Hermiq\Service\Llm\RunTokenService;
use OCA\Hermiq\Tests\Unit\Support\InMemoryRunTokenStore;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Security\ISecureRandom;
use PHPUnit\Framework\TestCase;

/**
 * Mint / verify / consume behaviour of the per-run token.
 *
 * @spec openspec/changes/cli-runner-governed-mcp-and-egress/specs/governed-cli-mcp-transport/spec.md#requirement-the-runner-to-hermiq-call-is-authenticated-by-a-short-lived-run-scoped-token
 */
final class RunTokenServiceTest extends TestCase {

	/**
	 * The clock every service in a test reads.
	 *
	 * @var int
	 */
	private int $now = 1_000_000;

	/**
	 * Build a service over the given store, with a deterministic-per-call CSPRNG
	 * and the test's clock. Two services over ONE store model two PHP process
	 * pools sharing the instance database.
	 *
	 * @param InMemoryRunTokenStore $store The backing store.
	 *
	 * @return RunTokenService
	 */
	private function service(InMemoryRunTokenStore $store): RunTokenService {
		static $counter = 0;
		$secureRandom = $this->createMock(ISecureRandom::class);
		$secureRandom->method('generate')->willReturnCallback(
			static function () use (&$counter): string {
				$counter++;
				return str_pad('tok' . $counter, 43, 'z');
			}
		);

		$clock = $this->createMock(ITimeFactory::class);
		$clock->method('getTime')->willReturnCallback(fn (): int => $this->now);

		return new RunTokenService($store, $secureRandom, $clock);
	}//end service()

	/**
	 * A minted token verifies back to its (runId, agentId, userId, conversationId) binding.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/cli-runner-governed-mcp-and-egress/specs/governed-cli-mcp-transport/spec.md#scenario-a-token-cannot-reach-another-runs-tools
	 */
	public function testMintThenVerifyReturnsBinding(): void {
		$service = $this->service(new InMemoryRunTokenStore());

		$token = $service->mint(runId: 'run-1', agentId: 'agent-1', userId: 'alice', conversationId: 'conv-1');
		$this->assertNotSame('', $token);

		$binding = $service->verify(token: $token);
		$this->assertSame('run-1', $binding['runId']);
		$this->assertSame('agent-1', $binding['agentId']);
		$this->assertSame('alice', $binding['userId']);
		$this->assertSame('conv-1', $binding['conversationId']);

	}//end testMintThenVerifyReturnsBinding()

	/**
	 * A missing/empty/unknown token is rejected.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/cli-runner-governed-mcp-and-egress/specs/governed-cli-mcp-transport/spec.md#scenario-a-request-without-a-valid-token-is-rejected-before-any-tool-work
	 */
	public function testUnknownAndEmptyTokensAreRejected(): void {
		$service = $this->service(new InMemoryRunTokenStore());

		$this->assertNull($service->verify(token: ''));
		$this->assertNull($service->verify(token: 'not-a-real-token'));

	}//end testUnknownAndEmptyTokensAreRejected()

	/**
	 * A consumed token is rejected on any later use (the run closed).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/cli-runner-governed-mcp-and-egress/specs/governed-cli-mcp-transport/spec.md#scenario-the-token-dies-with-the-run-for-both-endpoints
	 */
	public function testConsumedTokenIsRejected(): void {
		$service = $this->service(new InMemoryRunTokenStore());

		$token = $service->mint(runId: 'run-1', agentId: 'agent-1', userId: 'alice');
		$this->assertIsArray($service->verify(token: $token));

		$service->consume(token: $token);
		$this->assertNull($service->verify(token: $token));

	}//end testConsumedTokenIsRejected()

	/**
	 * The raw token never appears as a cache key or inside a stored value — the store is
	 * keyed by (and stores) an irreversible digest only, so token values never touch the
	 * store in plaintext.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/cli-runner-governed-mcp-and-egress/specs/governed-cli-mcp-transport/spec.md#requirement-the-runner-to-hermiq-call-is-authenticated-by-a-short-lived-run-scoped-token
	 */
	public function testTokenValueNeverStoredInPlaintext(): void {
		$store = new InMemoryRunTokenStore();

		$service = $this->service($store);
		$token = $service->mint(runId: 'run-1', agentId: 'agent-1', userId: 'alice');

		$this->assertNotEmpty($store->rows);
		foreach ($store->rows as $key => $row) {
			$this->assertStringNotContainsString($token, (string)$key);
			$this->assertStringNotContainsString($token, $row['record']);
		}

	}//end testTokenValueNeverStoredInPlaintext()

	/**
	 * A token minted for run A does not resolve run B's binding — each mint is independent.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/cli-runner-governed-mcp-and-egress/specs/governed-cli-mcp-transport/spec.md#scenario-a-token-cannot-reach-another-runs-tools
	 */
	public function testTokensAreRunIsolated(): void {
		$service = $this->service(new InMemoryRunTokenStore());

		$tokenA = $service->mint(runId: 'run-A', agentId: 'agent-A', userId: 'alice');
		$tokenB = $service->mint(runId: 'run-B', agentId: 'agent-B', userId: 'bob');

		$this->assertNotSame($tokenA, $tokenB);
		$this->assertSame('agent-A', $service->verify(token: $tokenA)['agentId']);
		$this->assertSame('agent-B', $service->verify(token: $tokenB)['agentId']);

	}//end testTokensAreRunIsolated()

	/**
	 * A token minted in one process pool verifies in another.
	 *
	 * The live failure (2026-09-29): a conversation title was generated by a
	 * cron-mode background job, so its egress token was minted by the CLI
	 * process, while the egress proxy's PDP call was answered by the web server.
	 * The token lived in the distributed cache, which on an instance without
	 * `memcache.distributed` is APCu, and APCu is per process pool. The web pool
	 * never saw it: 11 CONNECTs came back 401 and the brute-force throttle then
	 * answered 429 to every run. Two services over one store are two pools over
	 * one database.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/cli-runner-governed-mcp-and-egress/specs/governed-cli-mcp-transport/spec.md#requirement-the-runner-to-hermiq-call-is-authenticated-by-a-short-lived-run-scoped-token
	 */
	public function testTokenMintedInOneProcessPoolVerifiesInAnother(): void {
		$database = new InMemoryRunTokenStore();
		$cronPool = $this->service($database);
		$webPool = $this->service($database);

		$token = $cronPool->mint(runId: 'title-run', agentId: '', userId: 'alice');

		$binding = $webPool->verify(token: $token);
		$this->assertIsArray($binding, 'the web pool must see a token the cron pool minted');
		$this->assertSame('alice', $binding['userId']);

		// And the run closing in one pool kills it in the other.
		$cronPool->consume(token: $token);
		$this->assertNull($webPool->verify(token: $token));

	}//end testTokenMintedInOneProcessPoolVerifiesInAnother()

	/**
	 * A token past its TTL no longer authorises, even though its record is still stored.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/cli-runner-governed-mcp-and-egress/specs/governed-cli-mcp-transport/spec.md#requirement-the-runner-to-hermiq-call-is-authenticated-by-a-short-lived-run-scoped-token
	 */
	public function testExpiredTokenIsRejected(): void {
		$service = $this->service(new InMemoryRunTokenStore());

		$token = $service->mint(runId: 'run-1', agentId: 'agent-1', userId: 'alice', ttlSeconds: 60);
		$this->now += 59;
		$this->assertIsArray($service->verify(token: $token));

		$this->now += 1;
		$this->assertNull($service->verify(token: $token));

	}//end testExpiredTokenIsRejected()

	/**
	 * A spent or expired token is still RECOGNISED as issued, so the endpoints do not count it
	 * as a brute-force guess. An unknown or empty token is not recognised.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/cli-runner-governed-mcp-and-egress/specs/governed-cli-mcp-transport/spec.md#scenario-a-request-without-a-valid-token-is-rejected-before-any-tool-work
	 */
	public function testSpentAndExpiredTokensAreKnownButUnknownTokensAreNot(): void {
		$service = $this->service(new InMemoryRunTokenStore());

		$consumed = $service->mint(runId: 'run-1', agentId: 'agent-1', userId: 'alice');
		$service->consume(token: $consumed);
		$this->assertNull($service->verify(token: $consumed));
		$this->assertTrue($service->isKnown(token: $consumed));

		$expiring = $service->mint(runId: 'run-2', agentId: 'agent-1', userId: 'alice', ttlSeconds: 10);
		$this->now += 11;
		$this->assertNull($service->verify(token: $expiring));
		$this->assertTrue($service->isKnown(token: $expiring));

		$this->assertFalse($service->isKnown(token: 'not-a-real-token'));
		$this->assertFalse($service->isKnown(token: ''));

	}//end testSpentAndExpiredTokensAreKnownButUnknownTokensAreNot()

	/**
	 * Records are swept once their recognition window closes, so the table stays bounded, and
	 * a swept token is neither valid nor known.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/cli-runner-governed-mcp-and-egress/specs/governed-cli-mcp-transport/spec.md#requirement-the-runner-to-hermiq-call-is-authenticated-by-a-short-lived-run-scoped-token
	 */
	public function testRecordsAreSweptAfterTheRecognitionWindow(): void {
		$store = new InMemoryRunTokenStore();
		$service = $this->service($store);

		$old = $service->mint(runId: 'run-old', agentId: 'agent-1', userId: 'alice', ttlSeconds: 10);

		// Within the window the record stays.
		$this->now += 10 + 3600;
		$service->mint(runId: 'run-mid', agentId: 'agent-1', userId: 'alice');
		$this->assertTrue($service->isKnown(token: $old));

		// Past it, the next mint sweeps it.
		$this->now += 1;
		$service->mint(runId: 'run-new', agentId: 'agent-1', userId: 'alice');
		$this->assertFalse($service->isKnown(token: $old));
		$this->assertCount(2, $store->rows);

	}//end testRecordsAreSweptAfterTheRecognitionWindow()
}//end class
