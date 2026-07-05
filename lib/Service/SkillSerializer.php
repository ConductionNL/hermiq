<?php

/**
 * Hermiq SkillSerializer.
 *
 * Converts a Skill object to an agentskills.io package string and back. Fidelity is
 * guaranteed by preserving the raw frontmatter block verbatim (never re-dumping YAML), so
 * a serialise → deserialise round trip reproduces the frontmatter and body byte-for-byte.
 * Deliberately dependency-free (no Symfony Yaml) so it runs in the CI stub environment.
 *
 * @category Service
 * @package  OCA\Hermiq\Service
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
 * @spec openspec/changes/skills-catalog/tasks.md#2-skillserializer-lossless-round-trip
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service;

/**
 * Lossless agentskills.io package (de)serialiser for Skill objects.
 *
 * @spec openspec/changes/skills-catalog/tasks.md#2-skillserializer-lossless-round-trip
 */
class SkillSerializer
{

    /**
     * The frontmatter fence delimiter.
     *
     * @var string
     */
    private const FENCE = '---';

    /**
     * Serialise a Skill into an agentskills.io package string.
     *
     * Format: a `---` fenced frontmatter block followed by the body. The frontmatter is
     * emitted verbatim (the raw block stored on import), so a round trip is byte-for-byte.
     *
     * @param array<string, mixed> $skill The skill data (needs `frontmatter` + `body`).
     *
     * @return string The agentskills.io package string.
     *
     * @spec openspec/changes/skills-catalog/tasks.md#task-2-1
     */
    public function toPackage(array $skill): string
    {
        $frontmatter = (string) ($skill['frontmatter'] ?? '');
        $body        = (string) ($skill['body'] ?? '');

        return self::FENCE."\n".$frontmatter."\n".self::FENCE."\n".$body;

    }//end toPackage()

    /**
     * Parse an agentskills.io package string into skill fields.
     *
     * Splits on the leading `---`…`---` fence: everything between the fences is the raw
     * frontmatter block (returned verbatim), everything after is the body. `name` and
     * `description` are extracted from the frontmatter for browsing but are never used to
     * reconstruct the block.
     *
     * @param string $package The agentskills.io package string.
     *
     * @return array<string, string> The parsed { frontmatter, body, name, description }.
     *
     * @spec openspec/changes/skills-catalog/tasks.md#task-2-1
     */
    public function fromPackage(string $package): array
    {
        $normalised = str_replace("\r\n", "\n", $package);

        $frontmatter = '';
        $body        = $normalised;

        $fenceOpen = self::FENCE."\n";
        if (str_starts_with($normalised, $fenceOpen) === true) {
            $rest     = substr($normalised, strlen($fenceOpen));
            $closePos = strpos($rest, "\n".self::FENCE."\n");
            if ($closePos !== false) {
                $frontmatter = substr($rest, 0, $closePos);
                $body        = substr($rest, ($closePos + strlen("\n".self::FENCE."\n")));
            }
        }

        return [
            'frontmatter' => $frontmatter,
            'body'        => $body,
            'name'        => $this->extractField(frontmatter: $frontmatter, field: 'name'),
            'description' => $this->extractField(frontmatter: $frontmatter, field: 'description'),
        ];

    }//end fromPackage()

    /**
     * Extract a scalar `field: value` from a raw frontmatter block.
     *
     * @param string $frontmatter The raw frontmatter block.
     * @param string $field       The field name.
     *
     * @return string The trimmed, unquoted value (empty when absent).
     */
    private function extractField(string $frontmatter, string $field): string
    {
        $pattern = '/^'.preg_quote($field, '/').':\s*(.*)$/m';
        if (preg_match($pattern, $frontmatter, $matches) !== 1) {
            return '';
        }

        $value = trim($matches[1]);
        // Strip a single pair of surrounding quotes, if present.
        if (strlen($value) >= 2) {
            $first = $value[0];
            $last  = $value[(strlen($value) - 1)];
            if (($first === '"' && $last === '"') || ($first === "'" && $last === "'")) {
                $value = substr($value, 1, -1);
            }
        }

        return $value;

    }//end extractField()
}//end class
