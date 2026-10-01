<?php

/**
 * The prompt preview and start-values endpoints map the service's refusals to
 * statuses (agents-instruction-variables): signed out 401, not the owner 404,
 * already answered 409, a refused answer 422 with the reason per field.
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Controller
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

namespace OCA\Hermiq\Tests\Unit\Controller;

use OCA\Hermiq\Controller\InstructionVariablesController;
use OCA\Hermiq\Service\Agent\InstructionVariablesService;
use OCA\Hermiq\Service\Agent\StartValuesRejectedException;
use OCA\OpenRegister\Db\ObjectEntity;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * Tests for InstructionVariablesController.
 *
 * @spec openspec/changes/agents-instruction-variables/specs/agent-management-ui/spec.md#requirement-placeholders-in-an-agents-instructions-are-filled-in-per-turn-req-agvar-001
 */
class InstructionVariablesControllerTest extends TestCase {

	/**
	 * A controller for the given user (null = signed out) over the given service.
	 *
	 * @param string|null                 $uid     The signed-in user.
	 * @param InstructionVariablesService $service The service.
	 * @param array<string, mixed>        $params  The request parameters.
	 *
	 * @return InstructionVariablesController
	 */
	private function controller(?string $uid, InstructionVariablesService $service, array $params = []): InstructionVariablesController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			static fn (string $key, mixed $default = null): mixed => ($params[$key] ?? $default)
		);
		$session = $this->createMock(IUserSession::class);
		$user = null;
		if ($uid !== null) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($uid);
		}

		$session->method('getUser')->willReturn($user);

		return new InstructionVariablesController($request, $service, $session, new NullLogger());
	}//end controller()

	/**
	 * The owner's preview passes the form's text through; others get 404.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/agents-instruction-variables/specs/agent-management-ui/spec.md#requirement-placeholders-in-an-agents-instructions-are-filled-in-per-turn-req-agvar-001
	 */
	public function testThePreviewIsTheOwnersAlone(): void {
		$service = $this->createMock(InstructionVariablesService::class);
		$service->method('preview')->willReturnCallback(
			static function (string $agentId, string $uid, ?string $prompt, ?array $startFields, array $sampleValues): array {
				if ($uid !== 'anne') {
					throw new RuntimeException('Agent not found', 404);
				}

				return ['text' => 'Hi Anne (' . $prompt . ', ' . ($sampleValues['department'] ?? '') . ')', 'unknown' => []];
			}
		);

		$params = ['prompt' => 'Hi {{user.displayName}}', 'sampleValues' => ['department' => 'Permits']];
		$ok = $this->controller('anne', $service, $params)->preview('agent-1');
		$this->assertSame(200, $ok->getStatus());
		$this->assertSame('Hi Anne (Hi {{user.displayName}}, Permits)', $ok->getData()['text']);

		$this->assertSame(404, $this->controller('bram', $service, $params)->preview('agent-1')->getStatus());
		$this->assertSame(401, $this->controller(null, $service, $params)->preview('agent-1')->getStatus());

	}//end testThePreviewIsTheOwnersAlone()

	/**
	 * Answers: saved 200, a refused answer 422 with problems, answered twice 409.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/agents-instruction-variables/specs/agent-management-ui/spec.md#requirement-an-agent-can-ask-for-fields-before-a-conversation-starts-req-agvar-002
	 */
	public function testAnswersMapToStatuses(): void {
		$saved = new ObjectEntity();
		$saved->setUuid('sess-1');
		$saved->setObject(['startValues' => ['department' => 'Permits']]);

		$service = $this->createMock(InstructionVariablesService::class);
		$service->method('answer')->willReturnCallback(
			static function (string $sessionId, string $uid, array $values) use ($saved): ObjectEntity {
				if ($sessionId === 'answered') {
					throw new RuntimeException('This session already has its answers.', 409);
				}

				if ($values === []) {
					throw new StartValuesRejectedException(problems: ['department' => 'required']);
				}

				return $saved;
			}
		);

		$ok = $this->controller('bram', $service, ['values' => ['department' => 'Permits']])->answer('sess-1');
		$this->assertSame(200, $ok->getStatus());
		$this->assertSame(['uuid' => 'sess-1', 'startValues' => ['department' => 'Permits']], $ok->getData());

		$refused = $this->controller('bram', $service, ['values' => []])->answer('sess-1');
		$this->assertSame(422, $refused->getStatus());
		$this->assertSame(['department' => 'required'], $refused->getData()['problems']);

		$this->assertSame(409, $this->controller('bram', $service, ['values' => ['department' => 'Permits']])->answer('answered')->getStatus());

	}//end testAnswersMapToStatuses()
}//end class
