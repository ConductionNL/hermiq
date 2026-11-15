<?php

/**
 * @category Tests
 * @package  OCA\Hermiq\Tests\Unit
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/personal-preferences/specs/personal-preferences/spec.md#requirement-the-voorkeuren-section-offers-the-drawn-preferences-req-pref-002
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service\Engine;

use OCA\Hermiq\Service\ApprovalService;
use OCA\Hermiq\Service\Engine\FacadeToolInvoker;
use OCA\Hermiq\Service\Engine\ToolLoop;
use OCA\Hermiq\Service\PersonalPreferences;
use OCA\Hermiq\Service\ToolSearchService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Mcp\Service\Mcp\ToolRegistryFacade;
use OCA\OpenRegister\Service\Capability\ToolGrantResolver;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use ReflectionMethod;

/**
 * Ask-before-sending adds an approval for the person's outbound tools and removes none.
 *
 * @covers \OCA\Hermiq\Service\Engine\FacadeToolInvoker
 * @covers \OCA\Hermiq\Service\Engine\ToolLoop
 * @uses   \OCA\Hermiq\Service\Engine\OutboundToolRule
 * @uses   \OCA\Hermiq\Service\PersonalPreferences
 */
class FacadeToolInvokerAskBeforeSendTest extends TestCase {

	/**
	 * The facade calls made.
	 *
	 * @var list<string>
	 */
	private array $calls = [];

	/**
	 * An invoker for `hermiq.sendMail`.
	 *
	 * @param array<string, string> $policy   The organisation's classification.
	 * @param list<string>          $askFirst The ids the person asks to approve first.
	 *
	 * @return FacadeToolInvoker
	 */
	private function invoker(array $policy, array $askFirst): FacadeToolInvoker {
		$facade = $this->createMock(ToolRegistryFacade::class);
		$facade->method('invokeTool')->willReturnCallback(
			function (string $toolId): array {
				$this->calls[] = $toolId;
				return ['result' => ['sent' => true], 'isError' => false];
			}
		);
		$approval = new ObjectEntity();
		$approval->setUuid('appr-1');
		$approval->setObject(['status' => 'pending']);
		$approvals = $this->createMock(ApprovalService::class);
		$approvals->method('ensurePendingApprovalForToolCall')->willReturn($approval);

		return new FacadeToolInvoker(
			facade: $facade,
			approvalService: $approvals,
			agentId: 'agent-1',
			mcpIdByName: ['hermiq_sendMail' => 'hermiq.sendMail'],
			toolPolicy: $policy,
			askFirst: $askFirst
		);
	}//end invoker()

	/**
	 * Switched on: a mail tool that needs no approval asks first and sends nothing yet.
	 *
	 * @return void
	 */
	public function testSwitchedOnAMailAsksFirst(): void {
		$result = json_decode($this->invoker(policy: [], askFirst: ['hermiq.sendMail'])->hermiq_sendMail(to: 'a@example.org'), true);

		self::assertSame('approval_required', $result['error']);
		self::assertSame('appr-1', $result['approvalId']);
		self::assertSame([], $this->calls);
	}//end testSwitchedOnAMailAsksFirst()

	/**
	 * Switched off: a mail tool that needs no approval sends; one an administrator set to confirm still asks.
	 *
	 * @return void
	 */
	public function testSwitchedOffKeepsRequiredApprovals(): void {
		self::assertSame(['sent' => true], json_decode($this->invoker(policy: [], askFirst: [])->hermiq_sendMail(to: 'a@example.org'), true));
		self::assertSame(['hermiq_sendMail'], $this->calls);

		$result = json_decode($this->invoker(policy: ['hermiq.sendMail' => 'confirm'], askFirst: [])->hermiq_sendMail(to: 'a@example.org'), true);
		self::assertSame('approval_required', $result['error']);
	}//end testSwitchedOffKeepsRequiredApprovals()

	/**
	 * A denied tool stays denied; asking first never turns a refusal into a question.
	 *
	 * @return void
	 */
	public function testADeniedToolStaysDenied(): void {
		$result = json_decode($this->invoker(policy: ['hermiq.sendMail' => 'deny'], askFirst: ['hermiq.sendMail'])->hermiq_sendMail(to: 'a@example.org'), true);

		self::assertSame('tool_denied_by_policy', $result['error']);
	}//end testADeniedToolStaysDenied()

	/**
	 * The loop asks first for the signed-in person's outbound tools only when they switched it on.
	 *
	 * @return void
	 */
	public function testTheLoopReadsThePersonsPreference(): void {
		$functions = [['name' => 'hermiq_sendMail', 'mcpId' => 'hermiq.sendMail'], ['name' => 'hermiq_readFile', 'mcpId' => 'hermiq.readFile']];

		self::assertSame(['hermiq.sendMail'], $this->askFirstOf(stored: 'true', functions: $functions));
		self::assertSame([], $this->askFirstOf(stored: '', functions: $functions));
	}//end testTheLoopReadsThePersonsPreference()

	/**
	 * ToolLoop's ask-first list for a person with this stored preference.
	 *
	 * @param string                       $stored    The stored value.
	 * @param list<array<string, mixed>>   $functions The turn's functions.
	 *
	 * @return list<string>
	 */
	private function askFirstOf(string $stored, array $functions): array {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('pieter');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$config = $this->createMock(IConfig::class);
		$config->method('getUserValue')->with('pieter', 'hermiq', 'pref_ask-before-send', '')->willReturn($stored);

		$loop = new ToolLoop(
			toolRegistryFacade: $this->createMock(ToolRegistryFacade::class),
			logger: new NullLogger(),
			grantResolver: new ToolGrantResolver(),
			toolSearchService: new ToolSearchService(),
			approvalService: $this->createMock(ApprovalService::class),
			appConfig: $this->createMock(IAppConfig::class),
			userSession: $session,
			preferences: new PersonalPreferences(config: $config)
		);

		return (new ReflectionMethod($loop, 'askFirst'))->invoke($loop, $functions);
	}//end askFirstOf()
}//end class
