<?php

/**
 * Hermiq DepartmentControlController.
 *
 * Switches AI off or on for one department, next to the organisation
 * kill-switch's toggle. Only a Nextcloud administrator may switch; anyone
 * else gets 403 and the department's state stays as it was.
 *
 * @category Controller
 * @package  OCA\Hermiq\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/oversight-switch-ai-off-per-department/specs/human-approval-gate/spec.md#requirement-an-admin-shall-be-able-to-switch-ai-off-for-one-department
 */

declare(strict_types=1);

namespace OCA\Hermiq\Controller;

use OCA\Hermiq\Kernel\AppIdentity;
use OCA\Hermiq\Service\DepartmentEvidence;
use OCA\Hermiq\Service\DepartmentSwitch;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The per-department AI switch, administrators only.
 *
 * @spec openspec/changes/oversight-switch-ai-off-per-department/specs/human-approval-gate/spec.md#requirement-an-admin-shall-be-able-to-switch-ai-off-for-one-department
 */
class DepartmentControlController extends Controller {

	/**
	 * Constructor.
	 *
	 * @param IRequest                $request      The request.
	 * @param DepartmentSwitch        $departments  The department switches.
	 * @param IUserSession            $userSession  The signed-in user.
	 * @param IGroupManager           $groupManager Answers whether the user is an administrator.
	 * @param LoggerInterface         $logger       Logs a failed switch.
	 * @param DepartmentEvidence      $evidence     The department list and evidence file.
	 */
	public function __construct(
		IRequest $request,
		private readonly DepartmentSwitch $departments,
		private readonly IUserSession $userSession,
		private readonly IGroupManager $groupManager,
		private readonly LoggerInterface $logger,
		private readonly DepartmentEvidence $evidence,
	) {
		parent::__construct(appName: AppIdentity::APP_ID, request: $request);
	}//end __construct()

	/**
	 * Switch AI off or on for one department.
	 *
	 * @param string $group The department's group id.
	 *
	 * @return JSONResponse The department as stored; 401, 403, 404 or 500 otherwise.
	 *
	 * @spec openspec/changes/oversight-switch-ai-off-per-department/specs/human-approval-gate/spec.md#requirement-an-admin-shall-be-able-to-switch-ai-off-for-one-department
	 */
	public function toggle(string $group): JSONResponse {
		$refused = $this->refuseNonAdmin(message: 'Only an administrator can switch a department.');
		if ($refused !== null) {
			return $refused;
		}

		$user = $this->userSession->getUser();

		$off = filter_var($this->request->getParam('off'), FILTER_VALIDATE_BOOLEAN);
		$reason = trim((string)($this->request->getParam('reason') ?? ''));

		try {
			$department = $this->departments->switch(group: $group, off: $off, userId: (string)$user?->getUID(), reason: $reason);
		} catch (Throwable $e) {
			$this->logger->error('Hermiq department switch failed: ' . $e->getMessage(), ['exception' => $e]);
			return new JSONResponse(['error' => 'Switch failed'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}

		if ($department === null) {
			return new JSONResponse(['error' => 'This group is not marked as a department.'], Http::STATUS_NOT_FOUND);
		}

		return new JSONResponse($department);
	}//end toggle()

	/**
	 * The "Per afdeling" list: every marked department with its state, who
	 * switched it last and when, its agent count, last run and refused runs.
	 *
	 * @return JSONResponse The rows; 401, 403 or 500 otherwise.
	 *
	 * @spec openspec/changes/oversight-switch-ai-off-per-department/specs/human-approval-gate/spec.md#requirement-an-admin-shall-be-able-to-download-evidence-that-ai-was-off-for-a-department
	 */
	public function index(): JSONResponse {
		$refused = $this->refuseNonAdmin(message: 'Only an administrator can see the department switches.');
		if ($refused !== null) {
			return $refused;
		}

		try {
			return new JSONResponse(['results' => $this->evidence->overview()]);
		} catch (Throwable $e) {
			$this->logger->error('Hermiq department list failed: ' . $e->getMessage(), ['exception' => $e]);
			return new JSONResponse(['error' => 'Could not load the departments'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}//end index()

	/**
	 * Download the evidence file of one department: every switch event with
	 * who and when, and every run refused while it was off.
	 *
	 * @param string $group The department's group id.
	 *
	 * @return JSONResponse The evidence as an attachment; 401, 403, 404 or 500 otherwise.
	 *
	 * @spec openspec/changes/oversight-switch-ai-off-per-department/specs/human-approval-gate/spec.md#requirement-an-admin-shall-be-able-to-download-evidence-that-ai-was-off-for-a-department
	 */
	public function evidence(string $group): JSONResponse {
		$refused = $this->refuseNonAdmin(message: 'Only an administrator can download department evidence.');
		if ($refused !== null) {
			return $refused;
		}

		try {
			$evidence = $this->evidence->evidence(group: $group);
		} catch (Throwable $e) {
			$this->logger->error('Hermiq department evidence failed: ' . $e->getMessage(), ['exception' => $e]);
			return new JSONResponse(['error' => 'Could not produce the evidence'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}

		if ($evidence === null) {
			return new JSONResponse(['error' => 'This group is not marked as a department.'], Http::STATUS_NOT_FOUND);
		}

		$file = 'hermiq-department-' . preg_replace('/[^A-Za-z0-9_-]+/', '-', $group) . '-evidence.json';
		$response = new JSONResponse($evidence);
		$response->addHeader('Content-Disposition', 'attachment; filename="' . $file . '"');
		return $response;
	}//end evidence()

	/**
	 * A 401 or 403 answer unless the signed-in user is an administrator.
	 *
	 * @param string $message The refusal for a signed-in user who is not an administrator.
	 *
	 * @return JSONResponse|null Null when the user is an administrator.
	 */
	private function refuseNonAdmin(string $message): ?JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => 'Unauthenticated'], Http::STATUS_UNAUTHORIZED);
		}

		if ($this->groupManager->isAdmin($user->getUID()) === false) {
			return new JSONResponse(['error' => $message], Http::STATUS_FORBIDDEN);
		}

		return null;
	}//end refuseNonAdmin()
}//end class
