<?php

/**
 * Hermiq IntakeConclusion.
 *
 * How a conversation is to end: the proposed classification, the request types the
 * owning app declares, its create-only intake tool, and when. Built by IntakeService
 * from its conclude() call and never handed out of it.
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
 * @spec openspec/changes/a-conversational-intake-that-files-for-the-citizen/specs/conversational-intake/spec.md#scenario-an-uncertain-intake-does-not-guess
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service\Intake;

use DateTimeImmutable;

/**
 * One request to conclude an intake conversation.
 *
 * @spec openspec/changes/a-conversational-intake-that-files-for-the-citizen/specs/conversational-intake/spec.md#requirement-a-classification-must-carry-a-confidence-and-must-be-able-to-abstain
 */
final class IntakeConclusion {

	/**
	 * The owning app's declared create-only intake tool.
	 *
	 * @var string
	 */
	public string $intakeTool = '';

	/**
	 * When the conversation concluded.
	 *
	 * @var DateTimeImmutable
	 */
	public DateTimeImmutable $at;

	/**
	 * Propose the conclusion.
	 *
	 * @param array<string, mixed> $classification The proposal: `type`, `confidence` and the catalogue it came from.
	 * @param array<string, mixed> $catalogue      The request types the owning app declares.
	 */
	public function __construct(
		public readonly array $classification,
		public readonly array $catalogue,
	) {
	}//end __construct()

	/**
	 * The proposed request type, trimmed.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/a-conversational-intake-that-files-for-the-citizen/specs/conversational-intake/spec.md#requirement-a-classification-must-carry-a-confidence-and-must-be-able-to-abstain
	 */
	public function type(): string {
		return trim((string)($this->classification['type'] ?? ''));
	}//end type()

	/**
	 * The proposal's confidence (0.0 when absent).
	 *
	 * @return float
	 *
	 * @spec openspec/changes/a-conversational-intake-that-files-for-the-citizen/specs/conversational-intake/spec.md#requirement-a-classification-must-carry-a-confidence-and-must-be-able-to-abstain
	 */
	public function confidence(): float {
		return (float)($this->classification['confidence'] ?? 0.0);
	}//end confidence()

	/**
	 * The declared request types, as strings.
	 *
	 * @return array<int, string>
	 *
	 * @spec openspec/changes/a-conversational-intake-that-files-for-the-citizen/specs/conversational-intake/spec.md#scenario-an-uncertain-intake-does-not-guess
	 */
	public function catalogueTypes(): array {
		return array_values(array_map('strval', $this->catalogue));
	}//end catalogueTypes()

	/**
	 * Whether the proposed type is one the catalogue declares.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/a-conversational-intake-that-files-for-the-citizen/specs/conversational-intake/spec.md#scenario-an-uncertain-intake-does-not-guess
	 */
	public function inCatalogue(): bool {
		return ($this->type() !== '' && in_array($this->type(), array_map('strval', $this->catalogue), true));
	}//end inCatalogue()
}//end class
