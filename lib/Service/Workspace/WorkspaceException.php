<?php

/**
 * Hermiq WorkspaceException.
 *
 * A refusal or failure of a governed workspace tool, carrying the stable error
 * code of the tool contract (`openspec/changes/hermiq-runner-git-capability/contract.md`,
 * Error Codes). The message is written for the model and never carries a
 * credential, a filesystem path or an internal host name.
 *
 * @category Service
 * @package  OCA\Hermiq\Service\Workspace
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
 * @spec openspec/changes/hermiq-runner-git-capability/specs/agent-workspace-git-tools/spec.md#requirement-each-run-gets-a-bounded-workspace-on-the-governed-side-that-the-model-cannot-address-by-path
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service\Workspace;

use RuntimeException;

/**
 * A workspace tool refusal with a stable contract error code.
 *
 * @spec openspec/changes/hermiq-runner-git-capability/specs/agent-workspace-git-tools/spec.md#requirement-each-run-gets-a-bounded-workspace-on-the-governed-side-that-the-model-cannot-address-by-path
 */
class WorkspaceException extends RuntimeException {

	public const TOKEN_INVALID = 'token_invalid';

	public const WORKSPACE_ABSENT = 'workspace_absent';

	public const WORKSPACE_MISMATCH = 'workspace_mismatch';

	public const WORKSPACE_QUOTA = 'workspace_quota';

	public const PATH_OUTSIDE_WORKSPACE = 'path_outside_workspace';

	public const PATH_FORBIDDEN = 'path_forbidden';

	public const PATCH_REJECTED = 'patch_rejected';

	public const INVALID_ARGUMENT = 'invalid_argument';

	public const EGRESS_DENIED = 'egress_denied';

	public const OWNER_UNRESOLVABLE = 'owner_unresolvable';

	public const APPROVAL_REQUIRED = 'approval_required';

	public const APPROVAL_DENIED = 'approval_denied';

	public const CREDENTIAL_SCOPE_REFUSED = 'credential_scope_refused';

	public const PUSH_REJECTED = 'push_rejected';

	public const TOOL_TIMEOUT = 'tool_timeout';

	public const GIT_FAILED = 'git_failed';

	/**
	 * Build a refusal.
	 *
	 * @param string $errorCode The stable contract error code.
	 * @param string $message   The sentence the model reads (no path, host or secret).
	 */
	public function __construct(
		private readonly string $errorCode,
		string $message,
	) {
		parent::__construct(message: $message);
	}//end __construct()

	/**
	 * The stable contract error code.
	 *
	 * @return string The code.
	 *
	 * @spec openspec/changes/hermiq-runner-git-capability/specs/agent-workspace-git-tools/spec.md#requirement-each-run-gets-a-bounded-workspace-on-the-governed-side-that-the-model-cannot-address-by-path
	 */
	public function getErrorCode(): string {
		return $this->errorCode;
	}//end getErrorCode()
}//end class
