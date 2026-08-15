<?php

/**
 * Tests for the nc-native-write-tools NotesWriteService.
 *
 * Notes is an OPTIONAL app reached through an internal API with no deprecation
 * contract, so the absent path is not an edge case — it is the path most
 * instances take, and it must degrade to a structured error rather than break a
 * run.
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Service\NcNative
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service\NcNative;

use OCA\Hermiq\Service\NcNative\AgentArtefactMarker;
use OCA\Hermiq\Service\NcNative\CalendarWriteService;
use OCA\Hermiq\Service\NcNative\ContactWriteService;
use OCA\Hermiq\Service\NcNative\NotesWriteService;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for NotesWriteService.
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Service\NcNative
 */
class NotesWriteServiceTest extends TestCase {

	/**
	 * Build the service with a container that cannot resolve the Notes service.
	 *
	 * @return NotesWriteService
	 */
	private function service(): NotesWriteService {
		return new NotesWriteService(
			$this->createMock(AgentArtefactMarker::class),
			$this->createMock(ContainerInterface::class),
			$this->createMock(LoggerInterface::class)
		);

	}//end service()

	/**
	 * Every notes tool degrades to a structured error when Notes cannot be
	 * resolved — never an exception, so the agent run still completes.
	 *
	 * @return void
	 */
	public function testUnresolvableNotesReturnsStructuredErrorForEveryTool(): void {
		$service = $this->service();

		$results = [
			$service->listNotes('alice'),
			$service->createNote('alice', ['title' => 'x']),
			$service->updateNote('alice', ['id' => 1, 'content' => 'x']),
		];

		foreach ($results as $result) {
			$this->assertArrayHasKey('error', $result);
			$this->assertSame('notes_not_available', $result['error']['code']);
		}

	}//end testUnresolvableNotesReturnsStructuredErrorForEveryTool()

	/**
	 * A missing note id is refused before any lookup is attempted.
	 *
	 * @return void
	 */
	public function testUpdateNoteRequiresAnId(): void {
		$result = $this->service()->updateNote('alice', ['content' => 'x']);

		$this->assertSame('invalid_argument', $result['error']['code']);

	}//end testUpdateNoteRequiresAnId()

	/**
	 * A missing title is refused before any lookup is attempted.
	 *
	 * @return void
	 */
	public function testCreateNoteRequiresATitle(): void {
		$result = $this->service()->createNote('alice', []);

		$this->assertSame('invalid_argument', $result['error']['code']);

	}//end testCreateNoteRequiresATitle()

	/**
	 * No NC-native write service offers a delete or remove verb.
	 *
	 * A structural assertion rather than a behavioural one: the guarantee is that
	 * the capability does not exist, and that is only true if no method provides it.
	 *
	 * @return void
	 */
	public function testNoWriteServiceExposesADeleteVerb(): void {
		$services = [CalendarWriteService::class, ContactWriteService::class, NotesWriteService::class];

		foreach ($services as $service) {
			foreach (get_class_methods($service) as $method) {
				$this->assertStringNotContainsStringIgnoringCase(
					'delete',
					$method,
					"{$service}::{$method} — nc-native-write-tools grants create and update only."
				);
				$this->assertStringNotContainsStringIgnoringCase('remove', $method);
			}
		}

	}//end testNoWriteServiceExposesADeleteVerb()

}//end class
