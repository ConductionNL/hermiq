<?php

/**
 * The Anthropic http path with the provider's prompt cache: breakpoints on by
 * default, off when the admin says so, and the cache tokens counted.
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
 * @spec openspec/changes/models-prompt-and-answer-cache/specs/provider-prompt-caching/spec.md#requirement-anthropic-requests-carry-cache-breakpoints-by-default-req-pcache-001
 * @spec openspec/changes/models-prompt-and-answer-cache/specs/provider-prompt-caching/spec.md#requirement-the-run-reports-cache-tokens-req-pcache-003
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service\Llm;

use LLPhant\Chat\Message as LLPhantMessage;
use OCA\Hermiq\Service\Llm\BrokerHttpClient;
use OCA\Hermiq\Service\Llm\LlmSettingsHandler;
use OCA\Hermiq\Service\Llm\ProviderFactory;
use OCA\OpenRegister\Service\Credential\CredentialBrokerService;
use OCP\IUser;
use OCP\IUserSession;
use OCP\TaskProcessing\IManager;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * Prompt caching on the Anthropic http path.
 */
class ProviderFactoryAnthropicCachingTest extends TestCase {

	/**
	 * The decoded request bodies the broker saw.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $bodies = [];

	/**
	 * A factory over the given settings whose broker answers with the responses in line.
	 *
	 * @param array<int, array<string, mixed>>       $responses The decoded response bodies.
	 * @param array<string, mixed>|RuntimeException $settings  The stored LLM settings, or their read failure.
	 *
	 * @return ProviderFactory
	 */
	private function factory(array $responses, array|RuntimeException $settings=[]): ProviderFactory {
		$broker = $this->createMock(CredentialBrokerService::class);
		$broker->method('request')->willReturnCallback(
			function (...$args) use (&$responses): array {
				$this->bodies[] = json_decode((string)$args[5], true);
				return ['status' => 200, 'headers' => ['content-type' => ['application/json']], 'body' => (string)json_encode(array_shift($responses))];
			}
		);
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(static fn (string $id) => ($id === BrokerHttpClient::BROKER_CLASS ? $broker : null));
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$handler = $this->createMock(LlmSettingsHandler::class);
		if ($settings instanceof RuntimeException) {
			$handler->method('getLLMSettingsOnly')->willThrowException($settings);
		} else {
			$handler->method('getLLMSettingsOnly')->willReturn($settings);
		}

		return new ProviderFactory($handler, $this->createMock(IManager::class), $session, new NullLogger(), 'hermiq', null, null, null, null, null, null, $container);
	}//end factory()

	/**
	 * A tool turn over a history: one tool round, then the answer.
	 *
	 * @param ProviderFactory $factory The factory.
	 *
	 * @return string
	 */
	private function toolTurn(ProviderFactory $factory): string {
		return $factory->callAnthropicChat(
			credentialId: 'cred',
			model: 'claude-x',
			baseUrl: 'https://api.anthropic.com/v1',
			messageHistory: [
				LLPhantMessage::system('You are the case assistant.'),
				LLPhantMessage::user('First question'),
				LLPhantMessage::assistant('First answer'),
				LLPhantMessage::user('List my cases'),
			],
			functions: [['name' => 'list_cases', 'description' => 'List cases', 'parameters' => ['type' => 'object', 'properties' => []]]],
			toolExecutor: static fn (): string => '["case-1"]'
		);
	}//end toolTurn()

	/**
	 * The two responses of a tool turn, the given usage on each.
	 *
	 * @param array<string, int> $usage The usage block.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private static function toolResponses(array $usage): array {
		return [
			['content' => [['type' => 'tool_use', 'id' => 'tu-1', 'name' => 'list_cases', 'input' => []]], 'stop_reason' => 'tool_use', 'usage' => $usage],
			['content' => [['type' => 'text', 'text' => 'Two cases.']], 'stop_reason' => 'end_turn', 'usage' => $usage],
		];
	}//end toolResponses()

	public function testByDefaultEachRoundCarriesTheThreeBreakpoints(): void {
		$this->assertSame('Two cases.', $this->toolTurn($this->factory(self::toolResponses([]))));

		$cc = ['type' => 'ephemeral'];
		[$first, $second] = $this->bodies;
		$this->assertSame($cc, $first['tools'][0]['cache_control']);
		$this->assertSame([['type' => 'text', 'text' => 'You are the case assistant.', 'cache_control' => $cc]], $first['system']);
		$this->assertSame([['type' => 'text', 'text' => 'First answer', 'cache_control' => $cc]], $first['messages'][1]['content']);
		$this->assertSame('List my cases', $first['messages'][2]['content']);
		$this->assertSame(3, substr_count((string)json_encode($first), 'cache_control'));

		// The tool round: the conversation breakpoint moved to the tool result.
		$last = end($second['messages']);
		$this->assertSame('tool_result', $last['content'][0]['type']);
		$this->assertSame($cc, $last['content'][0]['cache_control']);
		$this->assertSame(3, substr_count((string)json_encode($second), 'cache_control'));
	}

	public function testTheAdminsLifetimeIsUsedAndUnreadableSettingsKeepCachingOn(): void {
		$this->toolTurn($this->factory(self::toolResponses([]), ['anthropicConfig' => ['promptCacheTtl' => '1h']]));
		$this->assertSame(['type' => 'ephemeral', 'ttl' => '1h'], $this->bodies[0]['tools'][0]['cache_control']);

		$this->bodies = [];
		$this->toolTurn($this->factory(self::toolResponses([]), new RuntimeException('no settings')));
		$this->assertSame(3, substr_count((string)json_encode($this->bodies[0]), 'cache_control'));

		$this->bodies = [];
		$this->toolTurn($this->factory(self::toolResponses([]), ['anthropicConfig' => 'broken']));
		$this->assertSame(3, substr_count((string)json_encode($this->bodies[0]), 'cache_control'));
	}

	public function testSwitchedOffNoRequestCarriesABreakpoint(): void {
		$this->toolTurn($this->factory(self::toolResponses([]), ['anthropicConfig' => ['promptCaching' => false]]));

		$this->assertCount(2, $this->bodies);
		$this->assertStringNotContainsString('cache_control', (string)json_encode($this->bodies));
		$this->assertSame('You are the case assistant.', $this->bodies[0]['system']);
	}

	public function testCacheTokensAreCountedAndIncludedInThePromptTokens(): void {
		$factory = $this->factory(self::toolResponses(['input_tokens' => 460, 'cache_read_input_tokens' => 1840, 'cache_creation_input_tokens' => 0, 'output_tokens' => 50]));
		$this->toolTurn($factory);

		$this->assertSame(
			['promptTokens' => 4600, 'completionTokens' => 100, 'cacheReadTokens' => 3680, 'cacheWriteTokens' => 0],
			$factory->lastCallUsage()
		);
	}

	public function testAProviderThatReportsNoCacheCountsAddsNoCacheKeys(): void {
		$factory = $this->factory(self::toolResponses(['input_tokens' => 10, 'output_tokens' => 5]));
		$this->toolTurn($factory);

		$this->assertSame(['promptTokens' => 20, 'completionTokens' => 10], $factory->lastCallUsage());
	}
}
