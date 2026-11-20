<?php

/**
 * Session links: what a chat session is about.
 *
 * A session carries zero or more links to the entities it concerns: a
 * Nextcloud file, an OpenRegister object of any schema, and kinds nobody has
 * named yet (session-entity-links D1). Each link is `{type, id, schema?,
 * label, linkedAt, lastUsedAt}`. A link is written when a turn is SENT from a
 * page that carries a context, never when the panel is opened (D2), and it is
 * idempotent per (session, type, id): sending again from the same context
 * only touches `lastUsedAt`. The label is denormalised on purpose so a session
 * still says what it was about after its target is gone, and for that reason
 * it carries only what any reader of the session may see: a file's display
 * name, never its path.
 *
 * @category Service
 * @package  OCA\Hermiq\Service\Chat
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/session-entity-links/tasks.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service\Chat;

use DateTimeImmutable;
use DateTimeInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindOptions;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindTarget;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectSaveOptions;
use OCA\OpenRegister\Service\ObjectService;
use OCP\Files\IRootFolder;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Records, lists and removes the links of a session.
 *
 * @spec openspec/changes/session-entity-links/tasks.md
 *
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) carried in with session-entity-links; splitting it is follow-up work, not part of this merge.
 */
class SessionLinks {

	/**
	 * The stored property holding the links.
	 *
	 * @var string
	 */
	public const PROPERTY = 'links';

	/**
	 * A link to a Nextcloud file.
	 *
	 * @var string
	 */
	public const TYPE_FILE = 'file';

	/**
	 * A link to an OpenRegister object.
	 *
	 * @var string
	 */
	public const TYPE_OBJECT = 'object';

	/**
	 * The register the sessions live in.
	 *
	 * @var string
	 */
	private const REGISTER_SLUG = 'hermiq';

	/**
	 * The chat-thread schema (the live session; `Conversation` is its older twin).
	 *
	 * @var string
	 */
	private const CONVERSATION_SCHEMA = 'agentsession';

	/**
	 * The object properties a label is read from, in order.
	 *
	 * @var array<int, string>
	 */
	private const LABEL_FIELDS = ['title', 'name', 'naam', 'label', 'displayName', 'subject', 'onderwerp'];

	/**
	 * Constructor.
	 *
	 * @param ObjectService   $objectService OpenRegister's object service.
	 * @param IRootFolder     $rootFolder    The file tree, for a file's display name.
	 * @param LoggerInterface $logger        For a link that could not be written.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ObjectService $objectService,
		private readonly IRootFolder $rootFolder,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Record the links of the context a turn was sent from.
	 *
	 * Called after a turn was processed. The session is read afresh so a
	 * save made while the turn ran is not overwritten. A context naming
	 * nothing the sender can read records nothing. A failure is logged and
	 * never fails the turn.
	 *
	 * @param string               $sessionUuid The session.
	 * @param array<string, mixed> $context     The page context the turn was sent with.
	 * @param string               $userId      The sender.
	 *
	 * @return array<int, array<string, mixed>> The session's links after recording, [] when nothing was recorded.
	 *
	 * @spec openspec/changes/session-entity-links/tasks.md
	 */
	public function recordTurn(string $sessionUuid, array $context, string $userId): array {
		try {
			$found = $this->fromContext(context: $context, userId: $userId);
			if ($found === [] || $sessionUuid === '') {
				return [];
			}

			$session = $this->objectService->find(
				id: $sessionUuid,
				options: new ObjectFindOptions(register: self::REGISTER_SLUG, schema: self::CONVERSATION_SCHEMA)
			);
			if (($session instanceof ObjectEntity) === false) {
				return [];
			}

			$data  = $session->getObject();
			$links = self::merge(stored: self::linksOf(sessionData: $data), found: $found, now: self::now());
			$this->save(sessionUuid: $sessionUuid, data: $data, links: $links);

			return $links;
		} catch (Throwable $e) {
			$this->logger->warning(
				'[SessionLinks] Could not record what a session is about: '.$e->getMessage(),
				['session' => $sessionUuid]
			);
			return [];
		}//end try
	}//end recordTurn()

	/**
	 * The links a page context names, each with the label the sender can see.
	 *
	 * A file is named by `fileId` (or `contextFileId`), an object by
	 * `objectUuid` with its `register` and `schema`. A target the sender
	 * cannot read gives no link, so a label never discloses something its
	 * writer could not see.
	 *
	 * @param array<string, mixed> $context The page context.
	 * @param string               $userId  The sender.
	 *
	 * @return array<int, array<string, string>> `type`, `id`, `label` and, for an object, `schema`.
	 *
	 * @spec openspec/changes/session-entity-links/tasks.md
	 */
	public function fromContext(array $context, string $userId): array {
		$links  = [];
		$fileId = self::text(value: ($context['fileId'] ?? $context['contextFileId'] ?? null));
		if ($fileId !== '' && ctype_digit($fileId) === true) {
			$label = $this->fileLabel(fileId: (int)$fileId, userId: $userId);
			if ($label !== null) {
				$links[] = ['type' => self::TYPE_FILE, 'id' => $fileId, 'label' => $label];
			}
		}

		$objectUuid = self::text(value: ($context['objectUuid'] ?? null));
		if ($objectUuid !== '') {
			$object = $this->objectLink(
				uuid: $objectUuid,
				register: self::text(value: ($context['register'] ?? $context['registerSlug'] ?? null)),
				schema: self::text(value: ($context['schema'] ?? $context['schemaSlug'] ?? null))
			);
			if ($object !== null) {
				$links[] = $object;
			}
		}

		return $links;
	}//end fromContext()

	/**
	 * Merge links found on a turn into the stored ones: a new (type, id) is
	 * appended, a known one keeps its label and first-linked time and only
	 * has `lastUsedAt` touched.
	 *
	 * @param array<int, array<string, mixed>>  $stored The stored links.
	 * @param array<int, array<string, string>> $found  The links of the turn's context.
	 * @param string                            $now    The time, ISO 8601.
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @spec openspec/changes/session-entity-links/tasks.md
	 */
	public static function merge(array $stored, array $found, string $now): array {
		$links = $stored;
		foreach ($found as $link) {
			$index = self::indexOf(links: $links, type: $link['type'], id: $link['id']);
			if ($index !== null) {
				$links[$index]['lastUsedAt'] = $now;
				continue;
			}

			$links[] = $link + ['linkedAt' => $now, 'lastUsedAt' => $now];
		}

		return $links;
	}//end merge()

	/**
	 * A session's links, every malformed entry dropped.
	 *
	 * @param array<string, mixed> $sessionData The stored session.
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @spec openspec/changes/session-entity-links/tasks.md
	 */
	public static function linksOf(array $sessionData): array {
		$links = [];
		foreach ((array)($sessionData[self::PROPERTY] ?? []) as $link) {
			if (is_array($link) === false) {
				continue;
			}

			$type = self::text(value: ($link['type'] ?? null));
			$id   = self::text(value: ($link['id'] ?? null));
			if ($type === '' || $id === '') {
				continue;
			}

			$clean = ['type' => $type, 'id' => $id, 'label' => self::text(value: ($link['label'] ?? null))];
			foreach (['schema', 'linkedAt', 'lastUsedAt'] as $field) {
				if (self::text(value: ($link[$field] ?? null)) !== '') {
					$clean[$field] = self::text(value: $link[$field]);
				}
			}

			$links[] = $clean;
		}//end foreach

		return $links;
	}//end linksOf()

	/**
	 * The link of a session to one entity, or null when it has none.
	 *
	 * @param array<string, mixed> $sessionData The stored session.
	 * @param string               $type        The entity kind.
	 * @param string               $id          The entity's id.
	 *
	 * @return array<string, mixed>|null
	 *
	 * @spec openspec/changes/session-entity-links/tasks.md
	 */
	public static function linkTo(array $sessionData, string $type, string $id): ?array {
		$links = self::linksOf(sessionData: $sessionData);
		$index = self::indexOf(links: $links, type: $type, id: $id);
		if ($index === null) {
			return null;
		}

		return $links[$index];
	}//end linkTo()

	/**
	 * The sessions linked to one entity, most recently used link first; every
	 * session when no entity is named.
	 *
	 * @param array<int, ObjectEntity> $sessions The readable sessions.
	 * @param string                   $type     The entity kind, '' for no scope.
	 * @param string                   $id       The entity's id, '' for no scope.
	 *
	 * @return array<int, ObjectEntity>
	 *
	 * @spec openspec/changes/session-entity-links/tasks.md
	 */
	public static function about(array $sessions, string $type, string $id): array {
		if ($type === '' || $id === '') {
			return $sessions;
		}

		$used = [];
		foreach ($sessions as $index => $session) {
			$link = self::linkTo(sessionData: $session->getObject(), type: $type, id: $id);
			if ($link !== null) {
				$used[$index] = (string)($link['lastUsedAt'] ?? '');
			}
		}

		// Newest use first; an equal time keeps the list's own order.
		uksort($used, static fn (int $a, int $b): int => [$used[$b], $a] <=> [$used[$a], $b]);

		return array_values(array_map(static fn (int $index): ObjectEntity => $sessions[$index], array_keys($used)));
	}//end about()

	/**
	 * Remove one link from a session and save it.
	 *
	 * @param ObjectEntity $session The session; the caller has checked it may change it.
	 * @param string       $type    The entity kind.
	 * @param string       $id      The entity's id.
	 *
	 * @return ObjectEntity|null The saved session, or null when it had no such link.
	 *
	 * @spec openspec/changes/session-entity-links/tasks.md
	 */
	public function remove(ObjectEntity $session, string $type, string $id): ?ObjectEntity {
		$data  = $session->getObject();
		$links = self::linksOf(sessionData: $data);
		$index = self::indexOf(links: $links, type: $type, id: $id);
		if ($index === null) {
			return null;
		}

		array_splice($links, $index, 1);

		return $this->save(sessionUuid: (string)$session->getUuid(), data: $data, links: $links);
	}//end remove()

	/**
	 * Save a session with its links.
	 *
	 * @param string                           $sessionUuid The session.
	 * @param array<string, mixed>             $data        The stored session.
	 * @param array<int, array<string, mixed>> $links       Its links.
	 *
	 * @return ObjectEntity
	 */
	private function save(string $sessionUuid, array $data, array $links): ObjectEntity {
		$data[self::PROPERTY] = array_values($links);
		unset($data['@self'], $data['id']);

		return $this->objectService->saveObject(
			object: $data,
			options: new ObjectSaveOptions(
				target: new ObjectFindTarget(register: self::REGISTER_SLUG, schema: self::CONVERSATION_SCHEMA),
				uuid: $sessionUuid
			)
		);
	}//end save()

	/**
	 * The display name of a file the user can read, never its path.
	 *
	 * @param int    $fileId The file id.
	 * @param string $userId The user.
	 *
	 * @return string|null The name, or null when the user cannot read the file.
	 */
	private function fileLabel(int $fileId, string $userId): ?string {
		if ($userId === '' || $fileId <= 0) {
			return null;
		}

		try {
			$node = $this->rootFolder->getUserFolder($userId)->getFirstNodeById($fileId);
		} catch (Throwable $e) {
			return null;
		}

		if ($node === null) {
			return null;
		}

		return basename(str_replace('\\', '/', (string)$node->getName()));
	}//end fileLabel()

	/**
	 * The link to an object the caller can read, labelled with its name.
	 *
	 * @param string $uuid     The object.
	 * @param string $register Its register, '' when unknown.
	 * @param string $schema   Its schema, '' when unknown.
	 *
	 * @return array<string, string>|null
	 */
	private function objectLink(string $uuid, string $register, string $schema): ?array {
		try {
			$record = $this->objectService->find(
				id: $uuid,
				options: new ObjectFindOptions(register: self::orNull(value: $register), schema: self::orNull(value: $schema))
			);
		} catch (Throwable $e) {
			return null;
		}

		if (($record instanceof ObjectEntity) === false || (string)$record->getUuid() !== $uuid) {
			return null;
		}

		$label = '';
		$data  = $record->getObject();
		foreach (self::LABEL_FIELDS as $field) {
			$label = self::text(value: ($data[$field] ?? null));
			if ($label !== '') {
				break;
			}
		}

		if ($label === '') {
			// No name field: say which kind of record it is rather than show an id.
			$label = self::text(value: $record->getSchema());
		}

		return [
			'type' => self::TYPE_OBJECT,
			'id' => $uuid,
			'schema' => trim((string)$record->getRegister().'/'.(string)$record->getSchema(), '/'),
			'label' => $label,
		];
	}//end objectLink()

	/**
	 * The index of the link to (type, id), or null.
	 *
	 * @param array<int, array<string, mixed>> $links The links.
	 * @param string                           $type  The kind.
	 * @param string                           $id    The id.
	 *
	 * @return int|null
	 */
	private static function indexOf(array $links, string $type, string $id): ?int {
		foreach ($links as $index => $link) {
			if (($link['type'] ?? null) === $type && (string)($link['id'] ?? '') === $id) {
				return $index;
			}
		}

		return null;
	}//end indexOf()

	/**
	 * A scalar as trimmed text, else ''.
	 *
	 * @param mixed $value The value.
	 *
	 * @return string
	 */
	private static function text(mixed $value): string {
		if (is_scalar($value) === false) {
			return '';
		}

		return trim((string)$value);
	}//end text()

	/**
	 * Null for '', else the value.
	 *
	 * @param string $value The value.
	 *
	 * @return string|null
	 */
	private static function orNull(string $value): ?string {
		if ($value === '') {
			return null;
		}

		return $value;
	}//end orNull()

	/**
	 * The time now, ISO 8601.
	 *
	 * @return string
	 */
	private static function now(): string {
		return (new DateTimeImmutable())->format(DateTimeInterface::ATOM);
	}//end now()
}//end class
