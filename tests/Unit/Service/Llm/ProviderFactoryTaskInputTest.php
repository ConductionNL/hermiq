<?php

/**
 * What actually goes into an Assistant task, and what is deliberately left out.
 *
 * `Task` validates its input against the RESOLVED provider's declared shapes, and a
 * key it does not declare — or an enum value it does not list — does not fall back
 * to a default. It fails the whole task, and it fails it the same way an unreachable
 * provider does. So "ask for this model" and "do not ask for this model" are both
 * correct answers depending on the provider, and picking the wrong one turns a
 * working step into a broken one with no clue as to why.
 *
 * Measured 2026-09-23 against a Fireworks-compatible endpoint whose `/models` listing
 * answered with zero entries: the enum was therefore empty and rejected EVERY value,
 * including the admin's own default model that was serving every other call. That is
 * the case `testAProviderListingNoModelsGetsNoModel` pins.
 *
 * `taskInput()` is private, matching the convention `ProviderFactoryTest::callPrivate()`
 * established for the factory's other dispatch internals.
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
use OCP\IUser;
use OCP\IUserSession;
use OCP\TaskProcessing\EShapeType;
use OCP\TaskProcessing\IManager;
use OCP\TaskProcessing\IProvider;
use OCP\TaskProcessing\ShapeDescriptor;
use OCP\TaskProcessing\ShapeEnumValue;
use OCP\TaskProcessing\TaskTypes\ContextAgentInteraction;
use OCP\TaskProcessing\TaskTypes\TextToText;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use ReflectionMethod;
use RuntimeException;

/**
 * @covers \OCA\Hermiq\Service\Llm\ProviderFactory
 */
final class ProviderFactoryTaskInputTest extends TestCase {

	/**
	 * Build a factory whose TaskProcessing manager resolves the given provider.
	 *
	 * @param IProvider|\Throwable|null $provider What `getPreferredProvider()` does —
	 *                                            the provider it returns, the throwable
	 *                                            it raises, or null when it must never
	 *                                            be asked.
	 *
	 * @return ProviderFactory The factory under test.
	 */
	private function factory($provider = null): ProviderFactory {
		$manager = $this->createMock(originalClassName: IManager::class);
		$manager->method('hasProviders')->willReturn(true);
		if ($provider instanceof \Throwable === true) {
			$manager->method('getPreferredProvider')->willThrowException($provider);
		} elseif ($provider !== null) {
			$manager->method('getPreferredProvider')->willReturn($provider);
		} else {
			$manager->expects($this->never())->method('getPreferredProvider');
		}

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
	 * A TaskProcessing provider describing its optional inputs.
	 *
	 * @param bool $declaresModel Whether it declares a `model` optional input at all.
	 * @param array<int,string> $models The values its `model` enum lists.
	 *
	 * @return IProvider The provider double.
	 */
	private function provider(bool $declaresModel, array $models = []): IProvider {
		$shape = [];
		if ($declaresModel === true) {
			$shape['model'] = new ShapeDescriptor('Model', 'The model to use', EShapeType::Enum);
		}

		$enum = [];
		if ($models !== []) {
			$enum['model'] = array_map(
				static fn (string $value): ShapeEnumValue => new ShapeEnumValue($value, $value),
				$models
			);
		}

		$provider = $this->createMock(originalClassName: IProvider::class);
		$provider->method('getOptionalInputShape')->willReturn($shape);
		$provider->method('getOptionalInputShapeEnumValues')->willReturn($enum);

		return $provider;
	}//end provider()

	/**
	 * Build the input map for one task.
	 *
	 * @param ProviderFactory $factory The factory under test.
	 * @param string $taskTypeId The task type.
	 * @param string|null $model The model asked for, if any.
	 *
	 * @return array<string,mixed> The input map.
	 */
	private function input(ProviderFactory $factory, string $taskTypeId, ?string $model = null): array {
		$reflected = new ReflectionMethod(ProviderFactory::class, 'taskInput');
		$reflected->setAccessible(true);

		return $reflected->invoke($factory, $taskTypeId, 'say something', $model);
	}//end input()

	/**
	 * The agent surface is a conversation, so it carries the two turn fields.
	 *
	 * An absent `conversation_token` is not a harmless omission: it is a REQUIRED
	 * input on the task type, and an empty one is what starts a conversation. The
	 * provider is never consulted on this path, so a model is not sent either.
	 *
	 * @return void
	 */
	public function testTheAgentTaskCarriesTheTwoTurnFields(): void {
		$factory = $this->factory();

		$input = $this->input($factory, ContextAgentInteraction::ID, null);

		$this->assertSame(
			['input' => 'say something', 'confirmation' => 0, 'conversation_token' => ''],
			$input
		);

	}//end testTheAgentTaskCarriesTheTwoTurnFields()

	/**
	 * A model asked for on the AGENT surface is asked for the same way as on the
	 * text one.
	 *
	 * This used to return the turn fields before looking at the model at all, so
	 * an agent that set both `taskType: contextagent` and a model ran on the
	 * admin's default and said nothing about it. That is the failure the model
	 * parameter exists to end, arriving through the branch meant to be the more
	 * capable one, which is why it is asserted here and not only for text.
	 *
	 * @return void
	 */
	public function testAModelIsAskedForOnTheAgentSurfaceToo(): void {
		$factory = $this->factory($this->provider(true, ['gpt-4o-mini', 'gpt-4o']));

		$this->assertSame(
			[
				'input' => 'say something',
				'confirmation' => 0,
				'conversation_token' => '',
				'model' => 'gpt-4o',
			],
			$this->input($factory, ContextAgentInteraction::ID, 'gpt-4o')
		);

	}//end testAModelIsAskedForOnTheAgentSurfaceToo()

	/**
	 * And a model the agent surface will not accept is dropped, not sent.
	 *
	 * The same rule as for text: an unlisted value fails the task outright rather
	 * than falling back, so the drop is the only behaviour that keeps the step
	 * running, and the warning is what stops it being silent.
	 *
	 * @return void
	 */
	public function testAnUnofferedModelIsDroppedOnTheAgentSurfaceToo(): void {
		$factory = $this->factory($this->provider(true, ['claude-3']));

		$this->assertSame(
			['input' => 'say something', 'confirmation' => 0, 'conversation_token' => ''],
			$this->input($factory, ContextAgentInteraction::ID, 'gpt-4o')
		);

	}//end testAnUnofferedModelIsDroppedOnTheAgentSurfaceToo()

	/**
	 * A text task with no model asked for is just the prompt.
	 *
	 * The provider is not consulted at all, which is what keeps the pre-existing
	 * behaviour of every caller that names no model exactly as it was.
	 *
	 * @return void
	 */
	public function testATextTaskWithNoModelIsJustTheInput(): void {
		$factory = $this->factory();

		$this->assertSame(['input' => 'say something'], $this->input($factory, TextToText::ID));

	}//end testATextTaskWithNoModelIsJustTheInput()

	/**
	 * A model the provider declares AND lists is sent.
	 *
	 * POSITIVE CONTROL for every drop case below: without it, a `taskInput()` that
	 * dropped the model unconditionally would satisfy all of them and make the whole
	 * per-agent model parameter a no-op.
	 *
	 * @return void
	 */
	public function testADeclaredAndListedModelIsSent(): void {
		$factory = $this->factory($this->provider(true, ['gpt-4o-mini', 'gpt-4o']));

		$this->assertSame(
			['input' => 'say something', 'model' => 'gpt-4o'],
			$this->input($factory, TextToText::ID, 'gpt-4o')
		);

	}//end testADeclaredAndListedModelIsSent()

	/**
	 * 🔴 A PROVIDER THAT LISTS NO MODELS AT ALL IS SENT NONE.
	 *
	 * This is the measured case and the one that reads backwards: the provider
	 * DECLARES the `model` input, so the shape check passes, and the enum built from
	 * its own `/models` listing is empty — which rejects every value rather than
	 * accepting any. Sending the model there does not pick a different model, it
	 * fails the task outright with "Wrong value given for Enum slot", and it does so
	 * for the admin's configured default too.
	 *
	 * Dropping it means the step runs on the provider's default, which is the
	 * behaviour that existed before the parameter and works.
	 *
	 * @return void
	 */
	public function testAProviderListingNoModelsGetsNoModel(): void {
		$factory = $this->factory($this->provider(true, []));

		$this->assertSame(
			['input' => 'say something'],
			$this->input($factory, TextToText::ID, 'gpt-4o'),
			'an empty enum rejects every value, so the model must be dropped rather than sent'
		);

	}//end testAProviderListingNoModelsGetsNoModel()

	/**
	 * A model outside the provider's enum is dropped rather than sent.
	 *
	 * @return void
	 */
	public function testAModelTheProviderDoesNotOfferIsDropped(): void {
		$factory = $this->factory($this->provider(true, ['gpt-4o-mini', 'gpt-4o']));

		$this->assertSame(
			['input' => 'say something'],
			$this->input($factory, TextToText::ID, 'claude-opus-4')
		);

	}//end testAModelTheProviderDoesNotOfferIsDropped()

	/**
	 * A provider that offers no choice of model is not told about one.
	 *
	 * An input key the provider does not declare fails the task's own validation, so
	 * asking blind would break every provider that simply has one model.
	 *
	 * @return void
	 */
	public function testAProviderThatDeclaresNoModelInputIsNotSentOne(): void {
		$factory = $this->factory($this->provider(false, ['gpt-4o']));

		$this->assertSame(
			['input' => 'say something'],
			$this->input($factory, TextToText::ID, 'gpt-4o')
		);

	}//end testAProviderThatDeclaresNoModelInputIsNotSentOne()

	/**
	 * A provider that cannot be resolved does not take the task down with it.
	 *
	 * "I could not ask which models you offer" must degrade to the admin default,
	 * not to a task that fails before it is submitted.
	 *
	 * @return void
	 */
	public function testAnUnresolvableProviderFallsBackToNoModel(): void {
		$factory = $this->factory(new RuntimeException('no provider for this task type'));

		$this->assertSame(
			['input' => 'say something'],
			$this->input($factory, TextToText::ID, 'gpt-4o')
		);

	}//end testAnUnresolvableProviderFallsBackToNoModel()

	/**
	 * A blank model is treated as no model, and never reaches the provider check.
	 *
	 * @return void
	 */
	public function testABlankModelIsNotAskedFor(): void {
		$factory = $this->factory();

		$this->assertSame(['input' => 'say something'], $this->input($factory, TextToText::ID, '   '));

	}//end testABlankModelIsNotAskedFor()

	/**
	 * The two task type constants are the literals the Assistant actually registers.
	 *
	 * They are compared against agent data and mapped onto `TextToText::ID` /
	 * `ContextAgentInteraction::ID`, so a renamed constant value would select the
	 * wrong surface silently.
	 *
	 * @return void
	 */
	public function testTheTaskTypeConstantsNameTheAssistantSurfaces(): void {
		$this->assertSame('text2text', ProviderFactory::TASK_TYPE_TEXT);
		$this->assertSame('contextagent', ProviderFactory::TASK_TYPE_AGENT);

	}//end testTheTaskTypeConstantsNameTheAssistantSurfaces()
}//end class
