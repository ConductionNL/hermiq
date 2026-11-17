<?php

/**
 * Test stub for OpenRegister LeafDescriptor.
 *
 * Stands in for OCA\OpenRegister\Service\Integration\LeafDescriptor when
 * OpenRegister is not installed (standalone CI). Mirrors the real value object's
 * kind constants, constructor signature, and the accessors Hermiq's
 * RegisterAgentLeafListener test asserts on. The real class ships with
 * OpenRegister (lib/Service/Integration/LeafDescriptor.php, ADR-066).
 *
 * @category Test
 * @package  OCA\OpenRegister\Service\Integration
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Integration;

/**
 * Minimal LeafDescriptor stub for standalone unit runs.
 */
final class LeafDescriptor {

	public const KIND_RENDER_SURFACE = 'render-surface';

	public const KIND_DATA_PROVIDER = 'data-provider';

	public const KIND_AGENT_RUNNER = 'agent-runner';

	public const VALID_KINDS = [
		self::KIND_RENDER_SURFACE,
		self::KIND_DATA_PROVIDER,
		self::KIND_AGENT_RUNNER,
	];

	public const VALID_SURFACES = [
		'user-dashboard',
		'app-dashboard',
		'detail-page',
		'single-entity',
	];

	/*
	 * The load-strategy constants mirror openregister's `LeafDescriptor`
	 * (openregister#3956), where they live next to `LeafRendering::$loadStrategy`.
	 * A stub may mirror the real class or lag it. It may never LEAD it: if
	 * OpenRegister renames one, this file must follow.
	 */

	public const LOADS_VIA_SHARED_ENTRY = 'shared-entry';

	public const LOADS_VIA_OWN_SCRIPT = 'own-script';

	public const LOADS_ALREADY_PRESENT = 'already-present';

	public const VALID_LOAD_STRATEGIES = [
		self::LOADS_VIA_SHARED_ENTRY,
		self::LOADS_VIA_OWN_SCRIPT,
		self::LOADS_ALREADY_PRESENT,
	];

	public const RENDER_MODE_COMPONENT = 'component';

	public const RENDER_MODE_MOUNT = 'mount';

	public const VALID_RENDER_MODES = [
		self::RENDER_MODE_COMPONENT,
		self::RENDER_MODE_MOUNT,
	];

	private string $id;

	private string $label;

	private string $icon;

	private ?string $requiredApp;

	private ?string $group;

	private array $surfaces;

	private ?string $referenceType;

	private ?string $requiresPermission;

	private string $renderMode;

	private ?string $loadStrategy;

	/**
	 * Constructor — same parameter order as the real descriptor.
	 *
	 * @param LeafIdentity          $identity     Id, label, icon and group.
	 * @param array<int,string>     $kinds        Non-empty subset of VALID_KINDS.
	 * @param LeafAvailability|null $availability Required app and permission; defaults to none.
	 * @param LeafRendering|null    $rendering    Surfaces, reference type, render mode and
	 *                                            load strategy; defaults to an empty rendering.
	 */
	public function __construct(
		LeafIdentity $identity,
		private array $kinds,
		?LeafAvailability $availability = null,
		?LeafRendering $rendering = null,
	) {
		$availability = $availability ?? new LeafAvailability();
		$rendering = $rendering ?? new LeafRendering();
		$this->id = $identity->id;
		$this->label = $identity->label;
		$this->icon = $identity->icon;
		$this->requiredApp = $availability->requiredApp;
		$this->group = $identity->group;
		$this->surfaces = $rendering->surfaces;
		$this->referenceType = $rendering->referenceType;
		$this->requiresPermission = $availability->requiresPermission;
		$this->renderMode = $rendering->renderMode;
		$this->loadStrategy = $rendering->loadStrategy;
	}//end __construct()

	/**
	 * How a render-surface leaf renders.
	 *
	 * @return string One of VALID_RENDER_MODES.
	 */
	public function getRenderMode(): string {
		return $this->renderMode;
	}//end getRenderMode()

	/**
	 * How the render bundle reaches the page, if the descriptor has said.
	 *
	 * @return string|null One of VALID_LOAD_STRATEGIES, or null when unstated.
	 */
	public function getLoadStrategy(): ?string {
		return $this->loadStrategy;
	}//end getLoadStrategy()

	/**
	 * @return string
	 */
	public function getId(): string {
		return $this->id;
	}//end getId()

	/**
	 * @return string
	 */
	public function getLabel(): string {
		return $this->label;
	}//end getLabel()

	/**
	 * @return string
	 */
	public function getIcon(): string {
		return $this->icon;
	}//end getIcon()

	/**
	 * @return array<int,string>
	 */
	public function getKinds(): array {
		return $this->kinds;
	}//end getKinds()

	/**
	 * @param string $kind One of the KIND_* constants.
	 *
	 * @return bool
	 */
	public function hasKind(string $kind): bool {
		return in_array($kind, $this->kinds, true);
	}//end hasKind()

	/**
	 * @return string|null
	 */
	public function getRequiredApp(): ?string {
		return $this->requiredApp;
	}//end getRequiredApp()

	/**
	 * @return string|null
	 */
	public function getGroup(): ?string {
		return $this->group;
	}//end getGroup()

	/**
	 * @return array<int,string>
	 */
	public function getSurfaces(): array {
		return $this->surfaces;
	}//end getSurfaces()

	/**
	 * @return string|null
	 */
	public function getReferenceType(): ?string {
		return $this->referenceType;
	}//end getReferenceType()

	/**
	 * @return string|null
	 */
	public function requiresPermission(): ?string {
		return $this->requiresPermission;
	}//end requiresPermission()

	/**
	 * @return array<string,mixed>
	 */
	public function toArray(): array {
		return [
			'id' => $this->id,
			'label' => $this->label,
			'icon' => $this->icon,
			'requiredApp' => $this->requiredApp,
			'group' => $this->group,
			'surfaces' => $this->surfaces,
			'kinds' => $this->kinds,
			'renderMode' => $this->renderMode,
		];
	}//end toArray()
}//end class
