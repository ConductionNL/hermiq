<?php

/**
 * Hermiq IntakeService unit tests.
 *
 * Covers what an intake owes a citizen: it files through the owning app or puts them
 * in front of a person, it never guesses when it is unsure, it never invents a
 * request type the municipality does not have, it does not ask them to start again
 * when they change channel, and it prefers a rule to a model where the owning app
 * already has one.
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Service\Intake
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
 * @spec openspec/changes/a-conversational-intake-that-files-for-the-citizen/specs/conversational-intake/spec.md
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service\Intake;

use DateTimeImmutable;
use OCA\Hermiq\Service\Intake\IntakeRefusedException;
use OCA\Hermiq\Service\Intake\IntakeService;
use OCA\Hermiq\Service\Intake\IntakeSettings;
use OCA\Hermiq\Service\Intake\IntakeToolGrant;
use OCA\OpenRegister\Db\AuditTrail;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\Capability\ToolGrantResolver;
use OCA\OpenRegister\Service\Mcp\ToolRegistryFacade;
use OCA\OpenRegister\Service\ObjectService;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * IntakeService unit tests.
 *
 * @spec openspec/changes/a-conversational-intake-that-files-for-the-citizen/specs/conversational-intake/spec.md
 */
class IntakeServiceTest extends TestCase {

	/**
	 * The conversations the ObjectService double holds, keyed by uuid.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private array $conversations = [];

	/**
	 * The tool calls the facade double received.
	 *
	 * @var array<int, array{tool: string, arguments: array<string, mixed>}>
	 */
	private array $calls = [];

	/**
	 * The audit entries written during a test.
	 *
	 * @var array<int, array{action: string, context: array<string, mixed>}>
	 */
	private array $audits = [];

	/**
	 * The catalogue a municipality declares in these tests.
	 *
	 * @var array<int, string>
	 */
	private const CATALOGUE = ['melding-openbare-ruimte', 'bezwaar', 'vraag'];

	/**
	 * An in-memory ObjectService double.
	 *
	 * @return ObjectService The double.
	 */
	private function objectService(): ObjectService {
		$service = $this->createMock(ObjectService::class);
		$service->method('setRegister')->willReturnSelf();
		$service->method('setSchema')->willReturnSelf();
		$service->method('findAll')->willReturnCallback(
			function (): array {
				$out = [];
				foreach ($this->conversations as $uuid => $data) {
					$entity = new ObjectEntity();
					$entity->setUuid((string)$uuid);
					$entity->setObject($data);
					$out[] = $entity;
				}

				return $out;
			}
		);
		$service->method('find')->willReturnCallback(
			function (mixed $id, mixed ...$rest): ?ObjectEntity {
				if (isset($this->conversations[(string)$id]) === false) {
					return null;
				}

				$entity = new ObjectEntity();
				$entity->setUuid((string)$id);
				$entity->setObject($this->conversations[(string)$id]);
				return $entity;
			}
		);
		$service->method('saveObject')->willReturnCallback(
			function (array|ObjectEntity $object, ?array $extend = [], mixed $register = null, mixed $schema = null, ?string $uuid = null): ObjectEntity {
				$key = ($uuid ?? ('conversation-' . (count($this->conversations) + 1)));
				$this->conversations[$key] = (array)$object;

				$entity = new ObjectEntity();
				$entity->setUuid($key);
				$entity->setObject($this->conversations[$key]);
				return $entity;
			}
		);

		return $service;
	}//end objectService()

	/**
	 * A tool catalogue with one declared intake tool, one declared review, and two
	 * tools that must never be reachable from this surface.
	 *
	 * @return array<int, array<string, mixed>> The catalogue.
	 */
	private function catalog(): array {
		return [
			[
				'name' => 'dossiq.melding.create',
				'annotations' => [IntakeToolGrant::INTAKE_ANNOTATION => true, 'destructiveHint' => true],
			],
			[
				'name' => 'buildiq.planreview.run',
				'annotations' => [IntakeToolGrant::REVIEW_ANNOTATION => true, 'readOnlyHint' => true],
			],
			[
				// Annotated for intake by an owning app that got it wrong. An
				// annotation is a claim; a tool that changes an existing record is
				// not an intake tool whatever it claims.
				'name' => 'dossiq.case.update',
				'annotations' => [IntakeToolGrant::INTAKE_ANNOTATION => true],
			],
			[
				'name' => 'dossiq.case.get',
				'annotations' => ['readOnlyHint' => true],
			],
		];
	}//end catalog()

	/**
	 * A facade double serving the catalogue and recording the calls.
	 *
	 * @param array<string, mixed>|null $invokeResult The envelope invokeTool returns.
	 *
	 * @return ToolRegistryFacade The double.
	 */
	private function facade(?array $invokeResult = null): ToolRegistryFacade {
		$facade = $this->createMock(ToolRegistryFacade::class);
		$facade->method('listTools')->willReturn($this->catalog());
		$facade->method('invokeTool')->willReturnCallback(
			function (string $toolId, array $arguments) use ($invokeResult): array {
				$this->calls[] = ['tool' => $toolId, 'arguments' => $arguments];

				return ($invokeResult ?? ['result' => ['id' => 'ZAAK-1'], 'isError' => false]);
			}
		);

		return $facade;
	}//end facade()

	/**
	 * An audit mapper recording what it was asked to write.
	 *
	 * @return AuditTrailMapper The double.
	 */
	private function auditTrailMapper(): AuditTrailMapper {
		$mapper = $this->createMock(AuditTrailMapper::class);
		$mapper->method('createAuditTrailEntry')->willReturnCallback(
			function (ObjectEntity $object, string $action, array $context = []): AuditTrail {
				$this->audits[] = ['action' => $action, 'context' => $context];

				$entry = new AuditTrail();
				$entry->setAction($action);
				return $entry;
			}
		);

		return $mapper;
	}//end auditTrailMapper()

	/**
	 * Build the service.
	 *
	 * @param float|null $threshold The abstention threshold, or null for the default.
	 * @param array<string, mixed>|null $invokeResult What the owning app answers.
	 *
	 * @return IntakeService The service.
	 */
	private function service(?float $threshold = null, ?array $invokeResult = null): IntakeService {
		$this->conversations = [];
		$this->calls = [];
		$this->audits = [];

		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturn(
			($threshold === null ? '' : (string)json_encode(['abstentionThreshold' => $threshold]))
		);

		$facade = $this->facade($invokeResult);

		return new IntakeService(
			$this->objectService(),
			new IntakeToolGrant($facade, new NullLogger()),
			new IntakeSettings($config),
			$this->auditTrailMapper(),
			new NullLogger()
		);
	}//end service()

	/**
	 * A conversation about a broken streetlight ends with the owning app asked to
	 * create a record of the declared type. hermiq proposes; the owning app
	 * validates and creates.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/a-conversational-intake-that-files-for-the-citizen/specs/conversational-intake/spec.md#scenario-a-conversation-ends-in-a-filed-request
	 */
	public function testAConversationEndsInAFiledRequest(): void {
		$service = $this->service();

		$conversation = $service->receive(
			person: 'burger-1',
			subject: 'lantaarnpaal',
			channel: 'portal',
			text: 'De lantaarnpaal op de hoek van de Molenweg doet het al een week niet',
			now: new DateTimeImmutable('2026-03-01T10:00:00+00:00')
		);

		$concluded = $service->conclude(
			conversationId: $conversation['id'],
			classification: ['type' => 'melding-openbare-ruimte', 'confidence' => 0.93],
			catalogue: self::CATALOGUE,
			intakeTool: 'dossiq.melding.create',
			now: new DateTimeImmutable('2026-03-01T10:01:00+00:00')
		);

		$this->assertSame(IntakeService::STATE_FILED, $concluded['state']);
		$this->assertTrue($concluded['terminal']);
		$this->assertSame('dossiq.melding.create', $this->calls[0]['tool']);
		$this->assertSame('melding-openbare-ruimte', $this->calls[0]['arguments']['type']);
	}//end testAConversationEndsInAFiledRequest()

	/**
	 * An uncertain intake does not guess: below the threshold it hands over, and no
	 * record is created. A bezwaar filed as a melding loses a statutory term, and
	 * nobody notices until the term has run.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/a-conversational-intake-that-files-for-the-citizen/specs/conversational-intake/spec.md#scenario-an-uncertain-intake-does-not-guess
	 */
	public function testAnUncertainIntakeDoesNotGuess(): void {
		$service = $this->service(threshold: 0.8);

		$conversation = $service->receive(
			person: 'burger-1',
			subject: 'iets',
			channel: 'email',
			text: 'Ik ben het er niet mee eens en ik wil dat iemand er naar kijkt'
		);

		$concluded = $service->conclude(
			conversationId: $conversation['id'],
			classification: ['type' => 'bezwaar', 'confidence' => 0.55],
			catalogue: self::CATALOGUE,
			intakeTool: 'dossiq.melding.create'
		);

		$this->assertSame(IntakeService::STATE_HANDOVER, $concluded['state']);
		$this->assertSame([], $this->calls);
		$this->assertNotSame('', $concluded['handover']['reason']);
	}//end testAnUncertainIntakeDoesNotGuess()

	/**
	 * The catalogue is the municipality's: a confident proposal of a type it does
	 * not have is a handover, not a filing. An invented type reads as an answer and
	 * belongs to nobody's process.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/a-conversational-intake-that-files-for-the-citizen/specs/conversational-intake/spec.md#scenario-the-catalogue-is-the-municipalitys
	 */
	public function testAnInventedRequestTypeIsNotFiled(): void {
		$service = $this->service(threshold: 0.5);

		$conversation = $service->receive(person: 'burger-1', subject: 'iets', channel: 'chat', text: 'Hallo');

		$concluded = $service->conclude(
			conversationId: $conversation['id'],
			classification: ['type' => 'klacht-over-de-wethouder', 'confidence' => 0.99],
			catalogue: self::CATALOGUE,
			intakeTool: 'dossiq.melding.create'
		);

		$this->assertSame(IntakeService::STATE_HANDOVER, $concluded['state']);
		$this->assertSame([], $this->calls);
		$this->assertStringContainsString('catalogue', $concluded['handover']['reason']);
	}//end testAnInventedRequestTypeIsNotFiled()

	/**
	 * Every terminal state is a filed request or a handover. Enumerated rather than
	 * inferred, so a third ending cannot be added without this failing.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/a-conversational-intake-that-files-for-the-citizen/specs/conversational-intake/spec.md#scenario-every-terminal-state-is-one-of-two
	 */
	public function testEveryTerminalStateIsOneOfTwo(): void {
		$this->assertSame(
			[IntakeService::STATE_FILED, IntakeService::STATE_HANDOVER],
			IntakeService::TERMINAL_STATES
		);

		$this->assertCount(2, IntakeService::TERMINAL_STATES);
		$this->assertNotContains(IntakeService::STATE_OPEN, IntakeService::TERMINAL_STATES);
	}//end testEveryTerminalStateIsOneOfTwo()

	/**
	 * A handover carries the transcript, so the person picking it up does not have
	 * to ask the citizen to say it all again. That moment is where people give up.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/a-conversational-intake-that-files-for-the-citizen/specs/conversational-intake/spec.md#scenario-an-unhelpable-conversation-reaches-a-person
	 */
	public function testAHandoverCarriesTheTranscript(): void {
		$service = $this->service(threshold: 0.9);

		$service->receive(person: 'burger-1', subject: 'iets', channel: 'email', text: 'Eerste bericht');
		$conversation = $service->receive(person: 'burger-1', subject: 'iets', channel: 'email', text: 'Tweede bericht');

		$concluded = $service->conclude(
			conversationId: $conversation['id'],
			classification: ['type' => 'vraag', 'confidence' => 0.1],
			catalogue: self::CATALOGUE,
			intakeTool: 'dossiq.melding.create'
		);

		$transcript = $concluded['handover']['transcript'];

		$this->assertCount(2, $transcript);
		$this->assertSame('Eerste bericht', $transcript[0]['text']);
		$this->assertSame('Tweede bericht', $transcript[1]['text']);
	}//end testAHandoverCarriesTheTranscript()

	/**
	 * A filing the owning app refuses is a handover rather than a dead end. A
	 * citizen must not be dropped because a create failed.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/a-conversational-intake-that-files-for-the-citizen/specs/conversational-intake/spec.md#requirement-no-conversation-must-dead-end
	 */
	public function testARefusedFilingBecomesAHandover(): void {
		$service = $this->service(
			threshold: 0.5,
			invokeResult: ['result' => ['error' => 'Verplicht veld ontbreekt'], 'isError' => true]
		);

		$conversation = $service->receive(person: 'burger-1', subject: 'lantaarnpaal', channel: 'portal', text: 'Kapot');

		$concluded = $service->conclude(
			conversationId: $conversation['id'],
			classification: ['type' => 'melding-openbare-ruimte', 'confidence' => 0.99],
			catalogue: self::CATALOGUE,
			intakeTool: 'dossiq.melding.create'
		);

		$this->assertSame(IntakeService::STATE_HANDOVER, $concluded['state']);
		$this->assertTrue($concluded['terminal']);
	}//end testARefusedFilingBecomesAHandover()

	/**
	 * Starting by e-mail and continuing in the portal is one conversation. The key
	 * is the person and the subject; the channel is recorded and decides nothing.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/a-conversational-intake-that-files-for-the-citizen/specs/conversational-intake/spec.md#scenario-starting-by-e-mail-and-continuing-in-the-portal
	 */
	public function testStartingByEmailAndContinuingInThePortal(): void {
		$service = $this->service();

		$first = $service->receive(
			person: 'burger-1',
			subject: 'lantaarnpaal',
			channel: 'email',
			text: 'De lantaarnpaal doet het niet'
		);

		$second = $service->receive(
			person: 'burger-1',
			subject: 'lantaarnpaal',
			channel: 'portal',
			text: 'Het is die op de hoek van de Molenweg'
		);

		$this->assertSame($first['id'], $second['id']);
		$this->assertCount(1, $this->conversations);
		$this->assertCount(2, $second['messages']);
		$this->assertSame('email', $second['messages'][0]['channel']);
		$this->assertSame('portal', $second['messages'][1]['channel']);
	}//end testStartingByEmailAndContinuingInThePortal()

	/**
	 * A different subject is a different conversation, so one person's two separate
	 * problems do not merge into one. The key is a pair, not a person.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/a-conversational-intake-that-files-for-the-citizen/specs/conversational-intake/spec.md#requirement-one-conversation-must-span-the-channels-a-person-uses
	 */
	public function testADifferentSubjectIsADifferentConversation(): void {
		$service = $this->service();

		$first = $service->receive(person: 'burger-1', subject: 'lantaarnpaal', channel: 'email', text: 'Kapot');
		$second = $service->receive(person: 'burger-1', subject: 'afvalbak', channel: 'email', text: 'Vol');

		$this->assertNotSame($first['id'], $second['id']);
		$this->assertCount(2, $this->conversations);
	}//end testADifferentSubjectIsADifferentConversation()

	/**
	 * The deterministic score wins where the owning app supplies one, and the
	 * conversation records that a rule decided.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/a-conversational-intake-that-files-for-the-citizen/specs/conversational-intake/spec.md#scenario-the-deterministic-score-wins
	 */
	public function testTheDeterministicScoreWins(): void {
		$service = $this->service();

		$conversation = $service->receive(person: 'burger-1', subject: 'bezwaar', channel: 'email', text: 'Mijn advocaat neemt contact op');

		$scored = $service->scoreEscalation(
			conversationId: $conversation['id'],
			deterministic: ['level' => 'hoog', 'reason' => 'advocaat genoemd'],
			model: ['level' => 'laag', 'reason' => 'klinkt rustig']
		);

		$this->assertSame('hoog', $scored['escalation']['level']);
		$this->assertSame(IntakeService::SIGNAL_DETERMINISTIC, $scored['escalation']['decidedBy']);
		$this->assertSame('Scored by rule', $scored['escalation']['label']);
	}//end testTheDeterministicScoreWins()

	/**
	 * A model score is used only where no rule exists, and is labelled as a model
	 * output so a reader can tell the two apart without asking.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/a-conversational-intake-that-files-for-the-citizen/specs/conversational-intake/spec.md#scenario-a-model-score-is-labelled-as-one
	 */
	public function testAModelScoreIsLabelledAsOne(): void {
		$service = $this->service();

		$conversation = $service->receive(person: 'burger-1', subject: 'iets', channel: 'chat', text: 'Dit duurt nu al maanden');

		$scored = $service->scoreEscalation(
			conversationId: $conversation['id'],
			deterministic: null,
			model: ['level' => 'midden', 'reason' => 'ongeduld']
		);

		$this->assertSame('midden', $scored['escalation']['level']);
		$this->assertSame(IntakeService::SIGNAL_MODEL, $scored['escalation']['decidedBy']);
		$this->assertSame('Scored by a model', $scored['escalation']['label']);
		$this->assertNotSame(
			IntakeService::SIGNAL_LABELS[IntakeService::SIGNAL_DETERMINISTIC],
			$scored['escalation']['label']
		);
	}//end testAModelScoreIsLabelledAsOne()

	/**
	 * The intake surface cannot touch an existing record. The grant covers the
	 * create-only tools an owning app declared and nothing else, and an annotation
	 * on an update tool does not make it one.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/a-conversational-intake-that-files-for-the-citizen/specs/conversational-intake/spec.md#scenario-intake-cannot-touch-an-existing-record
	 */
	public function testIntakeCannotTouchAnExistingRecord(): void {
		$service = $this->service(threshold: 0.5);

		$conversation = $service->receive(person: 'burger-1', subject: 'iets', channel: 'portal', text: 'Hallo');

		foreach (['dossiq.case.get', 'dossiq.case.update'] as $forbidden) {
			try {
				$service->conclude(
					conversationId: $conversation['id'],
					classification: ['type' => 'vraag', 'confidence' => 0.99],
					catalogue: self::CATALOGUE,
					intakeTool: $forbidden
				);
				$this->fail(sprintf("The intake surface reached '%s'.", $forbidden));
			} catch (IntakeRefusedException $refusal) {
				$this->assertSame($forbidden, $refusal->toolId);
			}
		}

		$this->assertSame([], $this->calls);
	}//end testIntakeCannotTouchAnExistingRecord()

	/**
	 * A verdict travels into the conversation with its author and the time. hermiq
	 * carries another party's opinion and records whose it was.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/a-conversational-intake-that-files-for-the-citizen/specs/conversational-intake/spec.md#scenario-a-verdict-travels-into-the-conversation-with-its-author
	 */
	public function testAVerdictTravelsWithItsAuthor(): void {
		$service = $this->service(
			invokeResult: [
				'result' => ['verdict' => 'voldoet niet aan artikel 3.2', 'reviewer' => 'Omgevingsdienst Noord'],
				'isError' => false,
			]
		);

		$conversation = $service->receive(person: 'burger-1', subject: 'bouwplan', channel: 'portal', text: 'Aanbouw achterzijde');

		$reviewed = $service->review(
			conversationId: $conversation['id'],
			toolId: 'buildiq.planreview.run',
			arguments: ['plan' => 'plan-1'],
			now: new DateTimeImmutable('2026-03-01T12:00:00+00:00')
		);

		$review = $reviewed['reviews'][0];

		$this->assertSame('Omgevingsdienst Noord', $review['reviewer']);
		$this->assertSame('2026-03-01T12:00:00+00:00', $review['at']);
		$this->assertSame('voldoet niet aan artikel 3.2', $review['verdict']['verdict']);
	}//end testAVerdictTravelsWithItsAuthor()

	/**
	 * hermiq does not review. Asserted over the source rather than over a
	 * behaviour, because the requirement is that no such logic exists: an app that
	 * grew its own plan review would pass every behavioural test above.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/a-conversational-intake-that-files-for-the-citizen/specs/conversational-intake/spec.md#scenario-hermiq-does-not-review
	 */
	public function testHermiqDoesNotReview(): void {
		$source = (string)file_get_contents(__DIR__ . '/../../../../lib/Service/Intake/IntakeService.php');

		// It carries a verdict and never forms one: no scoring, no rule evaluation,
		// no opinion about what was reviewed.
		$this->assertStringNotContainsString('function evaluatePlan', $source);
		$this->assertStringNotContainsString('function assessCompliance', $source);
		// Case-insensitive on purpose. This asserts a DESIGN CLAIM, and the
		// sentence carrying it is a doc comment, so its first letter belongs to
		// phpcs (Generic.Commenting.DocComment.LongNotCapital), not to this
		// test. Pinning the lowercase spelling made a style rule and a design
		// assertion fight over one character.
		$this->assertMatchesRegularExpression('/hermiq forms no opinion/i', $source);
	}//end testHermiqDoesNotReview()

	/**
	 * hermiq transports nothing. Every channel arrives through the app that owns
	 * it, so there is no mail client, no socket and no webhook sender here.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/a-conversational-intake-that-files-for-the-citizen/specs/conversational-intake/spec.md#scenario-hermiq-transports-nothing
	 */
	public function testHermiqTransportsNothing(): void {
		$source = (string)file_get_contents(__DIR__ . '/../../../../lib/Service/Intake/IntakeService.php');

		foreach (['IMailer', 'curl_exec', 'IClientService', 'fsockopen', 'stream_socket_client'] as $transport) {
			$this->assertStringNotContainsString(
				$transport,
				$source,
				'The channels belong to the apps that own them; hermiq holds the conversation.'
			);
		}
	}//end testHermiqTransportsNothing()

	/**
	 * The outcome is recorded as a run, under the intake's own AI feature rather
	 * than under the assistant that helps a handler.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/a-conversational-intake-that-files-for-the-citizen/specs/conversational-intake/spec.md#scenario-intake-is-classified-separately-from-the-handler-assistant
	 */
	public function testTheOutcomeIsRecordedUnderItsOwnFeature(): void {
		$service = $this->service(threshold: 0.5);

		$conversation = $service->receive(person: 'burger-1', subject: 'lantaarnpaal', channel: 'portal', text: 'Kapot');

		$service->conclude(
			conversationId: $conversation['id'],
			classification: ['type' => 'melding-openbare-ruimte', 'confidence' => 0.99],
			catalogue: self::CATALOGUE,
			intakeTool: 'dossiq.melding.create'
		);

		$this->assertCount(1, $this->audits);
		$this->assertSame(IntakeService::AUDIT_ACTION, $this->audits[0]['action']);
		$this->assertSame(IntakeService::FEATURE_SLUG, $this->audits[0]['context']['feature']);
		$this->assertSame('conversational-intake', IntakeService::FEATURE_SLUG);
	}//end testTheOutcomeIsRecordedUnderItsOwnFeature()

	/**
	 * The case assistant surface stays tool-free. Its `__none__` sentinel is the
	 * guarantee that a chat box on a case cannot act on the case, and this change
	 * must not weaken it, so the assertion is over the assistant's own source.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/a-conversational-intake-that-files-for-the-citizen/specs/conversational-intake/spec.md#scenario-the-case-assistant-stays-tool-free
	 */
	public function testTheCaseAssistantStaysToolFree(): void {
		$source = (string)file_get_contents(__DIR__ . '/../../../../lib/Service/Assistant/AssistantService.php');

		// The sentinel is named rather than spelled out in the assistant, so both
		// halves are asserted: the constant still holds `__none__`, and the case
		// assistant still binds its agent to exactly that.
		$this->assertSame('__none__', ToolGrantResolver::NO_TOOLS_SENTINEL);
		$this->assertStringContainsString("'tools' => [self::NO_TOOLS_SENTINEL]", $source);
		$this->assertStringNotContainsString(IntakeToolGrant::INTAKE_ANNOTATION, $source);
	}//end testTheCaseAssistantStaysToolFree()
}//end class
