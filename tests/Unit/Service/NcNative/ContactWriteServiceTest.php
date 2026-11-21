<?php

/**
 * Tests for the nc-native-write-tools ContactWriteService.
 *
 * The ownership guard is the security boundary here, and every one of these tests
 * asserts it holds independently of what a grant would have permitted.
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
use OCA\Hermiq\Service\NcNative\ContactWriteService;
use OCP\Contacts\IManager as IContactsManager;
use OCP\IAddressBook;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for ContactWriteService.
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Service\NcNative
 */
class ContactWriteServiceTest extends TestCase {

	/**
	 * Build the service.
	 *
	 * @param IContactsManager $contacts Contacts manager double.
	 * @param AgentArtefactMarker|null $marker Marker double.
	 *
	 * @return ContactWriteService
	 */
	private function service(IContactsManager $contacts, ?AgentArtefactMarker $marker = null): ContactWriteService {
		return new ContactWriteService(
			$contacts,
			$marker ?? $this->createMock(AgentArtefactMarker::class),
			$this->createMock(LoggerInterface::class)
		);

	}//end service()

	/**
	 * An address book double.
	 *
	 * @param bool $system Whether it is the system address book.
	 * @param bool $shared Whether it is shared from another user.
	 * @param string $key Its key.
	 *
	 * @return IAddressBook
	 */
	private function book(bool $system, bool $shared, string $key = 'own-book'): IAddressBook {
		$book = $this->createMock(IAddressBook::class);
		$book->method('isSystemAddressBook')->willReturn($system);
		$book->method('isShared')->willReturn($shared);
		$book->method('getKey')->willReturn($key);
		$book->method('getDisplayName')->willReturn('Contacts');

		return $book;

	}//end book()

	/**
	 * The system address book is refused as a write target.
	 *
	 * @return void
	 */
	public function testSystemAddressBookIsRefused(): void {
		$contacts = $this->createMock(IContactsManager::class);
		$contacts->method('getUserAddressBooks')->willReturn([$this->book(true, false)]);
		$contacts->expects($this->never())->method('createOrUpdate');

		$result = $this->service($contacts)->upsert('alice', ['name' => 'Jansen']);

		$this->assertSame('address_book_not_available', $result['error']['code']);

	}//end testSystemAddressBookIsRefused()

	/**
	 * An address book shared from another user is refused as a write target.
	 *
	 * @return void
	 */
	public function testSharedAddressBookIsRefused(): void {
		$contacts = $this->createMock(IContactsManager::class);
		$contacts->method('getUserAddressBooks')->willReturn([$this->book(false, true)]);
		$contacts->expects($this->never())->method('createOrUpdate');

		$result = $this->service($contacts)->upsert('alice', ['name' => 'Jansen']);

		$this->assertSame('address_book_not_available', $result['error']['code']);

	}//end testSharedAddressBookIsRefused()

	/**
	 * An agent-supplied id the user does not own is refused. The ownership guard
	 * holds regardless of what an argument-scoped grant would have permitted.
	 *
	 * @return void
	 */
	public function testUnknownAddressBookIdIsRefused(): void {
		$contacts = $this->createMock(IContactsManager::class);
		$contacts->method('getUserAddressBooks')->willReturn([$this->book(false, false, 'own-book')]);
		$contacts->expects($this->never())->method('createOrUpdate');

		$result = $this->service($contacts)->upsert(
			'alice',
			['name' => 'Jansen', 'addressBookId' => 'someone-elses-book']
		);

		$this->assertSame('address_book_not_available', $result['error']['code']);

	}//end testUnknownAddressBookIdIsRefused()

	/**
	 * A contact written to one of the user's own books carries the ADR-088 mark,
	 * and the result reports the artefact identity.
	 *
	 * @return void
	 */
	public function testContactIsWrittenWithTheAgentAuthoredProperty(): void {
		$written = [];
		$contacts = $this->createMock(IContactsManager::class);
		$contacts->method('getUserAddressBooks')->willReturn([$this->book(false, false, 'own-book')]);
		$contacts->method('createOrUpdate')->willReturnCallback(
			function (array $properties, string $key) use (&$written): array {
				$written = $properties;

				return ($properties + ['UID' => 'contact-1']);
			}
		);

		$marker = $this->createMock(AgentArtefactMarker::class);
		$marker->method('objectPropertyValue')->willReturn('hermiq:agent-7');

		$result = $this->service($contacts, $marker)->upsert(
			'alice',
			['name' => 'Jansen', 'email' => 'jansen@example.org', 'addressBookId' => 'own-book'],
			'agent-7'
		);

		$this->assertArrayNotHasKey('error', $result);
		$this->assertSame('hermiq:agent-7', $written[AgentArtefactMarker::OBJECT_PROPERTY]);
		$this->assertSame('Jansen', $written['FN']);
		$this->assertSame('jansen@example.org', $written['EMAIL']);
		$this->assertSame('contact', $result['artefact']['type']);
		$this->assertSame('contact-1', $result['artefact']['id']);

	}//end testContactIsWrittenWithTheAgentAuthoredProperty()

	/**
	 * A missing name is refused before any address book is resolved.
	 *
	 * @return void
	 */
	public function testMissingNameIsRefused(): void {
		$contacts = $this->createMock(IContactsManager::class);
		$contacts->expects($this->never())->method('getUserAddressBooks');

		$result = $this->service($contacts)->upsert('alice', []);

		$this->assertSame('invalid_argument', $result['error']['code']);

	}//end testMissingNameIsRefused()

	/**
	 * A saved contact reports its name, address book and artefact, and every given
	 * optional field reaches the card trimmed, in order, before the mark.
	 *
	 * @return void
	 */
	public function testSavedContactReportsItsBookAndCarriesEveryGivenField(): void {
		$written = [];
		$keys = [];
		$contacts = $this->createMock(IContactsManager::class);
		$contacts->method('getUserAddressBooks')->willReturn([$this->book(false, false, 'own-book')]);
		$contacts->method('createOrUpdate')->willReturnCallback(
			function (array $properties, string $key) use (&$written, &$keys): array {
				$written = $properties;
				$keys[] = $key;

				return ['UID' => 'contact-2'];
			}
		);

		$result = $this->service($contacts)->upsert(
			'alice',
			['name' => ' Jansen ', 'phone' => ' 0612 ', 'organization' => 'Gemeente', 'uid' => 'u-1', 'email' => '']
		);

		$this->assertSame(['saved' => true, 'name' => 'Jansen', 'addressBook' => 'Contacts', 'artefact' => ['type' => 'contact', 'id' => 'contact-2']], $result);
		$this->assertSame(['FN', 'TEL', 'ORG', 'UID', AgentArtefactMarker::OBJECT_PROPERTY], array_keys($written));
		$this->assertSame(['Jansen', '0612', 'Gemeente', 'u-1'], array_slice(array_values($written), 0, 4));
		$this->assertSame(['own-book'], $keys);

	}//end testSavedContactReportsItsBookAndCarriesEveryGivenField()

	/**
	 * A manager that throws, or returns no card, is reported as a failed write.
	 *
	 * @return void
	 */
	public function testAFailedWriteIsReported(): void {
		foreach ([new \RuntimeException('down'), null] as $outcome) {
			$contacts = $this->createMock(IContactsManager::class);
			$contacts->method('getUserAddressBooks')->willReturn([$this->book(false, false)]);
			if ($outcome === null) {
				$contacts->method('createOrUpdate')->willReturn(null);
			} else {
				$contacts->method('createOrUpdate')->willThrowException($outcome);
			}

			$result = $this->service($contacts)->upsert('alice', ['name' => 'Jansen']);

			$this->assertSame('contact_write_failed', $result['error']['code'] ?? null);
			$this->assertSame('The contact could not be saved.', $result['error']['message'] ?? null);
		}

	}//end testAFailedWriteIsReported()

}//end class
