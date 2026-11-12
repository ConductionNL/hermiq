<?php

/**
 * Hermiq RetrievalSourceCollector.
 *
 * Turns retrieval rows into the sources and context text of one RAG turn, within
 * the turn's per-type include flags and source limits.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\Hermiq\Service\Engine
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/agent-engine-port/tasks.md#task-1-3
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service\Engine;

/**
 * Collects the sources of one retrieval run; one instance per run.
 *
 * The settings name, per source type (`file`, `object`), whether that type is
 * included and how many of it are kept. Rows of any other type are always kept.
 *
 * @psalm-type SourceSettings = array{include: array{file: mixed, object: mixed}, limit: array{file: mixed, object: mixed}}
 *
 * @spec openspec/changes/agent-engine-port/tasks.md#task-1-3
 */
class RetrievalSourceCollector {

	/**
	 * Sources kept so far, per limited type.
	 *
	 * @var array{file: int, object: int}
	 */
	private array $count = ['file' => 0, 'object' => 0];

	/**
	 * Fields tried in order for a source's display name.
	 *
	 * @var array<int, string>
	 */
	private const NAME_FIELDS = ['title', 'name', 'filename'];

	/**
	 * Metadata fields tried in order for a source's display name.
	 *
	 * @var array<int, string>
	 */
	private const METADATA_NAME_FIELDS = ['object_title', 'file_name', 'name', 'title'];

	/**
	 * Constructor.
	 *
	 * @param array $settings The include flags and limits per source type.
	 *
	 * @psalm-param SourceSettings $settings
	 *
	 * @return void
	 *
	 * @spec openspec/changes/agent-engine-port/tasks.md#task-1-3
	 */
	public function __construct(
		private readonly array $settings,
	) {
	}//end __construct()

	/**
	 * Collect the rows into sources, appending each to the context text.
	 *
	 * @param array $found `results` (the rows) and `text` (the context text so far).
	 *
	 * @return array `text`, `sources`, and the kept `fileSources` and `objectSources`.
	 *
	 * @psalm-return array{text: mixed, sources: list<array<string, mixed>>, fileSources: int, objectSources: int}
	 *
	 * @spec openspec/changes/agent-engine-port/tasks.md#task-1-3
	 */
	public function collect(array $found): array {
		$text = $found['text'];
		$sources = [];
		foreach ($found['results'] as $result) {
			$type = $this->limitedType(type: ($result['entity_type'] ?? ''));
			if ($type !== null && $this->isClosed(type: $type) === true) {
				continue;
			}

			$source = $this->source(result: $result);
			$sources[] = $source;
			$this->countSource(type: $type);
			$text .= $this->sourceText(source: $source, registerName: ($result['register_name'] ?? null));

			if ($this->isClosed(type: 'file') === true && $this->isClosed(type: 'object') === true) {
				break;
			}
		}

		return [
			'text' => $text,
			'sources' => $sources,
			'fileSources' => $this->count['file'],
			'objectSources' => $this->count['object'],
		];
	}//end collect()

	/**
	 * Count a kept source against its type's limit, when its type has one.
	 *
	 * @param string|null $type `file`, `object`, or null for an unlimited type.
	 *
	 * @return void
	 */
	private function countSource(?string $type): void {
		if ($type !== null) {
			$this->count[$type]++;
		}
	}//end countSource()

	/**
	 * A kept source's block in the context text: its source line, then its text.
	 *
	 * @param array<string, mixed> $source       The source.
	 * @param string|null          $registerName The register it came from, if any.
	 *
	 * @return string The block.
	 */
	private function sourceText(array $source, ?string $registerName): string {
		return $this->sourceLine(name: $source['name'], registerName: $registerName) . "{$source['text']}\n\n";
	}//end sourceText()

	/**
	 * The row's type when it is one with a limit (`file` or `object`), else null.
	 *
	 * @param mixed $type The row's entity type.
	 *
	 * @return string|null
	 *
	 * @psalm-return 'file'|'object'|null
	 *
	 * @spec openspec/changes/agent-engine-port/tasks.md#task-1-3
	 */
	private function limitedType(mixed $type): ?string {
		if ($type === 'file' || $type === 'object') {
			return $type;
		}

		return null;
	}//end limitedType()

	/**
	 * Whether a limited type is excluded or has reached its limit.
	 *
	 * @param string $type `file` or `object`.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/agent-engine-port/tasks.md#task-1-3
	 */
	private function isClosed(string $type): bool {
		return $this->settings['include'][$type] === false || $this->count[$type] >= $this->settings['limit'][$type];
	}//end isClosed()

	/**
	 * One source descriptor, with the fields its type carries.
	 *
	 * @param array $result The retrieval row.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/agent-engine-port/tasks.md#task-1-3
	 */
	private function source(array $result): array {
		$source = [
			'id' => $result['entity_id'] ?? null,
			'type' => $result['entity_type'] ?? 'unknown',
			'name' => $this->extractSourceName(result: $result),
			'similarity' => $result['similarity'] ?? $result['score'] ?? 1.0,
			'text' => $result['chunk_text'] ?? $result['text'] ?? '',
		];

		$metadata = $result['metadata'] ?? [];
		if (is_string($metadata) === true) {
			$metadata = json_decode($metadata, true) ?? [];
		}

		if ($source['type'] === 'object') {
			$source += $this->objectPointer(metadata: $metadata, registerName: ($result['register_name'] ?? null));
		}

		if ($source['type'] === 'file') {
			$source += $this->filePointer(metadata: $metadata, id: $source['id']);
		}

		return $source;
	}//end source()

	/**
	 * Where an object source lives: its uuid, register, schema and uri.
	 *
	 * @param mixed $metadata     The result's metadata (an array, or whatever it decoded to).
	 * @param mixed $registerName The result's register name, if any.
	 *
	 * @return array<string, mixed> The pointer fields.
	 */
	private function objectPointer(mixed $metadata, mixed $registerName): array {
		return [
			'uuid' => $metadata['uuid'] ?? null,
			'register' => $registerName ?? $metadata['register_id'] ?? $metadata['register'] ?? null,
			'schema' => $metadata['schema_id'] ?? $metadata['schema'] ?? null,
			'uri' => $metadata['uri'] ?? null,
		];
	}//end objectPointer()

	/**
	 * Where a file source lives: its file id (else the result's id), path and mime type.
	 *
	 * @param mixed $metadata The result's metadata (an array, or whatever it decoded to).
	 * @param mixed $id       The result's entity id.
	 *
	 * @return array<string, mixed> The pointer fields.
	 */
	private function filePointer(mixed $metadata, mixed $id): array {
		return [
			'file_id' => $metadata['file_id'] ?? $id,
			'file_path' => $metadata['file_path'] ?? null,
			'mime_type' => $metadata['mime_type'] ?? null,
		];
	}//end filePointer()

	/**
	 * One source line of the context text, naming the register when known.
	 *
	 * @param string      $name         The source name.
	 * @param string|null $registerName The register.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/agent-management-ui/spec.md#requirement-an-apps-agent-answers-from-the-apps-data-first-req-appag-003
	 */
	private function sourceLine(string $name, ?string $registerName): string {
		if ($registerName === null || $registerName === '') {
			return "Source: {$name}\n";
		}

		return "Source: {$name} (register: {$registerName})\n";
	}//end sourceLine()

	/**
	 * A human-readable name for a row: its own name fields, then its metadata's,
	 * then its type and the start of its id, then `Unknown Source`.
	 *
	 * @param array $result The retrieval row.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/agent-engine-port/tasks.md#task-1-3
	 */
	private function extractSourceName(array $result): string {
		$name = $this->firstFilled(values: $result, fields: self::NAME_FIELDS);
		if ($name !== null) {
			return $name;
		}

		$name = $this->metadataName(metadata: ($result['metadata'] ?? null));
		if ($name !== null) {
			return $name;
		}

		if (empty($result['entity_id']) === false) {
			$type = ucfirst($result['entity_type'] ?? 'Item');
			return $type . ' #' . substr((string)$result['entity_id'], 0, 8);
		}

		return 'Unknown Source';
	}//end extractSourceName()

	/**
	 * The first filled name field of a result's metadata (an array or its JSON), or null.
	 *
	 * @param mixed $metadata The result's metadata.
	 *
	 * @return string|null The name, or null.
	 */
	private function metadataName(mixed $metadata): ?string {
		if (empty($metadata) === true) {
			return null;
		}

		if (is_string($metadata) === true) {
			$metadata = json_decode($metadata, true);
		}

		if (is_array($metadata) === false) {
			return null;
		}

		return $this->firstFilled(values: $metadata, fields: self::METADATA_NAME_FIELDS);
	}//end metadataName()

	/**
	 * The first non-empty value among the fields, or null.
	 *
	 * @param array              $values The values.
	 * @param array<int, string> $fields The fields, in order.
	 *
	 * @return mixed
	 *
	 * @spec openspec/changes/agent-engine-port/tasks.md#task-1-3
	 */
	private function firstFilled(array $values, array $fields): mixed {
		foreach ($fields as $field) {
			if (empty($values[$field]) === false) {
				return $values[$field];
			}
		}

		return null;
	}//end firstFilled()
}//end class
