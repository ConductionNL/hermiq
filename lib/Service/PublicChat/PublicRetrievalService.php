<?php

/**
 * Hermiq PublicRetrievalService.
 *
 * The only reader a public chat turn has. It searches the objects
 * OpenRegister serves to an ANONYMOUS caller, inside the channel's declared
 * source scope, and nothing else: no files, no memory, no user profile.
 *
 * @category Service
 * @package  OCA\Hermiq\Service\PublicChat
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/delivery-public-web-chat/specs/public-chat-channel/spec.md#requirement-a-visitor-chats-without-signing-in-and-gets-answers-only-from-public-content-req-pubchat-002
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service\PublicChat;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use OCP\IURLGenerator;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Scoped anonymous retrieval for public chat turns.
 *
 * WHY ANONYMOUS AND NOT "THE CALLER". A public turn has no caller, but the
 * request that carries it may still hold a Nextcloud session (an admin
 * testing the website, a portal running in-process). Reading as that session
 * would let the website answer from whatever the admin may read. Every search
 * therefore runs inside OpenRegister's anonymous scope, so its anonymous read
 * rule is the ceiling, and the declared scope narrows it further. When this
 * OpenRegister cannot run a read as anonymous, nothing is read at all: the
 * turn answers from no sources rather than from the session's.
 *
 * @spec openspec/changes/delivery-public-web-chat/specs/public-chat-channel/spec.md#requirement-a-visitor-chats-without-signing-in-and-gets-answers-only-from-public-content-req-pubchat-002
 */
class PublicRetrievalService {

	/**
	 * The most sources one answer is drawn from.
	 */
	public const MAX_SOURCES = 5;

	/**
	 * The longest excerpt a source contributes to the prompt.
	 */
	public const MAX_EXCERPT = 1200;

	/**
	 * Fields read as a source's title, first match wins.
	 */
	private const TITLE_FIELDS = ['title', 'name', 'subject', 'label'];

	/**
	 * Fields read as a source's text, in order.
	 */
	private const TEXT_FIELDS = ['summary', 'description', 'content', 'text', 'body'];

	/**
	 * Fields read as a source's own public link.
	 */
	private const URL_FIELDS = ['url', 'link', 'href'];

	/**
	 * Constructor.
	 *
	 * @param ObjectService   $objectService OpenRegister objects, read as anonymous only.
	 * @param IURLGenerator   $urls          Builds a source's link when it carries none.
	 * @param LoggerInterface $logger        Records why nothing was read.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/delivery-public-web-chat/specs/public-chat-channel/spec.md#requirement-a-visitor-chats-without-signing-in-and-gets-answers-only-from-public-content-req-pubchat-002
	 */
	public function __construct(
		private readonly ObjectService $objectService,
		private readonly IURLGenerator $urls,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * A source scope as a list of distinct `{register, schema}` pairs.
	 *
	 * An entry without a register is dropped; an empty schema means every
	 * schema of that register.
	 *
	 * @param mixed $scope The declared or requested scope.
	 *
	 * @return list<array{register: string, schema: string}>
	 *
	 * @spec openspec/changes/delivery-public-web-chat/specs/public-chat-channel/spec.md#requirement-a-visitor-chats-without-signing-in-and-gets-answers-only-from-public-content-req-pubchat-002
	 */
	public static function normaliseScope(mixed $scope): array {
		$pairs = [];
		if (is_array($scope) === false) {
			return [];
		}

		foreach ($scope as $entry) {
			if (is_array($entry) === false) {
				continue;
			}

			$register = trim((string)($entry['register'] ?? ''));
			$schema   = trim((string)($entry['schema'] ?? ''));
			if ($register === '') {
				continue;
			}

			$pairs[$register . '/' . $schema] = ['register' => $register, 'schema' => $schema];
		}

		return array_values($pairs);
	}//end normaliseScope()

	/**
	 * The scope a turn may read: the channel's, narrowed by what the caller asked.
	 *
	 * A requested pair is kept only when the channel declares it, or declares
	 * its whole register. It never widens: a pair outside the channel's scope
	 * is dropped, and a whole register asked for under a channel that declares
	 * only some of its schemas yields only those schemas. Null asks for no
	 * narrowing; an empty list asks for nothing.
	 *
	 * @param mixed      $channelScope The channel's declared scope.
	 * @param array|null $requested    What the caller asked for, or null.
	 *
	 * @return list<array{register: string, schema: string}>
	 *
	 * @spec openspec/changes/delivery-public-web-chat/specs/public-chat-channel/spec.md#requirement-sibling-apps-reach-the-same-channel-in-process-without-an-identity-req-pubchat-004
	 */
	public static function intersectScope(mixed $channelScope, ?array $requested): array {
		$declared = self::normaliseScope(scope: $channelScope);
		if ($requested === null) {
			return $declared;
		}

		$kept = [];
		foreach (self::normaliseScope(scope: $requested) as $asked) {
			foreach ($declared as $allowed) {
				$pair = self::narrower(asked: $asked, allowed: $allowed);
				if ($pair !== null) {
					$kept[$pair['register'] . '/' . $pair['schema']] = $pair;
				}
			}
		}

		return array_values($kept);
	}//end intersectScope()

	/**
	 * The objects an anonymous caller may read that match the question, inside the scope.
	 *
	 * @param array<int, array{register: string, schema: string}> $scope        The scope, already intersected.
	 * @param string                                              $question     The visitor's question.
	 * @param string                                              $organisation The channel's organisation, '' for none.
	 * @param int                                                 $limit        The most sources returned.
	 *
	 * @return list<array{id: string, register: string, schema: string, title: string, url: string, excerpt: string}>
	 *
	 * @spec openspec/changes/delivery-public-web-chat/specs/public-chat-channel/spec.md#requirement-a-visitor-chats-without-signing-in-and-gets-answers-only-from-public-content-req-pubchat-002
	 */
	public function retrieve(array $scope, string $question, string $organisation='', int $limit=self::MAX_SOURCES): array {
		$question = trim($question);
		$limit    = max(1, min($limit, self::MAX_SOURCES));
		if ($question === '' || $scope === []) {
			return [];
		}

		$sources = [];
		foreach ($scope as $pair) {
			$schema = null;
			if ($pair['schema'] !== '') {
				$schema = $pair['schema'];
			}

			$rows = $this->anonymously(
				organisation: $organisation,
				operation: fn (): mixed => $this->objectService->searchObjectsPaginated(
					query: [
						'_search'   => $question,
						'_limit'    => $limit,
						'_register' => $pair['register'],
						'_schema'   => $schema,
					],
					_rbac: true,
					_multitenancy: true
				)
			);

			foreach ($this->rowsOf(result: $rows) as $row) {
				$source = $this->sourceOf(row: $row, pair: $pair);
				if ($source !== null) {
					$sources[$source['id']] = $source;
				}
			}
		}

		return array_slice(array_values($sources), 0, $limit);
	}//end retrieve()

	/**
	 * The narrower of a requested pair and a declared one, or null when they do not overlap.
	 *
	 * @param array{register: string, schema: string} $asked   The requested pair.
	 * @param array{register: string, schema: string} $allowed The declared pair.
	 *
	 * @return array{register: string, schema: string}|null
	 */
	private static function narrower(array $asked, array $allowed): ?array {
		if ($asked['register'] !== $allowed['register']) {
			return null;
		}

		if ($allowed['schema'] === '' || $asked['schema'] === $allowed['schema']) {
			return $asked;
		}

		if ($asked['schema'] === '') {
			return $allowed;
		}

		return null;
	}//end narrower()

	/**
	 * Run a read inside OpenRegister's anonymous scope, for the organisation when one is named.
	 *
	 * @param string   $organisation The organisation, '' for none.
	 * @param callable $operation    The read.
	 *
	 * @return mixed The read's result, or an empty list when no anonymous scope is available or the read failed.
	 */
	private function anonymously(string $organisation, callable $operation): mixed {
		try {
			// An OpenRegister without these primitives throws an Error here,
			// which lands in the catch: nothing is read as the session instead.
			if ($organisation !== '') {
				return $this->objectService->runForOrganisation($organisation, $operation);
			}

			return $this->objectService->runAsAnonymous($operation);
		} catch (Throwable $e) {
			$this->logger->warning(
				message: '[PublicRetrievalService] The public read could not run as anonymous, so the turn reads nothing',
				context: ['file' => __FILE__, 'line' => __LINE__, 'error' => $e->getMessage()]
			);
		}

		return [];
	}//end anonymously()

	/**
	 * The rows of a search result.
	 *
	 * @param mixed $result A paginated result, a plain list, or anything else.
	 *
	 * @return list<array<string, mixed>>
	 */
	private function rowsOf(mixed $result): array {
		if (is_array($result) === false) {
			return [];
		}

		$rows = [];
		foreach ((array)($result['results'] ?? $result) as $row) {
			if ($row instanceof ObjectEntity) {
				$row = array_merge(
					$row->getObject(),
					['id' => (string)$row->getUuid(), '@self' => ['schema' => (string)$row->getSchema()]]
				);
			}

			if (is_array($row) === true) {
				$rows[] = $row;
			}
		}

		return $rows;
	}//end rowsOf()

	/**
	 * One row as a source, or null when it is not one of the scope's.
	 *
	 * @param array<string, mixed>                    $row  The object.
	 * @param array{register: string, schema: string} $pair The scope pair it was searched in.
	 *
	 * @return array{id: string, register: string, schema: string, title: string, url: string, excerpt: string}|null
	 */
	private function sourceOf(array $row, array $pair): ?array {
		$id = (string)($row['id'] ?? $row['uuid'] ?? $row['@self']['id'] ?? '');
		if ($id === '') {
			return null;
		}

		$schema = $pair['schema'];
		if ($schema === '') {
			$schema = (string)($row['@self']['schema'] ?? '');
		}

		return [
			'id'       => $id,
			'register' => $pair['register'],
			'schema'   => $schema,
			'title'    => self::firstString(row: $row, fields: self::TITLE_FIELDS, fallback: $id),
			'url'      => $this->linkOf(row: $row, register: $pair['register'], schema: $schema, id: $id),
			'excerpt'  => self::excerptOf(row: $row),
		];
	}//end sourceOf()

	/**
	 * The first non-empty string among the fields.
	 *
	 * @param array<string, mixed> $row      The object.
	 * @param list<string>         $fields   The fields, in order.
	 * @param string               $fallback What to answer when none is set.
	 *
	 * @return string
	 */
	private static function firstString(array $row, array $fields, string $fallback): string {
		foreach ($fields as $field) {
			$value = $row[$field] ?? null;
			if (is_string($value) === true && trim($value) !== '') {
				return trim($value);
			}
		}

		return $fallback;
	}//end firstString()

	/**
	 * A source's link: its own public link when it has one, else OpenRegister's object address.
	 *
	 * @param array<string, mixed> $row      The object.
	 * @param string               $register The register.
	 * @param string               $schema   The schema.
	 * @param string               $id       The object id.
	 *
	 * @return string
	 */
	private function linkOf(array $row, string $register, string $schema, string $id): string {
		foreach (self::URL_FIELDS as $field) {
			$value = $row[$field] ?? null;
			if (is_string($value) === true && preg_match('#^https?://#i', $value) === 1) {
				return $value;
			}
		}

		return $this->urls->getAbsoluteURL(
			'/index.php/apps/openregister/api/objects/' . rawurlencode($register) . '/' . rawurlencode($schema) . '/' . rawurlencode($id)
		);
	}//end linkOf()

	/**
	 * The text a source contributes, at most MAX_EXCERPT characters.
	 *
	 * @param array<string, mixed> $row The object.
	 *
	 * @return string
	 */
	private static function excerptOf(array $row): string {
		$parts = [];
		foreach (self::TEXT_FIELDS as $field) {
			$value = $row[$field] ?? null;
			if (is_string($value) === true && trim($value) !== '') {
				$parts[] = trim(strip_tags($value));
			}
		}

		$text = preg_replace('/\s+/u', ' ', implode("\n", $parts)) ?? '';

		return mb_substr($text, 0, self::MAX_EXCERPT);
	}//end excerptOf()
}//end class
