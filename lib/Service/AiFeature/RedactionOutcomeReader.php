<?php

/**
 * Hermiq RedactionOutcomeReader.
 *
 * Reads filinq's redaction outcome for one document, and nothing else. hermiq does
 * not redact: decision D13 puts redaction in filinq, which already owns the client,
 * and a second redactor in the fleet is a second thing to be wrong.
 *
 * The outcome is filinq's own `anonymizationLink` object in the `filinq` register,
 * keyed by the source file id and carrying the status, the anonymised file and when
 * it was produced. It is read rather than asked for: this class never triggers a
 * redaction and never has an opinion about what should have been removed.
 *
 * It fails closed. When filinq is absent, when the register cannot be read, or when
 * no link exists for the document, the outcome is "not available", and the caller
 * refuses every feature that requires redaction. Detection is not redaction, and an
 * unreadable filinq is not a redacted document.
 *
 * @category Service
 * @package  OCA\Hermiq\Service\AiFeature
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
 * @spec openspec/specs/woo-llm-anonymisation/spec.md#requirement-hermiq-must-not-redact-and-must-not-treat-detection-as-redaction
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service\AiFeature;

use OCA\Hermiq\Support\FleetAppId;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use OCP\App\IAppManager;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Reads filinq's redaction outcome for a document reference.
 *
 * @spec openspec/specs/woo-llm-anonymisation/spec.md#requirement-a-feature-may-require-that-a-document-was-redacted-before-it-is-read
 */
class RedactionOutcomeReader {

	/**
	 * The canonical fleet name of the app that owns redaction.
	 *
	 * @var string
	 */
	public const REDACTION_APP = 'filinq';

	/**
	 * filinq's register holding the anonymisation links.
	 *
	 * @var string
	 */
	private const REGISTER_SLUG = 'filinq';

	/**
	 * filinq's schema for the link between a document and its redacted output.
	 *
	 * @var string
	 */
	private const SCHEMA_SLUG = 'anonymizationLink';

	/**
	 * The statuses that mean the document was actually redacted. Anything else,
	 * including a link that exists but failed or is still running, is not a
	 * redaction.
	 *
	 * @var array<int, string>
	 */
	private const REDACTED_STATUSES = ['completed', 'success', 'anonymized', 'anonymised', 'done'];

	/**
	 * Constructor.
	 *
	 * @param IAppManager $appManager Resolves whether filinq is installed, under either id.
	 * @param ObjectService $objectService OpenRegister read path.
	 * @param LoggerInterface $logger Logger.
	 */
	public function __construct(
		private readonly IAppManager $appManager,
		private readonly ObjectService $objectService,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Whether the app that owns redaction is present at all, under either of its
	 * ids. When it is not, every feature requiring redaction is refused.
	 *
	 * @return bool True when filinq is installed.
	 *
	 * @spec openspec/specs/woo-llm-anonymisation/spec.md#requirement-a-missing-redaction-client-must-fail-closed
	 */
	public function clientAvailable(): bool {
		return FleetAppId::isInstalled(appManager: $this->appManager, canonical: self::REDACTION_APP);
	}//end clientAvailable()

	/**
	 * Read the redaction outcome for one document reference.
	 *
	 * @param string $documentReference The document's Nextcloud file id, as a string.
	 *
	 * @return array{available: bool, redacted: bool, status: string, redactedAt: string, reason: string}
	 *         What filinq says, or why nothing could be said.
	 *
	 * @spec openspec/specs/woo-llm-anonymisation/spec.md#requirement-a-feature-may-require-that-a-document-was-redacted-before-it-is-read
	 */
	public function outcomeFor(string $documentReference): array {
		if ($this->clientAvailable() === false) {
			return $this->unavailable(reason: 'the redaction client (filinq) is not installed on this instance');
		}

		if (trim($documentReference) === '') {
			return $this->unavailable(reason: 'no document reference was given');
		}

		try {
			$objects = $this->objectService
				->setRegister(self::REGISTER_SLUG)
				->setSchema(self::SCHEMA_SLUG)
				->findAll(config: ['filters' => ['sourceFileId' => $documentReference], 'limit' => 50]);
		} catch (Throwable $e) {
			$this->logger->warning(
				'Hermiq could not read filinq\'s redaction outcome: ' . $e->getMessage(),
				['exception' => $e, 'documentReference' => $documentReference]
			);

			return $this->unavailable(reason: 'the redaction outcome could not be read from ' . self::REDACTION_APP);
		}

		return $this->outcomeAmong(objects: $objects, documentReference: $documentReference);

	}//end outcomeFor()

	/**
	 * The outcome of the first link for the document, or "none recorded".
	 *
	 * @param iterable $objects The links read for the document.
	 * @param string $documentReference The document's Nextcloud file id, as a string.
	 *
	 * @return array{available: bool, redacted: bool, status: string, redactedAt: string, reason: string}
	 */
	private function outcomeAmong(iterable $objects, string $documentReference): array {
		foreach ($objects as $object) {
			if (($object instanceof ObjectEntity) === false) {
				continue;
			}

			$data = $object->getObject();
			if ((string)($data['sourceFileId'] ?? '') === trim($documentReference)) {
				return $this->linkOutcome(data: $data);
			}
		}

		return [
			'available' => true,
			'redacted' => false,
			'status' => 'none',
			'redactedAt' => '',
			'reason' => 'no redaction has been recorded for this document',
		];
	}//end outcomeAmong()

	/**
	 * What one anonymisation link says: its status, and whether that status is a
	 * redaction.
	 *
	 * @param array<string, mixed> $data The link's data.
	 *
	 * @return array{available: bool, redacted: bool, status: string, redactedAt: string, reason: string}
	 */
	private function linkOutcome(array $data): array {
		$status = strtolower(trim((string)($data['status'] ?? '')));

		return [
			'available' => true,
			'redacted' => in_array($status, self::REDACTED_STATUSES, true),
			'status' => $status,
			'redactedAt' => (string)($data['anonymizedAt'] ?? ''),
			'reason' => '',
		];
	}//end linkOutcome()

	/**
	 * The shape returned when nothing can be said about a document, which is never
	 * the same as saying it is safe.
	 *
	 * @param string $reason Why nothing could be said.
	 *
	 * @return array{available: bool, redacted: bool, status: string, redactedAt: string, reason: string} The outcome.
	 */
	private function unavailable(string $reason): array {
		return [
			'available' => false,
			'redacted' => false,
			'status' => 'unavailable',
			'redactedAt' => '',
			'reason' => $reason,
		];

	}//end unavailable()
}//end class
