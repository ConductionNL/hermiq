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
use OCP\ICache;
use OCP\ICacheFactory;
use OCP\Security\ISecureRandom;
use PHPUnit\Framework\TestCase;

/**
 * Mint / verify / consume behaviour of the per-run token.
 *
 * @spec openspec/changes/cli-runner-governed-mcp-and-egress/specs/governed-cli-mcp-transport/spec.md#requirement-the-runner-to-hermiq-call-is-authenticated-by-a-short-lived-run-scoped-token
 */
final class RunTokenServiceTest extends TestCase
{

    /**
     * An in-memory `ICache` matching the OCP interface.
     *
     * @return ICache
     */
    private function memoryCache(): ICache
    {
        return new class implements ICache {
            /** @var array<string, mixed> */
            private array $data = [];
            public function get($key) { return ($this->data[$key] ?? null); }
            public function set($key, $value, $ttl=0) { $this->data[$key] = $value; return true; }
            public function hasKey($key) { return isset($this->data[$key]); }
            public function remove($key) { unset($this->data[$key]); return true; }
            public function clear($prefix='') { $this->data = []; return true; }
            public static function isAvailable(): bool { return true; }
        };

    }//end memoryCache()

    /**
     * Build a service over the given cache, with a deterministic-per-call CSPRNG.
     *
     * @param ICache $cache The backing store.
     *
     * @return RunTokenService
     */
    private function service(ICache $cache): RunTokenService
    {
        $factory = $this->createMock(ICacheFactory::class);
        $factory->method('createDistributed')->willReturn($cache);

        $counter      = 0;
        $secureRandom = $this->createMock(ISecureRandom::class);
        $secureRandom->method('generate')->willReturnCallback(
            static function () use (&$counter): string {
                $counter++;
                return str_pad('tok'.$counter, 43, 'z');
            }
        );

        return new RunTokenService($factory, $secureRandom);

    }//end service()

    /**
     * A minted token verifies back to its (runId, agentId, userId, conversationId) binding.
     *
     * @return void
     *
     * @spec openspec/changes/cli-runner-governed-mcp-and-egress/specs/governed-cli-mcp-transport/spec.md#scenario-a-token-cannot-reach-another-runs-tools
     */
    public function testMintThenVerifyReturnsBinding(): void
    {
        $service = $this->service($this->memoryCache());

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
    public function testUnknownAndEmptyTokensAreRejected(): void
    {
        $service = $this->service($this->memoryCache());

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
    public function testConsumedTokenIsRejected(): void
    {
        $service = $this->service($this->memoryCache());

        $token = $service->mint(runId: 'run-1', agentId: 'agent-1', userId: 'alice');
        $this->assertIsArray($service->verify(token: $token));

        $service->consume(token: $token);
        $this->assertNull($service->verify(token: $token));

    }//end testConsumedTokenIsRejected()

    /**
     * The raw token never appears as a cache key or inside a stored value — the store is
     * keyed by (and stores) an irreversible digest only, so token values never touch the
     * cache in plaintext.
     *
     * @return void
     *
     * @spec openspec/changes/cli-runner-governed-mcp-and-egress/specs/governed-cli-mcp-transport/spec.md#requirement-the-runner-to-hermiq-call-is-authenticated-by-a-short-lived-run-scoped-token
     */
    public function testTokenValueNeverStoredInPlaintext(): void
    {
        // A store that captures everything written to it.
        $store = new class implements ICache {
            /** @var array<string, mixed> */
            public array $data = [];
            public function get($key) { return ($this->data[$key] ?? null); }
            public function set($key, $value, $ttl=0) { $this->data[$key] = $value; return true; }
            public function hasKey($key) { return isset($this->data[$key]); }
            public function remove($key) { unset($this->data[$key]); return true; }
            public function clear($prefix='') { $this->data = []; return true; }
            public static function isAvailable(): bool { return true; }
        };

        $service = $this->service($store);
        $token   = $service->mint(runId: 'run-1', agentId: 'agent-1', userId: 'alice');

        foreach ($store->data as $key => $value) {
            $this->assertStringNotContainsString($token, (string) $key);
            $this->assertStringNotContainsString($token, (string) $value);
        }

    }//end testTokenValueNeverStoredInPlaintext()

    /**
     * A token minted for run A does not resolve run B's binding — each mint is independent.
     *
     * @return void
     *
     * @spec openspec/changes/cli-runner-governed-mcp-and-egress/specs/governed-cli-mcp-transport/spec.md#scenario-a-token-cannot-reach-another-runs-tools
     */
    public function testTokensAreRunIsolated(): void
    {
        $service = $this->service($this->memoryCache());

        $tokenA = $service->mint(runId: 'run-A', agentId: 'agent-A', userId: 'alice');
        $tokenB = $service->mint(runId: 'run-B', agentId: 'agent-B', userId: 'bob');

        $this->assertNotSame($tokenA, $tokenB);
        $this->assertSame('agent-A', $service->verify(token: $tokenA)['agentId']);
        $this->assertSame('agent-B', $service->verify(token: $tokenB)['agentId']);

    }//end testTokensAreRunIsolated()
}//end class
