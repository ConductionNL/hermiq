<?php

/**
 * Agent templates as a hermiq-owned shareable configuration type.
 *
 * An agent template shared through OpenRegister's federated-config engine used
 * to ride the generic schema-derived type, which writes the template's fields
 * straight into the register. That skips hermiq's own import path: a template
 * from elsewhere must land quarantined and content-scanned, because its system
 * prompt is as much a prompt-injection vector as a skill body. This type carries
 * each template's package string and installs it through
 * `AgentTemplateService::importPackage(source: 'hub')`, so a shared template
 * installs exactly as a hub-installed one does (gap G8 of
 * store-through-federated-config).
 *
 * Heavy hermiq services are resolved lazily: OpenRegister's dispatcher builds
 * this type on every registry load, and the template chain is only assembled
 * when a template is actually shared or installed.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\Hermiq\Service\Config
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service\Config;

use OCA\Hermiq\Service\AgentTemplateService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\Config\IShareableConfigType;
use OCP\IUserSession;
use Psr\Container\ContainerInterface;
use Throwable;

/**
 * Shares and installs hermiq agent templates through the federated-config engine.
 *
 * @spec openspec/changes/store-through-federated-config/specs/agent-template-github-store/spec.md
 */
class HermiqAgentTemplateShareableConfigType implements IShareableConfigType {

	/**
	 * The type id, the same one the schema marker used to derive, so existing shares keep their type.
	 *
	 * @var string
	 */
	public const TYPE_ID = 'hermiq.agent-template';

	/**
	 * The discovery topic of hermiq's published agent-template corpus.
	 *
	 * @var string
	 */
	public const TOPIC = 'hermiq-agent-template';

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container Resolves the template service lazily.
	 */
	public function __construct(
		private readonly ContainerInterface $container,
	) {

	}//end __construct()

	/**
	 * The type id.
	 *
	 * @return string The id.
	 *
	 * @spec openspec/changes/store-through-federated-config/specs/agent-template-github-store/spec.md
	 */
	public function getId(): string {
		return self::TYPE_ID;
	}//end getId()

	/**
	 * The display name.
	 *
	 * @return string The name.
	 *
	 * @spec openspec/changes/store-through-federated-config/specs/agent-template-github-store/spec.md
	 */
	public function getDisplayName(): string {
		return 'Agent template';
	}//end getDisplayName()

	/**
	 * The discovery topic, pinned to the published corpus so earlier repositories stay discoverable.
	 *
	 * @return string The topic.
	 *
	 * @spec openspec/changes/store-through-federated-config/specs/agent-template-github-store/spec.md
	 */
	public function getTopic(): string {
		return self::TOPIC;
	}//end getTopic()

	/**
	 * Package selected templates (or all) into a portable bundle.
	 *
	 * Each template is exported through hermiq's own serializer, which carries no
	 * secrets and no tenant data by construction.
	 *
	 * @param array $selection `{templateIds?: [...]}`; empty shares every template.
	 *
	 * @return array `{type, version, templates: [{name, package}]}`.
	 *
	 * @spec openspec/changes/store-through-federated-config/specs/agent-template-github-store/spec.md
	 */
	public function serialise(array $selection): array {
		$templates = $this->container->get(AgentTemplateService::class);
		$wanted    = array_map('strval', (array)($selection['templateIds'] ?? []));

		$out = [];
		foreach ($this->selectedIds(templates: $templates, wanted: $wanted) as $id) {
			$package = $templates->exportTemplate(templateId: $id);
			if ($package === null) {
				continue;
			}

			$out[] = ['name' => $this->nameOf(package: $package), 'package' => $package];
		}

		return [
			'type' => self::TYPE_ID,
			'version' => '1.0',
			'templates' => $out,
		];
	}//end serialise()

	/**
	 * Install a template bundle into this instance: every template lands quarantined and scanned.
	 *
	 * @param array $bundle A bundle produced by this type.
	 *
	 * @return array `{installed: [uuid, ...]}`.
	 *
	 * @spec openspec/changes/store-through-federated-config/specs/agent-template-github-store/spec.md
	 */
	public function deserialise(array $bundle): array {
		$templates = $this->container->get(AgentTemplateService::class);
		$uid       = $this->importerUid();

		$installed = [];
		foreach ($this->packagesOf(bundle: $bundle) as $package) {
			$saved       = $templates->importPackage(package: $package, source: 'hub', createdBy: $uid);
			$installed[] = (string)$saved->getUuid();
		}

		return ['installed' => $installed];
	}//end deserialise()

	/**
	 * The template ids a selection names, or every visible template's id for an empty one.
	 *
	 * An id that no longer resolves is skipped by the export rather than failing the
	 * whole bundle: a share is a snapshot of what exists now.
	 *
	 * @param AgentTemplateService $templates The template service.
	 * @param list<string>         $wanted    The requested ids, possibly empty.
	 *
	 * @return list<string>
	 */
	private function selectedIds(AgentTemplateService $templates, array $wanted): array {
		if ($wanted !== []) {
			return $wanted;
		}

		$ids = [];
		foreach ($templates->list() as $template) {
			if ($template instanceof ObjectEntity && (string)$template->getUuid() !== '') {
				$ids[] = (string)$template->getUuid();
			}
		}

		return $ids;
	}//end selectedIds()

	/**
	 * The template name a package carries, or empty when it carries none.
	 *
	 * @param string $package The JSON package.
	 *
	 * @return string
	 */
	private function nameOf(string $package): string {
		$decoded = json_decode($package, true);
		if (is_array($decoded) === false) {
			return '';
		}

		return (string)($decoded['name'] ?? '');
	}//end nameOf()

	/**
	 * The importing user's id, or empty when no user can be read.
	 *
	 * @return string
	 */
	private function importerUid(): string {
		try {
			$user = $this->container->get(IUserSession::class)->getUser();
		} catch (Throwable $e) {
			return '';
		}

		if ($user === null) {
			return '';
		}

		return (string)$user->getUID();
	}//end importerUid()

	/**
	 * The non-empty packages of a bundle's template entries, in order.
	 *
	 * @param array<string, mixed> $bundle The bundle.
	 *
	 * @return list<string>
	 */
	private function packagesOf(array $bundle): array {
		$packages = [];
		foreach ((array)($bundle['templates'] ?? []) as $template) {
			if (is_array($template) === true && (string)($template['package'] ?? '') !== '') {
				$packages[] = (string)$template['package'];
			}
		}

		return $packages;
	}//end packagesOf()
}//end class
