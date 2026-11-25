<?php

/**
 * Hermiq OpenRegisterAttachmentTextSource.
 *
 * The AttachmentTextSource over OpenRegister's public FileTextFacade: a PDF or
 * office file a person attached is turned into text in their own Files, with
 * nothing indexed or stored. The facade is resolved lazily behind a
 * class_exists() probe, so on an OpenRegister without it the source reports
 * itself unavailable and the reader keeps its "could not read" notice.
 *
 * @category Service
 * @package  OCA\Hermiq\Service\Chat
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
 * @spec openspec/specs/chat-attachments/spec.md#requirement-a-model-without-the-capability-gets-the-text-and-the-person-is-told-req-catt-005
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service\Chat;

use OCA\Hermiq\Service\Engine\VectorFacadeLocator;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Reads an attachment's text through OpenRegister's FileTextFacade, as the person.
 *
 * @spec openspec/specs/chat-attachments/spec.md#requirement-a-model-without-the-capability-gets-the-text-and-the-person-is-told-req-catt-005
 */
class OpenRegisterAttachmentTextSource implements AttachmentTextSource {

	/**
	 * OpenRegister's published facade (openregister `file-text-facade`).
	 */
	public const FACADE_CLASS = 'OCA\\OpenRegister\\Files\\Service\\FileTextFacade';

	/**
	 * Finds the facade once, behind a class probe and the container; built on first use.
	 *
	 * @var VectorFacadeLocator|null
	 */
	private ?VectorFacadeLocator $locator = null;

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container   Resolves the facade at call time.
	 * @param LoggerInterface    $logger      Notes a facade that could not be resolved or failed.
	 * @param string             $facadeClass The facade class; tests point it at a stub.
	 *
	 * @spec openspec/specs/chat-attachments/spec.md#requirement-a-model-without-the-capability-gets-the-text-and-the-person-is-told-req-catt-005
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
		private readonly string $facadeClass = self::FACADE_CLASS,
	) {
	}//end __construct()

	/**
	 * Whether OpenRegister publishes the facade and it says it can answer.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/chat-attachments/spec.md#requirement-a-model-without-the-capability-gets-the-text-and-the-person-is-told-req-catt-005
	 */
	public function isAvailable(): bool {
		return $this->readyFacade() !== null;
	}//end isAvailable()

	/**
	 * The file's text in the person's own Files; null when the facade gives none.
	 *
	 * @param int    $fileId The file id.
	 * @param string $userId The uid whose Files the file is read from.
	 *
	 * @return string|null The text, or null.
	 *
	 * @spec openspec/specs/chat-attachments/spec.md#requirement-a-model-without-the-capability-gets-the-text-and-the-person-is-told-req-catt-005
	 */
	public function extractText(int $fileId, string $userId): ?string {
		$facade = $this->readyFacade();
		$text   = null;
		if ($facade !== null) {
			try {
				$text = $facade->extractText($fileId, $userId);
			} catch (Throwable $e) {
				$this->logger->info(
					message: '[OpenRegisterAttachmentTextSource] the file text facade failed',
					context: ['fileId' => $fileId, 'error' => $e->getMessage()]
				);
			}
		}

		if (is_string($text) === false || trim($text) === '') {
			return null;
		}

		return $text;
	}//end extractText()

	/**
	 * The facade when it exists, can extract and reports itself available.
	 *
	 * @return object|null The facade, or null.
	 */
	private function readyFacade(): ?object {
		$facade = $this->locator()->facade();
		if ($facade === null || method_exists($facade, 'extractText') === false) {
			return null;
		}

		if ($this->locator()->hasBackend(facade: $facade) === false) {
			return null;
		}

		return $facade;
	}//end readyFacade()

	/**
	 * The locator, generic over OpenRegister facades: a class probe, one container
	 * lookup, and an isAvailable() read that never throws.
	 *
	 * @return VectorFacadeLocator
	 */
	private function locator(): VectorFacadeLocator {
		if ($this->locator === null) {
			$this->locator = new VectorFacadeLocator(
				container: $this->container,
				logger: $this->logger,
				facadeClass: $this->facadeClass,
				caller: 'AttachmentText'
			);
		}

		return $this->locator;
	}//end locator()
}//end class
