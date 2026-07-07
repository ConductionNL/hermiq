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
use OCP\TaskProcessing\IManager;
use OCP\TaskProcessing\Task;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Tests for ProviderFactory::generateText().
 *
 * @spec openspec/changes/taskprocessing-provide-text2text/tasks.md#task-2-1
 */
class ProviderFactoryGenerateTextTest extends TestCase
{
    /**
     * Build a factory over a config-returning settings handler + manager mock.
     *
     * @param array $llmConfig     The config the settings handler returns.
     * @param bool  $hasProviders  Whether the TaskProcessing manager reports providers.
     *
     * @return array{0: ProviderFactory, 1: IManager} The factory and manager mock.
     */
    private function factory(array $llmConfig, bool $hasProviders=true): array
    {
        $settings = $this->createMock(LlmSettingsHandler::class);
        $settings->method('getLLMSettingsOnly')->willReturn($llmConfig);

        $manager = $this->createMock(IManager::class);
        $manager->method('hasProviders')->willReturn($hasProviders);

        return [new ProviderFactory($settings, $manager, new NullLogger()), $manager];
    }//end factory()

    /**
     * With allowNextcloud=false, selecting the nextcloud driver is refused (400) —
     * a Hermiq provider must never recurse into TaskProcessing.
     *
     * @return void
     *
     * @spec openspec/changes/taskprocessing-provide-text2text/tasks.md#task-2-1
     */
    public function testNextcloudForbiddenForProviderBackedGeneration(): void
    {
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
    public function testNextcloudAllowedForBackgroundGeneration(): void
    {
        [$factory, $manager] = $this->factory(['chatProvider' => 'nextcloud'], true);

        $done = new Task('core:text2text', ['input' => 'hello'], 'hermiq', null, '');
        $done->setOutput(['output' => 'assistant reply']);
        $manager->method('runTask')->willReturn($done);

        $this->assertSame('assistant reply', $factory->generateText('hello', null, true));
    }//end testNextcloudAllowedForBackgroundGeneration()

    /**
     * An unconfigured provider raises the recoverable unavailable signal.
     *
     * @return void
     *
     * @spec openspec/changes/taskprocessing-provide-text2text/tasks.md#task-2-1
     */
    public function testMissingProviderThrows(): void
    {
        [$factory] = $this->factory(['chatProvider' => null], true);

        $this->expectException(ProviderUnavailableException::class);
        $factory->generateText('hello', null, false);
    }//end testMissingProviderThrows()
}//end class
