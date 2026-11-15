<?php

/**
 * Contract of ProviderFactory
 *
 * The public surface of ProviderFactory that other components call, so they depend
 * on this contract instead of the implementation.
 *
 * @category  Kernel
 * @package   OCA\Hermiq\Kernel\Contracts
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/hermiq
 *
 * @spec exclude Structural contract, no functional requirement.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Hermiq\Kernel\Contracts;

use OCA\Hermiq\Service\Llm\ChatDriver;
use OCA\Hermiq\Service\Llm\ModelPolicyViolationException;
use OCA\Hermiq\Service\Llm\ProviderUnavailableException;

/**
 * Contract of ProviderFactory.
 *
 * @category Kernel
 * @package  OCA\Hermiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/hermiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface ProviderFactoryInterface {

	/**
	 * Resolve the configured `chatProvider` into a ready-to-use ChatDriver.
	 *
	 * @param array $llmConfig The `hermiq.llm` configuration
	 *                         (LlmSettingsHandler::getLLMSettingsOnly()).
	 * @param string|null $agentModel Agent-level model override, when set and non-empty.
	 * @param float|null $agentTemperature Agent-level temperature override.
	 * @param string|null $organisation The calling agent's organisation
	 *                                  (tenant-model-policy). When non-null (including
	 *                                  `''` for an organisation-less agent/instance
	 *                                  scope), the resolved (provider, model) pair is
	 *                                  checked against the effective ModelPolicy for
	 *                                  that organisation BEFORE the driver is returned
	 *                                  — this is the single enforcement chokepoint every
	 *                                  trigger path (schedule, Run now, conversation,
	 *                                  flow listener) shares. When null, no check is made
	 *                                  (opt-in; existing callers that do not pass an
	 *                                  organisation see zero behavior change).
	 * @param int|null $agentMaxTokens Agent-level max-tokens override, applied to the
	 *                                 resolved driver when set and non-null.
	 * @param string|null $aiFeature The slug of the registered AI feature this run belongs
	 *                               to, when the run names one. It selects the feature's
	 *                               own provider binding and the residency that feature
	 *                               requires (a-provider-and-a-place-per-ai-feature). null
	 *                               leaves every pre-existing call site unchanged.
	 * @param string|null $documentReference The document this run was handed, when it was
	 *                                       handed one. A feature declaring `requiresRedaction`
	 *                                       is refused unless filinq has redacted it; a run with
	 *                                       no document reference passes that gate untouched.
	 * @param array<string, string>|null $agentCredentialIds The agent's own credential per
	 *                                                       provider (`credentialIds`). A pin for the resolved
	 *                                                       provider is used first and never bypassed.
	 * @param array<int, string> $attachmentReferences The file ids attached to this turn,
	 *                                                 each checked by the feature's gates on its own.
	 *
	 * @return ChatDriver The resolved driver.
	 *
	 * @throws ProviderUnavailableException When no provider is configured, the selected
	 *                                      provider is missing required credentials, or
	 *                                      the provider identifier is not recognised.
	 * @throws ModelPolicyViolationException When `$organisation` is given and the resolved
	 *                                       (provider, model) pair falls outside its
	 *                                       effective ModelPolicy.
	 * @throws \OCA\Hermiq\Service\AiFeature\ResidencyViolationException When `$aiFeature`
	 *                                                                   names a feature that requires a residency the
	 *                                                                   resolved provider does not carry.
	 * @throws \OCA\Hermiq\Service\AiFeature\RedactionRequiredException When the feature
	 *                                                                  reads no unredacted document and the one it was
	 *                                                                  handed has no recorded redaction.
	 *
	 * @spec openspec/changes/archive/2026-09-06-agent-engine-port/tasks.md#task-2-1
	 * @spec openspec/changes/archive/2026-09-06-agent-engine-port/tasks.md#task-2-2
	 * @spec openspec/specs/tenant-model-policy/spec.md#requirement-run-time-enforcement-of-the-effective-model-policy
	 * @spec openspec/specs/agent-credentials/spec.md#requirement-a-pinned-credential-goes-first-and-is-never-bypassed-req-agcred-002
	 * @spec openspec/changes/chat-attachments-and-images/specs/chat-attachments/spec.md#requirement-an-attachment-is-read-as-the-person-who-sent-it-req-catt-003
	 */
	public function createChatDriver(
		array $llmConfig,
		?string $agentModel = null,
		?float $agentTemperature = null,
		?string $organisation = null,
		?int $agentMaxTokens = null,
		?string $aiFeature = null,
		?string $documentReference = null,
		?array $agentCredentialIds = null,
		array $attachmentReferences = [],
	): ChatDriver;

	/**
	 * Generate free text against whichever chat provider `hermiq.llm` currently
	 * selects — one blocking (non-streaming) call, resolving the driver and
	 * dispatching per provider (openai/ollama via LLPhant's `generateText()`,
	 * fireworks via direct HTTP, nextcloud via TaskProcessing).
	 *
	 * This is the shared entry point for background/non-interactive generation
	 * that is NOT part of the ported ChatService orchestration — used by Hermiq's
	 * TaskProcessing PROVIDER implementations (plan §8 move 2), which run the
	 * whole instance's text2text work through Hermiq's configured LLM.
	 *
	 * @param string $prompt The prompt text.
	 * @param string|null $userId The user id (forwarded to the nextcloud driver).
	 * @param bool $allowNextcloud When false, selecting the `nextcloud` driver is
	 *                             rejected. TaskProcessing providers pass false: a
	 *                             Hermiq TaskProcessing provider backed by the
	 *                             `nextcloud` (TaskProcessing) driver would recurse
	 *                             into TaskProcessing endlessly.
	 * @param string|null $organisation Agent-evals: when non-null (including `''` for an
	 *                                  organisation-less scope), the resolved (provider,
	 *                                  model) pair is checked against that organisation's
	 *                                  effective ModelPolicy before generating — the SAME
	 *                                  `createChatDriver()` enforcement chokepoint an
	 *                                  agent-under-test call goes through, so an
	 *                                  LLM-as-judge call is model-policy-governed exactly
	 *                                  like any other Hermiq LLM call. Default `null`
	 *                                  preserves every pre-existing caller's behaviour
	 *                                  unchanged (opt-in, no enforcement).
	 * @param string|null $aiFeature The AI feature this generation belongs to, when it
	 *                               names one. Its binding, residency and redaction
	 *                               gates run before any request (createChatDriver()).
	 * @param string|null $documentReference The document the generation reads, when it
	 *                                       reads one, so the feature's redaction gate is
	 *                                       applied to that document.
	 *
	 * @return string The generated text.
	 *
	 * @throws ProviderUnavailableException When no provider is configured/reachable, or
	 *                                      `nextcloud` is selected while `$allowNextcloud`
	 *                                      is false.
	 * @throws ModelPolicyViolationException When `$organisation` is given and the resolved
	 *                                       (provider, model) pair falls outside its
	 *                                       effective ModelPolicy.
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) `$allowNextcloud` is a genuine
	 * two-mode recursion guard (background-work caller allows the TaskProcessing
	 * driver; a TaskProcessing provider-backed caller forbids it to avoid recursing),
	 * not a responsibility split — both modes share the identical generation path.
	 * Mirrors the accepted `ScheduleService::runNow($bypassApprovalGate)` precedent.
	 *
	 * @spec openspec/changes/taskprocessing-provide-text2text/tasks.md#task-2-1
	 * @spec openspec/changes/archive/2026-07-14-agent-evals/tasks.md#task-4-providerfactorygeneratetext-optional-organisation-param
	 * @spec openspec/changes/ai-feature-run-on-a-document/specs/ai-feature-governance/spec.md#requirement-an-app-can-run-an-ai-feature-on-a-document-it-names-and-the-gates-apply-to-that-document
	 */
	public function generateText(
		string $prompt,
		?string $userId = null,
		bool $allowNextcloud = true,
		?string $organisation = null,
		?string $aiFeature = null,
		?string $documentReference = null,
	): string;

	/**
	 * Read the current `hermiq.llm` configuration.
	 *
	 * @return array LLM configuration (see LlmSettingsHandler::getLLMSettingsOnly()).
	 *
	 * @spec openspec/changes/archive/2026-09-06-agent-engine-port/tasks.md#task-2-1
	 */
	public function getLlmConfig(): array;

	/**
	 * Start the pooled CLI process for a conversation WITHOUT running a turn.
	 *
	 * The first question of a conversation is always the slow one, because
	 * nobody has paid the process start yet — measured on this instance, 11.2s
	 * for a trivial prompt cold against 3.2s warm. Called when the chat opens or
	 * an agent is picked, this moves that cost to while the user is still
	 * typing.
	 *
	 * Costs a process start and NOTHING else: no prompt is sent, so there is no
	 * inference, no tokens and no vendor request. It is not a "hello" turn.
	 *
	 * Best-effort by contract — a warm-up that fails must be invisible, because
	 * the turn that follows works exactly as it does today without it.
	 *
	 * @param string $credentialId The credential to resolve.
	 * @param string $model The model id (part of the pool key).
	 * @param string|null $agentId The acting agent's UUID.
	 * @param string $conversationId The conversation being opened.
	 * @param bool $governed Whether the agent holds tools, so the
	 *                       warmed process carries the same governed argv the real
	 *                       turn will need.
	 *
	 * @return bool True when a warm-up was dispatched.
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) `$governed` is a genuine
	 *   two-mode selector. The warmed process must be built EXACTLY as the first
	 *   turn will build it — same argv, same token, same mcp config — so the
	 *   posture is an input to one procedure rather than two procedures sharing a
	 *   name. Splitting it would be the way to get the two out of step, which is
	 *   the failure the warm-up exists to avoid. Passed by name at the call site.
	 *
	 * @spec openspec/changes/warm-start-and-cli-step-visibility/specs/warm-start-and-cli-step-visibility/spec.md#requirement-the-cli-process-is-warmed-before-the-first-question-not-by-it
	 */
	public function warmAnthropicCli(
		string $credentialId,
		string $model,
		?string $agentId,
		string $conversationId,
		bool $governed = true,
	): bool;
}//end interface
