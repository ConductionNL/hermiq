<?php

/**
 * Hermiq provides no TaskProcessing type it consumes (use-nextcloud-ai-settings).
 *
 * Reads every TaskProcessing provider Application::register() registers and checks its
 * task type against the types hermiq
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
	 * Read from the source of register(), the same way VersionFloorGuardTest reads it:
	 * running register() for real needs a booted Nextcloud (it calls \OCP\Util), which
	 * a host PHPUnit run does not have. Each `registerTaskProcessingProvider(X::class)`
	 * is resolved through the file's `use` statements and asked for its task type.
	 *
	 * @return list<string>
	 */
	private function registeredTaskTypes(): array {
		$source = (string)file_get_contents((new ReflectionClass(Application::class))->getFileName());

		$imports = [];
		preg_match_all('/^use\s+([A-Za-z0-9_\\\\]+);/m', $source, $uses);
		foreach ($uses[1] as $fqcn) {
			$parts = explode('\\', $fqcn);
			$imports[end($parts)] = $fqcn;
		}

		preg_match_all('/registerTaskProcessingProvider\(\s*([A-Za-z0-9_]+)::class\s*\)/', $source, $calls);
		$this->assertNotEmpty($calls[1], 'register() is expected to register at least one provider');

		$types = [];
		foreach ($calls[1] as $short) {
			$this->assertArrayHasKey($short, $imports, "Provider {$short} is not imported in Application.php");
			// getTaskTypeId() reads a constant; no collaborator is needed to answer it.
			$types[] = (new ReflectionClass($imports[$short]))->newInstanceWithoutConstructor()->getTaskTypeId();
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
