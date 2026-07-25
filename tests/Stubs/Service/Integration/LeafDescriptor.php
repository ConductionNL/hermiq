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
final class LeafDescriptor
{

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

    /**
     * Constructor — same parameter order as the real descriptor.
     *
     * @param string            $id                 Stable kebab-case id.
     * @param string            $label              Human-readable label.
     * @param string            $icon               MDI icon name.
     * @param array<int,string> $kinds              Non-empty subset of VALID_KINDS.
     * @param string|null       $requiredApp        Required NC app id, or null.
     * @param string|null       $group              Optional group.
     * @param array<int,string> $surfaces           Render surfaces.
     * @param string|null       $referenceType      Optional reference-type marker.
     * @param string|null       $requiresPermission Optional permission gate.
     */
    public function __construct(
        private string $id,
        private string $label,
        private string $icon,
        private array $kinds,
        private ?string $requiredApp=null,
        private ?string $group=null,
        private array $surfaces=[],
        private ?string $referenceType=null,
        private ?string $requiresPermission=null,
    ) {
    }//end __construct()

    /**
     * @return string
     */
    public function getId(): string
    {
        return $this->id;
    }//end getId()

    /**
     * @return string
     */
    public function getLabel(): string
    {
        return $this->label;
    }//end getLabel()

    /**
     * @return string
     */
    public function getIcon(): string
    {
        return $this->icon;
    }//end getIcon()

    /**
     * @return array<int,string>
     */
    public function getKinds(): array
    {
        return $this->kinds;
    }//end getKinds()

    /**
     * @param string $kind One of the KIND_* constants.
     *
     * @return bool
     */
    public function hasKind(string $kind): bool
    {
        return in_array($kind, $this->kinds, true);
    }//end hasKind()

    /**
     * @return string|null
     */
    public function getRequiredApp(): ?string
    {
        return $this->requiredApp;
    }//end getRequiredApp()

    /**
     * @return string|null
     */
    public function getGroup(): ?string
    {
        return $this->group;
    }//end getGroup()

    /**
     * @return array<int,string>
     */
    public function getSurfaces(): array
    {
        return $this->surfaces;
    }//end getSurfaces()

    /**
     * @return string|null
     */
    public function getReferenceType(): ?string
    {
        return $this->referenceType;
    }//end getReferenceType()

    /**
     * @return string|null
     */
    public function requiresPermission(): ?string
    {
        return $this->requiresPermission;
    }//end requiresPermission()

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return [
            'id'          => $this->id,
            'label'       => $this->label,
            'icon'        => $this->icon,
            'requiredApp' => $this->requiredApp,
            'group'       => $this->group,
            'surfaces'    => $this->surfaces,
            'kinds'       => $this->kinds,
        ];
    }//end toArray()
}//end class
