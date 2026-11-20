<?php

/**
 * Hermiq CliRunTokens.
 *
 * The run tokens of `cli` (hermiq-llm-runner ExApp) turns and warm-ups: which token a
 * turn is given (governed, with the MCP server config the runner hands to the CLI, or
 * egress-only), the refusal when a tool-requiring turn cannot be governed, and when a
 * turn's token is consumed.
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
 * @spec openspec/specs/governed-cli-mcp-transport/spec.md#requirement-a-turn-that-cannot-be-governed-fails-loudly-and-is-never-silently-tool-less
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service\Llm;

use OCP\IAppConfig;
use OCP\IURLGenerator;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Mints, and consumes, the run token of one `cli` turn or warm-up.
 *
 * @spec openspec/specs/governed-cli-mcp-transport/spec.md#requirement-a-turn-that-cannot-be-governed-fails-loudly-and-is-never-silently-tool-less
 */
final class CliRunTokens {

	/**
	 * Why a tool-requiring `cli` turn is refused, by reason: each names what is missing.
	 * All are 503s.
	 *
	 * @var array<string, string>
	 */
	private const REFUSALS = [
		'no_token_service' => 'Anthropic executionMode "cli" cannot serve a tool-requiring turn: the governed run-token '
			. 'service is unavailable, so the turn cannot be governed. It was refused rather than run '
			. 'without its tools.',
		'no_agent' => 'Anthropic executionMode "cli" cannot govern a tool-requiring turn without an agent '
			. 'identity to bind the run to. It was refused rather than run without its tools.',
		'no_user' => 'Anthropic executionMode "cli" cannot govern a tool-requiring turn without a user context '
			. 'to bind the run token to. It was refused rather than run without its tools.',
		'token_not_minted' => 'Anthropic executionMode "cli" cannot serve a tool-requiring turn: the per-run token could '
			. 'not be minted. It was refused rather than run without its tools.',
		'no_url_generator' => 'Anthropic executionMode "cli" cannot serve a tool-requiring turn: the governed MCP '
			. 'endpoint URL could not be resolved, so the MCP config could not be written. It was '
			. 'refused rather than run without its tools.',
		'empty_mcp_url' => 'Anthropic executionMode "cli" cannot serve a tool-requiring turn: the governed MCP '
			. 'endpoint URL resolved empty. It was refused rather than run without its tools.',
	];

	/**
	 * Constructor.
	 *
	 * @param RunTokenService|null $runTokenService Mints and consumes run tokens; null on an
	 *                                              older DI wiring.
	 * @param IURLGenerator|null   $urlGenerator    Resolves the governed MCP endpoint.
	 * @param IAppConfig|null      $appConfig       Reads `mcp_run_base_url`.
	 * @param LoggerInterface      $logger          Logger.
	 * @param int                  $pooledTokenTtl  The lifetime, in seconds, of a pooled turn's
	 *                                              governed token: as long as its process may live.
	 */
	public function __construct(
		private readonly ?RunTokenService $runTokenService,
		private readonly ?IURLGenerator $urlGenerator,
		private readonly ?IAppConfig $appConfig,
		private readonly LoggerInterface $logger,
		private readonly int $pooledTokenTtl,
	) {
	}//end __construct()

	/**
	 * Mint the turn's run token: governed (with its MCP config) for a tool-requiring
	 * turn, egress-only for a text-only one. Exactly one token is minted.
	 *
	 * A tool-requiring turn is GOVERNED, not refused: mint a run token and
	 * assemble the MCP server config the runner hands to the CLI. Done BEFORE the
	 * subscription credential is resolved, so a turn that cannot be governed pulls
	 * no secret from the vault. Fails LOUD when governance is impossible — never a
	 * silent text-only downgrade.
	 *
	 * An EMPTY $functions here means the agent is legitimately tool-less: an agent
	 * whose grants were configured but matched nothing never reaches this point,
	 * because `ToolLoop::listAgentFunctions()` raises ToolGrantResolutionException
	 * at the resolution site. That distinction cannot be recovered here — both
	 * cases arrive as an empty array — which is exactly why it is enforced there.
	 * EVERY cli turn needs a run token, not only a tool-requiring one: it is also
	 * the identity the egress proxy presents to the PDP, and the runner container
	 * has no default route — a turn without one cannot reach api.anthropic.com at
	 * all. The two mints differ in STRICTNESS, deliberately:
	 *
	 * - a GOVERNED turn must have an agent (its grants are what the token
	 * resolves) and fails LOUD without one;
	 * - a text-only turn may legitimately have NO agent — conversation-title
	 * generation calls this path with `agentId: null` — so it gets a tolerant
	 * egress-only identity instead. Minting strictly here would 503 every
	 * title generation the moment `executionMode: cli` is switched on.
	 *
	 * @param CliTurn $turn The turn; its run token and MCP config are set.
	 *
	 * @return void
	 *
	 * @throws ProviderUnavailableException When a tool-requiring turn cannot be governed (503).
	 *
	 * @spec openspec/specs/governed-cli-mcp-transport/spec.md#requirement-a-turn-that-cannot-be-governed-fails-loudly-and-is-never-silently-tool-less
	 */
	public function mintTurnTokens(CliTurn $turn): void {
		if (empty($turn->functions) === false) {
			$turn->runToken = $this->mintGovernedRunToken(turn: $turn);
			$turn->mcpConfig = $this->buildGovernedMcpConfig(runToken: $turn->runToken);
		}

		if (empty($turn->functions) === true) {
			$turn->runToken = $this->mintEgressRunToken(agentId: $turn->agentId, uid: $turn->uid);
		}
	}//end mintTurnTokens()

	/**
	 * Consume the turn's run token once the turn is over, unless the turn is pooled.
	 *
	 * The run token dies with the turn (success, error, or timeout), so a token
	 * outliving its run has no legitimate caller.
	 *
	 * EXCEPT on a poolable turn. The CLI reads `--mcp-config` ONCE at startup
	 * and never re-reads it (measured 2026-08-17, `REREAD=false`), so a pooled
	 * process cannot be handed a fresh token: consuming here would kill the
	 * token its own next turn must present, and the failure is silent — the
	 * model simply reports it has no tools.
	 *
	 * This is design D0.1 option 1, adopted deliberately: the token's lifetime
	 * follows the pooled process instead of the turn. The window is bounded by
	 * the pool's idle/hard cap, which is therefore a SECURITY parameter and not
	 * merely a performance one. The token still expires on its own TTL if the
	 * runner never reaps.
	 *
	 * @param CliTurn $turn The finished turn.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/governed-cli-mcp-transport/spec.md#requirement-a-turn-that-cannot-be-governed-fails-loudly-and-is-never-silently-tool-less
	 */
	public function consumeColdRunToken(CliTurn $turn): void {
		$consume = ($turn->runToken !== null && $this->runTokenService !== null);
		if ($consume === true && $turn->poolKey === '') {
			$this->runTokenService->consume(token: $turn->runToken);
		}
	}//end consumeColdRunToken()

	/**
	 * Mint the per-run token that authenticates the governed MCP + egress endpoints for a
	 * tool-requiring `cli` turn. Fails LOUD (503) when the turn cannot be governed: no agent
	 * identity to bind it to, no user context, or the token service is unavailable.
	 *
	 * @param CliTurn $turn The turn: its agent, user and conversation, and its pool key (a pooled
	 *                      process needs a token that outlives the turn, D0.1 option 1).
	 *
	 * @return string The minted run token (never logged, never on argv).
	 *
	 * @throws ProviderUnavailableException When the turn cannot be governed (503).
	 *
	 * @spec openspec/specs/governed-cli-mcp-transport/spec.md#requirement-a-turn-that-cannot-be-governed-fails-loudly-and-is-never-silently-tool-less
	 *
	 */
	private function mintGovernedRunToken(CliTurn $turn): string {
		$service = $this->governedTokenService(turn: $turn);

		try {
			return $service->mint(
				runId: bin2hex(random_bytes(16)),
				agentId: (string)$turn->agentId,
				userId: (string)$turn->uid,
				conversationId: $turn->conversationId,
				ttlSeconds: $this->governedTokenTtl(turn: $turn),
				projectKey: $turn->projectKey
			);
		} catch (Throwable $e) {
			$this->logger->error(
				'[ProviderFactory] Anthropic cli run token could not be minted',
				['reason' => $e->getMessage()]
			);

			throw new ProviderUnavailableException(self::REFUSALS['token_not_minted'], 503);
		}//end try
	}//end mintGovernedRunToken()

	/**
	 * The run-token service a governed turn mints with, or the refusal naming what
	 * the turn lacks: the service, an agent identity, or a user context.
	 *
	 * @param CliTurn $turn The turn.
	 *
	 * @return RunTokenService The service.
	 *
	 * @throws ProviderUnavailableException When the turn cannot be governed (503).
	 */
	private function governedTokenService(CliTurn $turn): RunTokenService {
		$gap = $this->governanceGap(turn: $turn);
		if ($gap !== null || $this->runTokenService === null) {
			throw new ProviderUnavailableException(self::REFUSALS[($gap ?? 'no_token_service')], 503);
		}

		return $this->runTokenService;
	}//end governedTokenService()

	/**
	 * What a governed turn lacks, first missing first: the token service, the agent, the user.
	 *
	 * @param CliTurn $turn The turn.
	 *
	 * @return string|null The refusal reason, or null when the turn can be governed.
	 */
	private function governanceGap(CliTurn $turn): ?string {
		if ($this->runTokenService === null) {
			return 'no_token_service';
		}

		if ((string)$turn->agentId === '') {
			return 'no_agent';
		}

		if ((string)$turn->uid === '') {
			return 'no_user';
		}

		return null;
	}//end governanceGap()

	/**
	 * The governed token's lifetime override.
	 *
	 * A poolable turn's token must stay valid for as long as the process that
	 * holds it can be reused, because the CLI never re-reads the config it was
	 * given at startup. Otherwise the default per-run TTL applies and the token
	 * still dies with the turn.
	 *
	 * @param CliTurn $turn The turn.
	 *
	 * @return int|null The lifetime in seconds, or null for the default.
	 */
	private function governedTokenTtl(CliTurn $turn): ?int {
		if ($turn->poolKey === '') {
			return null;
		}

		return $this->pooledTokenTtl;
	}//end governedTokenTtl()

	/**
	 * The token and MCP config a warm-up must be built with.
	 *
	 * ⚠️ Exactly ONE token is minted per call, and which one depends on the
	 * posture. An earlier attempt at removing the `else` this replaced hoisted
	 * the egress mint above the branch so both paths could share it — which
	 * quietly minted a second, unused token on every governed warm-up. Minting
	 * is a side effect, not a default value, and it does not belong in the slot
	 * where a default would go.
	 *
	 * @param CliTurn $turn The warm-up (its posture, agent, user, conversation and pool key);
	 *                      its run token and MCP config are set.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/warm-start-and-cli-step-visibility/specs/warm-start-and-cli-step-visibility/spec.md#requirement-the-cli-process-is-warmed-before-the-first-question-not-by-it
	 */
	public function mintWarmupToken(CliTurn $turn): void {
		if ($turn->governed === false) {
			$turn->runToken = $this->mintEgressRunToken(agentId: $turn->agentId, uid: $turn->uid);
			return;
		}

		$turn->runToken = $this->mintGovernedRunToken(turn: $turn);
		$turn->mcpConfig = $this->buildGovernedMcpConfig(runToken: $turn->runToken);
	}//end mintWarmupToken()

	/**
	 * Mint the egress-only run identity for a TEXT-ONLY cli turn.
	 *
	 * Deliberately tolerant where `mintGovernedRunToken()` is strict. A text-only
	 * turn may legitimately have no agent at all — conversation-title generation
	 * reaches this path with `agentId: null` — and it has no tools to lose, so
	 * there is nothing to fail loud about. It still needs an identity to get out
	 * of the container, because the proxy is the only route and it denies an
	 * identity-less connection.
	 *
	 * Returns '' (rather than throwing) whenever a token cannot be minted, so the
	 * turn is never blocked by the absence of a capability it does not need:
	 *   - no `RunTokenService` (an older DI wiring) — nothing to mint with;
	 *   - no acting user — nothing to bind to.
	 * With an empty token the runner injects no proxy env. Under the governed
	 * posture the CLI then has no way out and the turn fails as a provider error
	 * (correct: fail-closed); under the legacy jail posture it is a no-op.
	 *
	 * The token binds `agentId: ''` when there is no agent. That is safe: the MCP
	 * endpoint resolves the granted tool set FROM the bound agent, so a token with
	 * no agent resolves to no tools — it can open connections policy allows, and
	 * nothing else. A text-only turn is never handed the MCP endpoint's address
	 * anyway (it carries no `mcpConfig`).
	 *
	 * @param string|null $agentId The acting agent's UUID, when there is one.
	 * @param string|null $uid The acting user's UID.
	 *
	 * @return string The token, or '' when one could not be minted.
	 *
	 * @spec openspec/specs/governed-cli-mcp-transport/spec.md#requirement-agent-internet-access-is-governed-at-two-layers-by-one-allowed-url-policy
	 */
	private function mintEgressRunToken(?string $agentId, ?string $uid): string {
		if ($this->runTokenService === null || $uid === null || $uid === '') {
			return '';
		}

		try {
			return $this->runTokenService->mint(
				runId: bin2hex(random_bytes(16)),
				agentId: ($agentId ?? ''),
				userId: $uid
			);
		} catch (Throwable $e) {
			// Never fatal: a text-only turn has no tools to protect, and the
			// network layer already denies anything this token would have allowed.
			$this->logger->warning(
				'[ProviderFactory] Anthropic cli egress run token could not be minted',
				['reason' => $e->getMessage()]
			);

			return '';
		}//end try

	}//end mintEgressRunToken()

	/**
	 * Assemble the governed MCP server config the runner writes to a 0600 file and hands to
	 * the CLI. The bearer token rides in the `headers`, never on argv. Fails LOUD (503) when
	 * the endpoint URL cannot be resolved (so the config cannot be written).
	 *
	 * @param string $runToken The minted per-run token.
	 *
	 * @return array<string, mixed> The `{mcpServers: {...}}` config.
	 *
	 * @throws ProviderUnavailableException When the MCP endpoint URL cannot be resolved (503).
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) `GovernedMcpEndpoint::applyContainerOrigin()`
	 *   is a pure function of its two arguments, and it is static precisely so this
	 *   caller can share it. Injecting the service instead would mean a third nullable
	 *   collaborator on a constructor that already carries several for its test call
	 *   sites, to reach a method that reads no state. The alternative to the static call
	 *   is a second copy of the rewrite rule, and a second copy is what puts the egress
	 *   PDP and this config into disagreement about where the endpoint is.
	 *
	 * @spec openspec/specs/governed-cli-mcp-transport/spec.md#requirement-the-cli-is-locked-to-hermiqs-governance-by-its-invocation-flags
	 */
	private function buildGovernedMcpConfig(string $runToken): array {
		return [
			'mcpServers' => [
				'hermiq' => [
					'type' => 'http',
					'url' => $this->governedMcpUrl(),
					'headers' => [
						'Authorization' => 'Bearer ' . $runToken,
						'OCS-APIRequest' => 'true',
					],
				],
			],
		];

	}//end buildGovernedMcpConfig()

	/**
	 * The governed MCP endpoint's URL as the runner container must dial it.
	 *
	 * @return string The URL.
	 *
	 * @throws ProviderUnavailableException When the URL cannot be resolved or resolves empty (503).
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) GovernedMcpEndpoint::applyContainerOrigin() is the one
	 *   shared, pure rewrite rule the egress PDP also applies.
	 */
	private function governedMcpUrl(): string {
		if ($this->urlGenerator === null) {
			throw new ProviderUnavailableException(self::REFUSALS['no_url_generator'], 503);
		}

		$mcpUrl = $this->urlGenerator->linkToRouteAbsolute('hermiq.mcpRun.handle');

		// The linkToRouteAbsolute() call returns the URL Nextcloud publishes to BROWSERS
		// (overwrite.cli.url / the trusted domain). The CLI dials this endpoint from
		// INSIDE the runner container, where that host frequently does not resolve to
		// Nextcloud — a stock dev instance publishes `http://localhost`, which inside
		// the container is the container itself, so every tool call would fail with a
		// connection error that looks like a broken endpoint. AppAPI already records
		// the container-facing origin (its daemon's `nextcloud_url`, e.g.
		// `http://nextcloud`); `mcp_run_base_url` lets the operator pin the same value
		// here. Unset → the published URL is used unchanged (correct whenever
		// Nextcloud's public origin IS reachable from the container).
		// The rewrite rule itself lives in GovernedMcpEndpoint, because the egress
		// PDP has to recognise the very same origin. Two copies would be two
		// policies; the PDP would then deny the endpoint this config points at.
		$mcpUrl = GovernedMcpEndpoint::applyContainerOrigin(
			publishedUrl: $mcpUrl,
			baseOverride: (string)($this->appConfig?->getValueString('hermiq', 'mcp_run_base_url', '') ?? '')
		);

		if ($mcpUrl === '') {
			throw new ProviderUnavailableException(self::REFUSALS['empty_mcp_url'], 503);
		}

		return $mcpUrl;
	}//end governedMcpUrl()
}//end class
