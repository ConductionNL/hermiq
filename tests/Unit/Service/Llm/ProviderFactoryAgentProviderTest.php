<?php

/**
 * An agent's own `provider` field has to reach the driver.
 *
 * Agents have carried a `provider` field since they existed. It was shown in the
 * editor and stored on every save while nothing ever read it, so an agent authored
 * against one provider ran on whatever the instance's single `chatProvider` happened
 * to be. There was no error and no log line: the only symptom was answers that did
 * not match the model the agent claimed to use.
 *
 * That is why an empty value has to be pinned as carefully as a set one. "The agent
 * named no provider" must leave the instance setting exactly alone; if it instead
 * blanked it, every agent without an explicit provider would stop working at once —
 * a loud failure, but the opposite one.
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Service\Llm
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service\Llm;

use OCA\Hermiq\Service\Llm\LlmSettingsHandler;
use OCA\Hermiq\Service\Llm\ProviderFactory;
use OCA\Hermiq\Service\Llm\ProviderUnavailableException;
use OCP\IUser;
use OCP\IUserSession;
use OCP\TaskProcessing\IManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * @covers \OCA\Hermiq\Service\Llm\ProviderFactory
 */
final class ProviderFactoryAgentProviderTest extends TestCase {

	/**
	 * Build a factory, exactly as `ProviderFactoryTest::factory()` does.
	 *
	 * @return ProviderFactory The factory under test.
	 */
	private function factory(): ProviderFactory {
		$manager = $this->createMock(originalClassName: IManager::class);
		$manager->method('hasProviders')->willReturn(false);

		// The broker's ownership guard needs an identity to check the credential against.
		$user = $this->createMock(originalClassName: IUser::class);
		$user->method('getUID')->willReturn('alice');
		$userSession = $this->createMock(originalClassName: IUserSession::class);
		$userSession->method('getUser')->willReturn($user);

		return new ProviderFactory(
			$this->createMock(originalClassName: LlmSettingsHandler::class),
			$manager,
			$userSession,
			new NullLogger()
		);
	}//end factory()

	/**
	 * An instance configured for one provider, with a second one also usable.
	 *
	 * `openai` is the instance setting and carries no credential, so reaching it
	 * raises. That is deliberate: it makes "the instance setting was used" a visible
	 * outcome rather than something the test has to infer.
	 *
	 * @param string $chatProvider The instance-wide provider.
	 *
	 * @return array<string,mixed> The `hermiq.llm` configuration.
	 */
	private function llmConfig(string $chatProvider): array {
		return [
			'chatProvider' => $chatProvider,
			'ollamaConfig' => ['url' => 'http://ollama:11434', 'chatModel' => 'llama3'],
			'openaiConfig' => [],
		];
	}//end llmConfig()

	/**
	 * 🔴 THE AGENT'S PROVIDER OUTRANKS THE INSTANCE SETTING.
	 *
	 * The conflict is the whole test. An agent naming the SAME provider the instance
	 * is set to passes against both the old code and the new and distinguishes
	 * nothing, so the two have to disagree: the instance says `openai` (which has no
	 * credential and would raise), the agent says `ollama`, and the resolved driver
	 * has to be the agent's.
	 *
	 * @return void
	 */
	public function testAnAgentProviderOutranksTheInstanceSetting(): void {
		$driver = $this->factory()->createChatDriver(
			llmConfig: $this->llmConfig('openai'),
			agentProvider: 'ollama'
		);

		$this->assertSame('ollama', $driver->provider);

	}//end testAnAgentProviderOutranksTheInstanceSetting()

	/**
	 * The agent's provider is trimmed and lower-cased before it is matched.
	 *
	 * The field is free text in the editor, and the dispatch is an exact `match`, so
	 * `Ollama` with a trailing space would fall through to the "unsupported provider"
	 * arm — an agent refused for a provider that is installed and configured.
	 *
	 * @param string $written What the agent's `provider` field holds.
	 *
	 * @return void
	 *
	 * @dataProvider provideUntidyProviderNames
	 */
	public function testTheAgentProviderIsTrimmedAndLowerCased(string $written): void {
		$driver = $this->factory()->createChatDriver(
			llmConfig: $this->llmConfig('openai'),
			agentProvider: $written
		);

		$this->assertSame('ollama', $driver->provider);

	}//end testTheAgentProviderIsTrimmedAndLowerCased()

	/**
	 * The ways a person types a provider name into a text field.
	 *
	 * @return array<string,array<int,string>> The cases.
	 */
	public static function provideUntidyProviderNames(): array {
		return [
			'padded' => ['  ollama  '],
			'capitalised' => ['Ollama'],
			'shouted' => ['OLLAMA'],
			'padded and shouted' => [" OLLAMA\n"],
		];
	}//end provideUntidyProviderNames()

	/**
	 * An agent that names no provider leaves the instance setting alone.
	 *
	 * The mirror of the test above, and the one that matters for the agents that
	 * already exist: none of them fills this field in. If an empty value overwrote
	 * the instance setting, `chatProvider` would be blank and every one of them
	 * would be refused as "not configured".
	 *
	 * @param string|null $written What the agent's `provider` field holds.
	 *
	 * @return void
	 *
	 * @dataProvider provideUnsetProviderNames
	 */
	public function testAnAgentNamingNoProviderLeavesTheInstanceSettingAlone(?string $written): void {
		$driver = $this->factory()->createChatDriver(
			llmConfig: $this->llmConfig('ollama'),
			agentProvider: $written
		);

		$this->assertSame('ollama', $driver->provider);
		$this->assertSame('llama3', $driver->model, 'the instance provider\'s own configuration must still apply');

	}//end testAnAgentNamingNoProviderLeavesTheInstanceSettingAlone()

	/**
	 * The ways an agent can decline to name a provider.
	 *
	 * @return array<string,array<int,string|null>> The cases.
	 */
	public static function provideUnsetProviderNames(): array {
		return ['null' => [null], 'empty' => [''], 'blank' => ['   ']];
	}//end provideUnsetProviderNames()

	/**
	 * An agent naming a provider nobody has heard of is refused, not quietly rehoused.
	 *
	 * Falling back to the instance setting is exactly how the unread field went
	 * unnoticed for as long as it did.
	 *
	 * @return void
	 */
	public function testAnUnrecognisedAgentProviderIsRefused(): void {
		$this->expectException(ProviderUnavailableException::class);
		$this->expectExceptionMessage('llamma');

		$this->factory()->createChatDriver(
			llmConfig: $this->llmConfig('ollama'),
			agentProvider: 'llamma'
		);

	}//end testAnUnrecognisedAgentProviderIsRefused()
}//end class
