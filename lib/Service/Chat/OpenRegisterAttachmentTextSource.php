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
 * @spec openspec/changes/chat-attachments-and-images/specs/chat-attachments/spec.md#requirement-a-model-without-the-capability-gets-the-text-and-the-person-is-told-req-catt-005
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service\Chat;

use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Reads an attachment's text through OpenRegister's FileTextFacade, as the person.
 *
 * @spec openspec/changes/chat-attachments-and-images/specs/chat-attachments/spec.md#requirement-a-model-without-the-capability-gets-the-text-and-the-person-is-told-req-catt-005
 */
class OpenRegisterAttachmentTextSource implements AttachmentTextSource {

	/**
	 * OpenRegister's published facade (openregister `file-text-facade`).
	 */
	public const FACADE_CLASS = 'OCA\\OpenRegister\\Files\\Service\\FileTextFacade';

	/**
	 * The resolved facade; false once resolution failed, null before it was tried.
	 *
	 * @var object|false|null
	 */
	private object|false|null $facade = null;

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container   Resolves the facade at call time.
	 * @param LoggerInterface    $logger      Notes a facade that could not be resolved or failed.
	 * @param string             $facadeClass The facade class; tests point it at a stub.
	 *
	 * @spec openspec/changes/chat-attachments-and-images/specs/chat-attachments/spec.md#requirement-a-model-without-the-capability-gets-the-text-and-the-person-is-told-req-catt-005
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
	 * @spec openspec/changes/chat-attachments-and-images/specs/chat-attachments/spec.md#requirement-a-model-without-the-capability-gets-the-text-and-the-person-is-told-req-catt-005
	 */
	public function isAvailable(): bool {
		$facade = $this->facade();
		if ($facade === null || method_exists($facade, 'extractText') === false) {
			return false;
		}

		try {
			return method_exists($facade, 'isAvailable') === false || $facade->isAvailable() === true;
		} catch (Throwable) {
			return false;
		}
	}//end isAvailable()

	/**
	 * The file's text in the person's own Files; null when the facade gives none.
	 *
	 * @param int    $fileId The file id.
	 * @param string $userId The uid whose Files the file is read from.
	 *
	 * @return string|null The text, or null.
	 *
	 * @spec openspec/changes/chat-attachments-and-images/specs/chat-attachments/spec.md#requirement-a-model-without-the-capability-gets-the-text-and-the-person-is-told-req-catt-005
	 */
	public function extractText(int $fileId, string $userId): ?string {
		if ($this->isAvailable() === false) {
			return null;
		}

		try {
			$text = $this->facade()?->extractText($fileId, $userId);
		} catch (Throwable $e) {
			$this->logger->info(
				message: '[OpenRegisterAttachmentTextSource] the file text facade failed',
				context: ['fileId' => $fileId, 'error' => $e->getMessage()]
			);
			return null;
		}

		if (is_string($text) === false || trim($text) === '') {
			return null;
		}

		return $text;
	}//end extractText()

	/**
	 * The facade, resolved once.
	 *
	 * @return object|null The facade, or null when OpenRegister has none.
	 */
	private function facade(): ?object {
		if ($this->facade === null) {
			$this->facade = $this->resolve();
		}

		if ($this->facade === false) {
			return null;
		}

		return $this->facade;
	}//end facade()

	/**
	 * Resolve the facade behind a class probe.
	 *
	 * @return object|false The facade, or false.
	 */
	private function resolve(): object|false {
		if (class_exists($this->facadeClass) === false) {
			return false;
		}

		try {
			$facade = $this->container->get($this->facadeClass);
		} catch (Throwable $e) {
			$this->logger->info(
				message: '[OpenRegisterAttachmentTextSource] the OpenRegister file text facade could not be resolved',
				context: ['error' => $e->getMessage()]
			);
			return false;
		}

		if (is_object($facade) === false) {
			return false;
		}

		return $facade;
	}//end resolve()
}//end class
