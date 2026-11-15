<?php

/**
 * Hermiq's reformulation and translation providers (REQ-T2T-003): each reports
 * its own task type, frames its prompt, names the target language for a
 * translation, refuses a translation without one, and never lets the nextcloud
 * driver answer.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
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
 * @spec openspec/changes/taskprocessing-provide-text2text/tasks.md#task-4-2
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\TaskProcessing;

use OCA\Hermiq\Service\Llm\ProviderFactory;
use OCA\Hermiq\TaskProcessing\AbstractTextProvider;
use OCA\Hermiq\TaskProcessing\ProviderRegistrar;
use OCA\Hermiq\TaskProcessing\Text2TextReformulationProvider;
use OCA\Hermiq\TaskProcessing\Text2TextTranslateProvider;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\TaskProcessing\Exception\ProcessingException;
use OCP\TaskProcessing\TaskTypes\TextToTextReformulation;
use OCP\TaskProcessing\TaskTypes\TextToTextTranslate;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

#[CoversClass(Text2TextReformulationProvider::class)]
#[CoversClass(Text2TextTranslateProvider::class)]
#[CoversClass(AbstractTextProvider::class)]
#[CoversClass(ProviderRegistrar::class)]
class Text2TextReformulateTranslateTest extends TestCase {

	/**
	 * A factory that answers with the prompt it was given, and asserts the nextcloud driver stays banned.
	 *
	 * @return ProviderFactory
	 */
	private function echoingFactory(): ProviderFactory {
		$factory = $this->createMock(ProviderFactory::class);
		$factory->method('generateText')->willReturnCallback(
			function (string $prompt, ?string $userId, bool $allowNextcloud): string {
				$this->assertFalse($allowNextcloud, 'A hermiq TaskProcessing provider must never use the nextcloud driver.');
				return $prompt;
			}
		);
		return $factory;
	}

	/**
	 * Run a provider and answer the prompt it sent.
	 *
	 * @param AbstractTextProvider $provider The provider.
	 * @param array<string, mixed> $input    The task input.
	 *
	 * @return string
	 */
	private static function promptOf(AbstractTextProvider $provider, array $input): string {
		return $provider->process('alice', $input, static fn (float $progress): bool => true)['output'];
	}

	public function testEachReportsItsOwnTaskType(): void {
		$this->assertSame(TextToTextReformulation::ID, (new Text2TextReformulationProvider($this->echoingFactory(), new NullLogger()))->getTaskTypeId());
		$this->assertSame('core:text2text:reformulation', TextToTextReformulation::ID);
		$this->assertSame('core:text2text:translate', (new Text2TextTranslateProvider($this->echoingFactory(), new NullLogger()))->getTaskTypeId());
		$this->assertSame('hermiq:text2text:reformulation', (new Text2TextReformulationProvider($this->echoingFactory(), new NullLogger()))->getId());
		$this->assertSame('hermiq:text2text:translate', (new Text2TextTranslateProvider($this->echoingFactory(), new NullLogger()))->getId());
	}

	public function testAReformulationIsFramedAndKeepsTheText(): void {
		$prompt = self::promptOf(new Text2TextReformulationProvider($this->echoingFactory(), new NullLogger()), ['input' => 'De raad besluit.']);

		$this->assertSame(
			"Reformulate the following text in different words. Keep its meaning, its language and roughly its length. Reply with only the reformulated text.\n\nDe raad besluit.",
			$prompt
		);
	}

	public function testATranslationNamesTheTargetAndAKnownOrigin(): void {
		$provider = new Text2TextTranslateProvider($this->echoingFactory(), new NullLogger());

		$this->assertSame(
			"Translate the following text from English into Dutch. Keep its meaning, tone and layout. Reply with only the translation.\n\nDear sir",
			self::promptOf($provider, ['input' => 'Dear sir', 'origin_language' => 'en', 'target_language' => 'nl'])
		);
		$this->assertSame(
			"Translate the following text into Dutch. Keep its meaning, tone and layout. Reply with only the translation.\n\nDear sir",
			self::promptOf($provider, ['input' => 'Dear sir', 'origin_language' => Text2TextTranslateProvider::DETECT, 'target_language' => 'nl'])
		);
	}

	public function testATranslationWithoutAKnownTargetIsRefused(): void {
		$factory = $this->createMock(ProviderFactory::class);
		$factory->expects($this->never())->method('generateText');
		$provider = new Text2TextTranslateProvider($factory, new NullLogger());

		foreach ([['input' => 'x'], ['input' => 'x', 'target_language' => 'xx']] as $input) {
			try {
				$provider->process('alice', $input, static fn (float $progress): bool => true);
				$this->fail('A translation without a target must be refused.');
			} catch (ProcessingException $e) {
				$this->assertSame('Hermiq translation requires a target language it offers.', $e->getMessage());
			}
		}
	}

	public function testTheLanguageSlotsOfferTheLanguagesAndTheOriginDetects(): void {
		$provider = new Text2TextTranslateProvider($this->echoingFactory(), new NullLogger());
		$enums = $provider->getInputShapeEnumValues();

		$values = static fn (array $options): array => array_map(static fn ($option): string => $option->getValue(), $options);
		$this->assertSame(array_keys(Text2TextTranslateProvider::LANGUAGES), $values($enums['target_language']));
		$this->assertSame([Text2TextTranslateProvider::DETECT, ...array_keys(Text2TextTranslateProvider::LANGUAGES)], $values($enums['origin_language']));
		$this->assertSame(['origin_language' => Text2TextTranslateProvider::DETECT], $provider->getInputShapeDefaults());
	}

	public function testBothAreRegistered(): void {
		$registered = [];
		$context = $this->createMock(IRegistrationContext::class);
		$context->method('registerTaskProcessingProvider')->willReturnCallback(
			static function (string $class) use (&$registered): void {
				$registered[] = $class;
			}
		);

		(new ProviderRegistrar())->register($context);

		$this->assertContains(Text2TextReformulationProvider::class, $registered);
		$this->assertContains(Text2TextTranslateProvider::class, $registered);
	}
}
