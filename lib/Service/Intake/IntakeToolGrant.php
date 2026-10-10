<?php

/**
 * Hermiq IntakeToolGrant.
 *
 * What the intake surface is allowed to call, which is deliberately almost nothing:
 * the create-only tools the owning app declares for intake, scoped to the record
 * types it declares, and a declared external review.
 *
 * The narrowness is the design. `case-assistant-surface` is tool-free because a chat
 * box on a case must not be able to act on the case; intake has the opposite problem,
 * because a citizen who has no record yet needs one filed. The answer is not to widen
 * the assistant but to give intake its own surface with a grant that can create and
 * cannot read or change anything that already exists.
 *
 * hermiq creates nothing itself. It calls the owning app's declared intake tool, and
 * the owning app validates and creates through its own path. A second write path in
 * hermiq would be a second set of rules about what a valid case is, and the second
 * one is always the one that is wrong.
 *
 * @category Service
 * @package  OCA\Hermiq\Service\Intake
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
 * @spec openspec/changes/a-conversational-intake-that-files-for-the-citizen/specs/conversational-intake/spec.md#requirement-an-intake-conversation-must-be-able-to-file-on-its-own-surface
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service\Intake;

use OCA\OpenRegister\Service\Capability\ToolGrantResolver;
use OCA\OpenRegister\Service\Mcp\ToolRegistryFacade;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Resolves the create-only intake tools and the declared reviews.
 *
 * @spec openspec/changes/a-conversational-intake-that-files-for-the-citizen/specs/conversational-intake/spec.md#scenario-intake-cannot-touch-an-existing-record
 */
class IntakeToolGrant {

	/**
	 * The annotation an owning app sets on a tool descriptor to declare it usable
	 * by the conversational intake. hermiq reads it and never writes one.
	 *
	 * @var string
	 */
	public const INTAKE_ANNOTATION = 'citizenIntake';

	/**
	 * The annotation an owning app sets to declare a tool an external review: a
	 * verdict somebody else forms, which hermiq carries and records.
	 *
	 * @var string
	 */
	public const REVIEW_ANNOTATION = 'externalReview';

	/**
	 * The one verb an intake tool may end in. A tool that reads or changes an
	 * existing record is not an intake tool, whatever it is annotated with.
	 *
	 * @var string
	 */
	private const CREATE_VERB = 'create';

	/**
	 * Constructor.
	 *
	 * @param ToolRegistryFacade $toolRegistryFacade OpenRegister's catalogue and invoke surface.
	 * @param LoggerInterface $logger Logger.
	 */
	public function __construct(
		private readonly ToolRegistryFacade $toolRegistryFacade,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The intake tools an owning app declared: annotated for intake, and ending in
	 * the create verb. The second test is not redundant with the first. An
	 * annotation is a claim, and a claim that a `case.update` tool is an intake
	 * tool is exactly the mistake this surface must not be able to make.
	 *
	 * @return array<int, array<string, mixed>> The descriptors.
	 *
	 * @spec openspec/changes/a-conversational-intake-that-files-for-the-citizen/specs/conversational-intake/spec.md#scenario-intake-cannot-touch-an-existing-record
	 */
	public function intakeTools(): array {
		return $this->annotated(annotation: self::INTAKE_ANNOTATION, createOnly: true);
	}//end intakeTools()

	/**
	 * The external reviews an owning app declared. A review is asked a question and
	 * answers it; it is not required to be a create tool, and hermiq forms no
	 * opinion of its own about what it reviews.
	 *
	 * @return array<int, array<string, mixed>> The descriptors.
	 *
	 * @spec openspec/changes/a-conversational-intake-that-files-for-the-citizen/specs/conversational-intake/spec.md#requirement-an-external-review-must-be-a-declared-tool-whose-verdict-is-recorded
	 */
	public function reviewTools(): array {
		return $this->annotated(annotation: self::REVIEW_ANNOTATION, createOnly: false);
	}//end reviewTools()

	/**
	 * Whether the intake surface may call one tool id.
	 *
	 * @param string $toolId The tool id.
	 *
	 * @return bool True when it is a declared intake or review tool.
	 *
	 * @spec openspec/changes/a-conversational-intake-that-files-for-the-citizen/specs/conversational-intake/spec.md#scenario-intake-cannot-touch-an-existing-record
	 */
	public function permits(string $toolId): bool {
		foreach (array_merge($this->intakeTools(), $this->reviewTools()) as $descriptor) {
			if ((string)($descriptor['name'] ?? ($descriptor['id'] ?? '')) === $toolId) {
				return true;
			}
		}

		return false;
	}//end permits()

	/**
	 * Call one tool on behalf of an intake conversation, refusing anything the
	 * grant does not cover.
	 *
	 * @param string $toolId The tool to call.
	 * @param array<string, mixed> $arguments The arguments, passed through untouched.
	 *
	 * @return array{result: mixed, isError: bool} The owning app's own response.
	 *
	 * @throws IntakeRefusedException When the grant does not cover this tool.
	 *
	 * @spec openspec/changes/a-conversational-intake-that-files-for-the-citizen/specs/conversational-intake/spec.md#scenario-a-conversation-ends-in-a-filed-request
	 */
	public function call(string $toolId, array $arguments): array {
		if ($this->permits(toolId: $toolId) === false) {
			throw new IntakeRefusedException(
				toolId: $toolId,
				reason: 'the intake surface may only call the create-only intake tools and the reviews an owning app declared'
			);
		}

		$envelope = $this->toolRegistryFacade->invokeTool(toolId: $toolId, arguments: $arguments);

		return [
			'result' => ($envelope['result'] ?? []),
			'isError' => (($envelope['isError'] ?? false) === true),
		];

	}//end call()

	/**
	 * The catalogue entries carrying one annotation.
	 *
	 * @param string $annotation The annotation to look for.
	 * @param bool $createOnly Whether to keep only tools ending in the create verb.
	 *
	 * @return array<int, array<string, mixed>> The descriptors.
	 */
	private function annotated(string $annotation, bool $createOnly): array {
		try {
			$catalog = $this->toolRegistryFacade->listTools(toolWhitelist: []);
		} catch (Throwable $e) {
			$this->logger->warning(
				'Hermiq could not read the tool catalogue for the intake surface: ' . $e->getMessage(),
				['exception' => $e]
			);

			return [];
		}

		$out = [];
		foreach ($catalog as $descriptor) {
			if (is_array($descriptor) === false) {
				continue;
			}

			$annotations = ($descriptor['annotations'] ?? []);
			if (is_array($annotations) === false || ($annotations[$annotation] ?? false) !== true) {
				continue;
			}

			$id = (string)($descriptor['name'] ?? ($descriptor['id'] ?? ''));
			if ($id === '') {
				continue;
			}

			if ($createOnly === true && $this->createsOnly(toolId: $id, descriptor: $descriptor) === false) {
				$this->logger->warning(
					sprintf(
						"Hermiq ignored '%s' on the intake surface: it is annotated for intake but it is not a create-only tool.",
						$id
					)
				);
				continue;
			}

			$out[] = $descriptor;
		}//end foreach

		return $out;
	}//end annotated()

	/**
	 * Whether a tool only creates: its id ends in the create verb, and
	 * OpenRegister's own classification agrees that it writes rather than reads.
	 *
	 * @param string $toolId The tool id.
	 * @param array<string, mixed> $descriptor Its descriptor.
	 *
	 * @return bool True when the tool creates and nothing else.
	 */
	private function createsOnly(string $toolId, array $descriptor): bool {
		$parts = explode('.', $toolId);

		if (end($parts) !== self::CREATE_VERB) {
			return false;
		}

		return ToolGrantResolver::isWriteOrDestructive(id: $toolId, descriptor: $descriptor);
	}//end createsOnly()
}//end class
