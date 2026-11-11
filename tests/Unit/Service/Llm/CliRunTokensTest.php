<?php

/**
 * Hermiq cli run tokens: which token a cli turn or warm-up gets, and when it dies.
 *
 * Golden for the allowed AND refused outcomes of the run-token minting: a governed
 * turn is refused (503, named reason) without the token service, an agent or a user,
 * or when the token cannot be minted or the governed MCP endpoint cannot be resolved;
 * a text-only turn gets a tolerant egress token or none; a cold turn's token is
 * consumed and a pooled one's is not.
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Service\Llm
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
 * @spec openspec/changes/cli-runner-governed-mcp-and-egress/specs/governed-cli-mcp-transport/spec.md#requirement-a-turn-that-cannot-be-governed-fails-loudly-and-is-never-silently-tool-less
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service\Llm;

use OCA\Hermiq\Service\Llm\CliTurn;
use OCA\Hermiq\Service\Llm\LlmSettingsHandler;
use OCA\Hermiq\Service\Llm\ProviderFactory;
use OCA\Hermiq\Service\Llm\ProviderUnavailableException;
use OCA\Hermiq\Service\Llm\RunTokenService;
use OCP\IAppConfig;
use OCP\IURLGenerator;
use OCP\IUserSession;
use OCP\TaskProcessing\IManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Tests for the cli run-token minting and consumption.
 */
class CliRunTokensTest extends TestCase {

	/**
	 * The run-token service double.
	 *
	 * @var RunTokenService&MockObject
	 */
	private RunTokenService $tokens;

	/**
	 * The URL generator double.
	 *
	 * @var IURLGenerator&MockObject
	 */
	private IURLGenerator $urls;

	/**
	 * The app config double.
	 *
	 * @var IAppConfig&MockObject
	 */
	private IAppConfig $config;

	/**
	 * The logger double.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface $logger;

	/**
	 * Fresh doubles for every test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->tokens = $this->createMock(RunTokenService::class);
		$this->urls = $this->createMock(IURLGenerator::class);
		$this->urls->method('linkToRouteAbsolute')->willReturn('http://localhost/apps/hermiq/mcp/run');
		$this->config = $this->createMock(IAppConfig::class);
		$this->config->method('getValueString')->willReturn('');
		$this->logger = $this->createMock(LoggerInterface::class);
	}//end setUp()

	/**
	 * The object under test, with or without its token service and URL generator.
	 *
	 * @param bool $withTokens Whether the run-token service is wired.
	 * @param bool $withUrls   Whether the URL generator is wired.
	 *
	 * @return object
	 */
	private function subject(bool $withTokens = true, bool $withUrls = true): object {
		$tokens = null;
		if ($withTokens === true) {
			$tokens = $this->tokens;
		}

		$urls = null;
		if ($withUrls === true) {
			$urls = $this->urls;
		}

		return new ProviderFactory(
			$this->createMock(LlmSettingsHandler::class),
			$this->createMock(IManager::class),
			$this->createMock(IUserSession::class),
			$this->logger,
			'hermiq',
			null,
			null,
			null,
			$tokens,
			$urls,
			$this->config
		);
	}//end subject()

	/**
	 * Call a method on the subject, private or not.
	 *
	 * @param object $subject The object under test.
	 * @param string $method  The method.
	 * @param array  $args    Its arguments.
	 *
	 * @return mixed
	 */
	private function call(object $subject, string $method, array $args): mixed {
		$reflected = new \ReflectionMethod($subject, $method);
		$reflected->setAccessible(true);

		return $reflected->invokeArgs($subject, $args);
	}//end call()

	/**
	 * A turn for an agent and a user, with tools or not, pooled or not.
	 *
	 * @param string|null $agentId The agent.
	 * @param string|null $uid     The user.
	 *
	 * @return CliTurn
	 */
	private function turn(?string $agentId, ?string $uid): CliTurn {
		$turn = new CliTurn(model: 'claude-sonnet', agentId: $agentId);
		$turn->uid = $uid;
		$turn->conversationId = 'conv-1';
		return $turn;
	}//end turn()

	/**
	 * The refusal a governed mint raises for a turn.
	 *
	 * @param object  $subject The object under test.
	 * @param CliTurn $turn    The turn.
	 *
	 * @return ProviderUnavailableException
	 */
	private function refusal(object $subject, CliTurn $turn): ProviderUnavailableException {
		try {
			$this->call($subject, 'mintTurnTokens', [$turn]);
		} catch (ProviderUnavailableException $e) {
			return $e;
		}

		$this->fail('The governed turn must be refused.');
	}//end refusal()

	/**
	 * A governed cold turn mints one bound token with the default TTL, and an MCP config.
	 *
	 * @return void
	 */
	public function testAGovernedTurnMintsABoundTokenAndItsMcpConfig(): void {
		$this->tokens->expects($this->once())->method('mint')
			->with($this->matchesRegularExpression('/^[0-9a-f]{32}$/'), 'agent-1', 'alice', 'conv-1', null)
			->willReturn('TOKEN');
		$turn = $this->turn(agentId: 'agent-1', uid: 'alice');
		$turn->functions = [['name' => 'book_room']];

		$this->call($this->subject(), 'mintTurnTokens', [$turn]);

		$this->assertSame('TOKEN', $turn->runToken);
		$this->assertSame(
			['mcpServers' => ['hermiq' => ['type' => 'http', 'url' => 'http://localhost/apps/hermiq/mcp/run', 'headers' => ['Authorization' => 'Bearer TOKEN', 'OCS-APIRequest' => 'true']]]],
			$turn->mcpConfig
		);
	}//end testAGovernedTurnMintsABoundTokenAndItsMcpConfig()

	/**
	 * A pooled governed turn's token lives for the pooled process (600 s + 30 s slack).
	 *
	 * @return void
	 */
	public function testAPooledGovernedTurnTokenLivesForTheProcess(): void {
		$this->tokens->expects($this->once())->method('mint')
			->with($this->anything(), 'agent-1', 'alice', 'conv-1', 630)
			->willReturn('TOKEN');
		$turn = $this->turn(agentId: 'agent-1', uid: 'alice');
		$turn->functions = [['name' => 'book_room']];
		$turn->poolKey = 'pool';

		$this->call($this->subject(), 'mintTurnTokens', [$turn]);

		$this->assertSame('TOKEN', $turn->runToken);
	}//end testAPooledGovernedTurnTokenLivesForTheProcess()

	/**
	 * The container-facing base override rewrites the MCP endpoint's origin.
	 *
	 * @return void
	 */
	public function testTheMcpUrlTakesTheContainerOrigin(): void {
		$this->config = $this->createMock(IAppConfig::class);
		$this->config->expects($this->once())->method('getValueString')
			->with('hermiq', 'mcp_run_base_url', '')->willReturn('http://nextcloud/');
		$this->tokens->method('mint')->willReturn('TOKEN');
		$turn = $this->turn(agentId: 'agent-1', uid: 'alice');
		$turn->governed = true;

		$this->call($this->subject(), 'mintWarmupToken', [$turn]);

		$this->assertSame('http://nextcloud/apps/hermiq/mcp/run', $turn->mcpConfig['mcpServers']['hermiq']['url']);
	}//end testTheMcpUrlTakesTheContainerOrigin()

	/**
	 * A governed turn without the token service is refused, and nothing is minted.
	 *
	 * @return void
	 */
	public function testAGovernedTurnWithoutTheTokenServiceIsRefused(): void {
		$turn = $this->turn(agentId: 'agent-1', uid: 'alice');
		$turn->functions = [['name' => 'book_room']];

		$e = $this->refusal($this->subject(withTokens: false), $turn);

		$this->assertSame(503, $e->getCode());
		$this->assertStringContainsString('the governed run-token service is unavailable', $e->getMessage());
		$this->assertNull($turn->runToken);
	}//end testAGovernedTurnWithoutTheTokenServiceIsRefused()

	/**
	 * A governed turn without an agent, or without a user, is refused before any mint.
	 *
	 * @return void
	 */
	public function testAGovernedTurnWithoutAnAgentOrAUserIsRefused(): void {
		$this->tokens->expects($this->never())->method('mint');
		$noAgent = $this->turn(agentId: '', uid: 'alice');
		$noAgent->functions = [['name' => 'book_room']];
		$noUser = $this->turn(agentId: 'agent-1', uid: '');
		$noUser->functions = [['name' => 'book_room']];

		$agentRefusal = $this->refusal($this->subject(), $noAgent);
		$userRefusal = $this->refusal($this->subject(), $noUser);

		$this->assertSame(503, $agentRefusal->getCode());
		$this->assertStringContainsString('without an agent identity', $agentRefusal->getMessage());
		$this->assertSame(503, $userRefusal->getCode());
		$this->assertStringContainsString('without a user context', $userRefusal->getMessage());
	}//end testAGovernedTurnWithoutAnAgentOrAUserIsRefused()

	/**
	 * A mint that throws refuses the governed turn and logs the reason as an error.
	 *
	 * @return void
	 */
	public function testAFailedGovernedMintIsRefused(): void {
		$this->tokens->method('mint')->willThrowException(new RuntimeException('store down'));
		$this->logger->expects($this->once())->method('error')
			->with('[ProviderFactory] Anthropic cli run token could not be minted', ['reason' => 'store down']);
		$turn = $this->turn(agentId: 'agent-1', uid: 'alice');
		$turn->functions = [['name' => 'book_room']];

		$e = $this->refusal($this->subject(), $turn);

		$this->assertSame(503, $e->getCode());
		$this->assertStringContainsString('the per-run token could not be minted', $e->getMessage());
	}//end testAFailedGovernedMintIsRefused()

	/**
	 * A governed turn whose MCP endpoint cannot be resolved, or resolves empty, is refused.
	 *
	 * @return void
	 */
	public function testAGovernedTurnWithoutAnMcpUrlIsRefused(): void {
		$this->tokens->method('mint')->willReturn('TOKEN');
		$noUrls = $this->turn(agentId: 'agent-1', uid: 'alice');
		$noUrls->functions = [['name' => 'book_room']];
		$this->urls = $this->createMock(IURLGenerator::class);
		$this->urls->method('linkToRouteAbsolute')->willReturn('');
		$empty = $this->turn(agentId: 'agent-1', uid: 'alice');
		$empty->functions = [['name' => 'book_room']];

		$noUrlsRefusal = $this->refusal($this->subject(withUrls: false), $noUrls);
		$emptyRefusal = $this->refusal($this->subject(), $empty);

		$this->assertSame(503, $noUrlsRefusal->getCode());
		$this->assertStringContainsString('endpoint URL could not be resolved', $noUrlsRefusal->getMessage());
		$this->assertSame(503, $emptyRefusal->getCode());
		$this->assertStringContainsString('endpoint URL resolved empty', $emptyRefusal->getMessage());
	}//end testAGovernedTurnWithoutAnMcpUrlIsRefused()

	/**
	 * A text-only turn gets an egress token bound to no agent when it has none, and no MCP config.
	 *
	 * @return void
	 */
	public function testATextOnlyTurnGetsAnEgressToken(): void {
		$this->tokens->expects($this->once())->method('mint')
			->with($this->matchesRegularExpression('/^[0-9a-f]{32}$/'), '', 'alice')
			->willReturn('EGRESS');
		$turn = $this->turn(agentId: null, uid: 'alice');

		$this->call($this->subject(), 'mintTurnTokens', [$turn]);

		$this->assertSame('EGRESS', $turn->runToken);
		$this->assertNull($turn->mcpConfig);
	}//end testATextOnlyTurnGetsAnEgressToken()

	/**
	 * A text-only turn without the service or a user, or whose mint throws, gets ''.
	 *
	 * @return void
	 */
	public function testATextOnlyTurnThatCannotMintGetsNoToken(): void {
		$this->tokens->method('mint')->willThrowException(new RuntimeException('store down'));
		$this->logger->expects($this->once())->method('warning')
			->with('[ProviderFactory] Anthropic cli egress run token could not be minted', ['reason' => 'store down']);
		$noService = $this->turn(agentId: 'agent-1', uid: 'alice');
		$noUser = $this->turn(agentId: 'agent-1', uid: '');
		$failing = $this->turn(agentId: 'agent-1', uid: 'alice');
		$failing->governed = false;

		$this->call($this->subject(withTokens: false), 'mintTurnTokens', [$noService]);
		$this->call($this->subject(), 'mintTurnTokens', [$noUser]);
		$this->call($this->subject(), 'mintWarmupToken', [$failing]);

		$this->assertSame('', $noService->runToken);
		$this->assertSame('', $noUser->runToken);
		$this->assertSame('', $failing->runToken);
		$this->assertNull($failing->mcpConfig);
	}//end testATextOnlyTurnThatCannotMintGetsNoToken()

	/**
	 * A governed warm-up is refused like a governed turn.
	 *
	 * @return void
	 */
	public function testAGovernedWarmUpWithoutAUserIsRefused(): void {
		$this->tokens->expects($this->never())->method('mint');
		$turn = $this->turn(agentId: 'agent-1', uid: null);

		$this->expectException(ProviderUnavailableException::class);
		$this->expectExceptionCode(503);

		$this->call($this->subject(), 'mintWarmupToken', [$turn]);
	}//end testAGovernedWarmUpWithoutAUserIsRefused()

	/**
	 * A cold turn's token is consumed; a pooled turn's, or a turn without one, is not.
	 *
	 * @return void
	 */
	public function testOnlyAColdTurnsTokenIsConsumed(): void {
		$this->tokens->expects($this->once())->method('consume')->with('COLD');
		$cold = $this->turn(agentId: 'agent-1', uid: 'alice');
		$cold->runToken = 'COLD';
		$pooled = $this->turn(agentId: 'agent-1', uid: 'alice');
		$pooled->runToken = 'POOLED';
		$pooled->poolKey = 'pool';
		$none = $this->turn(agentId: 'agent-1', uid: 'alice');

		$subject = $this->subject();
		$this->call($subject, 'consumeColdRunToken', [$cold]);
		$this->call($subject, 'consumeColdRunToken', [$pooled]);
		$this->call($subject, 'consumeColdRunToken', [$none]);
		$this->call($this->subject(withTokens: false), 'consumeColdRunToken', [$cold]);
	}//end testOnlyAColdTurnsTokenIsConsumed()
}//end class
