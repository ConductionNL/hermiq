<?php

/**
 * Contract tests for the Hermiq TaskProcessing providers.
 *
 * Pins what the existing tests leave loose: the exact prompts, the provider ids
 * and names, the runtime estimates, the empty optional shapes, the progress
 * report, the input validation before any LLM call, and the logged context and
 * wrapped message of a generation failure.
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
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\TaskProcessing;

use OCA\Hermiq\Service\ContextAgentInteractionService;
use OCA\Hermiq\Service\Llm\ProviderFactory;
use OCA\Hermiq\TaskProcessing\AbstractTextProvider;
use OCA\Hermiq\TaskProcessing\ContextAgentProvider;
use OCA\Hermiq\TaskProcessing\EmptyOptionalShapesTrait;
use OCA\Hermiq\TaskProcessing\Text2TextHeadlineProvider;
use OCA\Hermiq\TaskProcessing\Text2TextProvider;
use OCA\Hermiq\TaskProcessing\Text2TextSummaryProvider;
use OCP\TaskProcessing\Exception\ProcessingException;
use OCP\TaskProcessing\TaskTypes\TextToTextSummary;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * Contract tests for the text providers and the context agent provider.
 *
 * @spec openspec/changes/taskprocessing-provide-text2text/tasks.md#task-1-3
 */
#[CoversClass(AbstractTextProvider::class)]
#[CoversClass(Text2TextProvider::class)]
#[CoversClass(Text2TextSummaryProvider::class)]
#[CoversClass(Text2TextHeadlineProvider::class)]
#[CoversClass(ContextAgentProvider::class)]
#[CoversClass(EmptyOptionalShapesTrait::class)]
class TextProviderContractTest extends TestCase {
	/**
	 * The LLM seam.
	 *
	 * @var ProviderFactory&MockObject
	 */
	private ProviderFactory&MockObject $factory;

	/**
	 * Set up the LLM seam mock.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->factory = $this->createMock(originalClassName: ProviderFactory::class);
	}//end setUp()

	/**
	 * A progress reporter that records every value it is handed.
	 *
	 * @param array<int, float> $seen The recorded values.
	 *
	 * @return callable
	 */
	private function recorder(array &$seen): callable {
		return static function (float $progress) use (&$seen): bool {
			$seen[] = $progress;
			return true;
		};
	}//end recorder()

	/**
	 * Each text provider, with its id and the prompt it builds for "BODY".
	 *
	 * @return array<string, array{0: class-string<AbstractTextProvider>, 1: string, 2: string}>
	 */
	public static function textProviders(): array {
		return [
			'text2text' => [Text2TextProvider::class, 'hermiq:text2text', 'BODY'],
			'summary'   => [
				Text2TextSummaryProvider::class,
				'hermiq:text2text:summary',
				"Summarize the following text concisely, preserving the key points. Reply with only the summary.\n\nBODY",
			],
			'headline'  => [
				Text2TextHeadlineProvider::class,
				'hermiq:text2text:headline',
				'Generate a single concise headline (max 10 words) for the following text. '
					."Reply with only the headline, no punctuation at the end.\n\nBODY",
			],
		];
	}//end textProviders()

	/**
	 * Each provider sends its exact prompt, without the nextcloud driver, and
	 * reports completion once.
	 *
	 * @param class-string<AbstractTextProvider> $class  The provider class.
	 * @param string                             $id     The provider id.
	 * @param string                             $prompt The expected prompt.
	 *
	 * @return void
	 */
	#[DataProvider('textProviders')]
	public function testProviderSendsItsExactPrompt(string $class, string $id, string $prompt): void {
		$this->factory->expects($this->once())
			->method('generateText')
			->with($prompt, 'carol', false)
			->willReturn('reply');

		$seen     = [];
		$provider = new $class(providerFactory: $this->factory, logger: new NullLogger());

		$this->assertSame(expected: ['output' => 'reply'], actual: $provider->process('carol', ['input' => 'BODY'], $this->recorder(seen: $seen)));
		$this->assertSame(expected: [1.0], actual: $seen);
		$this->assertSame(expected: $id, actual: $provider->getId());
		$this->assertSame(expected: 'Hermiq', actual: $provider->getName());
		$this->assertSame(expected: 10, actual: $provider->getExpectedRuntime());
	}//end testProviderSendsItsExactPrompt()

	/**
	 * Input that is not a non-empty string.
	 *
	 * @return array<string, array{0: array<string, mixed>}>
	 */
	public static function invalidInputs(): array {
		return [
			'missing'    => [[]],
			'null'       => [['input' => null]],
			'empty'      => [['input' => '']],
			'whitespace' => [['input' => "\n\t "]],
			'number'     => [['input' => 42]],
			'list'       => [['input' => ['hello']]],
		];
	}//end invalidInputs()

	/**
	 * Invalid input is refused before any LLM call or progress report.
	 *
	 * @param array<string, mixed> $input The task input.
	 *
	 * @return void
	 */
	#[DataProvider('invalidInputs')]
	public function testInvalidInputIsRefusedBeforeTheLlm(array $input): void {
		$this->factory->expects($this->never())->method('generateText');
		$seen     = [];
		$provider = new Text2TextProvider(providerFactory: $this->factory, logger: new NullLogger());

		try {
			$provider->process('carol', $input, $this->recorder(seen: $seen));
			$this->fail(message: 'Expected a ProcessingException');
		} catch (ProcessingException $e) {
			$this->assertSame(expected: 'Hermiq text provider requires a non-empty "input".', actual: $e->getMessage());
		}

		$this->assertSame(expected: [], actual: $seen);
	}//end testInvalidInputIsRefusedBeforeTheLlm()

	/**
	 * A generation failure is logged with the task type and error, wrapped with
	 * the cause as previous, and reports no progress.
	 *
	 * @return void
	 */
	public function testGenerationFailureIsLoggedAndWrapped(): void {
		$cause = new RuntimeException('quota exceeded');
		$this->factory->method('generateText')->willThrowException($cause);

		$logger = $this->createMock(originalClassName: LoggerInterface::class);
		$logger->expects($this->once())
			->method('warning')
			->with(
				'[Hermiq TaskProcessing] text2text generation failed',
				$this->callback(
					callback: static fn (array $context): bool => $context['taskTypeId'] === TextToTextSummary::ID
						&& $context['error'] === 'quota exceeded'
						&& is_string($context['file']) === true
						&& is_int($context['line']) === true
				)
			);

		$seen     = [];
		$provider = new Text2TextSummaryProvider(providerFactory: $this->factory, logger: $logger);

		try {
			$provider->process(null, ['input' => 'x'], $this->recorder(seen: $seen));
			$this->fail(message: 'Expected a ProcessingException');
		} catch (ProcessingException $e) {
			$this->assertSame(expected: 'Hermiq could not generate a reply: quota exceeded', actual: $e->getMessage());
			$this->assertSame(expected: $cause, actual: $e->getPrevious());
		}

		$this->assertSame(expected: [], actual: $seen);
	}//end testGenerationFailureIsLoggedAndWrapped()

	/**
	 * The context agent provider names itself and reports completion once.
	 *
	 * @return void
	 */
	public function testContextAgentIdentityAndProgress(): void {
		$service = $this->createMock(originalClassName: ContextAgentInteractionService::class);
		$service->method('interact')->willReturn(['output' => 'o', 'conversation_token' => 't', 'actions' => '{}']);

		$seen     = [];
		$provider = new ContextAgentProvider(interactionService: $service);
		$provider->process('dave', ['input' => 'hi'], $this->recorder(seen: $seen));

		$this->assertSame(expected: [1.0], actual: $seen);
		$this->assertSame(expected: 'hermiq:contextagent', actual: $provider->getId());
		$this->assertSame(expected: 'Hermiq (governed agents)', actual: $provider->getName());
		$this->assertSame(expected: 30, actual: $provider->getExpectedRuntime());
	}//end testContextAgentIdentityAndProgress()

	/**
	 * Confirmation slots and the integer the service receives.
	 *
	 * @return array<string, array{0: mixed, 1: int|null}>
	 */
	public static function confirmations(): array {
		return [
			'numeric string' => ['1', 1],
			'zero'           => [0, 0],
			'float'          => [1.9, 1],
			'null'           => [null, null],
			'word'           => ['yes', null],
			'empty string'   => ['', null],
		];
	}//end confirmations()

	/**
	 * Only a numeric confirmation is forwarded, as an int; the rest is null.
	 *
	 * @param mixed    $slot     The confirmation slot value.
	 * @param int|null $expected The confirmation the service receives.
	 *
	 * @return void
	 */
	#[DataProvider('confirmations')]
	public function testContextAgentConfirmationParsing(mixed $slot, ?int $expected): void {
		$service = $this->createMock(originalClassName: ContextAgentInteractionService::class);
		$service->expects($this->once())
			->method('interact')
			->with('dave', 'hi', $expected, 'conv-2')
			->willReturn([]);

		$provider = new ContextAgentProvider(interactionService: $service);
		$result   = $provider->process(
			userId: 'dave',
			input: ['input' => 'hi', 'confirmation' => $slot, 'conversation_token' => 'conv-2'],
			reportProgress: static fn (float $p): bool => true
		);

		$this->assertSame(expected: [], actual: $result);
	}//end testContextAgentConfirmationParsing()

	/**
	 * Missing message and token slots reach the service as empty strings.
	 *
	 * @return void
	 */
	public function testContextAgentDefaultsMissingSlotsToEmpty(): void {
		$service = $this->createMock(originalClassName: ContextAgentInteractionService::class);
		$service->expects($this->once())
			->method('interact')
			->with(null, '', null, '')
			->willReturn(['output' => '']);

		$provider = new ContextAgentProvider(interactionService: $service);

		$this->assertSame(expected: ['output' => ''], actual: $provider->process(null, [], static fn (float $p): bool => true));
	}//end testContextAgentDefaultsMissingSlotsToEmpty()

	/**
	 * Every provider declares no optional slots, enum values or defaults.
	 *
	 * @return void
	 */
	public function testOptionalShapesAreEmpty(): void {
		$providers = [
			new Text2TextProvider(providerFactory: $this->factory, logger: new NullLogger()),
			new Text2TextSummaryProvider(providerFactory: $this->factory, logger: new NullLogger()),
			new Text2TextHeadlineProvider(providerFactory: $this->factory, logger: new NullLogger()),
			new ContextAgentProvider(interactionService: $this->createMock(originalClassName: ContextAgentInteractionService::class)),
		];

		foreach ($providers as $provider) {
			$this->assertSame(expected: [], actual: $provider->getOptionalInputShape());
			$this->assertSame(expected: [], actual: $provider->getOptionalOutputShape());
			$this->assertSame(expected: [], actual: $provider->getInputShapeEnumValues());
			$this->assertSame(expected: [], actual: $provider->getInputShapeDefaults());
			$this->assertSame(expected: [], actual: $provider->getOptionalInputShapeEnumValues());
			$this->assertSame(expected: [], actual: $provider->getOptionalInputShapeDefaults());
			$this->assertSame(expected: [], actual: $provider->getOutputShapeEnumValues());
			$this->assertSame(expected: [], actual: $provider->getOptionalOutputShapeEnumValues());
		}
	}//end testOptionalShapesAreEmpty()
}//end class
