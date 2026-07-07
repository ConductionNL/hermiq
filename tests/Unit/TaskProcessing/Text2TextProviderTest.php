<?php

/**
 * Unit tests for the Hermiq text2text TaskProcessing provider family.
 *
 * Covers the shared process() contract (delegates to ProviderFactory::generateText,
 * returns its output under `output`, forbids the nextcloud driver, wraps failures as
 * ProcessingException, rejects empty input) and each subclass's task-type id + prompt
 * framing.
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\TaskProcessing
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/taskprocessing-provide-text2text/tasks.md#task-1-2
 * @spec openspec/changes/taskprocessing-provide-text2text/tasks.md#task-1-3
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\TaskProcessing;

use OCA\Hermiq\Service\Llm\ProviderFactory;
use OCA\Hermiq\Service\Llm\ProviderUnavailableException;
use OCA\Hermiq\TaskProcessing\Text2TextHeadlineProvider;
use OCA\Hermiq\TaskProcessing\Text2TextProvider;
use OCA\Hermiq\TaskProcessing\Text2TextSummaryProvider;
use OCP\TaskProcessing\Exception\ProcessingException;
use OCP\TaskProcessing\TaskTypes\TextToText;
use OCP\TaskProcessing\TaskTypes\TextToTextHeadline;
use OCP\TaskProcessing\TaskTypes\TextToTextSummary;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Tests for the text2text provider family.
 *
 * @spec openspec/changes/taskprocessing-provide-text2text/tasks.md#task-1-1
 */
class Text2TextProviderTest extends TestCase
{
    /**
     * A no-op progress reporter.
     *
     * @return callable
     */
    private function report(): callable
    {
        return static fn (float $progress): bool => true;
    }//end report()

    /**
     * process() returns the LLM output verbatim under `output` and forbids the
     * nextcloud driver (allowNextcloud must be false).
     *
     * @return void
     *
     * @spec openspec/changes/taskprocessing-provide-text2text/tasks.md#task-1-2
     */
    public function testProcessReturnsGeneratedOutput(): void
    {
        $factory = $this->createMock(ProviderFactory::class);
        $factory->expects($this->once())
            ->method('generateText')
            ->with('a prompt', 'alice', false)
            ->willReturn('the reply');

        $provider = new Text2TextProvider($factory, new NullLogger());
        $result   = $provider->process('alice', ['input' => 'a prompt'], $this->report());

        $this->assertSame(['output' => 'the reply'], $result);
    }//end testProcessReturnsGeneratedOutput()

    /**
     * A missing/empty input is a processing error.
     *
     * @return void
     *
     * @spec openspec/changes/taskprocessing-provide-text2text/tasks.md#task-1-2
     */
    public function testProcessRejectsEmptyInput(): void
    {
        $provider = new Text2TextProvider($this->createMock(ProviderFactory::class), new NullLogger());

        $this->expectException(ProcessingException::class);
        $provider->process(null, ['input' => '   '], $this->report());
    }//end testProcessRejectsEmptyInput()

    /**
     * A generation failure (e.g. the nextcloud-recursion guard firing) surfaces as
     * a ProcessingException.
     *
     * @return void
     *
     * @spec openspec/changes/taskprocessing-provide-text2text/tasks.md#task-1-2
     */
    public function testProcessWrapsGenerationFailure(): void
    {
        $factory = $this->createMock(ProviderFactory::class);
        $factory->method('generateText')->willThrowException(
            new ProviderUnavailableException('nextcloud driver forbidden', 400)
        );

        $provider = new Text2TextProvider($factory, new NullLogger());

        $this->expectException(ProcessingException::class);
        $provider->process(null, ['input' => 'x'], $this->report());
    }//end testProcessWrapsGenerationFailure()

    /**
     * Each subclass reports its own task-type id.
     *
     * @return void
     *
     * @spec openspec/changes/taskprocessing-provide-text2text/tasks.md#task-1-3
     */
    public function testTaskTypeIds(): void
    {
        $factory = $this->createMock(ProviderFactory::class);
        $logger  = new NullLogger();

        $this->assertSame(TextToText::ID, (new Text2TextProvider($factory, $logger))->getTaskTypeId());
        $this->assertSame(TextToTextSummary::ID, (new Text2TextSummaryProvider($factory, $logger))->getTaskTypeId());
        $this->assertSame(TextToTextHeadline::ID, (new Text2TextHeadlineProvider($factory, $logger))->getTaskTypeId());
    }//end testTaskTypeIds()

    /**
     * The summary provider wraps the input in a summarise instruction; the headline
     * provider wraps it in a headline instruction; text2text passes it through.
     *
     * @return void
     *
     * @spec openspec/changes/taskprocessing-provide-text2text/tasks.md#task-1-3
     */
    public function testPromptFraming(): void
    {
        $logger = new NullLogger();

        // Build a ProviderFactory mock whose generateText() records the prompt it
        // was handed and returns a canned reply.
        $factoryCapturing = function (array &$seen): ProviderFactory {
            $factory = $this->createMock(ProviderFactory::class);
            $factory->method('generateText')->willReturnCallback(
                static function (string $prompt) use (&$seen): string {
                    $seen[] = $prompt;
                    return 'ok';
                }
            );
            return $factory;
        };

        // text2text: verbatim.
        $seen1 = [];
        (new Text2TextProvider($factoryCapturing($seen1), $logger))->process(null, ['input' => 'RAW'], $this->report());
        $this->assertSame('RAW', $seen1[0]);

        // summary: framed.
        $seen2 = [];
        (new Text2TextSummaryProvider($factoryCapturing($seen2), $logger))->process(null, ['input' => 'BODY'], $this->report());
        $this->assertStringContainsString('Summarize', $seen2[0]);
        $this->assertStringContainsString('BODY', $seen2[0]);

        // headline: framed.
        $seen3 = [];
        (new Text2TextHeadlineProvider($factoryCapturing($seen3), $logger))->process(null, ['input' => 'ARTICLE'], $this->report());
        $this->assertStringContainsString('headline', $seen3[0]);
        $this->assertStringContainsString('ARTICLE', $seen3[0]);
    }//end testPromptFraming()
}//end class
