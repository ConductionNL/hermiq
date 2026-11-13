<?php

/**
 * Hermiq Validated Tag Settings Controller.
 *
 * The admin surface for the tag that marks a document as validated
 * (memory-retrieval-scope, D2): read the setting, name an existing tag, or create
 * the restricted "Validated" tag and set which groups may assign it. Admin only.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Controller
 * @package  OCA\Hermiq\Controller\Settings
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/memory-retrieval-scope/specs/retrieval-scope/spec.md#requirement-validated-documents-carry-a-restricted-nextcloud-tag-req-rscope-002
 */

declare(strict_types=1);

namespace OCA\Hermiq\Controller\Settings;

use InvalidArgumentException;
use OCA\Hermiq\Kernel\AppIdentity;
use OCA\Hermiq\Kernel\Contracts\ValidatedTagSettingsInterface;
use OCA\Hermiq\Settings\AdminSettings;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Read, choose and create the validated tag.
 *
 * @spec openspec/changes/memory-retrieval-scope/specs/retrieval-scope/spec.md#requirement-validated-documents-carry-a-restricted-nextcloud-tag-req-rscope-002
 */
class ValidatedTagSettingsController extends Controller {

	/**
	 * Constructor.
	 *
	 * @param IRequest                      $request  The request.
	 * @param ValidatedTagSettingsInterface $settings The setting.
	 * @param LoggerInterface               $logger   Logger.
	 */
	public function __construct(
		IRequest $request,
		private readonly ValidatedTagSettingsInterface $settings,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: AppIdentity::APP_ID, request: $request);
	}//end __construct()

	/**
	 * The validated tag, its name and the groups that may assign it.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/memory-retrieval-scope/specs/retrieval-scope/spec.md#requirement-validated-documents-carry-a-restricted-nextcloud-tag-req-rscope-002
	 */
	#[AuthorizedAdminSetting(AdminSettings::class)]
	public function get(): JSONResponse {
		return $this->run(call: fn (): array => $this->settings->all(), failure: 'Failed to read the validated tag setting');
	}//end get()

	/**
	 * Name an existing tag (`tagId`), optionally setting `groups`.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/memory-retrieval-scope/specs/retrieval-scope/spec.md#requirement-validated-documents-carry-a-restricted-nextcloud-tag-req-rscope-002
	 */
	#[AuthorizedAdminSetting(AdminSettings::class)]
	public function update(): JSONResponse {
		$tagId = $this->request->getParam('tagId');
		if (is_scalar($tagId) === false || trim((string)$tagId) === '') {
			return new JSONResponse(['error' => 'Choose a tag.'], Http::STATUS_UNPROCESSABLE_ENTITY);
		}

		return $this->run(
			call: fn (): array => $this->settings->choose(tagId: (string)$tagId, groups: $this->groupsParam()),
			failure: 'Failed to save the validated tag setting'
		);
	}//end update()

	/**
	 * Create the restricted tag (`name`, default "Validated") assignable by `groups`, and name it.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/memory-retrieval-scope/specs/retrieval-scope/spec.md#requirement-validated-documents-carry-a-restricted-nextcloud-tag-req-rscope-002
	 */
	#[AuthorizedAdminSetting(AdminSettings::class)]
	public function create(): JSONResponse {
		$name = $this->request->getParam('name', 'Validated');
		if (is_string($name) === false) {
			$name = '';
		}

		return $this->run(
			call: fn (): array => $this->settings->create(name: $name, groups: ($this->groupsParam() ?? [])),
			failure: 'Failed to create the validated tag'
		);
	}//end create()

	/**
	 * Run a settings call: its answer, 422 on a refused value, 500 on a failure.
	 *
	 * @param callable(): array $call    The call.
	 * @param string            $failure The message for an unexpected failure.
	 *
	 * @return JSONResponse
	 */
	private function run(callable $call, string $failure): JSONResponse {
		try {
			return new JSONResponse($call());
		} catch (InvalidArgumentException $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_UNPROCESSABLE_ENTITY);
		} catch (Throwable $e) {
			$this->logger->error(
				message: '[ValidatedTagSettingsController] ' . $failure,
				context: ['file' => __FILE__, 'line' => __LINE__, 'error' => $e->getMessage()]
			);
			return new JSONResponse(['error' => $failure], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}//end run()

	/**
	 * The `groups` parameter as a list, or null when not given.
	 *
	 * @return list<string>|null
	 */
	private function groupsParam(): ?array {
		$groups = $this->request->getParam('groups');
		if (is_array($groups) === false) {
			return null;
		}

		return array_values(array_filter($groups, 'is_string'));
	}//end groupsParam()
}//end class
