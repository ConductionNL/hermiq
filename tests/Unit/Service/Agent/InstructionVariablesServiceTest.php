<?php

/**
 * The owner's preview of the filled-in instructions and the answers a person
 * gives before a conversation starts (agents-instruction-variables).
 *
 * The preview is the owner's alone: a person who cannot see the agent, and one
 * who can use it but does not own it, both get a 404. The answers are set once
 * per session by the person who owns it, checked against the agent's fields,
 * and passed through the input filter, because they reach the model inside
 * the instructions.
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Service\Agent
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/agents-instruction-variables/specs/agent-management-ui/spec.md#requirement-placeholders-in-an-agents-instructions-are-filled-in-per-turn-req-agvar-001
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service\Agent;

use DateTime;
use DateTimeZone;
use OCA\Hermiq\Service\Agent\InstructionVariablesService;
use OCA\Hermiq\Service\Agent\StartValuesRejectedException;
use OCA\Hermiq\Service\AgentAccessService;
use OCA\Hermiq\Service\Engine\PromptVariableResolver;
use OCA\Hermiq\Service\GuardrailPolicyService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IConfig;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Tests for InstructionVariablesService.
 *
 * @spec openspec/changes/agents-instruction-variables/specs/agent-management-ui/spec.md#requirement-placeholders-in-an-agents-instructions-are-filled-in-per-turn-req-agvar-001
 */
class InstructionVariablesServiceTest extends TestCase {

	/**
	 * Sessions saved through the object service.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $saved = [];

	/**
	 * The agent the tests use: owned by anne, used by bram.
	 *
	 * @return ObjectEntity
	 */
	private function agent(): ObjectEntity {
		$agent = new ObjectEntity();
		$agent->setUuid('agent-1');
		$agent->setOwner('anne');
		$agent->setObject(
			[
				'name' => 'Vergunningen helper',
				'prompt' => 'Address {{user.displayName}}. Today is {{today}}. Department: {{field.department}}. {{user.email}}',
				'startFields' => [
					['key' => 'department', 'label' => 'Department', 'type' => 'select', 'options' => ['Permits', 'Taxes'], 'required' => true],
				],
			]
		);
		return $agent;
	}//end agent()

	/**
	 * The service, with bram able to see the agent and nobody else but anne.
	 *
	 * @param array<string, mixed> $session  The stored session's data.
	 * @param bool                 $blockAll Whether the input filter blocks every answer.
	 *
	 * @return InstructionVariablesService
	 */
	private function service(array $session = [], bool $blockAll = false): InstructionVariablesService {
		$agent = $this->agent();

		$access = $this->createMock(AgentAccessService::class);
		$access->method('loadAccessibleAgent')->willReturnCallback(
			static fn (string $id, string $uid): ?ObjectEntity => (in_array($uid, ['anne', 'bram'], true) === true) ? $agent : null
		);
		$access->method('canUserModifyAgent')->willReturnCallback(
			static fn (ObjectEntity $a, string $uid): bool => ($uid === 'anne')
		);

		$objects = $this->createMock(ObjectService::class);
		$objects->method('find')->willReturnCallback(
			static function (int|string $id) use ($agent, $session): ?ObjectEntity {
				if ($id === 'agent-1') {
					return $agent;
				}

				if ($id !== 'sess-1') {
					return null;
				}

				$entity = new ObjectEntity();
				$entity->setUuid('sess-1');
				$entity->setObject(array_merge(['userId' => 'bram', 'agentId' => 'agent-1', 'title' => 'Dakkapel'], $session));
				return $entity;
			}
		);
		$objects->method('saveObject')->willReturnCallback(
			function (array $object): ObjectEntity {
				$this->saved[] = $object;
				$entity = new ObjectEntity();
				$entity->setUuid('sess-1');
				$entity->setObject($object);
				return $entity;
			}
		);

		$guardrails = $this->createMock(GuardrailPolicyService::class);
		$guardrails->method('effectivePolicyFor')->willReturn([]);
		$guardrails->method('filterInput')->willReturnCallback(
			static fn (array $policy, string $text): array => $blockAll === true
				? ['text' => $text, 'blocked' => true, 'reason' => 'Looks like an instruction to the model.']
				: ['text' => str_replace('0612345678', '[phone]', $text), 'blocked' => false, 'reason' => null]
		);

		return new InstructionVariablesService(
			objectService: $objects,
			agentAccess: $access,
			resolver: $this->resolver(),
			guardrails: $guardrails
		);
	}//end service()

	/**
	 * A resolver for anne de Vries on 27 September 2026.
	 *
	 * @return PromptVariableResolver
	 */
	private function resolver(): PromptVariableResolver {
		$users = $this->createMock(IUserManager::class);
		$users->method('get')->willReturnCallback(
			function (string $uid): IUser {
				$user = $this->createMock(IUser::class);
				$user->method('getDisplayName')->willReturn(['anne' => 'Anne de Vries'][$uid] ?? $uid);
				return $user;
			}
		);
		$config = $this->createMock(IConfig::class);
		$config->method('getUserValue')->willReturn('');
		$config->method('getSystemValueString')->willReturnArgument(1);
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturn(new DateTime('2026-09-27 09:00:00', new DateTimeZone('UTC')));

		return new PromptVariableResolver(userManager: $users, config: $config, time: $time);
	}//end resolver()

	/**
	 * The owner sees the instructions filled in with her own details and the
	 * sample answers, and the placeholder nobody fills is named.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/agents-instruction-variables/specs/agent-management-ui/spec.md#requirement-placeholders-in-an-agents-instructions-are-filled-in-per-turn-req-agvar-001
	 */
	public function testTheOwnerPreviewsTheFilledInInstructions(): void {
		$preview = $this->service()->preview(agentId: 'agent-1', uid: 'anne', prompt: null, startFields: null, sampleValues: ['department' => 'Taxes']);

		$this->assertSame('Address Anne de Vries. Today is 2026-09-27. Department: Taxes. {{user.email}}', $preview['text']);
		$this->assertSame(['{{user.email}}'], $preview['unknown']);

	}//end testTheOwnerPreviewsTheFilledInInstructions()

	/**
	 * The preview can show the form's unsaved text and fields.
	 *
	 * @return void
	 */
	public function testThePreviewUsesTheUnsavedFormText(): void {
		$preview = $this->service()->preview(
			agentId: 'agent-1',
			uid: 'anne',
			prompt: 'Case {{field.case_no}} for {{agent.name}}',
			startFields: [['key' => 'case_no', 'label' => 'Case', 'type' => 'number']],
			sampleValues: ['case_no' => '42']
		);

		$this->assertSame('Case 42 for Vergunningen helper', $preview['text']);
		$this->assertSame([], $preview['unknown']);

	}//end testThePreviewUsesTheUnsavedFormText()

	/**
	 * A user of the agent who does not own it, and a stranger, both get a 404.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/agents-instruction-variables/specs/agent-management-ui/spec.md#requirement-placeholders-in-an-agents-instructions-are-filled-in-per-turn-req-agvar-001
	 */
	public function testANonOwnerGetsNotFound(): void {
		foreach (['bram', 'carla'] as $uid) {
			try {
				$this->service()->preview(agentId: 'agent-1', uid: $uid, prompt: null, startFields: null, sampleValues: []);
				$this->fail('A preview for ' . $uid . ' must be refused.');
			} catch (RuntimeException $e) {
				$this->assertSame(404, $e->getCode(), $uid);
			}
		}

	}//end testANonOwnerGetsNotFound()

	/**
	 * The person picks a department; it is stored on the session, filtered.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/agents-instruction-variables/specs/agent-management-ui/spec.md#requirement-an-agent-can-ask-for-fields-before-a-conversation-starts-req-agvar-002
	 */
	public function testThePersonsAnswersAreStoredOnTheSession(): void {
		$session = $this->service()->answer(sessionId: 'sess-1', uid: 'bram', values: ['department' => 'Permits', 'stray' => 'x']);

		$this->assertSame(['department' => 'Permits'], $session->getObject()['startValues']);
		$this->assertCount(1, $this->saved);
		$this->assertSame('Dakkapel', $this->saved[0]['title'], 'The rest of the session is kept.');

	}//end testThePersonsAnswersAreStoredOnTheSession()

	/**
	 * A required field left empty is refused with the field named, and nothing is saved.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/agents-instruction-variables/specs/agent-management-ui/spec.md#requirement-an-agent-can-ask-for-fields-before-a-conversation-starts-req-agvar-002
	 */
	public function testAMissingRequiredAnswerIsRefused(): void {
		try {
			$this->service()->answer(sessionId: 'sess-1', uid: 'bram', values: []);
			$this->fail('A missing required answer must be refused.');
		} catch (StartValuesRejectedException $e) {
			$this->assertSame(422, $e->getCode());
			$this->assertSame(['department' => 'required'], $e->getProblems());
		}

		$this->assertSame([], $this->saved);

	}//end testAMissingRequiredAnswerIsRefused()

	/**
	 * Answers are fixed once given; someone else's session is not found.
	 *
	 * @return void
	 */
	public function testAnswersAreSetOnceByTheSessionsOwner(): void {
		try {
			$this->service(session: ['startValues' => ['department' => 'Taxes']])->answer(sessionId: 'sess-1', uid: 'bram', values: ['department' => 'Permits']);
			$this->fail('A second answer must be refused.');
		} catch (RuntimeException $e) {
			$this->assertSame(409, $e->getCode());
		}

		try {
			$this->service()->answer(sessionId: 'sess-1', uid: 'carla', values: ['department' => 'Permits']);
			$this->fail('Another person\'s session must not be found.');
		} catch (RuntimeException $e) {
			$this->assertSame(404, $e->getCode());
		}

		$this->assertSame([], $this->saved);

	}//end testAnswersAreSetOnceByTheSessionsOwner()

	/**
	 * The input filter runs on the answers: a blocked one is refused with its reason.
	 *
	 * @return void
	 */
	public function testTheInputFilterRunsOnTheAnswers(): void {
		try {
			$this->service(blockAll: true)->answer(sessionId: 'sess-1', uid: 'bram', values: ['department' => 'Permits']);
			$this->fail('A blocked answer must be refused.');
		} catch (StartValuesRejectedException $e) {
			$this->assertSame(422, $e->getCode());
			$this->assertSame(['department' => 'Looks like an instruction to the model.'], $e->getProblems());
		}

		$this->assertSame([], $this->saved);

	}//end testTheInputFilterRunsOnTheAnswers()
}//end class
