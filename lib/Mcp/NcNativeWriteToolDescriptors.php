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

use OCA\Hermiq\Kernel\AppIdentity;
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
				'id' => AppIdentity::APP_ID . '.createCalendarEvent',
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
				'id' => AppIdentity::APP_ID . '.upsertContact',
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
				'id' => AppIdentity::APP_ID . '.listNotes',
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
				'id' => AppIdentity::APP_ID . '.createNote',
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
				'id' => AppIdentity::APP_ID . '.updateNote',
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
			[
				'id' => AppIdentity::APP_ID . '.createFile',
				'subject' => 'file',
				'action' => 'create',
				'reach' => ToolReachResolver::REACH_USER,
				'name' => 'Create file',
				'description' => 'Create a new file in the acting user\'s own Files. The path is relative to their '
					. 'files; a path outside them is refused, and an existing file is never overwritten.',
				'inputSchema' => [
					'type' => 'object',
					'properties' => [
						'path' => ['type' => 'string', 'description' => 'Path of the new file, relative to the user\'s files.'],
						'content' => ['type' => 'string', 'description' => 'The text to write, at most one megabyte.'],
					],
					'required' => ['path'],
				],
				// Create-only: it refuses an existing path, so nothing is lost.
				'readOnlyHint' => false,
				'destructiveHint' => false,
				'idempotentHint' => false,
				'scope' => 'create',
			],
			[
				'id' => AppIdentity::APP_ID . '.moveFile',
				'subject' => 'file',
				'action' => 'move',
				'reach' => ToolReachResolver::REACH_USER,
				'name' => 'Move file',
				'description' => 'Move a file within the acting user\'s own Files to a free path in a folder that exists. '
					. 'Paths outside their files are refused, and nothing at the target is overwritten.',
				'inputSchema' => [
					'type' => 'object',
					'properties' => [
						'from' => ['type' => 'string', 'description' => 'Current path of the file, relative to the user\'s files.'],
						'to' => ['type' => 'string', 'description' => 'New path of the file, relative to the user\'s files.'],
					],
					'required' => ['from', 'to'],
				],
				// A move is reversible by moving back, and refuses an occupied target.
				'readOnlyHint' => false,
				'destructiveHint' => false,
				'idempotentHint' => false,
				'scope' => 'update',
			],
	];

}//end class
