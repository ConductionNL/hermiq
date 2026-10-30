<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category  Service
 * @package   OCA\Hermiq\Service
 * @author    Conduction B.V. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 * @link      https://github.com/ConductionNL/hermiq
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service;

use OCA\OpenRegister\Service\ObjectService;
use OCP\IGroupManager;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Decides who may read the advisory Approvals about one subject.
 *
 * An advisory Approval is evidence about another app's record (a case, a
 * document). It is readable by whoever may open that record, by the members of
 * the AI-oversight group and by administrators, and by nobody else.
 *
 * The Approval schema's own read rule cannot say "whoever may open the
 * subject": the subject lives in another app's register and OpenRegister has no
 * rule that borrows another object's access. So the schema keeps advisory rows
 * to the oversight group and admins, and the subject route asks OpenRegister,
 * as the signed-in user, whether that user can read the subject.
 *
 * @spec openspec/specs/ai-oversight/spec.md
 */
class AiOversightAccess {

    /**
     * Nextcloud group whose members read every advisory Approval.
     *
     * The same id stands in the Approval schema's read rule in
     * hermiq_register.json; the two must stay equal.
     *
     * @var string
     */
    public const OVERSIGHT_GROUP = 'ai-oversight';

    /**
     * A subject resolved through OpenRegister must be an object uuid.
     *
     * A uuid is unique across registers, so a reader cannot borrow access to
     * an unrelated object that happens to share a short id with the subject.
     *
     * @var string
     */
    private const UUID_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';


    /**
     * Constructor.
     *
     * @param ObjectService   $objectService OpenRegister object service, used as the signed-in user.
     * @param IGroupManager   $groupManager  Admin and group membership.
     * @param LoggerInterface $logger        The logger.
     *
     * @return void
     */
    public function __construct(
        private readonly ObjectService $objectService,
        private readonly IGroupManager $groupManager,
        private readonly LoggerInterface $logger,
    ) {

    }//end __construct()


    /**
     * Whether the user holds the AI-oversight role or is an administrator.
     *
     * @param string $userId The user.
     *
     * @return boolean True for an admin or a member of the oversight group.
     *
     * @spec openspec/specs/ai-oversight/spec.md
     */
    public function hasOversightRole(string $userId): bool {
        if ($userId === '') {
            return false;
        }

        if ($this->groupManager->isAdmin($userId) === true) {
            return true;
        }

        return $this->groupManager->isInGroup($userId, self::OVERSIGHT_GROUP);

    }//end hasOversightRole()


    /**
     * Whether the signed-in user may read the advisory Approvals about a subject.
     *
     * Fails closed: a subject that is not an OpenRegister uuid, cannot be found,
     * or whose lookup throws, grants nothing to a user without the role.
     *
     * @param string $userId    The signed-in user.
     * @param string $subjectId The subject's id in its origin app.
     *
     * @return boolean True when the user may read them.
     *
     * @spec openspec/specs/ai-oversight/spec.md
     */
    public function maySeeSubject(string $userId, string $subjectId): bool {
        if ($this->hasOversightRole(userId: $userId) === true) {
            return true;
        }

        if ($userId === '' || preg_match(self::UUID_PATTERN, $subjectId) !== 1) {
            return false;
        }

        try {
            $subject = $this->objectService->find(
                id: $subjectId,
                _rbac: true,
                _multitenancy: true,
                _render: false,
                _audit: false
            );
        } catch (Throwable $e) {
            $this->logger->info(
                'AI oversight: subject not readable for user',
                ['subjectId' => $subjectId, 'userId' => $userId, 'error' => $e->getMessage()]
            );
            return false;
        }

        return $subject !== null;

    }//end maySeeSubject()


}//end class
