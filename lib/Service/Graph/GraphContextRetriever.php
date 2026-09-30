<?php

/**
 * Hermiq GraphContextRetriever.
 *
 * The `graph` value of an agent's ragSearchMode. It matches seed entities in the
 * query, walks their bounded visible neighbourhood through GraphService, reads each
 * visible node's record LIVE as the session user (objects with RBAC, files from the
 * user's own folder, mail through the mail read service), and returns rows in the
 * superset shape ContextRetrievalHandler already formats, plus a compact
 * `Relations:` block. Traversal starts from named seeds, never from a scan.
 *
 * Returns null when there is nothing to add (no session user, no visible seed), so
 * the caller degrades to keyword retrieval. A conversation node contributes its
 * label only: its transcript is read through the session tools, not pasted in.
 *
 * @category Service
 * @package  OCA\Hermiq\Service\Graph
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
 * @spec openspec/changes/knowledge-graph/specs/knowledge-graph/spec.md#requirement-graph-traversal-is-available-to-context-assembly
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service\Graph;

use OCA\Hermiq\Service\NcNative\MailReadService;
use OCA\OpenRegister\Service\ObjectService;
use OCP\Files\File;
use OCP\Files\IRootFolder;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Graph-mode context retrieval.
 *
 * @spec openspec/changes/knowledge-graph/specs/knowledge-graph/spec.md#requirement-graph-traversal-is-available-to-context-assembly
 */
class GraphContextRetriever {

	/**
	 * The most characters one record contributes.
	 */
	private const MAX_TEXT = 2000;

	/**
	 * The largest file read into context, in bytes.
	 */
	private const MAX_FILE_BYTES = 1048576;

	/**
	 * The most seeds matched in one query.
	 */
	private const MAX_SEEDS = 5;

	/**
	 * Constructor.
	 *
	 * @param GraphService $graph The graph.
	 * @param ObjectService $objectService OpenRegister's object service.
	 * @param IRootFolder $rootFolder The root folder.
	 * @param MailReadService $mail The mail read service.
	 * @param IUserSession $session The user session (the acting user).
	 * @param LoggerInterface $logger The logger.
	 */
	public function __construct(
		private readonly GraphService $graph,
		private readonly ObjectService $objectService,
		private readonly IRootFolder $rootFolder,
		private readonly MailReadService $mail,
		private readonly IUserSession $session,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The graph context for a query, or null when the graph has nothing visible to add.
	 *
	 * @param string $query The user's query.
	 * @param int $limit The most records to hydrate.
	 *
	 * @return array{results: array<int, array<string, mixed>>, relations: string}|null
	 *
	 * @spec openspec/changes/knowledge-graph/specs/knowledge-graph/spec.md#requirement-graph-traversal-is-available-to-context-assembly
	 */
	public function retrieve(string $query, int $limit): ?array {
		$uid = (string)$this->session->getUser()?->getUID();
		$seeds = [];
		if ($uid !== '') {
			$seeds = $this->graph->seeds(query: $query, actingUserId: $uid, limit: self::MAX_SEEDS);
		}

		if ($seeds === []) {
			return null;
		}

		$nodes = [];
		$edges = [];
		foreach ($seeds as $seed) {
			$nodes[$seed['uuid']] = $seed;
			$walk = $this->graph->neighbors(entityUuid: (string)$seed['uuid'], actingUserId: $uid, depth: 2);
			$nodes = $nodes + $walk['nodes'];
			foreach ($walk['edges'] as $edge) {
				$edges[$edge['uuid']] = $edge;
			}
		}

		$results = [];
		foreach ($nodes as $node) {
			if (count($results) >= $limit) {
				break;
			}

			$results[] = $this->row(node: $node, uid: $uid);
		}

		return ['results' => $results, 'relations' => $this->relations(nodes: $nodes, edges: $edges)];

	}//end retrieve()

	/**
	 * One node as a retrieval row, its text read live from its record.
	 *
	 * @param array<string, mixed> $node The node view.
	 * @param string $uid The acting user.
	 *
	 * @return array<string, mixed> The row.
	 */
	private function row(array $node, string $uid): array {
		$ref = (array)$node['sourceRef'];
		$metadata = ['name' => (string)$node['label'], 'graphEntity' => (string)$node['uuid']] + $ref;
		$row = [
			'entity_id' => (string)$node['uuid'],
			'entity_type' => (string)$node['sourceType'],
			'text' => (string)$node['label'],
			'score' => (float)$node['confidence'],
			'metadata' => $metadata,
		];

		try {
			$live = match ((string)$node['sourceType']) {
				'object' => $this->objectText(ref: $ref),
				'file' => $this->fileText(ref: $ref, uid: $uid),
				'mail' => $this->mailText(ref: $ref, uid: $uid),
				default => null,
			};
		} catch (Throwable $e) {
			$this->logger->debug('Hermiq graph: a record could not be read into context', ['exception' => $e]);
			$live = null;
		}

		if ($live !== null && $live !== '') {
			$row['text'] = mb_substr($live, 0, self::MAX_TEXT);
		}

		if ($row['entity_type'] === 'object') {
			$row['entity_id'] = (string)($ref['uuid'] ?? $row['entity_id']);
		}

		return $row;

	}//end row()

	/**
	 * An object's current data, found with RBAC as the session user.
	 *
	 * @param array<string, mixed> $ref The pointer.
	 *
	 * @return string|null The data as JSON.
	 */
	private function objectText(array $ref): ?string {
		$object = $this->objectService->find(
			id: (string)($ref['uuid'] ?? ''),
			register: (string)($ref['register'] ?? ''),
			schema: (string)($ref['schema'] ?? '')
		);
		if ($object === null) {
			return null;
		}

		$data = $object->getObject();
		unset($data['@self']);

		return (string)json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

	}//end objectText()

	/**
	 * A small file's current content, from the user's own folder.
	 *
	 * @param array<string, mixed> $ref The pointer.
	 * @param string $uid The acting user.
	 *
	 * @return string|null The content.
	 */
	private function fileText(array $ref, string $uid): ?string {
		$file = $this->rootFolder->getUserFolder($uid)->getFirstNodeById((int)($ref['fileId'] ?? 0));
		if (($file instanceof File) === false || $file->getSize() > self::MAX_FILE_BYTES) {
			return null;
		}

		return $file->getContent();

	}//end fileText()

	/**
	 * A mail message's current plain body, through the mail read service.
	 *
	 * @param array<string, mixed> $ref The pointer.
	 * @param string $uid The acting user.
	 *
	 * @return string|null The body.
	 */
	private function mailText(array $ref, string $uid): ?string {
		$message = $this->mail->readMessage(uid: $uid, arguments: ['id' => (int)($ref['messageId'] ?? 0)]);
		if (isset($message['error']) === true) {
			return null;
		}

		return trim((string)($message['subject'] ?? '') . "\n" . (string)($message['body'] ?? ''));

	}//end mailText()

	/**
	 * The visible relations as one compact block, one `from -[predicate]-> to` per line.
	 *
	 * @param array<string, array<string, mixed>> $nodes Node views by uuid.
	 * @param array<string, array<string, mixed>> $edges Edge views by uuid.
	 *
	 * @return string The block, or an empty string when there are no relations.
	 */
	private function relations(array $nodes, array $edges): string {
		$lines = [];
		foreach ($edges as $edge) {
			$from = ($nodes[$edge['from']]['label'] ?? null);
			$to = ($nodes[$edge['to']]['label'] ?? null);
			if ($from !== null && $to !== null) {
				$lines[] = $from . ' -[' . $edge['predicate'] . ']-> ' . $to;
			}
		}

		if ($lines === []) {
			return '';
		}

		return "Relations:\n" . implode("\n", $lines) . "\n\n";

	}//end relations()
}//end class
