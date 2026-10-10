<?php

/**
 * Hermiq provides no TaskProcessing type it consumes (use-nextcloud-ai-settings).
 *
 * Runs the real Application::register() against a recording registration context and
 * checks every registered TaskProcessing provider's task type against the types hermiq
 * sends its own LLM work to. On the old code hermiq registered core:text2text, summary and
 * headline providers, so an admin who preferred hermiq would loop hermiq's own request.
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\AppInfo
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/use-nextcloud-ai-settings/tasks.md#task-4-1
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\AppInfo;

use OCA\Hermiq\AppInfo\Application;
use OCA\Hermiq\Service\Llm\NextcloudTaskClient;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\TaskProcessing\TaskTypes\ContextAgentInteraction;
use OCP\TaskProcessing\TaskTypes\TextToTextHeadline;
use OCP\TaskProcessing\TaskTypes\TextToTextSummary;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Tests the TaskProcessing provider registrations.
 *
 * @spec openspec/changes/use-nextcloud-ai-settings/tasks.md#task-4-1
 */
class ProviderRegistrationLoopTest extends TestCase {
	/**
	 * The task types of every provider Application::register() registers.
	 *
	 * @return list<string>
	 */
	private function registeredTaskTypes(): array {
		$providers = [];
		$context = $this->createMock(IRegistrationContext::class);
		$context->method('registerTaskProcessingProvider')->willReturnCallback(
			static function (string $class) use (&$providers): void {
				$providers[] = $class;
			}
		);

		$app = (new ReflectionClass(Application::class))->newInstanceWithoutConstructor();
		$app->register($context);

		$types = [];
		foreach ($providers as $class) {
			// getTaskTypeId() reads a constant; no collaborator is needed to answer it.
			$types[] = (new ReflectionClass($class))->newInstanceWithoutConstructor()->getTaskTypeId();
		}

		return $types;
	}//end registeredTaskTypes()

	/**
	 * No registered provider serves a type hermiq consumes, nor the summary and headline
	 * types that were backed by hermiq's own generation.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/use-nextcloud-ai-settings/tasks.md#task-4-1
	 */
	public function testNoProviderForAConsumedTaskType(): void {
		$types = $this->registeredTaskTypes();
		$forbidden = array_merge(
			array_values(NextcloudTaskClient::CONSUMED_TASK_TYPES),
			[TextToTextSummary::ID, TextToTextHeadline::ID]
		);

		$this->assertNotEmpty($types, 'register() is expected to register at least one provider');
		$this->assertSame([], array_values(array_intersect($types, $forbidden)));
	}//end testNoProviderForAConsumedTaskType()

	/**
	 * The governed contextagent provider stays, and contextagent:interaction is not a type
	 * hermiq consumes.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/use-nextcloud-ai-settings/tasks.md#task-4-1
	 */
	public function testContextAgentStaysAndIsNotConsumed(): void {
		$this->assertContains(ContextAgentInteraction::ID, $this->registeredTaskTypes());
		$this->assertNotContains(ContextAgentInteraction::ID, NextcloudTaskClient::CONSUMED_TASK_TYPES);
	}//end testContextAgentStaysAndIsNotConsumed()
}//end class
