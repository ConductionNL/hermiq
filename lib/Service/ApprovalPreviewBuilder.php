<?php

/**
 * Hermiq ApprovalPreviewBuilder.
 *
 * Says what a held action will do before a reviewer decides on it: for a held
 * tool call the tool, whether it reads, changes, sends or deletes, how far it
 * reaches and its arguments as stored (after redaction); for a held run of a
 * schedule, flow or webhook the tools the agent may call, with the same labels.
 *
 * Derived, never stored: built from what the Approval already holds plus the
 * live tool catalog, read once per inbox load.
 *
 * @category Service
 * @package  OCA\Hermiq\Service
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
 * @spec openspec/specs/human-approval-gate/spec.md#requirement-a-reviewer-sees-what-a-held-action-will-do-req-apprev-001
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service;

use OCA\OpenRegister\Service\Capability\ToolGrantResolver;
use OCA\OpenRegister\Service\Capability\ToolReachResolver;
use OCA\OpenRegister\Service\Mcp\ToolRegistryFacade;
use OCA\OpenRegister\Service\ObjectService;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Builds the preview of a held action.
 *
 * @spec openspec/specs/human-approval-gate/spec.md#requirement-a-reviewer-sees-what-a-held-action-will-do-req-apprev-001
 */
class ApprovalPreviewBuilder {

	/**
	 * Approval source types that hold one tool call.
	 *
	 * @var array<int, string>
	 */
	private const TOOL_SOURCES = ['tool', 'toolcall', 'workspace-run'];

	/**
	 * A tool label Talk shows as plain text: letters, digits, spaces and
	 * `, ' ( ) -`, at most 80 characters. No `@`, no markdown, no dots, so no
	 * mention, link, autolinked domain or formatting can form.
	 *
	 * @var string
	 */
	private const TALK_SAFE_LABEL = '/^[\\p{L}\\p{M}\\p{N} ,\'’()\\-]{1,80}$/u';

	/**
	 * English reach labels for text that leaves the UI (the Talk line).
	 *
	 * @var array<string, string>
	 */
	private const REACH_TEXT = [
		'self' => 'only the agent itself',
		'user' => 'your own files and data',
		'instance' => 'everyone on this Nextcloud',
		'external' => 'outside this Nextcloud',
	];

	/**
	 * The catalog keyed by tool id, loaded once per builder instance.
	 *
	 * @var array<string, array<string, mixed>>|null
	 */
	private ?array $catalog = null;

	/**
	 * Constructor.
	 *
	 * @param ToolRegistryFacade $toolRegistry  OpenRegister's public tool catalog.
	 * @param ToolGrantResolver  $grantResolver Resolves an agent's grants against the catalog, as a run does.
	 * @param ObjectService      $objectService Reads the agent for a held run.
	 * @param LoggerInterface    $logger        PSR-3 logger.
	 */
	public function __construct(
		private readonly ToolRegistryFacade $toolRegistry,
		private readonly ToolGrantResolver $grantResolver,
		private readonly ObjectService $objectService,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The preview of one Approval's data.
	 *
	 * @param array<string, mixed> $approval The Approval object data.
	 *
	 * @return array{kind: string, heldBecause: string, tools: array<int, array<string, mixed>>}
	 *
	 * @spec openspec/specs/human-approval-gate/spec.md#requirement-a-reviewer-sees-what-a-held-action-will-do-req-apprev-001
	 */
	public function build(array $approval): array {
		$sourceType = (string)($approval['sourceType'] ?? 'schedule');

		if (in_array($sourceType, self::TOOL_SOURCES, true) === true) {
			$toolId = (string)($approval['toolId'] ?? '');
			$arguments = $approval['toolArguments'] ?? null;
			if (is_array($arguments) === false) {
				$arguments = null;
			}

			$tools = [];
			if ($toolId !== '') {
				$tools[] = $this->describeTool(id: $toolId, arguments: $arguments);
			}

			return ['kind' => 'toolcall', 'heldBecause' => 'This tool needs a reviewer before the agent may call it.', 'tools' => $tools];
		}

		$tools = [];
		foreach ($this->grantedTools(agentId: (string)($approval['agentId'] ?? '')) as $id) {
			$tools[] = $this->describeTool(id: $id, arguments: null);
		}

		return ['kind' => 'run', 'heldBecause' => 'This run needs a reviewer before it starts.', 'tools' => $tools];
	}//end build()

	/**
	 * One line for a text message: the tool and how far it reaches.
	 *
	 * @param string $toolId The tool id.
	 *
	 * @return string E.g. "The tool files.delete deletes, and reaches your own files and data."
	 *
	 * @spec openspec/specs/human-approval-gate/spec.md#requirement-the-talk-request-names-the-tool-and-its-reach-req-apprev-002
	 */
	public function toolLine(string $toolId): string {
		$tool = $this->describeTool(id: $toolId, arguments: null);

		return sprintf(
			'The tool %s %s, and reaches %s.',
			$this->talkLabel(tool: $tool),
			$tool['effect'],
			(self::REACH_TEXT[$tool['reach']] ?? 'an unknown reach')
		);
	}//end toolLine()

	/**
	 * The tool's name as it may appear in a Talk message.
	 *
	 * Talk reads mentions, links and markdown in message text, and a tool's title
	 * and name are free text from whoever registered the tool. A label is used only
	 * when it holds letters, digits, spaces and plain punctuation; otherwise the
	 * message names the tool by its id.
	 *
	 * @param array{id: string, name: string, title?: string} $tool The described tool.
	 *
	 * @return string The title, the name, or the id.
	 *
	 * @spec openspec/specs/human-approval-gate/spec.md#requirement-the-talk-request-names-the-tool-and-its-reach-req-apprev-002
	 */
	private function talkLabel(array $tool): string {
		foreach ([($tool['title'] ?? ''), $tool['name']] as $label) {
			$label = trim((string)preg_replace('/[\s\p{C}]+/u', ' ', $label));
			if ($label !== '' && preg_match(self::TALK_SAFE_LABEL, $label) === 1) {
				return $label;
			}
		}

		return $tool['id'];
	}//end talkLabel()

	/**
	 * Describe one tool from its catalog descriptor.
	 *
	 * @param string                    $id        The tool id.
	 * @param array<string, mixed>|null $arguments The stored (redacted) arguments, or null.
	 *
	 * @return array{id: string, name: string, effect: string, reach: string, title?: string, arguments?: array<string, mixed>}
	 */
	private function describeTool(string $id, ?array $arguments): array {
		$descriptor = ($this->catalog()[$id] ?? null);

		$tool = [
			'id' => $id,
			'name' => (string)($descriptor['name'] ?? $id),
			'effect' => $this->effect(descriptor: $descriptor),
			'reach' => $this->reach(id: $id, descriptor: $descriptor),
		];

		$title = $this->titleOf(descriptor: $descriptor);
		if ($title !== null) {
			$tool['title'] = $title;
		}

		if ($arguments !== null) {
			$tool['arguments'] = $arguments;
		}

		return $tool;
	}//end describeTool()

	/**
	 * The human title a catalogue descriptor gives its tool: MCP's top-level
	 * `title`, else `annotations.title`; null when neither is a non-blank string.
	 *
	 * @param array<string, mixed>|null $descriptor The catalog descriptor.
	 *
	 * @return string|null The title.
	 *
	 * @spec openspec/specs/human-approval-gate/spec.md#requirement-a-reviewer-sees-what-a-held-action-will-do-req-apprev-001
	 */
	private function titleOf(?array $descriptor): ?string {
		foreach ([($descriptor['title'] ?? null), ($descriptor['annotations']['title'] ?? null)] as $candidate) {
			if (is_string($candidate) === true && trim($candidate) !== '') {
				return trim($candidate);
			}
		}

		return null;
	}//end titleOf()

	/**
	 * What a tool does: reads, deletes, sends or changes.
	 *
	 * An unknown tool is described as changing, never as reading: a reviewer
	 * told "reads" about a tool nobody can see would be told the safe answer.
	 *
	 * @param array<string, mixed>|null $descriptor The catalog descriptor.
	 *
	 * @return string One of reads, deletes, sends, changes.
	 */
	private function effect(?array $descriptor): string {
		if ($descriptor === null) {
			return 'changes';
		}

		$scope = (string)($descriptor['scope'] ?? '');
		if (($descriptor['readOnlyHint'] ?? false) === true || $scope === 'read') {
			return 'reads';
		}

		if ($scope === 'delete') {
			return 'deletes';
		}

		if (($descriptor['reach'] ?? '') === 'external') {
			return 'sends';
		}

		return 'changes';
	}//end effect()

	/**
	 * How far a tool reaches: the descriptor's own declaration, else OpenRegister's resolver.
	 *
	 * @param string                    $id         The tool id.
	 * @param array<string, mixed>|null $descriptor The catalog descriptor.
	 *
	 * @return string self, user, instance, external or unknown.
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) ToolReachResolver::resolve() is
	 *   OpenRegister's stateless reach classifier; the tool oversight matrix calls it
	 *   the same way, so the preview and the matrix read one rule set.
	 */
	private function reach(string $id, ?array $descriptor): string {
		$declared = (string)($descriptor[ToolReachResolver::REACH_KEY] ?? '');
		if ($declared !== '') {
			return $declared;
		}

		try {
			return ToolReachResolver::resolve(toolId: $id, descriptor: $descriptor);
		} catch (Throwable $e) {
			$this->logger->debug('[ApprovalPreviewBuilder] reach unresolved for ' . $id . ': ' . $e->getMessage());
			return 'unknown';
		}
	}//end reach()

	/**
	 * The tools the agent's grants resolve to, as a run would resolve them.
	 *
	 * @param string $agentId The agent UUID.
	 *
	 * @return array<int, string> Tool ids.
	 */
	private function grantedTools(string $agentId): array {
		if ($agentId === '') {
			return [];
		}

		try {
			$agent = $this->objectService->find(id: $agentId, register: 'hermiq', schema: 'agent', _rbac: false, _multitenancy: false);
		} catch (Throwable $e) {
			return [];
		}

		if ($agent === null) {
			return [];
		}

		$grants = [];
		foreach ((array)($agent->getObject()['tools'] ?? []) as $grant) {
			if (is_string($grant) === true && $grant !== '') {
				$grants[] = $grant;
			}
		}

		if ($grants === []) {
			return [];
		}

		return array_values($this->grantResolver->resolve(grants: $grants, catalog: array_values($this->catalog())));
	}//end grantedTools()

	/**
	 * The catalog keyed by tool id (mcpId, else name), read once.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function catalog(): array {
		if ($this->catalog !== null) {
			return $this->catalog;
		}

		$this->catalog = [];
		try {
			foreach ($this->toolRegistry->listTools() as $descriptor) {
				if (is_array($descriptor) === false) {
					continue;
				}

				foreach (['mcpId', 'id', 'name'] as $key) {
					$id = ($descriptor[$key] ?? null);
					if (is_string($id) === true && $id !== '' && isset($this->catalog[$id]) === false) {
						$this->catalog[$id] = $descriptor;
					}
				}
			}
		} catch (Throwable $e) {
			$this->logger->warning('[ApprovalPreviewBuilder] tool catalog unavailable: ' . $e->getMessage());
		}

		return $this->catalog;
	}//end catalog()
}//end class
