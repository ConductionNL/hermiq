<?php

/**
 * Hermiq OutsideToolSurface.
 *
 * What an AI agent outside this instance may see it could call. The tools are
 * declared by the app that owns the data, and hermiq publishes them: it ships no
 * tool of its own in this direction any more than in the inbound one (ADR-063,
 * gate 27). An app declares a tool reachable from outside by annotating its own
 * descriptor; hermiq reads that annotation and never writes one.
 *
 * Publishing is not permitting. What this surface offers is the set that exists;
 * whether a given registration may call one, and whether its principal may perform
 * the act, are two later gates that both have to open.
 *
 * @category Service
 * @package  OCA\Hermiq\Service\OutsideAgent
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
 * @spec openspec/changes/the-declared-tool-surface-and-the-prompt-library/specs/agent-tool-governance/spec.md#requirement-an-outside-agent-must-reach-declared-tools-through-a-registration
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service\OutsideAgent;

use OCA\OpenRegister\Service\Capability\ToolGrantResolver;
use OCA\OpenRegister\Service\Mcp\ToolRegistryFacade;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Publishes the tools an owning app declared reachable from outside.
 *
 * @spec openspec/changes/the-declared-tool-surface-and-the-prompt-library/specs/agent-tool-governance/spec.md#scenario-the-owning-app-declares-hermiq-publishes
 */
class OutsideToolSurface {

	/**
	 * The annotation an owning app sets on its own tool descriptor to declare that
	 * tool reachable by an agent outside the instance. hermiq reads it and never
	 * writes it: a publisher that could mark its own tools reachable would be
	 * deciding what it is only supposed to be passing on.
	 *
	 * @var string
	 */
	public const OUTSIDE_ANNOTATION = 'outsideAgent';

	/**
	 * hermiq's own tool namespace, which this surface never offers.
	 *
	 * @var string
	 */
	private const OWN_NAMESPACE = 'hermiq.';

	/**
	 * Constructor.
	 *
	 * @param ToolRegistryFacade $toolRegistryFacade OpenRegister's catalogue read surface.
	 * @param LoggerInterface $logger Logger.
	 */
	public function __construct(
		private readonly ToolRegistryFacade $toolRegistryFacade,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Every tool an owning app has declared reachable from outside, in the
	 * catalogue's own shape.
	 *
	 * @return array<int, array<string, mixed>> The descriptors.
	 *
	 * @spec openspec/changes/the-declared-tool-surface-and-the-prompt-library/specs/agent-tool-governance/spec.md#scenario-the-owning-app-declares-hermiq-publishes
	 */
	public function declaredTools(): array {
		try {
			$catalog = $this->toolRegistryFacade->listTools(toolWhitelist: []);
		} catch (Throwable $e) {
			$this->logger->warning(
				'Hermiq could not read the tool catalogue for the outside surface: ' . $e->getMessage(),
				['exception' => $e]
			);

			return [];
		}

		$offered = [];
		foreach ($catalog as $descriptor) {
			if (is_array($descriptor) === false) {
				continue;
			}

			$id = (string)($descriptor['name'] ?? ($descriptor['id'] ?? ''));
			if ($id === '' || str_starts_with($id, self::OWN_NAMESPACE) === true) {
				continue;
			}

			if ($this->declaredReachable(descriptor: $descriptor) === false) {
				continue;
			}

			$offered[] = $descriptor;
		}//end foreach

		return $offered;
	}//end declaredTools()

	/**
	 * The descriptor of one offered tool, or null when nothing offers it.
	 *
	 * @param string $toolId The tool id.
	 *
	 * @return array<string, mixed>|null The descriptor, or null.
	 *
	 * @spec openspec/changes/the-declared-tool-surface-and-the-prompt-library/specs/agent-tool-governance/spec.md#requirement-an-outside-agent-must-reach-declared-tools-through-a-registration
	 */
	public function describe(string $toolId): ?array {
		foreach ($this->declaredTools() as $descriptor) {
			if ((string)($descriptor['name'] ?? ($descriptor['id'] ?? '')) === $toolId) {
				return $descriptor;
			}
		}

		return null;
	}//end describe()

	/**
	 * Whether a tool writes, decided by OpenRegister's own classification rather
	 * than by a second rule here. Two rules for one question drift apart, and the
	 * one that drifts is the one nobody is looking at.
	 *
	 * @param string $toolId The tool id.
	 * @param array<string, mixed>|null $descriptor Its descriptor, when in hand.
	 *
	 * @return bool True when the tool writes or destroys.
	 *
	 * @spec openspec/changes/the-declared-tool-surface-and-the-prompt-library/specs/agent-tool-governance/spec.md#scenario-a-write-tool-is-denied-unless-granted
	 */
	public function writes(string $toolId, ?array $descriptor = null): bool {
		return ToolGrantResolver::isWriteOrDestructive(id: $toolId, descriptor: $descriptor);
	}//end writes()

	/**
	 * Whether the owning app declared this descriptor reachable from outside.
	 *
	 * @param array<string, mixed> $descriptor The tool descriptor.
	 *
	 * @return bool True when it was declared.
	 */
	private function declaredReachable(array $descriptor): bool {
		$annotations = ($descriptor['annotations'] ?? []);
		if (is_array($annotations) === false) {
			return false;
		}

		return (($annotations[self::OUTSIDE_ANNOTATION] ?? false) === true);
	}//end declaredReachable()
}//end class
