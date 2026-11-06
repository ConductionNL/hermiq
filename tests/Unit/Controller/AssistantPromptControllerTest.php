<?php

/**
 * Unit tests for AssistantPromptController and OutsideAgentController.
 *
 * Both surfaces are probed with the least privileged principal that should be
 * refused, because what the model is told and what an outside agent may call are
 * both boundaries an ordinary authenticated user must not be able to move.
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Controller
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
 * @spec openspec/changes/the-declared-tool-surface-and-the-prompt-library/specs/ai-feature-admin-surface/spec.md
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Controller;

use OCA\Hermiq\Controller\AssistantPromptController;
use OCA\Hermiq\Controller\OutsideAgentController;
use OCA\Hermiq\Service\ActionAuthService;
use OCA\Hermiq\Service\Assistant\AssistantPromptLibrary;
use OCA\Hermiq\Service\OutsideAgent\OutsideAgentGateway;
use OCA\Hermiq\Service\OutsideAgent\OutsideCallRefusedException;
use OCA\Hermiq\Service\OutsideAgent\OutsideToolSurface;
use OCA\OpenRegister\Db\AuditTrail;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCP\AppFramework\Http;
use OCP\AppFramework\OCS\OCSForbiddenException;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * The prompt library and outside-agent controllers.
 *
 * @spec openspec/changes/the-declared-tool-surface-and-the-prompt-library/specs/ai-feature-admin-surface/spec.md
 */
class AssistantPromptControllerTest extends TestCase {

	/**
	 * The audit entries written during a test.
	 *
	 * @var array<int, array{action: string, context: array<string, mixed>}>
	 */
	private array $audits = [];

	/**
	 * A session for one user, or for nobody.
	 *
	 * @param string|null $uid The user id, or null for an unauthenticated session.
	 *
	 * @return IUserSession The session.
	 */
	private function session(?string $uid): IUserSession {
		$session = $this->createMock(IUserSession::class);

		if ($uid === null) {
			$session->method('getUser')->willReturn(null);
			return $session;
		}

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$session->method('getUser')->willReturn($user);

		return $session;
	}//end session()

	/**
	 * An action-auth service that permits or refuses.
	 *
	 * @param bool $permits Whether the action is permitted.
	 *
	 * @return ActionAuthService The double.
	 */
	private function actionAuth(bool $permits): ActionAuthService {
		$auth = $this->createMock(ActionAuthService::class);
		if ($permits === false) {
			$auth->method('requireAction')->willThrowException(new OCSForbiddenException('nope'));
		}

		return $auth;
	}//end actionAuth()

	/**
	 * An audit mapper recording what it was asked to write.
	 *
	 * @return AuditTrailMapper The double.
	 */
	private function auditTrailMapper(): AuditTrailMapper {
		$this->audits = [];

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
	 * An ordinary authenticated user cannot change what the model is told. This is
	 * the least privileged principal that should be refused: the prompt is the
	 * instruction the assistant runs on, and a user who may use the assistant is
	 * not thereby someone who may rewrite it.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/the-declared-tool-surface-and-the-prompt-library/specs/ai-feature-admin-surface/spec.md#requirement-the-prompts-the-assistant-offers-must-be-administered-objects
	 */
	public function testAnOrdinaryUserCannotRewriteAPrompt(): void {
		$library = $this->createMock(AssistantPromptLibrary::class);
		$library->expects($this->never())->method('upsert');

		$controller = new AssistantPromptController(
			$this->createMock(IRequest::class),
			$library,
			$this->actionAuth(permits: false),
			$this->session('mallory'),
			new NullLogger()
		);

		$this->assertSame(Http::STATUS_FORBIDDEN, $controller->save('prompt-1')->getStatus());
	}//end testAnOrdinaryUserCannotRewriteAPrompt()

	/**
	 * Nor can they switch every prompt off, which is an incident response rather
	 * than an ordinary act.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/the-declared-tool-surface-and-the-prompt-library/specs/ai-feature-admin-surface/spec.md#requirement-disabling-every-prompt-must-be-one-recorded-act
	 */
	public function testAnOrdinaryUserCannotDisableEveryPrompt(): void {
		$library = $this->createMock(AssistantPromptLibrary::class);
		$library->expects($this->never())->method('disableAll');

		$controller = new AssistantPromptController(
			$this->createMock(IRequest::class),
			$library,
			$this->actionAuth(permits: false),
			$this->session('mallory'),
			new NullLogger()
		);

		$this->assertSame(Http::STATUS_FORBIDDEN, $controller->disableAll()->getStatus());
	}//end testAnOrdinaryUserCannotDisableEveryPrompt()

	/**
	 * An unauthenticated caller reads nothing at all.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/the-declared-tool-surface-and-the-prompt-library/specs/ai-feature-admin-surface/spec.md#requirement-the-prompts-the-assistant-offers-must-be-administered-objects
	 */
	public function testAnUnauthenticatedCallerReadsNothing(): void {
		$controller = new AssistantPromptController(
			$this->createMock(IRequest::class),
			$this->createMock(AssistantPromptLibrary::class),
			$this->actionAuth(permits: true),
			$this->session(null),
			new NullLogger()
		);

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $controller->index()->getStatus());
	}//end testAnUnauthenticatedCallerReadsNothing()

	/**
	 * A permitted administrator's disable-all goes through and reports how many
	 * prompts it switched off.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/the-declared-tool-surface-and-the-prompt-library/specs/ai-feature-admin-surface/spec.md#scenario-everything-stops-in-one-act
	 */
	public function testAnAdministratorDisablesEveryPromptInOneCall(): void {
		$library = $this->createMock(AssistantPromptLibrary::class);
		$library->expects($this->once())->method('disableAll')->willReturn(12);

		$controller = new AssistantPromptController(
			$this->createMock(IRequest::class),
			$library,
			$this->actionAuth(permits: true),
			$this->session('noor'),
			new NullLogger()
		);

		$response = $controller->disableAll();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(12, $response->getData()['disabled']);
	}//end testAnAdministratorDisablesEveryPromptInOneCall()

	/**
	 * A refused outside call comes back as a 403 naming the gate, and is recorded
	 * on the audit trail beside the calls that succeeded.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/the-declared-tool-surface-and-the-prompt-library/specs/agent-tool-governance/spec.md#requirement-an-outside-agents-calls-must-be-recorded-like-an-internal-agents
	 */
	public function testARefusedOutsideCallIsA403AndIsRecorded(): void {
		$gateway = $this->createMock(OutsideAgentGateway::class);
		$gateway->method('call')->willThrowException(
			new OutsideCallRefusedException(
				gate: OutsideCallRefusedException::GATE_GRANT,
				toolId: 'dossiq.case.update',
				principal: 'mallory',
				reason: 'this tool writes, and a tool that writes is denied unless the registration names it'
			)
		);

		$controller = new OutsideAgentController(
			$this->createMock(IRequest::class),
			$this->createMock(OutsideToolSurface::class),
			$gateway,
			$this->session('mallory'),
			$this->auditTrailMapper(),
			new NullLogger()
		);

		$response = $controller->call();

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		$this->assertSame(OutsideCallRefusedException::GATE_GRANT, $response->getData()['gate']);

		$this->assertCount(1, $this->audits);
		$this->assertSame(OutsideAgentController::AUDIT_ACTION, $this->audits[0]['action']);
		$this->assertSame('mallory', $this->audits[0]['context']['principal']);
		$this->assertSame('refused', $this->audits[0]['context']['outcome']);
	}//end testARefusedOutsideCallIsA403AndIsRecorded()

	/**
	 * A permitted call is recorded too, so the oversight surface has one place to
	 * read who called what rather than only a record of the failures.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/the-declared-tool-surface-and-the-prompt-library/specs/agent-tool-governance/spec.md#scenario-one-place-to-read-who-called-what
	 */
	public function testAPermittedOutsideCallIsRecordedToo(): void {
		$gateway = $this->createMock(OutsideAgentGateway::class);
		$gateway->method('call')->willReturn(['result' => ['identificatie' => 'ZAAK-1'], 'isError' => false]);

		$controller = new OutsideAgentController(
			$this->createMock(IRequest::class),
			$this->createMock(OutsideToolSurface::class),
			$gateway,
			$this->session('agent-user'),
			$this->auditTrailMapper(),
			new NullLogger()
		);

		$response = $controller->call();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertCount(1, $this->audits);
		$this->assertSame('ok', $this->audits[0]['context']['outcome']);
	}//end testAPermittedOutsideCallIsRecordedToo()

	/**
	 * An unauthenticated caller reaches no tool, and nothing is recorded for a
	 * request that never had a principal.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/the-declared-tool-surface-and-the-prompt-library/specs/agent-tool-governance/spec.md#requirement-every-outside-call-must-be-authorised-as-the-calling-principal
	 */
	public function testAnUnauthenticatedOutsideCallIsRefusedBeforeAnything(): void {
		$gateway = $this->createMock(OutsideAgentGateway::class);
		$gateway->expects($this->never())->method('call');

		$controller = new OutsideAgentController(
			$this->createMock(IRequest::class),
			$this->createMock(OutsideToolSurface::class),
			$gateway,
			$this->session(null),
			$this->auditTrailMapper(),
			new NullLogger()
		);

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $controller->call()->getStatus());
		$this->assertCount(0, $this->audits);
	}//end testAnUnauthenticatedOutsideCallIsRefusedBeforeAnything()
}//end class
