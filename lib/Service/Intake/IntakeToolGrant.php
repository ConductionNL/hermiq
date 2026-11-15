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
 * @spec openspec/specs/conversational-intake/spec.md#requirement-an-intake-conversation-must-be-able-to-file-on-its-own-surface
 * @spec openspec/specs/conversational-intake/spec.md#requirement-an-intake-tool-is-recognised-by-its-mark-and-its-declared-create-taxonomy
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service\Intake;

use OCA\OpenRegister\Service\Capability\ToolGrantResolver;
use OCA\OpenRegister\Mcp\Service\Mcp\ToolRegistryFacade;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Resolves the create-only intake tools and the declared reviews.
 *
 * @spec openspec/specs/conversational-intake/spec.md#scenario-intake-cannot-touch-an-existing-record
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
	 * The one scope and the one action an intake tool must declare. A tool that
	 * reads or changes an existing record is not an intake tool, whatever it is
	 * annotated with.
	 *
	 * Read off the descriptor's declared `scope` and `action`, not off the last
	 * segment of the id: a curated tool such as `dossiq.fileCase` has a
	 * two-part id with no verb in it, and the id the catalogue hands over as
	 * `name` is the LLM-safe form (`dossiq_fileCase`), which has no segments at
	 * all (decision 177).
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
	 * The intake tools an owning app declared: annotated for intake, and declaring
	 * the create scope and action. The second test is not redundant with the first. An
	 * annotation is a claim, and a claim that a `case.update` tool is an intake
	 * tool is exactly the mistake this surface must not be able to make.
	 *
	 * @return array<int, array<string, mixed>> The descriptors.
	 *
	 * @spec openspec/specs/conversational-intake/spec.md#scenario-intake-cannot-touch-an-existing-record
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
	 * @spec openspec/specs/conversational-intake/spec.md#requirement-an-external-review-must-be-a-declared-tool-whose-verdict-is-recorded
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
	 * @spec openspec/specs/conversational-intake/spec.md#scenario-intake-cannot-touch-an-existing-record
	 */
	public function permits(string $toolId): bool {
		foreach (array_merge($this->intakeTools(), $this->reviewTools()) as $descriptor) {
			if ($toolId === self::idOf(descriptor: $descriptor)) {
				return true;
			}

			// The LLM-safe alias of the same tool routes back the same way.
			if ($toolId !== '' && $toolId === (string)($descriptor['name'] ?? '')) {
				return true;
			}
		}

		return false;
	}//end permits()

	/**
	 * The dotted id of a catalogue entry: its `mcpId` when the catalogue carries
	 * one (every provider-bridged tool), else its `name`, else its `id`.
	 *
	 * @param array<string, mixed> $descriptor The catalogue entry.
	 *
	 * @return string The id, or an empty string.
	 *
	 * @spec openspec/specs/conversational-intake/spec.md#requirement-an-intake-tool-is-recognised-by-its-mark-and-its-declared-create-taxonomy
	 */
	public static function idOf(array $descriptor): string {
		foreach (['mcpId', 'name', 'id'] as $key) {
			$value = ($descriptor[$key] ?? null);
			if (is_string($value) === true && $value !== '') {
				return $value;
			}
		}

		return '';
	}//end idOf()

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
	 * @spec openspec/specs/conversational-intake/spec.md#scenario-a-conversation-ends-in-a-filed-request
	 */
	public function call(string $toolId, array $arguments): array {
		if ($this->permits(toolId: $toolId) === false) {
			throw new IntakeRefusedException(
				toolId: $toolId,
				reason: 'the intake surface may only call the create-only intake tools and the reviews an owning app declared'
			);
		}

		return $this->outcome(envelope: $this->toolRegistryFacade->invokeTool(toolId: $toolId, arguments: $arguments));
	}//end call()

	/**
	 * The call's result and whether it is a refusal, read from the envelope and from inside the result.
	 *
	 * @param array<string, mixed> $envelope The facade's envelope.
	 *
	 * @return array{result: mixed, isError: bool}
	 */
	private function outcome(array $envelope): array {
		$result = ($envelope['result'] ?? []);

		// A refusal can arrive INSIDE the result. OpenRegister's provider bridge
		// catches what an attribute tool throws and returns it as
		// `{isError: true, message}`, and the facade then wraps that answer as a
		// successful call. Reading only the envelope's flag would record an
		// owning app's refusal as a filed request, and the citizen would never
		// reach a person.
		$isError = (($envelope['isError'] ?? false) === true)
			|| (is_array($result) === true && (($result['isError'] ?? false) === true));

		return [
			'result' => $result,
			'isError' => $isError,
		];
	}//end outcome()

	/**
	 * The catalogue entries carrying one annotation.
	 *
	 * @param string $annotation The annotation to look for.
	 * @param bool $createOnly Whether to keep only tools ending in the create verb.
	 *
	 * @return array<int, array<string, mixed>> The descriptors.
	 */
	private function annotated(string $annotation, bool $createOnly): array {
		$out = [];
		foreach ($this->catalogue() as $descriptor) {
			if ($this->carries(descriptor: $descriptor, annotation: $annotation) === false) {
				continue;
			}

			if ($createOnly === false || $this->admittedAsCreate(descriptor: $descriptor) === true) {
				$out[] = $descriptor;
			}
		}

		return $out;
	}//end annotated()

	/**
	 * The whole tool catalogue; an unreadable one is logged and is empty.
	 *
	 * @return array<int|string, mixed>
	 */
	private function catalogue(): array {
		try {
			return $this->toolRegistryFacade->listTools(toolWhitelist: []);
		} catch (Throwable $e) {
			$this->logger->warning(
				'Hermiq could not read the tool catalogue for the intake surface: ' . $e->getMessage(),
				['exception' => $e]
			);

			return [];
		}
	}//end catalogue()

	/**
	 * Whether a catalogue entry is a descriptor with an id that carries the annotation set to true.
	 *
	 * @param mixed  $descriptor One catalogue entry.
	 * @param string $annotation The annotation to look for.
	 *
	 * @return bool
	 *
	 * @phpstan-assert-if-true array<string, mixed> $descriptor
	 */
	private function carries(mixed $descriptor, string $annotation): bool {
		if (is_array($descriptor) === false) {
			return false;
		}

		$annotations = ($descriptor['annotations'] ?? []);
		if (is_array($annotations) === false || ($annotations[$annotation] ?? false) !== true) {
			return false;
		}

		return self::idOf(descriptor: $descriptor) !== '';
	}//end carries()

	/**
	 * Whether a marked intake tool only creates; one that does not is ignored with a warning.
	 *
	 * @param array<string, mixed> $descriptor The descriptor.
	 *
	 * @return bool
	 */
	private function admittedAsCreate(array $descriptor): bool {
		$id = self::idOf(descriptor: $descriptor);
		if ($this->createsOnly(toolId: $id, descriptor: $descriptor) === true) {
			return true;
		}

		$this->logger->warning(
			sprintf("Hermiq ignored '%s' on the intake surface: it is annotated for intake but it is not a create-only tool.", $id)
		);
		return false;
	}//end admittedAsCreate()

	/**
	 * Whether a tool only creates: it declares `scope: create` AND
	 * `action: create`, and OpenRegister's own classification agrees that it
	 * writes rather than reads.
	 *
	 * Both declarations are required, not either. `scope` is the CRUD verb the
	 * grant matrix reads; `action` is the free-form verb the owning app names.
	 * An app that marks a tool for intake and declares only one of them has not
	 * said, in both vocabularies, that the tool creates and does nothing else.
	 *
	 * @param string $toolId The tool id.
	 * @param array<string, mixed> $descriptor Its descriptor.
	 *
	 * @return bool True when the tool creates and nothing else.
	 *
	 * @spec openspec/specs/conversational-intake/spec.md#requirement-an-intake-tool-is-recognised-by-its-mark-and-its-declared-create-taxonomy
	 */
	private function createsOnly(string $toolId, array $descriptor): bool {
		if (($descriptor['scope'] ?? null) !== self::CREATE_VERB || ($descriptor['action'] ?? null) !== self::CREATE_VERB) {
			return false;
		}

		// OpenRegister's classification lets a declared scope outrank the hints,
		// so a tool that says `scope: create` AND `readOnlyHint: true` would pass
		// it. The two contradict each other, and a contradiction does not qualify.
		if (($descriptor['readOnlyHint'] ?? null) === true) {
			return false;
		}

		return ToolGrantResolver::isWriteOrDestructive(id: $toolId, descriptor: $descriptor);
	}//end createsOnly()
}//end class
