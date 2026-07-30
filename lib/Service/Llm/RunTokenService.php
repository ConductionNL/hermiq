<?php

/**
 * Hermiq RunTokenService.
 *
 * Mints, verifies and consumes the short-lived, run-scoped bearer token that
 * authenticates the runner-to-Hermiq direction of an `executionMode: cli` turn
 * (cli-runner-governed-mcp-and-egress). AppAPI's shared secret authenticates the
 * Hermiq→runner direction; the reverse direction — the CLI's own MCP client and
 * the egress proxy calling back INTO Hermiq — has no NC session and no cookie
 * jar, so it cannot borrow that credential. This service provides the one
 * credential BOTH governed endpoints (the MCP tools endpoint and the egress PDP)
 * accept, so one mint, one expiry and one consumption govern the whole run and
 * closing the run invalidates both capabilities atomically.
 *
 * Decisions (design.md "Authentication — the per-run token"):
 *
 *   - **Entropy**: 256-bit via `ISecureRandom::generate(43, CHAR_ALPHANUMERIC)`
 *     (matches OpenRegister's `McpProtocolService` session precedent).
 *   - **Binding**: `(runId, agentId, userId, conversationId)`. The token re-enters
 *     an already-authorized run; it never authenticates a user or elevates
 *     anything. The acting user and agent are resolved FROM the token, never from
 *     a request body.
 *   - **Lifetime**: TTL = `RUNNER_TIMEOUT_MS` (default 120000ms) + 30s slack
 *     (~150s). The CLI is SIGKILLed at `RUNNER_TIMEOUT_MS`, so a token outliving
 *     the turn has no legitimate caller. `RUNNER_TIMEOUT_MS` is a runner-container
 *     env var Hermiq cannot read, so this tracks the runner's DEFAULT.
 *   - **Storage**: `ICache` (distributed, TTL-native, auto-expiring). A token is a
 *     secret and ephemeral — wrong place for an OpenRegister object.
 *   - **Comparison**: constant-time (`hash_equals`). No timing oracle.
 *   - **Consumption**: `consume()` is called in a `finally` when the run closes
 *     (success, error, timeout), so later use is rejected.
 *   - **Logging**: a token value is NEVER logged and NEVER placed in an error body.
 *
 * The cache is keyed by `sha256(token)` — never the raw token — so the store
 * cannot be enumerated to a live token even with cache-key visibility, and the
 * stored record additionally carries the same `sha256(token)` so `verify()` runs
 * a `hash_equals()` confirmation on every lookup (the code path never
 * short-circuits before that comparison).
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\Hermiq\Service\Llm
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/cli-runner-governed-mcp-and-egress/specs/governed-cli-mcp-transport/spec.md#requirement-the-runner-to-hermiq-call-is-authenticated-by-a-short-lived-run-scoped-token
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service\Llm;

use OCP\ICache;
use OCP\ICacheFactory;
use OCP\Security\ISecureRandom;
use RuntimeException;

/**
 * Mint / verify / consume the per-run bearer token that authenticates both
 * governed CLI endpoints.
 *
 * @spec openspec/changes/cli-runner-governed-mcp-and-egress/specs/governed-cli-mcp-transport/spec.md#requirement-the-runner-to-hermiq-call-is-authenticated-by-a-short-lived-run-scoped-token
 */
class RunTokenService
{

    /**
     * Distributed-cache prefix for run tokens (mirrors OR's session-cache prefix
     * pattern).
     *
     * @var string
     */
    private const CACHE_PREFIX = 'hermiq_run_tokens';

    /**
     * Token length: 43 alphanumeric characters ≈ 256 bits of entropy (matches
     * `McpProtocolService`/`WebhookSecretService` precedent).
     *
     * @var int
     */
    private const TOKEN_LENGTH = 43;

    /**
     * The runner's own CLI timeout in milliseconds — mirrored from `runner.js`'s
     * `RUNNER_TIMEOUT_MS` default (a container env var Hermiq cannot read, so this
     * tracks the DEFAULT rather than the live value).
     *
     * @var int
     */
    private const RUNNER_TIMEOUT_MS = 120000;

    /**
     * Slack (seconds) added to the runner's own timeout to form the token TTL, so
     * a token outlives the turn it belongs to only briefly and never becomes a
     * long-lived credential.
     *
     * @var int
     */
    private const TTL_SLACK_SECONDS = 30;

    /**
     * The distributed token store (TTL-native, auto-expiring).
     *
     * @var ICache
     */
    private ICache $cache;

    /**
     * Constructor.
     *
     * @param ICacheFactory $cacheFactory Builds the distributed token store.
     * @param ISecureRandom $secureRandom CSPRNG for the token entropy.
     */
    public function __construct(
        ICacheFactory $cacheFactory,
        private readonly ISecureRandom $secureRandom
    ) {
        $this->cache = $cacheFactory->createDistributed(prefix: self::CACHE_PREFIX);

    }//end __construct()

    /**
     * Mint a fresh per-run token bound to `(runId, agentId, userId,
     * conversationId)` and store it with the run TTL. The returned string is the
     * ONLY place the plaintext token exists — it is never logged.
     *
     * @param string $runId          The run's unique id (opaque; binds the token to one run).
     * @param string $agentId        The acting agent's UUID (resolves the granted tool set).
     * @param string $userId         The acting user's UID (resolves RBAC on tool dispatch).
     * @param string $conversationId The conversation UUID, when one exists (audit binding only).
     *
     * @return string The plaintext bearer token.
     *
     * @spec openspec/changes/cli-runner-governed-mcp-and-egress/specs/governed-cli-mcp-transport/spec.md#scenario-a-token-cannot-reach-another-runs-tools
     */
    public function mint(string $runId, string $agentId, string $userId, string $conversationId=''): string
    {
        $token = $this->secureRandom->generate(self::TOKEN_LENGTH, ISecureRandom::CHAR_ALPHANUMERIC);

        $record = [
            'runId'          => $runId,
            'agentId'        => $agentId,
            'userId'         => $userId,
            'conversationId' => $conversationId,
            // The token's own digest — re-checked with hash_equals() on verify().
            'hash'           => $this->digest(token: $token),
        ];

        $encoded = json_encode($record, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (is_string($encoded) === false) {
            // Never mint a token whose record could not be stored — a token that
            // cannot be verified later is worse than no token.
            throw new RuntimeException('Could not encode the run-token record.');
        }

        $this->cache->set(key: $this->digest(token: $token), value: $encoded, ttl: $this->ttlSeconds());

        return $token;

    }//end mint()

    /**
     * Verify a presented token and return its binding, or null when it is missing,
     * malformed, unknown, expired or already consumed. The comparison is
     * constant-time; the code path always reaches the `hash_equals()` call rather
     * than short-circuiting on a fast negative.
     *
     * @param string $token The presented bearer token.
     *
     * @return array{runId: string, agentId: string, userId: string, conversationId: string}|null
     *         The binding, or null when the token is not valid.
     *
     * @spec openspec/changes/cli-runner-governed-mcp-and-egress/specs/governed-cli-mcp-transport/spec.md#scenario-a-request-without-a-valid-token-is-rejected-before-any-tool-work
     */
    public function verify(string $token): ?array
    {
        // Always compute the digest (constant work) even for an empty token, so
        // the presence/absence of a token is not itself a timing signal.
        $presentedDigest = $this->digest(token: $token);

        $raw = $this->cache->get(key: $presentedDigest);
        if (is_string($raw) === false) {
            return null;
        }

        $record = json_decode($raw, true);
        if (is_array($record) === false || isset($record['hash']) === false || is_string($record['hash']) === false) {
            return null;
        }

        if (hash_equals($record['hash'], $presentedDigest) === false) {
            return null;
        }

        if ($token === '') {
            // A digest collision on the empty string cannot authorise a run.
            return null;
        }

        return [
            'runId'          => (string) ($record['runId'] ?? ''),
            'agentId'        => (string) ($record['agentId'] ?? ''),
            'userId'         => (string) ($record['userId'] ?? ''),
            'conversationId' => (string) ($record['conversationId'] ?? ''),
        ];

    }//end verify()

    /**
     * Consume a token so any later use is rejected. Called in a `finally` when the
     * run closes (success, error, or timeout). Idempotent and safe on an unknown
     * token.
     *
     * @param string $token The token to invalidate.
     *
     * @return void
     *
     * @spec openspec/changes/cli-runner-governed-mcp-and-egress/specs/governed-cli-mcp-transport/spec.md#scenario-the-token-dies-with-the-run-for-both-endpoints
     */
    public function consume(string $token): void
    {
        if ($token === '') {
            return;
        }

        $this->cache->remove(key: $this->digest(token: $token));

    }//end consume()

    /**
     * The run-token TTL in seconds: the runner's own CLI timeout plus a small
     * slack, so the token cannot legitimately outlive the turn.
     *
     * @return int The TTL in seconds.
     */
    private function ttlSeconds(): int
    {
        return ((int) ceil(self::RUNNER_TIMEOUT_MS / 1000) + self::TTL_SLACK_SECONDS);

    }//end ttlSeconds()

    /**
     * The cache key / stored digest for a token: a SHA-256 hex digest, so the
     * store is keyed by an irreversible value and never by the raw token.
     *
     * @param string $token The token to digest.
     *
     * @return string The hex digest.
     */
    private function digest(string $token): string
    {
        return hash('sha256', $token);

    }//end digest()
}//end class
