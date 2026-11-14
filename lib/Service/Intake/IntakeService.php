<?php

/**
 * Hermiq IntakeService.
 *
 * A citizen describes a problem in their own words, and this either files it
 * correctly or puts them in front of a person. Those are the only two endings: a
 * citizen who gives up leaves no case, no complaint and no measurement, so an intake
 * that loses people is invisible in exactly the way that matters.
 *
 * Four rules shape it.
 *
 * It proposes and does not create. The classification is drawn from the catalogue the
 * owning app declares, never invented, and the filing goes through that app's own
 * declared intake tool, which validates and creates by its own rules. hermiq holds no
 * second write path, because a second set of rules about what a valid case is is a
 * second one to keep correct.
 *
 * It abstains rather than guesses. Below an administered confidence it hands over,
 * because a wrong confident filing loses more than an abstention: a bezwaar filed as
 * a melding loses a statutory term, and nobody notices until the term has run.
 *
 * It is one conversation, not one per channel. A conversation is keyed by the person
 * and the subject, so somebody who starts by e-mail and continues in the portal is
 * not asked to repeat themselves. The channels themselves belong to the apps that own
 * them: nothing here transports a message.
 *
 * And it prefers a deterministic signal to a model one. Where the owning app already
 * scores escalation by rule, that answer is used and recorded as the rule's. A model
 * sentiment is the fallback, and it is labelled as a model output everywhere it is
 * shown, so a handler can tell the two apart without asking.
 *
 * @category Service
 * @package  OCA\Hermiq\Service\Intake
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/conversational-intake/spec.md
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service\Intake;

use DateTimeImmutable;
use DateTimeZone;
use OCA\Hermiq\Kernel\Contracts\IntakeServiceInterface;
use OCA\OpenRegister\Db\AuditTrail\ObjectAuditEvent;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Holds an intake conversation, classifies it, and files or hands over.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) The intake orchestrates the
 *   conversation store, the declared tool grant, the threshold setting and the audit
 *   trail — each a distinct single-responsibility collaborator.
 *
 * @spec openspec/specs/conversational-intake/spec.md
 */
class IntakeService implements IntakeServiceInterface {

	/**
	 * OpenRegister register slug that holds Hermiq objects.
	 *
	 * @var string
	 */
	private const REGISTER_SLUG = 'hermiq';

	/**
	 * Schema slug for the intake conversations.
	 *
	 * @var string
	 */
	private const SCHEMA_SLUG = 'agentintakeconversation';

	/**
	 * The AI feature this surface runs under, registered separately from the
	 * assistant that helps a handler: an assistant that files on a citizen's
	 * behalf is not a minimal-risk feature.
	 *
	 * @var string
	 */
	public const FEATURE_SLUG = 'conversational-intake';

	/**
	 * The audit action an intake conversation's outcome is recorded under.
	 *
	 * @var string
	 */
	public const AUDIT_ACTION = 'intake';

	/**
	 * The conversation is still going.
	 *
	 * @var string
	 */
	public const STATE_OPEN = 'open';

	/**
	 * Terminal: the owning app was asked to create a record and did.
	 *
	 * @var string
	 */
	public const STATE_FILED = 'filed';

	/**
	 * Terminal: a person has it, with the transcript.
	 *
	 * @var string
	 */
	public const STATE_HANDOVER = 'handover';

	/**
	 * Every state an intake conversation can end in. There are two, and the
	 * enumeration is public so a test can assert there are still two.
	 *
	 * @var array<int, string>
	 */
	public const TERMINAL_STATES = [self::STATE_FILED, self::STATE_HANDOVER];

	/**
	 * An escalation level the owning app decided by rule.
	 *
	 * @var string
	 */
	public const SIGNAL_DETERMINISTIC = 'deterministic';

	/**
	 * An escalation level a model produced, which is labelled as one everywhere it
	 * is shown.
	 *
	 * @var string
	 */
	public const SIGNAL_MODEL = 'model';

	/**
	 * The words shown beside a score, so a reader can tell a rule from a model
	 * without asking.
	 *
	 * @var array<string, string>
	 */
	public const SIGNAL_LABELS = [
		self::SIGNAL_DETERMINISTIC => 'Scored by rule',
		self::SIGNAL_MODEL => 'Scored by a model',
	];

	/**
	 * Constructor.
	 *
	 * @param ObjectService $objectService OpenRegister single read/write path for conversations.
	 * @param IntakeToolGrant $grant The create-only tools and declared reviews.
	 * @param IntakeSettings $settings The abstention threshold.
	 * @param AuditTrailMapper $auditTrailMapper Records the conversation's outcome as a run.
	 * @param LoggerInterface $logger Logger.
	 */
	public function __construct(
		private readonly ObjectService $objectService,
		private readonly IntakeToolGrant $grant,
		private readonly IntakeSettings $settings,
		private readonly AuditTrailMapper $auditTrailMapper,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Add one message to a person's open conversation about a subject, opening one
	 * if there is none.
	 *
	 * The conversation is keyed by the person and the subject, never by the
	 * channel, which is what lets somebody start by e-mail and continue in the
	 * portal without repeating themselves. The channel is recorded on the message
	 * because it is worth knowing; it decides nothing.
	 *
	 * @param string $person The person the conversation belongs to.
	 * @param string $subject What it is about, as the owning app names it.
	 * @param string $channel Which channel this message arrived on.
	 * @param string $text What the person wrote.
	 * @param DateTimeImmutable|null $now The moment, for a deterministic test.
	 *
	 * @return array<string, mixed> The conversation as it now stands.
	 *
	 * @spec openspec/specs/conversational-intake/spec.md#scenario-starting-by-e-mail-and-continuing-in-the-portal
	 */
	public function receive(
		string $person,
		string $subject,
		string $channel,
		string $text,
		?DateTimeImmutable $now = null,
	): array {
		$message = new IntakeMessage(person: $person, subject: $subject);
		$message->channel = $channel;
		$message->text = $text;
		$message->at = ($now ?? new DateTimeImmutable('now', new DateTimeZone('UTC')));

		return $this->receiveMessage(message: $message);
	}//end receive()

	/**
	 * Add the message to the person's open conversation about its subject, opening
	 * one if there is none.
	 *
	 * @param IntakeMessage $message The received message.
	 *
	 * @return array<string, mixed> The conversation as it now stands.
	 *
	 * @spec openspec/changes/a-conversational-intake-that-files-for-the-citizen/specs/conversational-intake/spec.md#scenario-starting-by-e-mail-and-continuing-in-the-portal
	 */
	private function receiveMessage(IntakeMessage $message): array {
		$existing = $this->openConversationFor(person: $message->person, subject: $message->subject);

		$data = $this->newConversation(message: $message);
		$uuid = null;
		if ($existing !== null) {
			$data = $existing->getObject();
			$uuid = (string)$existing->getUuid();
		}

		$data['messages'] = $this->withMessage(messages: ($data['messages'] ?? []), message: $message);

		return $this->shape(conversation: $this->save(data: $data, uuid: $uuid));
	}//end receiveMessage()

	/**
	 * A new, open conversation of the message's person about its subject.
	 *
	 * @param IntakeMessage $message The received message.
	 *
	 * @return array<string, mixed>
	 */
	private function newConversation(IntakeMessage $message): array {
		return [
			'person' => $message->person,
			'subject' => $message->subject,
			'state' => self::STATE_OPEN,
			'messages' => [],
		];
	}//end newConversation()

	/**
	 * The conversation's messages with the received one appended (a non-list starts over).
	 *
	 * @param mixed         $messages The stored messages.
	 * @param IntakeMessage $message  The received message.
	 *
	 * @return array<int, mixed>
	 */
	private function withMessage(mixed $messages, IntakeMessage $message): array {
		if (is_array($messages) === false) {
			$messages = [];
		}

		$messages[] = [
			'channel' => $message->channel,
			'text' => $message->text,
			'at' => $message->at->format('c'),
		];

		return $messages;
	}//end withMessage()

	/**
	 * Score the escalation of a conversation, preferring the owning app's own rule
	 * to a model.
	 *
	 * @param string $conversationId The conversation uuid.
	 * @param array<string, mixed>|null $deterministic The owning app's own level, when it supplies one.
	 * @param array<string, mixed>|null $model A model-produced level, used only in its absence.
	 *
	 * @return array<string, mixed>|null The conversation, or null when there is no such conversation.
	 *
	 * @spec openspec/specs/conversational-intake/spec.md#scenario-the-deterministic-score-wins
	 */
	public function scoreEscalation(string $conversationId, ?array $deterministic, ?array $model = null): ?array {
		$conversation = $this->find(conversationId: $conversationId);
		if ($conversation === null) {
			return null;
		}

		$data = $conversation->getObject();

		$source = self::SIGNAL_MODEL;
		$chosen = $model;
		if ($deterministic !== null) {
			$source = self::SIGNAL_DETERMINISTIC;
			$chosen = $deterministic;
		}

		if ($chosen === null) {
			return $this->shape(conversation: $conversation);
		}

		$data['escalation'] = [
			'level' => (string)($chosen['level'] ?? ''),
			'reason' => (string)($chosen['reason'] ?? ''),
			'decidedBy' => $source,
			'label' => self::SIGNAL_LABELS[$source],
		];

		return $this->shape(conversation: $this->save(data: $data, uuid: $conversationId));
	}//end scoreEscalation()

	/**
	 * Carry an external party's verdict into the conversation, with whose it was
	 * and when.
	 *
	 * Hermiq forms no opinion about what was reviewed. It calls the tool the owning
	 * app declared, records the answer, and that is the whole of its part.
	 *
	 * @param string $conversationId The conversation uuid.
	 * @param string $toolId The declared review tool.
	 * @param array<string, mixed> $arguments The arguments, passed through untouched.
	 * @param DateTimeImmutable|null $now The moment, for a deterministic test.
	 *
	 * @return array<string, mixed>|null The conversation, or null when there is no such conversation.
	 *
	 * @throws IntakeRefusedException When the grant does not cover the tool.
	 *
	 * @spec openspec/specs/conversational-intake/spec.md#scenario-a-verdict-travels-into-the-conversation-with-its-author
	 */
	public function review(
		string $conversationId,
		string $toolId,
		array $arguments,
		?DateTimeImmutable $now = null,
	): ?array {
		$review = new IntakeReview(toolId: $toolId, arguments: $arguments);
		$review->at = ($now ?? new DateTimeImmutable('now', new DateTimeZone('UTC')));

		return $this->reviewConversation(conversationId: $conversationId, review: $review);
	}//end review()

	/**
	 * Call the declared review tool and carry its verdict into the conversation.
	 *
	 * @param string       $conversationId The conversation uuid.
	 * @param IntakeReview $review         The review request.
	 *
	 * @return array<string, mixed>|null The conversation, or null when there is no such conversation.
	 *
	 * @throws IntakeRefusedException When the grant does not cover the tool.
	 *
	 * @spec openspec/changes/a-conversational-intake-that-files-for-the-citizen/specs/conversational-intake/spec.md#scenario-a-verdict-travels-into-the-conversation-with-its-author
	 */
	private function reviewConversation(string $conversationId, IntakeReview $review): ?array {
		$conversation = $this->find(conversationId: $conversationId);
		if ($conversation === null) {
			return null;
		}

		$answer = $this->grant->call(toolId: $review->toolId, arguments: $review->arguments);

		$data = $conversation->getObject();
		$reviews = ($data['reviews'] ?? []);
		if (is_array($reviews) === false) {
			$reviews = [];
		}

		$reviews[] = $this->reviewEntry(review: $review, answer: $answer);
		$data['reviews'] = $reviews;

		return $this->shape(conversation: $this->save(data: $data, uuid: $conversationId));
	}//end reviewConversation()

	/**
	 * The review as the conversation records it: which tool, who stands behind the
	 * verdict, the verdict, whether the call failed, and when.
	 *
	 * @param IntakeReview         $review The review request.
	 * @param array<string, mixed> $answer The tool's answer (`result`, `isError`).
	 *
	 * @return array<string, mixed>
	 */
	private function reviewEntry(IntakeReview $review, array $answer): array {
		return [
			'tool' => $review->toolId,
			'reviewer' => $this->reviewerFrom(result: $answer['result'], toolId: $review->toolId),
			'verdict' => $answer['result'],
			'failed' => $answer['isError'],
			'at' => $review->at->format('c'),
		];
	}//end reviewEntry()

	/**
	 * End the conversation: file it when the classification is confident enough,
	 * and hand it to a person otherwise.
	 *
	 * @param string $conversationId The conversation uuid.
	 * @param array<string, mixed> $classification The proposal: `type`, `confidence` and the catalogue it came from.
	 * @param array<string, mixed> $catalogue The request types the owning app declares.
	 * @param string $intakeTool The owning app's declared create-only intake tool.
	 * @param DateTimeImmutable|null $now The moment, for a deterministic test.
	 *
	 * @return array<string, mixed>|null The conversation in its terminal state, or null when absent.
	 *
	 * @spec openspec/specs/conversational-intake/spec.md#scenario-an-uncertain-intake-does-not-guess
	 */
	public function conclude(
		string $conversationId,
		array $classification,
		array $catalogue,
		string $intakeTool,
		?DateTimeImmutable $now = null,
	): ?array {
		$conclusion = new IntakeConclusion(classification: $classification, catalogue: $catalogue);
		$conclusion->intakeTool = $intakeTool;
		$conclusion->at = ($now ?? new DateTimeImmutable('now', new DateTimeZone('UTC')));

		return $this->concludeConversation(conversationId: $conversationId, conclusion: $conclusion);
	}//end conclude()

	/**
	 * End the conversation, store and record its terminal state.
	 *
	 * @param string           $conversationId The conversation uuid.
	 * @param IntakeConclusion $conclusion     How it is to end.
	 *
	 * @return array<string, mixed>|null The conversation in its terminal state, or null when absent.
	 *
	 * @spec openspec/changes/a-conversational-intake-that-files-for-the-citizen/specs/conversational-intake/spec.md#scenario-an-uncertain-intake-does-not-guess
	 */
	private function concludeConversation(string $conversationId, IntakeConclusion $conclusion): ?array {
		$conversation = $this->find(conversationId: $conversationId);
		if ($conversation === null) {
			return null;
		}

		$data = $this->classified(data: $conversation->getObject(), conclusion: $conclusion);
		$data = $this->concluded(data: $data, conclusion: $conclusion);

		$stored = $this->save(data: $data, uuid: $conversationId);
		$this->record(conversationId: $conversationId, data: $data);

		return $this->shape(conversation: $stored);
	}//end concludeConversation()

	/**
	 * The conversation data with the classification it is concluded on: the
	 * proposed type and confidence, the threshold applied and the catalogue.
	 *
	 * @param array<string, mixed> $data       The conversation data.
	 * @param IntakeConclusion     $conclusion How it is to end.
	 *
	 * @return array<string, mixed>
	 */
	private function classified(array $data, IntakeConclusion $conclusion): array {
		$data['classification'] = [
			'type' => $conclusion->type(),
			'confidence' => $conclusion->confidence(),
			'threshold' => $this->settings->abstentionThreshold(),
			'catalogue' => $conclusion->catalogueTypes(),
		];

		return $data;
	}//end classified()

	/**
	 * File the conversation when the classification is confident enough and the
	 * owning app creates the record; hand it to a person otherwise.
	 *
	 * @param array<string, mixed> $data       The classified conversation data.
	 * @param IntakeConclusion     $conclusion How it is to end.
	 *
	 * @return array<string, mixed> The conversation data in its terminal state.
	 *
	 * @spec openspec/changes/a-conversational-intake-that-files-for-the-citizen/specs/conversational-intake/spec.md#scenario-an-uncertain-intake-does-not-guess
	 */
	private function concluded(array $data, IntakeConclusion $conclusion): array {
		$reason = $this->abstentionReason(data: $data, conclusion: $conclusion);
		if ($reason !== null) {
			return $this->handOver(data: $data, reason: $reason, at: $conclusion->at);
		}

		$filing = $this->file(data: $data, conclusion: $conclusion);
		if ($filing['isError'] === true) {
			// The owning app refused or failed to create. A citizen must not be
			// dropped because a filing failed, so this is a handover like any other
			// ending that is not a filed request.
			return $this->handOver(data: $data, reason: 'the owning app did not create a record for this request', at: $conclusion->at);
		}

		$data['state'] = self::STATE_FILED;
		$data['filed'] = $this->filedEntry(conclusion: $conclusion, record: $filing['result']);

		return $data;
	}//end concluded()

	/**
	 * Why the intake must not file, or null when it may.
	 *
	 * The catalogue is the municipality's, and a type outside it is not a
	 * low-confidence proposal but an invented one, which is worse: it reads as
	 * an answer and belongs to nobody's process.
	 *
	 * @param array<string, mixed> $data       The classified conversation data.
	 * @param IntakeConclusion     $conclusion How it is to end.
	 *
	 * @return string|null
	 *
	 * @spec openspec/changes/a-conversational-intake-that-files-for-the-citizen/specs/conversational-intake/spec.md#scenario-an-uncertain-intake-does-not-guess
	 */
	private function abstentionReason(array $data, IntakeConclusion $conclusion): ?string {
		if ($conclusion->inCatalogue() === false) {
			return 'the proposed request type is not in the catalogue this municipality declares';
		}

		if ($conclusion->confidence() < $data['classification']['threshold']) {
			return 'the classification was less certain than the threshold this instance requires';
		}

		return null;
	}//end abstentionReason()

	/**
	 * Ask the owning app's create-only intake tool to file the request.
	 *
	 * @param array<string, mixed> $data       The classified conversation data.
	 * @param IntakeConclusion     $conclusion How it is to end.
	 *
	 * @return array<string, mixed> The tool's answer (`result`, `isError`).
	 *
	 * @throws IntakeRefusedException When the grant does not cover the tool.
	 */
	private function file(array $data, IntakeConclusion $conclusion): array {
		return $this->grant->call(
			toolId: $conclusion->intakeTool,
			arguments: [
				'type' => $conclusion->type(),
				'subject' => (string)($data['subject'] ?? ''),
				'person' => (string)($data['person'] ?? ''),
				'messages' => ($data['messages'] ?? []),
			]
		);
	}//end file()

	/**
	 * The filing as the conversation records it.
	 *
	 * @param IntakeConclusion $conclusion How it ended.
	 * @param mixed            $record     The record the owning app created.
	 *
	 * @return array<string, mixed>
	 */
	private function filedEntry(IntakeConclusion $conclusion, mixed $record): array {
		return [
			'tool' => $conclusion->intakeTool,
			'type' => $conclusion->type(),
			'record' => $record,
			'at' => $conclusion->at->format('c'),
		];
	}//end filedEntry()

	/**
	 * One conversation, as a reader sees it.
	 *
	 * @param string $conversationId The conversation uuid.
	 *
	 * @return array<string, mixed>|null The conversation, or null.
	 *
	 * @spec openspec/specs/conversational-intake/spec.md#requirement-no-conversation-must-dead-end
	 */
	public function conversation(string $conversationId): ?array {
		$conversation = $this->find(conversationId: $conversationId);
		if ($conversation === null) {
			return null;
		}

		return $this->shape(conversation: $conversation);
	}//end conversation()

	/**
	 * Put the conversation in front of a person, carrying the transcript. A reply
	 * that cannot help is a handover, not an ending.
	 *
	 * @param array<string, mixed> $data The conversation data.
	 * @param string $reason Why it could not be filed.
	 * @param DateTimeImmutable $at The moment.
	 *
	 * @return array<string, mixed> The conversation data, handed over.
	 *
	 * @spec openspec/specs/conversational-intake/spec.md#scenario-an-unhelpable-conversation-reaches-a-person
	 */
	private function handOver(array $data, string $reason, DateTimeImmutable $at): array {
		$data['state'] = self::STATE_HANDOVER;
		$data['handover'] = [
			'reason' => $reason,
			'at' => $at->format('c'),
			// The transcript travels. A person picking this up must not have to ask
			// the citizen to say it all again, which is the moment people give up.
			'transcript' => ($data['messages'] ?? []),
		];

		return $data;
	}//end handOver()

	/**
	 * Who gave the verdict, as the reviewing tool reported it. Never inferred from
	 * the tool id alone when the answer says: an external review's value is that
	 * somebody in particular stands behind it.
	 *
	 * @param mixed $result The tool's answer.
	 * @param string $toolId The tool that was called.
	 *
	 * @return string The reviewing party.
	 */
	private function reviewerFrom(mixed $result, string $toolId): string {
		if (is_array($result) === true) {
			foreach (['reviewer', 'reviewedBy', 'party', 'authority'] as $field) {
				if (is_scalar($result[$field] ?? null) === true && (string)$result[$field] !== '') {
					return (string)$result[$field];
				}
			}
		}

		return $toolId;
	}//end reviewerFrom()

	/**
	 * The open conversation for one person and subject, if there is one. This is
	 * the join that makes the channels one conversation.
	 *
	 * @param string $person The person.
	 * @param string $subject The subject.
	 *
	 * @return ObjectEntity|null The conversation, or null.
	 *
	 * @spec openspec/specs/conversational-intake/spec.md#requirement-one-conversation-must-span-the-channels-a-person-uses
	 */
	private function openConversationFor(string $person, string $subject): ?ObjectEntity {
		try {
			$objects = $this->objectService
				->setRegister(self::REGISTER_SLUG)
				->setSchema(self::SCHEMA_SLUG)
				->findAll(config: ['filters' => ['person' => $person], 'limit' => 100]);
		} catch (Throwable $e) {
			$this->logger->warning(
				'Hermiq could not read the intake conversations: ' . $e->getMessage(),
				['exception' => $e]
			);

			return null;
		}

		foreach ($objects as $object) {
			if (($object instanceof ObjectEntity) === false) {
				continue;
			}

			$data = $object->getObject();
			if ((string)($data['person'] ?? '') !== $person || (string)($data['subject'] ?? '') !== $subject) {
				continue;
			}

			if ((string)($data['state'] ?? self::STATE_OPEN) !== self::STATE_OPEN) {
				continue;
			}

			return $object;
		}//end foreach

		return null;
	}//end openConversationFor()

	/**
	 * Persist one conversation through OpenRegister's single write path.
	 *
	 * @param array<string, mixed> $data The conversation data.
	 * @param string|null $uuid The uuid, or null to create.
	 *
	 * @return ObjectEntity The stored conversation.
	 */
	private function save(array $data, ?string $uuid): ObjectEntity {
		return $this->objectService->saveObject(
			object: $data,
			register: self::REGISTER_SLUG,
			schema: self::SCHEMA_SLUG,
			uuid: $uuid
		);

	}//end save()

	/**
	 * One conversation by uuid.
	 *
	 * @param string $conversationId The uuid.
	 *
	 * @return ObjectEntity|null The conversation, or null.
	 */
	private function find(string $conversationId): ?ObjectEntity {
		if ($conversationId === '') {
			return null;
		}

		try {
			return $this->objectService->find(
				id: $conversationId,
				register: self::REGISTER_SLUG,
				schema: self::SCHEMA_SLUG
			);
		} catch (Throwable $e) {
			return null;
		}

	}//end find()

	/**
	 * Shape a conversation for a reader.
	 *
	 * @param ObjectEntity $conversation The conversation object.
	 *
	 * @return array<string, mixed> The conversation.
	 */
	private function shape(ObjectEntity $conversation): array {
		$data = $conversation->getObject();
		$data['id'] = (string)($conversation->getUuid() ?? '');
		$data['state'] = (string)($data['state'] ?? self::STATE_OPEN);
		$data['terminal'] = in_array($data['state'], self::TERMINAL_STATES, true);

		return $data;
	}//end shape()

	/**
	 * Record the conversation's outcome as a run, like any other model output.
	 *
	 * Non-fatal by contract: a failed audit write never fails the intake, in line
	 * with every other audit write in this app.
	 *
	 * @param string $conversationId The conversation.
	 * @param array<string, mixed> $data The conversation data.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/conversational-intake/spec.md#requirement-intake-must-be-registered-as-an-ai-feature-with-its-own-risk-category
	 */
	private function record(string $conversationId, array $data): void {
		try {
			$marker = new ObjectEntity();
			$marker->setUuid($conversationId);

			$this->auditTrailMapper->recordObjectEvent(
				event: new ObjectAuditEvent(
					object: $marker,
					action: self::AUDIT_ACTION,
					context: [
						'feature' => self::FEATURE_SLUG,
						'conversation' => $conversationId,
						'state' => (string)($data['state'] ?? ''),
						'classification' => ($data['classification'] ?? []),
						'escalation' => ($data['escalation'] ?? []),
						'reviews' => ($data['reviews'] ?? []),
					]
				)
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				'Hermiq could not record an intake outcome: ' . $e->getMessage(),
				['exception' => $e]
			);
		}

	}//end record()
}//end class
