<?php

/**
 * Unit tests for ProviderFactory::generateText() (taskprocessing-provide-text2text).
 *
 * Covers the shared blocking-generation seam used by Hermiq's TaskProcessing
 * providers: the nextcloud-recursion guard (forbidden when allowNextcloud is false;
 * allowed otherwise), and the ollama driver path returning the LLPhant reply.
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Service\Llm
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/taskprocessing-provide-text2text/tasks.md#task-2-1
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service\Llm;

use OCA\Hermiq\Service\Llm\LlmSettingsHandler;
use OCA\Hermiq\Service\Llm\ProviderFactory;
use OCA\Hermiq\Service\Llm\ProviderUnavailableException;
use OCP\IUser;
use OCP\IUserSession;
use OCP\TaskProcessing\IManager;
use OCP\TaskProcessing\ISynchronousProvider;
use OCP\TaskProcessing\Task;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Tests for ProviderFactory::generateText().
 *
 * @spec openspec/changes/taskprocessing-provide-text2text/tasks.md#task-2-1
 */
class ProviderFactoryGenerateTextTest extends TestCase {
	/**
	 * Build a factory over a config-returning settings handler + manager mock.
	 *
	 * @param array $llmConfig The config the settings handler returns.
	 * @param bool $hasProviders Whether the TaskProcessing manager reports providers.
	 *
	 * @return array{0: ProviderFactory, 1: IManager} The factory and manager mock.
	 */
	private function factory(array $llmConfig, bool $hasProviders = true): array {
		$settings = $this->createMock(LlmSettingsHandler::class);
		$settings->method('getLLMSettingsOnly')->willReturn($llmConfig);

		$manager = $this->createMock(IManager::class);
		$manager->method('hasProviders')->willReturn($hasProviders);
		$manager->method('getAvailableTaskTypeIds')->willReturn(
			$hasProviders ? ['core:text2text', 'core:text2text:chat', 'core:text2text:chatwithtools'] : []
		);
		$provider = $this->createMock(ISynchronousProvider::class);
		$provider->method('getId')->willReturn('integration_openai-text2text');
		$manager->method('getPreferredProvider')->willReturn($provider);

		// The broker's ownership guard needs an identity to check the credential against.
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn($user);

		return [new ProviderFactory($settings, $manager, $userSession, new NullLogger()), $manager];
	}//end factory()

	/**
	 * With allowNextcloud=false, selecting the nextcloud driver is refused (400) —
	 * a Hermiq provider must never recurse into TaskProcessing.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/taskprocessing-provide-text2text/tasks.md#task-2-1
	 */
	public function testNextcloudForbiddenForProviderBackedGeneration(): void {
		[$factory] = $this->factory(['chatProvider' => 'nextcloud'], true);

		$this->expectException(ProviderUnavailableException::class);
		$this->expectExceptionCode(400);
		$factory->generateText('hello', null, false);
	}//end testNextcloudForbiddenForProviderBackedGeneration()

	/**
	 * With allowNextcloud=true (the default, background-work caller), the nextcloud
	 * driver runs the TaskProcessing round-trip and returns its output.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/taskprocessing-provide-text2text/tasks.md#task-2-1
	 */
	public function testNextcloudAllowedForBackgroundGeneration(): void {
		[$factory, $manager] = $this->factory(['chatProvider' => 'nextcloud'], true);

		$done = new Task('core:text2text', ['input' => 'hello'], 'hermiq', null, '');
		$done->setStatus(Task::STATUS_SUCCESSFUL);
		$done->setOutput(['output' => 'assistant reply']);
		$manager->method('runTask')->willReturn($done);

		$this->assertSame('assistant reply', $factory->generateText('hello', null, true));
	}//end testNextcloudAllowedForBackgroundGeneration()

	/**
	 * No hermiq-specific provider runs on Nextcloud AI (use-nextcloud-ai-settings): a
	 * core:text2text task as the session user. On the old code this threw "Chat provider
	 * is not configured".
	 *
	 * @return void
	 *
	 * @spec openspec/changes/use-nextcloud-ai-settings/tasks.md#task-1-1
	 */
	public function testNoProviderRunsOnNextcloudAiAsTheSessionUser(): void {
		[$factory, $manager] = $this->factory(['chatProvider' => null], true);

		$seen = null;
		$manager->expects($this->once())->method('runTask')->willReturnCallback(
			static function (Task $task) use (&$seen): Task {
				$seen = $task;
				$task->setStatus(Task::STATUS_SUCCESSFUL);
				$task->setOutput(['output' => 'a title']);
				return $task;
			}
		);

		$this->assertSame('a title', $factory->generateText('Title this'));
		$this->assertSame('core:text2text', $seen->getTaskTypeId());
		$this->assertSame('alice', $seen->getUserId());
		$this->assertSame(['input' => 'Title this'], $seen->getInput());
	}//end testNoProviderRunsOnNextcloudAiAsTheSessionUser()

	/**
	 * Nextcloud without any AI provider gives the admin message, not a silent failure.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/use-nextcloud-ai-settings/tasks.md#task-3-1
	 */
	public function testNoNextcloudProviderGivesTheAdminMessage(): void {
		[$factory] = $this->factory(['chatProvider' => null], false);

		$this->expectException(ProviderUnavailableException::class);
		$this->expectExceptionMessage('Configure an AI provider under Administration settings > Artificial intelligence.');
		$factory->generateText('hello');
	}//end testNoNextcloudProviderGivesTheAdminMessage()

	/**
	 * A chat turn on Nextcloud AI runs as the session user when the caller names none.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/use-nextcloud-ai-settings/tasks.md#task-2-4
	 */
	public function testCallNextcloudChatRunsAsTheSessionUser(): void {
		[$factory, $manager] = $this->factory(['chatProvider' => null], true);

		$seen = null;
		$manager->expects($this->once())->method('runTask')->willReturnCallback(
			static function (Task $task) use (&$seen): Task {
				$seen = $task;
				$task->setStatus(Task::STATUS_SUCCESSFUL);
				$task->setOutput(['output' => 'hi alice']);
				return $task;
			}
		);

		$reply = $factory->callNextcloudChat(messageHistory: [\LLPhant\Chat\Message::user('hi')]);

		$this->assertSame('hi alice', $reply);
		$this->assertSame('core:text2text:chat', $seen->getTaskTypeId());
		$this->assertSame('alice', $seen->getUserId());
	}//end testCallNextcloudChatRunsAsTheSessionUser()
}//end class
