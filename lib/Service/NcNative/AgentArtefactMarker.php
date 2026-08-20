<?php

/**
 * Hermiq Agent Artefact Marker (ADR-088).
 *
 * Marks artefacts an agent created or modified as agent-authored, so a user
 * looking at the artefact in the surface they already use can tell it was not
 * written by a human.
 *
 * ADR-088 deliberately mandates the MARK, not a single mechanism, because no one
 * mechanism covers the object types involved:
 *
 * - **Files** (Notes are files) — a Nextcloud system tag, visible and filterable
 *   in the Files UI.
 * - **vCard / iCalendar objects** — an `X-` property on the object itself, since
 *   system tags do not apply to CardDAV/CalDAV objects. It travels with the
 *   object through sync and export, which a side table never would.
 *
 * Two rules this class exists to enforce, both easy to soften and neither of
 * which may be:
 *
 * 1. Marking happens in the SAME operation as the write. An artefact that exists
 *    unmarked even briefly is one a user can mistake for their own, and a
 *    background pass that fails leaves it that way permanently.
 * 2. A failed mark is a FAILED WRITE. `markFile()` throws rather than returning a
 *    boolean nobody checks — reporting success on an unmarked artefact produces
 *    the one outcome nothing downstream will ever re-examine.
 *
 * The mark is a HINT, not a guarantee: a user can remove a system tag, and an
 * `X-` property survives most but not all client round-trips. The authoritative
 * record is Hermiq's run trace; the mark is what makes that record discoverable
 * from the artefact.
 *
 * @category Service
 * @package  OCA\Hermiq\Service\NcNative
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service\NcNative;

use OCP\SystemTag\ISystemTagManager;
use OCP\SystemTag\ISystemTagObjectMapper;
use OCP\SystemTag\TagNotFoundException;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Applies the ADR-088 agent-authored mark to files and to vCard/iCalendar objects.
 *
 * @category Service
 * @package  OCA\Hermiq\Service\NcNative
 *
 * @spec openspec/specs/nc-native-tools/spec.md#requirement-per-object-idor-guard-on-every-provider
 */
class AgentArtefactMarker {

	/**
	 * The system tag applied to agent-authored files.
	 *
	 * Deliberately NOT translated. A tag name is an identifier here: translating
	 * it would fork the tag per UI language, so a Dutch session and an English
	 * session would tag the same artefact differently and neither filter would
	 * find both.
	 *
	 * @var string
	 */
	public const TAG_NAME = 'Agent authored';

	/**
	 * The `X-` property carrying the mark on vCard and iCalendar objects.
	 *
	 * @var string
	 */
	public const OBJECT_PROPERTY = 'X-HERMIQ-AGENT-AUTHORED';

	/**
	 * Nextcloud's object type for files in the system-tag object mapper.
	 *
	 * @var string
	 */
	private const OBJECT_TYPE_FILES = 'files';

	/**
	 * Constructor.
	 *
	 * @param ISystemTagManager $tagManager System tag resolution/creation.
	 * @param ISystemTagObjectMapper $tagMapper Assigns tags to objects.
	 * @param LoggerInterface $logger Diagnostics.
	 */
	public function __construct(
		private readonly ISystemTagManager $tagManager,
		private readonly ISystemTagObjectMapper $tagMapper,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * Mark a file as agent-authored.
	 *
	 * Throws on failure BY DESIGN (ADR-088 §5): the caller must fail the write
	 * rather than report success on an unmarked artefact. A boolean return would
	 * be checked by the first caller and forgotten by the third.
	 *
	 * @param int $fileId The file id to mark.
	 *
	 * @return void
	 *
	 * @throws ArtefactMarkingFailedException When the tag cannot be resolved,
	 *                                        created or assigned.
	 *
	 * @spec openspec/changes/nc-native-write-tools/specs/nc-native-tools/spec.md#requirement-every-object-an-agent-writes-is-marked-as-agent-authored
	 */
	public function markFile(int $fileId): void {
		try {
			$tagId = $this->resolveTagId();
			$this->tagMapper->assignTags((string)$fileId, self::OBJECT_TYPE_FILES, [$tagId]);
		} catch (Throwable $e) {
			$this->logger->warning(
				'Hermiq: could not apply the agent-authored tag to file {fileId}',
				['fileId' => $fileId, 'exception' => $e]
			);

			throw new ArtefactMarkingFailedException(
				'The artefact was written but could not be marked as agent-authored.',
				0,
				$e
			);
		}//end try

	}//end markFile()

	/**
	 * Whether a file already carries the agent-authored mark.
	 *
	 * Read-only probe used by tests and by callers that must not double-assign;
	 * never used to skip marking, since assigning an already-assigned tag is a
	 * no-op in Nextcloud.
	 *
	 * @param int $fileId The file id to check.
	 *
	 * @return bool True when the mark is present.
	 *
	 * @spec openspec/changes/nc-native-write-tools/specs/nc-native-tools/spec.md#requirement-every-object-an-agent-writes-is-marked-as-agent-authored
	 */
	public function isFileMarked(int $fileId): bool {
		try {
			$tagId = $this->resolveTagId();

			return $this->tagMapper->haveTag([(string)$fileId], self::OBJECT_TYPE_FILES, $tagId);
		} catch (Throwable) {
			return false;
		}

	}//end isFileMarked()

	/**
	 * The `X-` property line to embed in a vCard or iCalendar object.
	 *
	 * Returned as a line rather than applied here because the caller owns the
	 * serialised object and is the only place that can guarantee the property is
	 * written in the SAME operation as the object itself (ADR-088 §1).
	 *
	 * @param string $agentId The invoking agent's id, or an empty string when a
	 *                        run has no agent attributed.
	 *
	 * @return string The property value to store under `self::OBJECT_PROPERTY`.
	 *
	 * @spec openspec/changes/nc-native-write-tools/specs/nc-native-tools/spec.md#requirement-every-object-an-agent-writes-is-marked-as-agent-authored
	 */
	public function objectPropertyValue(string $agentId = ''): string {
		if (trim($agentId) === '') {
			return 'hermiq';
		}

		return 'hermiq:' . trim($agentId);

	}//end objectPropertyValue()

	/**
	 * Resolve the agent-authored tag id, creating the tag on first use.
	 *
	 * The tag is user-visible (the whole point is that a user can see it) but NOT
	 * user-assignable: a human hand-applying "Agent authored" to their own file
	 * would make the mark mean two different things.
	 *
	 * @return string The system tag id.
	 */
	private function resolveTagId(): string {
		try {
			return $this->tagManager->getTag(self::TAG_NAME, true, false)->getId();
		} catch (TagNotFoundException) {
			return $this->tagManager->createTag(self::TAG_NAME, true, false)->getId();
		}

	}//end resolveTagId()

}//end class
