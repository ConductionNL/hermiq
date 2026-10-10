<?php

/**
 * Graph extraction: runs as the user who enqueued it, reads only what that user can
 * read, writes only through GraphService with provenance, redacts labels, and is
 * idempotent (knowledge-graph).
 *
 * Real GraphService (over the in-memory graph), real GraphSourceReader, real
 * RedactionService and real ActingUserScope; the LLM layer is the double.
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Service\Graph
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/knowledge-graph/spec.md#requirement-extraction-runs-as-the-acting-user-in-audited-background-jobs
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service\Graph;

use OCA\Hermiq\Service\Engine\ConversationManagementHandler;
use OCA\Hermiq\Service\Graph\ActingUserScope;
use OCA\Hermiq\Service\Graph\GraphExtractionService;
use OCA\Hermiq\Service\Graph\GraphService;
use OCA\Hermiq\Service\Graph\GraphSourceReader;
use OCA\Hermiq\Service\NcNative\MailReadService;
use OCA\Hermiq\Service\RedactionService;
use OCP\Files\IRootFolder;
use OCP\IConfig;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for GraphExtractionService and GraphSourceReader.
 *
 * @spec openspec/specs/knowledge-graph/spec.md#requirement-extraction-runs-as-the-acting-user-in-audited-background-jobs
 */
class GraphExtractionServiceTest extends TestCase {

	use InMemoryGraph;

	/**
	 * The session user.
	 *
	 * @var IUser|null
	 */
	private ?IUser $sessionUser = null;

	/**
	 * The session uid during each LLM call.
	 *
	 * @var array<int, string|null>
	 */
	private array $llmCallsAs = [];

	/**
	 * A user double.
	 *
	 * @param string $uid The uid.
	 *
	 * @return IUser
	 */
	private function user(string $uid): IUser {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);

		return $user;
	}//end user()

	/**
	 * The service over the in-memory graph; the object "rec-jan" is readable by alice
	 * only (the store double answers find() with RBAC by the session user).
	 *
	 * @param string $llmAnswer What the model answers.
	 *
	 * @return array{0: GraphExtractionService, 1: GraphService}
	 */
	private function service(string $llmAnswer): array {
		$graph = $this->graph();
		$this->store['contact']['rec-jan'] = ['name' => 'Jan Jansen', 'employer' => 'Acme'];
		$this->store['agentsession']['conv-1'] = ['userId' => 'carol', 'participants' => ['dave']];
		$this->store['agentsessionturn']['t-1'] = ['sessionId' => 'conv-1', 'role' => 'user', 'content' => 'Budget for Acme'];
		$this->ownerOf['rec-jan'] = 'alice';
		$this->sessionUid = fn (): ?string => $this->sessionUser?->getUID();

		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturnCallback(fn (): ?IUser => $this->sessionUser);
		$session->method('setUser')->willReturnCallback(
			function (?IUser $user): void {
				$this->sessionUser = $user;
			}
		);
		$users = $this->createMock(IUserManager::class);
		$users->method('get')->willReturnCallback(fn (string $uid): IUser => $this->user($uid));

		$llm = $this->createMock(ConversationManagementHandler::class);
		$llm->method('generateText')->willReturnCallback(
			function () use ($llmAnswer): string {
				$this->llmCallsAs[] = $this->sessionUser?->getUID();

				return $llmAnswer;
			}
		);

		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')->willReturn('yes');

		$reader = new GraphSourceReader(
			$this->objects,
			$this->createMock(IRootFolder::class),
			$this->createMock(MailReadService::class),
			$this->createMock(LoggerInterface::class)
		);

		return [
			new GraphExtractionService(
				$graph,
				$reader,
				$llm,
				new RedactionService($config),
				new ActingUserScope($session, $users),
				$this->createMock(LoggerInterface::class)
			),
			$graph,
		];

	}//end service()

	/**
	 * The model's answer for the Jan record: two entities and a relation, one label
	 * carrying a secret.
	 *
	 * @return string
	 */
	private function answer(): string {
		return 'Here you go: ' . json_encode(
			[
				'entities' => [
					['label' => 'Jan Jansen', 'type' => 'person', 'aliases' => ['J. Jansen'], 'confidence' => 0.9],
					['label' => 'Acme ghp_abcdefghijklmnopqrstuvwxyz0123456789', 'type' => 'organisation', 'confidence' => 0.8],
				],
				'relations' => [
					['from' => 'Jan Jansen', 'predicate' => 'worksFor', 'to' => 'Acme ghp_abcdefghijklmnopqrstuvwxyz0123456789', 'confidence' => 0.7],
				],
			]
		);
	}//end answer()

	/**
	 * Extraction reads the object as alice, writes both nodes and the edge through
	 * GraphService with the object as sourceRef, extractedBy and confidence, redacts the
	 * secret out of a label, and restores the prior session user.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/knowledge-graph/spec.md#scenario-a-graph-write-is-audit-trailed
	 */
	public function testAnExtractionWritesReferencesWithProvenance(): void {
		[$service] = $this->service(llmAnswer: $this->answer());
		$this->sessionUser = $this->user('admin');
		$ref = ['register' => 'crm', 'schema' => 'contact', 'uuid' => 'rec-jan'];

		$counts = $service->extract(uid: 'alice', sources: [['sourceType' => 'object', 'sourceRef' => $ref]]);

		$this->assertSame(['entities' => 2, 'relations' => 1, 'skipped' => 0], $counts);
		$this->assertSame(['alice'], $this->llmCallsAs);
		$this->assertSame('admin', $this->sessionUser?->getUID());
		$this->assertCount(3, $this->writes);
		foreach ($this->writes as $write) {
			$this->assertSame(GraphExtractionService::EXTRACTOR, $write['object']['extractedBy']);
			$this->assertIsFloat($write['object']['confidence']);
			$this->assertSame($ref, $write['object']['sourceRef']);
			$this->assertStringNotContainsString('abcdefghijklmnopqrstuvwxyz0123456789', (string)json_encode($write['object']));
		}

		$this->assertSame(['J. Jansen'], $this->writes[0]['object']['aliases']);
		$this->assertSame(GraphService::RELATION_SCHEMA, $this->writes[2]['schema']);

	}//end testAnExtractionWritesReferencesWithProvenance()

	/**
	 * A record the user cannot read yields nothing: no model call, no write.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/knowledge-graph/spec.md#scenario-extraction-cannot-read-beyond-its-user
	 */
	public function testExtractionCannotReadBeyondItsUser(): void {
		[$service] = $this->service(llmAnswer: $this->answer());
		$ref = ['register' => 'crm', 'schema' => 'contact', 'uuid' => 'rec-jan'];

		$counts = $service->extract(uid: 'bob', sources: [['sourceType' => 'object', 'sourceRef' => $ref]]);

		$this->assertSame(['entities' => 0, 'relations' => 0, 'skipped' => 1], $counts);
		$this->assertSame([], $this->llmCallsAs);
		$this->assertSame([], $this->writes);

	}//end testExtractionCannotReadBeyondItsUser()

	/**
	 * A conversation whose owner and roster exclude the user is refused; the owner and a
	 * participant are not.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/knowledge-graph/spec.md#scenario-conversation-extraction-respects-the-session-roster
	 */
	public function testConversationExtractionRespectsTheRoster(): void {
		[$service] = $this->service(llmAnswer: '{"entities": [{"label": "Budget", "type": "topic"}], "relations": []}');
		$source = [['sourceType' => 'conversation', 'sourceRef' => ['conversationUuid' => 'conv-1']]];

		$this->assertSame(['entities' => 0, 'relations' => 0, 'skipped' => 1], $service->extract(uid: 'bob', sources: $source));
		$this->assertSame([], $this->llmCallsAs);

		$this->assertSame(['entities' => 1, 'relations' => 0, 'skipped' => 0], $service->extract(uid: 'dave', sources: $source));
		$this->assertSame(['conversationUuid' => 'conv-1'], $this->writes[0]['object']['sourceRef']);

	}//end testConversationExtractionRespectsTheRoster()

	/**
	 * Running the same batch again adds nothing, and a model answer that is not JSON
	 * writes nothing and throws nothing.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/knowledge-graph/spec.md#requirement-extraction-runs-as-the-acting-user-in-audited-background-jobs
	 */
	public function testReRunsAreIdempotentAndGarbageIsHarmless(): void {
		[$service] = $this->service(llmAnswer: $this->answer());
		$source = [['sourceType' => 'object', 'sourceRef' => ['register' => 'crm', 'schema' => 'contact', 'uuid' => 'rec-jan']]];
		$service->extract(uid: 'alice', sources: $source);
		$written = count($this->writes);

		$service->extract(uid: 'alice', sources: $source);
		$this->assertCount($written, $this->writes);
		$this->assertCount(2, $this->store[GraphService::ENTITY_SCHEMA]);
		$this->assertCount(1, $this->store[GraphService::RELATION_SCHEMA]);

		[$garbage] = $this->service(llmAnswer: 'I cannot help with that.');
		$this->writes = [];
		$this->assertSame(['entities' => 0, 'relations' => 0, 'skipped' => 0], $garbage->extract(uid: 'alice', sources: $source));
		$this->assertSame([], $this->writes);

	}//end testReRunsAreIdempotentAndGarbageIsHarmless()
}//end class
