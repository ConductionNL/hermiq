<?php

/**
 * Hermiq Contact Write Service (nc-native-write-tools).
 *
 * Creates or updates contacts in address books the acting user OWNS.
 *
 * The target address book is agent-supplied, because people organise contacts
 * across books and a fixed target makes the tool useless for the case it exists to
 * serve. The widening that causes is closed at TWO independent layers: an operator
 * may pin the argument with an argument-scoped grant
 * (`hermiq.upsertContact?addressBookId=...`), and this service resolves the id only
 * against books the acting user owns. Grant narrowing is an operator convenience;
 * the ownership guard is the security boundary, and it never defers to the grant.
 *
 * @category Service
 * @package  OCA\Hermiq\Service\NcNative
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/specs/nc-native-tools/spec.md#requirement-per-object-idor-guard-on-every-provider
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service\NcNative;

use OCP\Contacts\IManager as IContactsManager;
use OCP\IAddressBook;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Contact upsert scoped to the acting user's own address books.
 *
 * @category Service
 * @package  OCA\Hermiq\Service\NcNative
 *
 * @spec openspec/specs/nc-native-tools/spec.md#requirement-per-object-idor-guard-on-every-provider
 */
class ContactWriteService {

	use ErrorEnvelopeTrait;

	/**
	 * Constructor.
	 *
	 * @param IContactsManager $contactsManager Address book access for the acting user.
	 * @param AgentArtefactMarker $marker ADR-088 marking.
	 * @param LoggerInterface $logger Diagnostics.
	 */
	public function __construct(
		private readonly IContactsManager $contactsManager,
		private readonly AgentArtefactMarker $marker,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * Create or update a contact in one of the acting user's own address books.
	 *
	 * @param string $uid The acting user id (for logging; the manager is user-scoped).
	 * @param array<string, mixed> $arguments name, email, phone, organization, addressBookId, uid.
	 * @param string $agentId The invoking agent id, for the ADR-088 mark.
	 *
	 * @return array<string, mixed> The result, or an error envelope.
	 *
	 * @spec openspec/specs/nc-native-tools/spec.md#requirement-per-object-idor-guard-on-every-provider
	 */
	public function upsert(string $uid, array $arguments, string $agentId = ''): array {
		$name = trim((string)($arguments['name'] ?? ''));
		if ($name === '') {
			return $this->err(code: 'invalid_argument', message: 'A contact name is required.');
		}

		$book = $this->resolveOwnAddressBook(requestedKey: trim((string)($arguments['addressBookId'] ?? '')));
		if ($book === null) {
			return $this->err(
				code: 'address_book_not_available',
				message: 'No address book you own matched. The system address book and books shared from others cannot be written to.'
			);
		}

		try {
			$saved = $this->contactsManager->createOrUpdate(
				$this->properties(name: $name, arguments: $arguments, agentId: $agentId),
				$book->getKey()
			);
		} catch (Throwable $e) {
			$this->logger->warning('Hermiq: contact upsert failed', ['exception' => $e, 'user' => $uid]);

			return $this->err(code: 'contact_write_failed', message: 'The contact could not be saved.');
		}

		if (is_array($saved) === false) {
			return $this->err(code: 'contact_write_failed', message: 'The contact could not be saved.');
		}

		return [
			'saved' => true,
			'name' => $name,
			'addressBook' => $book->getDisplayName(),
			'artefact' => ['type' => 'contact', 'id' => (string)($saved['UID'] ?? '')],
		];

	}//end upsert()

	/**
	 * Assemble the vCard property map, including the ADR-088 mark.
	 *
	 * @param string $name The contact's full name.
	 * @param array<string, mixed> $arguments The tool arguments.
	 * @param string $agentId The invoking agent id.
	 *
	 * @return array<string, string> The vCard properties.
	 */
	private function properties(string $name, array $arguments, string $agentId): array {
		$properties = ['FN' => $name];

		$optional = ['email' => 'EMAIL', 'phone' => 'TEL', 'organization' => 'ORG', 'uid' => 'UID'];
		foreach ($optional as $argument => $property) {
			$value = trim((string)($arguments[$argument] ?? ''));
			if ($value !== '') {
				$properties[$property] = $value;
			}
		}

		// ADR-088 mark, written as part of the same card so the contact cannot
		// exist unmarked. An `X-` property is used because system tags do not
		// apply to CardDAV objects, and it survives sync and export.
		$properties[AgentArtefactMarker::OBJECT_PROPERTY] = $this->marker->objectPropertyValue(agentId: $agentId);

		return $properties;

	}//end properties()

	/**
	 * Resolve an address book the acting user OWNS.
	 *
	 * Shared and system address books are excluded unconditionally — this holds
	 * even when an agent's grant would permit the supplied id.
	 *
	 * @param string $requestedKey The agent-supplied address book key, or ''.
	 *
	 * @return IAddressBook|null The address book, or null when none qualifies.
	 */
	private function resolveOwnAddressBook(string $requestedKey): ?IAddressBook {
		foreach ($this->contactsManager->getUserAddressBooks() as $book) {
			if ($book->isSystemAddressBook() === true || $book->isShared() === true) {
				continue;
			}

			if ($requestedKey !== '' && (string)$book->getKey() !== $requestedKey) {
				continue;
			}

			return $book;
		}

		return null;

	}//end resolveOwnAddressBook()

}//end class
