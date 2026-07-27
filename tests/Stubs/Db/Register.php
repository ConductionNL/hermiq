<?php
/**
 * Minimal OpenRegister Register stub for standalone unit runs.
 *
 * Registered at TEST TIME only by tests/bootstrap.php — see the note there on
 * why these mappings must not live in composer.json `autoload-dev`.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db;

/**
 * Minimal Register stub.
 */
class Register
{

    /**
     * The register id.
     *
     * @var int|null
     */
    private ?int $id = null;

    /**
     * The register slug.
     *
     * @var string|null
     */
    private ?string $slug = null;

    /**
     * The schema ids linked to this register.
     *
     * @var array<int, int|string>
     */
    private array $schemas = [];

    /**
     * Get the id.
     *
     * @return int|null
     */
    public function getId(): ?int
    {
        return $this->id;
    }//end getId()

    /**
     * Set the id.
     *
     * @param int|null $id The id.
     *
     * @return void
     */
    public function setId(?int $id): void
    {
        $this->id = $id;
    }//end setId()

    /**
     * Get the slug.
     *
     * @return string|null
     */
    public function getSlug(): ?string
    {
        return $this->slug;
    }//end getSlug()

    /**
     * Set the slug.
     *
     * @param string|null $slug The slug.
     *
     * @return void
     */
    public function setSlug(?string $slug): void
    {
        $this->slug = $slug;
    }//end setSlug()

    /**
     * Get the linked schema ids.
     *
     * @return array<int, int|string>
     */
    public function getSchemas(): array
    {
        return $this->schemas;
    }//end getSchemas()

    /**
     * Set the linked schema ids.
     *
     * @param array<int, int|string> $schemas The schema ids.
     *
     * @return void
     */
    public function setSchemas(array $schemas): void
    {
        $this->schemas = $schemas;
    }//end setSchemas()
}//end class
