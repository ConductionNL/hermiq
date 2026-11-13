<?php

/**
 * Hermiq LlmSettingsHandler failure-path unit tests.
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
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service\Llm;

use Exception;
use OCA\Hermiq\Service\Llm\LlmSettingsHandler;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Tests for how stored-config and persistence failures surface.
 *
 * @covers \OCA\Hermiq\Service\Llm\LlmSettingsHandler
 */
class LlmSettingsHandlerFailureTest extends TestCase {

	/**
	 * A stored value that is not a JSON object is refused with a wrapped message.
	 *
	 * @return void
	 */
	public function testAStoredNonObjectIsRefused(): void {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->with('hermiq', 'llm', '')->willReturn('"just a string"');

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('Failed to retrieve LLM settings: Stored hermiq.llm config is not a JSON object');

		(new LlmSettingsHandler(appConfig: $config))->getLLMSettingsOnly();
	}//end testAStoredNonObjectIsRefused()

	/**
	 * A stored object without vector fields is backfilled, and the app name is the one injected.
	 *
	 * @return void
	 */
	public function testAStoredObjectIsBackfilledUnderTheInjectedAppName(): void {
		$config = $this->createMock(IAppConfig::class);
		$config->expects($this->once())
			->method('getValueString')
			->with('otherapp', 'llm', '')
			->willReturn('{"chatProvider":"openai","vectorConfig":{"backend":"solr"}}');

		$settings = (new LlmSettingsHandler(appConfig: $config, appName: 'otherapp'))->getLLMSettingsOnly();

		$this->assertFalse($settings['enabled']);
		$this->assertSame('openai', $settings['chatProvider']);
		$this->assertSame(['backend' => 'solr', 'solrField' => '_embedding_'], $settings['vectorConfig']);
	}//end testAStoredObjectIsBackfilledUnderTheInjectedAppName()

	/**
	 * A failing write is wrapped in a RuntimeException naming the update.
	 *
	 * @return void
	 */
	public function testAFailingWriteIsWrapped(): void {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturn('');
		$config->expects($this->once())
			->method('setValueString')
			->willThrowException(new Exception('database locked'));

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('Failed to update LLM settings: database locked');

		(new LlmSettingsHandler(appConfig: $config))->updateLLMSettingsOnly(['enabled' => true]);
	}//end testAFailingWriteIsWrapped()

	/**
	 * An unreadable stored value also fails the update, before anything is written.
	 *
	 * @return void
	 */
	public function testAnUnreadableStoreFailsTheUpdateWithoutWriting(): void {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturn('[not json');
		$config->expects($this->never())->method('setValueString');

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('Failed to update LLM settings: Failed to retrieve LLM settings: Stored hermiq.llm config is not a JSON object');

		(new LlmSettingsHandler(appConfig: $config))->updateLLMSettingsOnly(['enabled' => true]);
	}//end testAnUnreadableStoreFailsTheUpdateWithoutWriting()
}//end class
