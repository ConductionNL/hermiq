<?php

/**
 * Hermiq NC-native write tool descriptors (nc-native-write-tools).
 *
 * The five write-tool descriptors, held outside `HermiqToolProvider` so that class
 * stays under its length budget. The proposal anticipated exactly this: a sibling
 * class is permitted when the provider grows past a reasonable size, provided the
 * **DI alias stays singular** per ADR-034/035 — which it does. This class registers
 * nothing; `HermiqToolProvider` remains the one `IMcpToolProvider`, and merely
 * merges these descriptors into its catalogue.
 *
 * @category Mcp
 * @package  OCA\Hermiq\Mcp
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/specs/nc-native-tools/spec.md#requirement-nc-native-capabilities-registered-as-imcptoolprovider-tools
 */

declare(strict_types=1);

namespace OCA\Hermiq\Mcp;

use OCA\Hermiq\AppInfo\Application;
use OCA\OpenRegister\Service\Capability\ToolReachResolver;

/**
 * Descriptor source for the NC-native write tools.
 *
 * @category Mcp
 * @package  OCA\Hermiq\Mcp
 *
 * @spec openspec/specs/nc-native-tools/spec.md#requirement-nc-native-capabilities-registered-as-imcptoolprovider-tools
 */
final class NcNativeWriteToolDescriptors {

	/**
	 * The write-tool descriptors, in catalogue order.
	 *
	 * A constant rather than a static method: the data is fixed at compile time,
	 * a constant reference is not static ACCESS in the sense that couples callers
	 * to behaviour, and it keeps the whole block out of any method-length budget.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	public const ALL = [
			[
				'id' => Application::APP_ID . '.createCalendarEvent',
				'subject' => 'calendarEvent',
				'action' => 'create',
				// 🔴 `external`, NOT `user`, and this is the whole point of the
				// tool's design. An event carrying attendees makes Nextcloud
				// dispatch iMIP invitations — the effect lands in a third party's
				// inbox and cannot be recalled, exactly like sendMail. Classifying
				// it `user` because "it writes to the user's own calendar" would
				// hide an outbound capability behind an innocuous-looking grant,
				// which is the precise failure the per-tool grant editor exists to
				// prevent.
				'reach' => ToolReachResolver::REACH_EXTERNAL,
				'name' => 'Create calendar event',
				'description' => 'Create an event in one of the acting user\'s writable calendars. Adding attendees '
					. 'SENDS THEM INVITATION EMAILS, which cannot be recalled. Only calendars the user owns and can '
					. 'write to are eligible.',
				'inputSchema' => [
					'type' => 'object',
					'properties' => [
						'summary' => ['type' => 'string', 'description' => 'Event title.'],
						'start' => ['type' => 'string', 'description' => 'Start date-time, ISO-8601.'],
						'end' => ['type' => 'string', 'description' => 'End date-time, ISO-8601.'],
						'description' => ['type' => 'string', 'description' => 'Optional event description.'],
						'location' => ['type' => 'string', 'description' => 'Optional location.'],
						'calendarUri' => [
							'type' => 'string',
							'description' => 'Optional specific calendar uri (default: first writable).',
						],
						'attendees' => [
							'type' => 'array',
							'items' => ['type' => 'string'],
							'description' => 'Optional attendee email addresses. Each one receives an invitation email.',
						],
					],
					'required' => ['summary', 'start', 'end'],
				],
				// Dispatches externally-visible invitations that cannot be recalled
				// — irreversible + externally visible, so destructive is true even
				// though nothing is deleted (the same reasoning sendMail carries).
				'readOnlyHint' => false,
				'destructiveHint' => true,
				'idempotentHint' => false,
				'scope' => 'create',
			],
			[
				'id' => Application::APP_ID . '.upsertContact',
				// `upsert`, not `create` or `update`. It may do EITHER, so
				// declaring one of them would let a grant that reads as
				// "may update existing contacts" also create new ones.
				'subject' => 'contact',
				'action' => 'upsert',
				'reach' => ToolReachResolver::REACH_USER,
				'name' => 'Save contact',
				'description' => 'Create or update a contact in one of the acting user\'s own address books. The '
					. 'system address book and address books shared from other users cannot be written to.',
				'inputSchema' => [
					'type' => 'object',
					'properties' => [
						'name' => ['type' => 'string', 'description' => 'Full name (FN).'],
						'email' => ['type' => 'string', 'description' => 'Optional email address.'],
						'phone' => ['type' => 'string', 'description' => 'Optional telephone number.'],
						'organization' => ['type' => 'string', 'description' => 'Optional organisation.'],
						'addressBookId' => [
							'type' => 'string',
							'description' => 'Optional target address book key (default: first own book).',
						],
						'uid' => [
							'type' => 'string',
							'description' => 'Optional existing contact UID to update instead of creating.',
						],
					],
					'required' => ['name'],
				],
				'readOnlyHint' => false,
				'destructiveHint' => false,
				'idempotentHint' => false,
				'scope' => 'create',
			],
			[
				'id' => Application::APP_ID . '.listNotes',
				'subject' => 'note',
				'action' => 'list',
				'reach' => ToolReachResolver::REACH_USER,
				'name' => 'List notes',
				'description' => 'List the acting user\'s notes (titles and categories only, never bodies). '
					. 'Requires the Notes app.',
				'inputSchema' => [
					'type' => 'object',
					'properties' => [],
					'required' => [],
				],
				'readOnlyHint' => true,
				'destructiveHint' => false,
				'idempotentHint' => true,
				'scope' => 'read',
			],
			[
				'id' => Application::APP_ID . '.createNote',
				'subject' => 'note',
				'action' => 'create',
				'reach' => ToolReachResolver::REACH_USER,
				'name' => 'Create note',
				'description' => 'Create a note for the acting user. Requires the Notes app.',
				'inputSchema' => [
					'type' => 'object',
					'properties' => [
						'title' => ['type' => 'string', 'description' => 'Note title.'],
						'content' => ['type' => 'string', 'description' => 'Note body (Markdown).'],
						'category' => ['type' => 'string', 'description' => 'Optional category.'],
					],
					'required' => ['title'],
				],
				'readOnlyHint' => false,
				'destructiveHint' => false,
				'idempotentHint' => false,
				'scope' => 'create',
			],
			[
				'id' => Application::APP_ID . '.updateNote',
				'subject' => 'note',
				'action' => 'update',
				'reach' => ToolReachResolver::REACH_USER,
				// 🔴 destructive, and NOT because it deletes. Notes keeps no
				// version history, so replacing the content of a note a human wrote
				// destroys that prose with nothing to restore from — unlike an
				// in-place document edit, which Nextcloud file versioning can undo.
				// `scope: update` alone would read as reversible; it is not.
				'name' => 'Update note',
				'description' => 'Replace the content of one of the acting user\'s notes. Notes keeps NO version '
					. 'history, so the previous content cannot be recovered. Requires the Notes app.',
				'inputSchema' => [
					'type' => 'object',
					'properties' => [
						'id' => ['type' => 'integer', 'description' => 'The note id.'],
						'content' => ['type' => 'string', 'description' => 'The replacement body (Markdown).'],
						'title' => ['type' => 'string', 'description' => 'Optional new title.'],
					],
					'required' => ['id'],
				],
				'readOnlyHint' => false,
				'destructiveHint' => true,
				'idempotentHint' => false,
				'scope' => 'update',
			],
	];

}//end class
