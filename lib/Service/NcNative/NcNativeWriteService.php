<?php

/**
 * Hermiq Nextcloud-Native Write Service (nc-native-write-tools).
 *
 * A thin facade over the three subsystem write services, so `HermiqToolProvider`
 * gains ONE dependency rather than three and its dispatch switch stays a flat list
 * of one-line delegations.
 *
 * The write half of the `nc-native-tools` capability lives behind this. Until this
 * change every NC-native tool was read-only EXCEPT `sendMail` — so the one thing
 * an agent could do was the one thing that could not be undone. Calendar, Contacts
 * and Notes close that asymmetry.
 *
 * Invariants, enforced in the services this delegates to:
 *
 * - **Authorise before touching anything.** Every operation resolves resources
 *   through the acting user's own principal / address books / notes folder, so
 *   another user's objects are never in the resolved set to begin with.
 * - **No delete verb exists anywhere here.** Create and update only.
 * - **Nothing throws outward.** Failures are `['error' => [...]]` envelopes,
 *   because `invokeTool()` must never throw.
 * - **ADR-088: a failed mark is a failed write.**
 * - **The record gets identity, never content** — each result carries an
 *   `artefact` descriptor that `FacadeToolInvoker` lifts into the run trace.
 *
 * This is a legitimate ADR-031 imperative external-integration seam: side-effecting
 * calls into Nextcloud subsystems, owning no schema, no derived value, no lifecycle.
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

/**
 * Facade over the Calendar, Contacts and Notes write services.
 *
 * @category Service
 * @package  OCA\Hermiq\Service\NcNative
 *
 * @spec openspec/specs/nc-native-tools/spec.md#requirement-per-object-idor-guard-on-every-provider
 */
class NcNativeWriteService {

	/**
	 * Constructor.
	 *
	 * @param CalendarWriteService $calendar Event creation.
	 * @param ContactWriteService $contacts Contact upsert.
	 * @param NotesWriteService $notes Notes list/create/update.
	 */
	public function __construct(
		private readonly CalendarWriteService $calendar,
		private readonly ContactWriteService $contacts,
		private readonly NotesWriteService $notes,
	) {

	}//end __construct()

	/**
	 * Create an event in a calendar the acting user owns and can write to.
	 *
	 * @param string $uid The acting user id.
	 * @param array<string, mixed> $arguments The tool arguments.
	 * @param string $agentId The invoking agent id, for the ADR-088 mark.
	 *
	 * @return array<string, mixed> The result, or an error envelope.
	 *
	 * @spec openspec/specs/nc-native-tools/spec.md#requirement-per-object-idor-guard-on-every-provider
	 */
	public function createCalendarEvent(string $uid, array $arguments, string $agentId = ''): array {
		return $this->calendar->create(uid: $uid, arguments: $arguments, agentId: $agentId);

	}//end createCalendarEvent()

	/**
	 * Create or update a contact in one of the acting user's own address books.
	 *
	 * @param string $uid The acting user id.
	 * @param array<string, mixed> $arguments The tool arguments.
	 * @param string $agentId The invoking agent id, for the ADR-088 mark.
	 *
	 * @return array<string, mixed> The result, or an error envelope.
	 *
	 * @spec openspec/specs/nc-native-tools/spec.md#requirement-per-object-idor-guard-on-every-provider
	 */
	public function upsertContact(string $uid, array $arguments, string $agentId = ''): array {
		return $this->contacts->upsert(uid: $uid, arguments: $arguments, agentId: $agentId);

	}//end upsertContact()

	/**
	 * List the acting user's notes.
	 *
	 * @param string $uid The acting user id.
	 *
	 * @return array<string, mixed> The notes, or an error envelope.
	 *
	 * @spec openspec/specs/nc-native-tools/spec.md#requirement-per-object-idor-guard-on-every-provider
	 */
	public function listNotes(string $uid): array {
		return $this->notes->listNotes(uid: $uid);

	}//end listNotes()

	/**
	 * Create a note for the acting user.
	 *
	 * @param string $uid The acting user id.
	 * @param array<string, mixed> $arguments The tool arguments.
	 *
	 * @return array<string, mixed> The result, or an error envelope.
	 *
	 * @spec openspec/specs/nc-native-tools/spec.md#requirement-per-object-idor-guard-on-every-provider
	 */
	public function createNote(string $uid, array $arguments): array {
		return $this->notes->createNote(uid: $uid, arguments: $arguments);

	}//end createNote()

	/**
	 * Update a note the acting user owns.
	 *
	 * @param string $uid The acting user id.
	 * @param array<string, mixed> $arguments The tool arguments.
	 *
	 * @return array<string, mixed> The result, or an error envelope.
	 *
	 * @spec openspec/specs/nc-native-tools/spec.md#requirement-per-object-idor-guard-on-every-provider
	 */
	public function updateNote(string $uid, array $arguments): array {
		return $this->notes->updateNote(uid: $uid, arguments: $arguments);

	}//end updateNote()

}//end class
