<?php

/**
 * `POST /api/agents/draft-check` answers 200 with findings, 422 for an
 * unreadable draft and 401 signed out (agents-plain-language-builder).
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
 * @spec openspec/changes/agents-plain-language-builder/specs/agent-management-ui/spec.md#requirement-a-draft-opens-in-the-full-agent-form-after-a-check-req-agbuild-002
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Controller;

use OCA\Hermiq\Controller\AgentDraftController;
use OCA\Hermiq\Service\Agent\AgentDraftService;
use OCA\Hermiq\Service\Agent\AgentDraftUnreadableException;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Tests for AgentDraftController.
 *
 * @spec openspec/changes/agents-plain-language-builder/specs/agent-management-ui/spec.md#requirement-a-draft-opens-in-the-full-agent-form-after-a-check-req-agbuild-002
 */
class AgentDraftControllerTest extends TestCase {

	/**
	 * The controller for a signed-in (or not) person posting a draft.
	 *
	 * @param string|null $uid   The signed-in user.
	 * @param string      $draft The posted draft text.
	 *
	 * @return AgentDraftController
	 */
	private function controller(?string $uid, string $draft): AgentDraftController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturn($draft);
		$session = $this->createMock(IUserSession::class);
		$user = null;
		if ($uid !== null) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($uid);
		}

		$session->method('getUser')->willReturn($user);

		$service = $this->createMock(AgentDraftService::class);
		$service->method('check')->willReturnCallback(
			static function (string $text, string $uid): array {
				if ($text === 'not json') {
					throw new AgentDraftUnreadableException();
				}

				return ['draft' => ['name' => 'Objections digest'], 'findings' => []];
			}
		);

		return new AgentDraftController($request, $service, $session, new NullLogger());
	}//end controller()

	/**
	 * A readable draft 200, an unreadable one 422 with the sentence, signed out 401.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/agents-plain-language-builder/specs/agent-management-ui/spec.md#requirement-a-draft-opens-in-the-full-agent-form-after-a-check-req-agbuild-002
	 */
	public function testStatuses(): void {
		$ok = $this->controller('teamlead', '{"name": "Objections digest"}')->check();
		$this->assertSame(200, $ok->getStatus());
		$this->assertSame('Objections digest', $ok->getData()['draft']['name']);

		$refused = $this->controller('teamlead', 'not json')->check();
		$this->assertSame(422, $refused->getStatus());
		$this->assertSame('This draft could not be read', $refused->getData()['error']);

		$this->assertSame(401, $this->controller(null, '{}')->check()->getStatus());

	}//end testStatuses()
}//end class
