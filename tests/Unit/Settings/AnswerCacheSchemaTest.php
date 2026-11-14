<?php

/**
 * Register tests for models-prompt-and-answer-cache: the agent's answer cache
 * setting and the AnswerCacheEntry schema, read from the real register file.
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Settings
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/models-prompt-and-answer-cache/tasks.md#task-4
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Settings;

use Opis\JsonSchema\Validator;
use PHPUnit\Framework\TestCase;

/**
 * Tests the register declarations of the answer cache.
 */
class AnswerCacheSchemaTest extends TestCase {

	/**
	 * The decoded register.
	 *
	 * @return object
	 */
	private function register(): object {
		return json_decode((string)file_get_contents(__DIR__ . '/../../../lib/Settings/hermiq_register.json'));
	}//end register()

	/**
	 * Whether a value validates against the Agent's answerCache property.
	 *
	 * @param mixed $value The value.
	 *
	 * @return bool
	 */
	private function answerCacheAccepts(mixed $value): bool {
		$schema = $this->register()->components->schemas->Agent->properties->answerCache;

		return (new Validator())->validate(json_decode((string)json_encode($value)), $schema)->isValid();
	}//end answerCacheAccepts()

	/**
	 * An owner's setting within the bounds is stored; one outside them is refused.
	 *
	 * @return void
	 */
	public function testTheAgentSettingIsStoredWithinItsBounds(): void {
		$this->assertTrue($this->answerCacheAccepts(['enabled' => true, 'ttlMinutes' => 10080, 'similarity' => 'organisation']));
		$this->assertTrue($this->answerCacheAccepts(['enabled' => false]));
		$this->assertFalse($this->answerCacheAccepts(['enabled' => true, 'ttlMinutes' => 43201]));
		$this->assertFalse($this->answerCacheAccepts(['enabled' => true, 'ttlMinutes' => 0]));
		$this->assertFalse($this->answerCacheAccepts(['enabled' => true, 'similarity' => 'everyone']));
		$this->assertFalse($this->answerCacheAccepts(['enabled' => 'yes']));

		$properties = $this->register()->components->schemas->Agent->properties->answerCache->properties;
		$this->assertFalse($properties->enabled->default);
		$this->assertSame(1440, $properties->ttlMinutes->default);
		$this->assertSame('off', $properties->similarity->default);
	}//end testTheAgentSettingIsStoredWithinItsBounds()

	/**
	 * The entry schema is in the register, admin-only through the object API, and stores a key hash, not a prompt.
	 *
	 * @return void
	 */
	public function testTheEntrySchemaIsAdminOnlyAndStoresNoPrompt(): void {
		$register = $this->register();
		$entry    = $register->components->schemas->AnswerCacheEntry;

		$this->assertSame('agentanswercache', $entry->slug);
		$this->assertContains('agentanswercache', $register->components->registers->hermiq->schemas);
		foreach (['read', 'create', 'update', 'delete'] as $action) {
			$this->assertSame(['admin'], $entry->authorization->{$action});
		}

		$this->assertSame(['agentId', 'keyHash', 'answer', 'expiresAt'], $entry->required);
		$this->assertObjectNotHasProperty('prompt', $entry->properties);
		$this->assertSame(64, $entry->properties->keyHash->maxLength);
		$this->assertSame('agent', $entry->properties->agentId->{'$ref'});
		$this->assertTrue(version_compare($register->info->version, '0.57.0', '>='));
	}//end testTheEntrySchemaIsAdminOnlyAndStoresNoPrompt()
}//end class
