<?php

/**
 * Hermiq record summary (agents-bound-to-their-app, task 5).
 *
 * Writes a short, AI-written summary of one OpenRegister object for the agent
 * leaf on its page, and keeps it per object version so it shows again without
 * a model call until the record changes. The record is read as the caller, so
 * a record the caller cannot read is a 404 before anything else happens. The
 * summary is written by the app's assistant (AppAssistantResolver) through the
 * same tool-free path as `POST /api/assistant/converse`, with the administered
 * `record-summary` prompt, and only while the organisation keeps the
 * `record-summary` AI feature enabled.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\Hermiq\Service\Assistant
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/agent-object-leaf/spec.md#requirement-a-record-page-can-show-an-ai-written-summary-of-the-record-req-appag-004
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service\Assistant;

use DateTimeImmutable;
use DateTimeZone;
use OCA\Hermiq\Service\Agent\AgentAvailability;
use OCA\Hermiq\Service\AiFeatureService;
use OCA\Hermiq\Service\AppAssistantResolver;
use OCA\Hermiq\Service\Engine\AppRegisterScope;
use OCA\Hermiq\Service\Engine\ResponseGenerationHandler;
use OCA\Hermiq\Service\GuardrailBlockedException;
use OCA\Hermiq\Service\GuardrailPolicyService;
use OCA\Hermiq\Service\Literacy\LiteracyRequirement;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\Capability\ToolGrantResolver;
use OCA\OpenRegister\Service\ObjectService;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * RecordSummaryService writes, keeps and reads the summary of one record.
 *
 * @spec openspec/specs/agent-object-leaf/spec.md#requirement-a-record-page-can-show-an-ai-written-summary-of-the-record-req-appag-004
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) It joins the record read, the
 * feature gate, the agent choice, the prompt library and the model call: each
 * is one collaborator, and splitting them would only move the joins elsewhere.
 * @SuppressWarnings(PHPMD.LongVariable) `$guardrailPolicyService` is named after its class.
 */
class RecordSummaryService {

	/**
	 * The AI feature that switches record summaries on and off.
	 *
	 * @var string
	 */
	public const FEATURE_SLUG = 'record-summary';

	/**
	 * The assistant prompt scope whose prompt writes the summary.
	 *
	 * @var string
	 */
	public const PROMPT_SCOPE = 'record-summary';

	/**
	 * The prompt used when the library holds none for the scope (design.md, seed data).
	 *
	 * @var string
	 */
	public const DEFAULT_PROMPT = 'Summarise this record in at most five sentences for a colleague who has not '
		. 'seen it. Name its status, the last change and anything that needs action.';

	/**
	 * Tool whitelist entry that resolves to no tool: the summary is tool-free by
	 * construction, whatever tools the app's agent holds (ToolLoop intersects).
	 *
	 * @var string
	 */
	public const NO_TOOLS_SENTINEL = ToolGrantResolver::NO_TOOLS_SENTINEL;

	/**
	 * Register and schema of the stored summaries.
	 *
	 * @var string
	 */
	private const REGISTER_SLUG = 'hermiq';

	/**
	 * Schema slug of the stored summaries.
	 *
	 * @var string
	 */
	private const SUMMARY_SCHEMA = 'agentrecordsummary';

	/**
	 * Schema slug of agents.
	 *
	 * @var string
	 */
	private const AGENT_SCHEMA = 'agent';

	/**
	 * The most record text sent to the model (characters).
	 *
	 * @var int
	 */
	private const MAX_RECORD_TEXT = 20000;

	/**
	 * Constructor.
	 *
	 * @param ObjectService $objectService OpenRegister's single read and write path.
	 * @param AppAssistantResolver $resolver Picks the agent that answers in the record's app.
	 * @param AppRegisterScope $registerScope Names the app a register belongs to.
	 * @param AiFeatureService $features The AI feature register (the on/off switch).
	 * @param AssistantPromptLibrary $prompts The administered prompts.
	 * @param ResponseGenerationHandler $responseHandler The model call (shared with converse).
	 * @param LoggerInterface $logger PSR-3 logger.
	 * @param GuardrailPolicyService|null $guardrailPolicyService The organisation's input and output filters.
	 * @param LiteracyRequirement|null $literacy The course requirement (compliance-ai-literacy).
	 *
	 * @spec openspec/specs/agent-object-leaf/spec.md#requirement-a-record-page-can-show-an-ai-written-summary-of-the-record-req-appag-004
	 */
	public function __construct(
		private readonly ObjectService $objectService,
		private readonly AppAssistantResolver $resolver,
		private readonly AppRegisterScope $registerScope,
		private readonly AiFeatureService $features,
		private readonly AssistantPromptLibrary $prompts,
		private readonly ResponseGenerationHandler $responseHandler,
		private readonly LoggerInterface $logger,
		private readonly ?GuardrailPolicyService $guardrailPolicyService = null,
		private readonly ?LiteracyRequirement $literacy = null,
	) {
	}//end __construct()

	/**
	 * What the leaf may show for a record, without calling a model.
	 *
	 * @param string $userId The caller.
	 * @param string $register The record's register (id or slug).
	 * @param string $schema The record's schema (id or slug).
	 * @param string $objectId The record's id.
	 *
	 * @return array{enabled: bool, agent: array{id: string, name: string}|null, summary: array<string, mixed>|null}
	 *
	 * @throws RuntimeException 404 when the caller cannot read the record.
	 *
	 * @spec openspec/specs/agent-object-leaf/spec.md#scenario-the-summary-is-not-rewritten-until-the-record-changes
	 */
	public function status(string $userId, string $register, string $schema, string $objectId): array {
		$record = $this->readRecord(register: $register, schema: $schema, objectId: $objectId);
		$enabled = $this->featureOn();

		$agent = null;
		if ($enabled === true) {
			$found = $this->answeringAgent(userId: $userId, record: $record);
			if ($found !== null) {
				$agent = ['id' => (string)$found->getUuid(), 'name' => (string)($found->getObject()['name'] ?? '')];
			}
		}

		$summary = null;
		$stored = $this->storedFor(record: $record);
		if ($stored !== null && $this->isCurrent(stored: $stored, record: $record) === true) {
			$summary = $this->view(data: $stored->getObject(), cached: true);
		}

		return ['enabled' => $enabled, 'agent' => $agent, 'summary' => $summary];
	}//end status()

	/**
	 * The summary of a record: the stored one while the record is unchanged,
	 * else a new one written by the app's assistant and stored.
	 *
	 * @param string $userId The caller.
	 * @param string $register The record's register (id or slug).
	 * @param string $schema The record's schema (id or slug).
	 * @param string $objectId The record's id.
	 *
	 * @return array{summary: string, generatedAt: string, objectVersion: string, agentId: string, agentName: string, cached: bool}
	 *
	 * @throws RuntimeException 404 unreadable record, 403 feature off, 409 no agent answers.
	 * @throws GuardrailBlockedException When the organisation's guardrail refuses the record text.
	 *
	 * @spec openspec/specs/agent-object-leaf/spec.md#scenario-a-case-handler-reads-a-summary-of-a-long-application
	 * @spec openspec/specs/agent-object-leaf/spec.md#scenario-no-summary-for-a-record-the-user-cannot-read
	 */
	public function summarise(string $userId, string $register, string $schema, string $objectId): array {
		$this->literacy?->assertMayUseAgents(uid: $userId);

		$record = $this->readRecord(register: $register, schema: $schema, objectId: $objectId);
		if ($this->featureOn() === false) {
			throw new RuntimeException('The organisation has switched off AI summaries of records', 403);
		}

		$stored = $this->storedFor(record: $record);
		if ($stored !== null && $this->isCurrent(stored: $stored, record: $record) === true) {
			return $this->view(data: $stored->getObject(), cached: true);
		}

		$agent = $this->answeringAgent(userId: $userId, record: $record);
		if ($agent === null) {
			throw new RuntimeException('No agent answers in this app', 409);
		}

		// Agents-switch-off-and-stop: a switched-off agent writes nothing.
		(new AgentAvailability())->assertRunnable(agent: $agent);

		$prompt = $this->prompt();
		$text = $this->writeSummary(record: $record, agent: $agent, prompt: $prompt['text'], schema: $schema);

		$payload = [
			'objectUuid' => (string)$record->getUuid(),
			'register' => $register,
			'schema' => $schema,
			'objectVersion' => (string)($record->getVersion() ?? ''),
			'contentHash' => self::contentHash(object: $record),
			'summary' => $text,
			'agentId' => (string)$agent->getUuid(),
			'agentName' => (string)($agent->getObject()['name'] ?? ''),
			'promptId' => $prompt['id'],
			'generatedAt' => (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('c'),
		];

		// _rbac false: the schema lets only an admin read summaries through the
		// object API, because a summary repeats its record. This service is the
		// one reader, and it checked the record above as the caller.
		$this->objectService->saveObject(
			object: $payload,
			register: self::REGISTER_SLUG,
			schema: self::SUMMARY_SCHEMA,
			uuid: $stored?->getUuid(),
			_rbac: false
		);

		return $this->view(data: $payload, cached: false);
	}//end summarise()

	/**
	 * A stable hash of a record's data: it changes exactly when the record does.
	 *
	 * @param ObjectEntity $object The record.
	 *
	 * @return string The sha256 of the key-sorted data.
	 *
	 * @spec openspec/specs/agent-object-leaf/spec.md#scenario-the-summary-is-not-rewritten-until-the-record-changes
	 */
	public static function contentHash(ObjectEntity $object): string {
		$data = $object->getObject();
		self::sortKeys(data: $data);
		return hash('sha256', (string)json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
	}//end contentHash()

	/**
	 * Sort the keys of an array and of every array in it, in place.
	 *
	 * @param array<mixed> $data The data.
	 *
	 * @return void
	 *
	 * @spec exclude helper of contentHash(), covered by its test
	 */
	private static function sortKeys(array &$data): void {
		if (array_is_list($data) === false) {
			ksort($data);
		}

		foreach ($data as &$value) {
			if (is_array($value) === true) {
				self::sortKeys(data: $value);
			}
		}
	}//end sortKeys()

	/**
	 * Read the record as the caller; anything but a readable record is a 404.
	 *
	 * @param string $register The register.
	 * @param string $schema The schema.
	 * @param string $objectId The object id.
	 *
	 * @return ObjectEntity The record.
	 *
	 * @throws RuntimeException 400 on a missing reference, 404 when it cannot be read.
	 *
	 * @spec openspec/specs/agent-object-leaf/spec.md#scenario-no-summary-for-a-record-the-user-cannot-read
	 */
	private function readRecord(string $register, string $schema, string $objectId): ObjectEntity {
		if ($register === '' || $schema === '' || $objectId === '') {
			throw new RuntimeException('register, schema and objectId are required', 400);
		}

		try {
			$record = $this->objectService->find(id: $objectId, register: $register, schema: $schema);
		} catch (Throwable $e) {
			$record = null;
		}

		if (($record instanceof ObjectEntity) === false) {
			throw new RuntimeException('Object not found', 404);
		}

		return $record;
	}//end readRecord()

	/**
	 * Whether the organisation keeps the record-summary feature enabled.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/agent-object-leaf/spec.md#requirement-a-record-page-can-show-an-ai-written-summary-of-the-record-req-appag-004
	 */
	private function featureOn(): bool {
		$feature = $this->features->findBySlugForGate(slug: self::FEATURE_SLUG);
		return $feature !== null && (string)($feature->getObject()['lifecycle'] ?? '') === AiFeatureService::RESULT_ENABLED;
	}//end featureOn()

	/**
	 * The agent that answers in the record's app for this user, or null.
	 *
	 * @param string $userId The caller.
	 * @param ObjectEntity $record The record.
	 *
	 * @return ObjectEntity|null
	 *
	 * @spec openspec/specs/agent-object-leaf/spec.md#requirement-a-record-page-can-show-an-ai-written-summary-of-the-record-req-appag-004
	 */
	private function answeringAgent(string $userId, ObjectEntity $record): ?ObjectEntity {
		$app = $this->registerScope->appOf(register: (string)($record->getRegister() ?? ''));
		$agentId = $this->resolver->resolve(userId: $userId, appId: $app);
		if ($agentId === '') {
			return null;
		}

		try {
			$agent = $this->objectService->find(id: $agentId, register: self::REGISTER_SLUG, schema: self::AGENT_SCHEMA);
		} catch (Throwable $e) {
			$this->logger->warning('[RecordSummaryService] Agent ' . $agentId . ' could not be read: ' . $e->getMessage());
			return null;
		}

		if (($agent instanceof ObjectEntity) === false) {
			return null;
		}

		return $agent;
	}//end answeringAgent()

	/**
	 * The administered prompt for the scope, or the design's text.
	 *
	 * Only a prompt scoped to `record-summary` counts: the library also offers
	 * unscoped prompts everywhere, and one of those is not a summary prompt.
	 *
	 * @return array{id: string, text: string}
	 *
	 * @spec openspec/specs/agent-object-leaf/spec.md#requirement-a-record-page-can-show-an-ai-written-summary-of-the-record-req-appag-004
	 */
	private function prompt(): array {
		foreach ($this->prompts->forScope(usageScope: self::PROMPT_SCOPE) as $prompt) {
			$text = trim((string)($prompt['prompt'] ?? ''));
			if (trim((string)($prompt['usageScope'] ?? '')) === self::PROMPT_SCOPE && $text !== '') {
				return ['id' => (string)($prompt['id'] ?? ''), 'text' => $text];
			}
		}

		return ['id' => '', 'text' => self::DEFAULT_PROMPT];
	}//end prompt()

	/**
	 * Ask the agent for the summary, tool-free, through the guardrail filters.
	 *
	 * @param ObjectEntity $record The record.
	 * @param ObjectEntity $agent The answering agent.
	 * @param string $prompt The summary prompt.
	 * @param string $schema The record's schema, named to the model as its type.
	 *
	 * @return string The summary text.
	 *
	 * @throws GuardrailBlockedException When the input filter refuses the record.
	 *
	 * @spec openspec/specs/agent-object-leaf/spec.md#requirement-a-record-page-can-show-an-ai-written-summary-of-the-record-req-appag-004
	 */
	private function writeSummary(ObjectEntity $record, ObjectEntity $agent, string $prompt, string $schema): string {
		$policy = $this->guardrailPolicyService?->effectivePolicyFor(organisation: (string)($agent->getOrganisation() ?? ''));
		$recordText = mb_substr(
			(string)json_encode($record->getObject(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
			0,
			self::MAX_RECORD_TEXT
		);

		if ($policy !== null && $this->guardrailPolicyService !== null) {
			$input = $this->guardrailPolicyService->filterInput(policy: $policy, text: $recordText);
			if ($input['blocked'] === true) {
				throw new GuardrailBlockedException(reason: (string)$input['reason']);
			}

			$recordText = (string)$input['text'];
		}

		$reply = $this->responseHandler->generateResponse(
			userMessage: $prompt,
			context: ['text' => $recordText, 'sources' => []],
			messageHistory: [],
			agent: $agent,
			selectedTools: [self::NO_TOOLS_SENTINEL],
			channel: null,
			cnAiContext: ['objectType' => $schema, 'objectRef' => (string)$record->getUuid()],
			contextPreamble: '',
			trace: null,
			dryRun: false
		);

		if ($policy !== null && $this->guardrailPolicyService !== null) {
			$reply = (string)$this->guardrailPolicyService->filterOutput(policy: $policy, text: $reply)['text'];
		}

		return trim($reply);
	}//end writeSummary()

	/**
	 * The stored summary of a record in the caller's organisation, or null.
	 *
	 * @param ObjectEntity $record The record.
	 *
	 * @return ObjectEntity|null
	 *
	 * @spec openspec/specs/agent-object-leaf/spec.md#scenario-the-summary-is-not-rewritten-until-the-record-changes
	 */
	private function storedFor(ObjectEntity $record): ?ObjectEntity {
		$uuid = (string)$record->getUuid();
		$found = $this->objectService
			->setRegister(self::REGISTER_SLUG)
			->setSchema(self::SUMMARY_SCHEMA)
			->findAll(config: ['filters' => ['objectUuid' => $uuid], 'limit' => 10], _rbac: false);

		foreach ($found as $candidate) {
			if ($candidate instanceof ObjectEntity && (string)($candidate->getObject()['objectUuid'] ?? '') === $uuid) {
				return $candidate;
			}
		}

		return null;
	}//end storedFor()

	/**
	 * Whether a stored summary was written for the record as it is now.
	 *
	 * @param ObjectEntity $stored The stored summary.
	 * @param ObjectEntity $record The record.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/agent-object-leaf/spec.md#scenario-the-summary-is-not-rewritten-until-the-record-changes
	 */
	private function isCurrent(ObjectEntity $stored, ObjectEntity $record): bool {
		return (string)($stored->getObject()['contentHash'] ?? '') === self::contentHash(object: $record);
	}//end isCurrent()

	/**
	 * The fields the leaf shows.
	 *
	 * @param array<string, mixed> $data A stored or new summary.
	 * @param bool $cached Whether it was read back rather than written now.
	 *
	 * @return array{summary: string, generatedAt: string, objectVersion: string, agentId: string, agentName: string, cached: bool}
	 *
	 * @spec openspec/specs/agent-object-leaf/spec.md#requirement-a-record-page-can-show-an-ai-written-summary-of-the-record-req-appag-004
	 */
	private function view(array $data, bool $cached): array {
		return [
			'summary' => (string)($data['summary'] ?? ''),
			'generatedAt' => (string)($data['generatedAt'] ?? ''),
			'objectVersion' => (string)($data['objectVersion'] ?? ''),
			'agentId' => (string)($data['agentId'] ?? ''),
			'agentName' => (string)($data['agentName'] ?? ''),
			'cached' => $cached,
		];
	}//end view()
}//end class
