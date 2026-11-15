<?php

/**
 * Hermiq BudgetLimits.
 *
 * One budget's token limit, EUR limit and soft threshold, read from its payload,
 * and the usage blocks BudgetService::status() reports against them.
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
 * @spec openspec/changes/archive/2026-07-12-cost-guardrails/tasks.md#task-2-budgetservice-status-hard-cap-check-soft-threshold-warn-estimate
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service;

/**
 * A budget's limits and the token and EUR usage measured against them.
 *
 * @spec openspec/changes/archive/2026-07-12-cost-guardrails/tasks.md#task-2-budgetservice-status-hard-cap-check-soft-threshold-warn-estimate
 */
class BudgetLimits
{

    /**
     * Default soft-threshold percentage when a Budget omits it.
     *
     * @var int
     */
    public const DEFAULT_SOFT_THRESHOLD = 80;

    /**
     * The token limit, or null when unset.
     *
     * @var int|null
     */
    private readonly ?int $tokenLimit;

    /**
     * The EUR limit, or null when unset.
     *
     * @var float|null
     */
    private readonly ?float $eurLimit;

    /**
     * The soft threshold in percent.
     *
     * @var int
     */
    private readonly int $softPercent;

    /**
     * Read the limits from a budget payload.
     *
     * @param array<string, mixed> $data The budget payload.
     */
    public function __construct(array $data)
    {
        // `isset()` is already false for null, so a `!== null` conjunct on
        // these two conditions would be unreachable.
        $tokenLimit = null;
        if (isset($data['tokenLimit']) === true && $data['tokenLimit'] !== '') {
            $tokenLimit = (int) $data['tokenLimit'];
        }

        $eurLimit = null;
        if (isset($data['eurLimit']) === true && $data['eurLimit'] !== '') {
            $eurLimit = (float) $data['eurLimit'];
        }

        $this->tokenLimit  = $tokenLimit;
        $this->eurLimit    = $eurLimit;
        $this->softPercent = (int) ($data['softThresholdPercent'] ?? self::DEFAULT_SOFT_THRESHOLD);

    }//end __construct()

    /**
     * The token dimension: usage block plus its threshold flags.
     *
     * @param int $used Tokens used in the period.
     *
     * @return array{usage: array<string, mixed>, hard: bool, soft: bool}
     */
    public function tokenUsage(int $used): array
    {
        $tokenPercent = null;
        if ($this->tokenLimit !== null && $this->tokenLimit > 0) {
            $tokenPercent = round((($used / $this->tokenLimit) * 100), 1);
        }

        return [
            'usage' => [
                'used'    => $used,
                'limit'   => $this->tokenLimit,
                'percent' => $tokenPercent,
            ],
            'hard'  => ($this->tokenLimit !== null && $this->tokenLimit > 0 && $used >= $this->tokenLimit),
            'soft'  => ($tokenPercent !== null && $tokenPercent >= $this->softPercent),
        ];

    }//end tokenUsage()

    /**
     * The EUR dimension, available only when a conversion rate is set.
     *
     * @param int        $tokensUsed Tokens used in the period.
     * @param float|null $rate       EUR per 1000 tokens, or null when unset.
     *
     * @return array{usage: array<string, mixed>, hard: bool, soft: bool}
     */
    public function eurUsage(int $tokensUsed, ?float $rate): array
    {
        $eurUsed = null;
        if ($rate !== null) {
            $eurUsed = round((($tokensUsed * $rate) / 1000), 4);
        }

        $eurPercent = $this->eurPercentOf(eurUsed: $eurUsed);

        return [
            'usage' => [
                'available' => ($rate !== null),
                'used'      => $eurUsed,
                'limit'     => $this->eurLimit,
                'percent'   => $eurPercent,
            ],
            'hard'  => ($eurPercent !== null && $eurUsed >= $this->eurLimit),
            'soft'  => ($eurPercent !== null && $eurPercent >= $this->softPercent),
        ];

    }//end eurUsage()

    /**
     * The euro spend as a percentage of the euro limit, or null without a spend or a positive limit.
     *
     * @param float|null $eurUsed The euro spend, or null when no rate is known.
     *
     * @return float|null
     */
    private function eurPercentOf(?float $eurUsed): ?float
    {
        if ($eurUsed === null || $this->hasEurLimit() === false) {
            return null;
        }

        return round((($eurUsed / $this->eurLimit) * 100), 1);

    }//end eurPercentOf()

    /**
     * Whether a positive euro limit is set.
     *
     * @return bool
     *
     * @psalm-assert-if-true float $this->eurLimit
     */
    private function hasEurLimit(): bool
    {
        return ($this->eurLimit !== null && $this->eurLimit > 0);

    }//end hasEurLimit()
}//end class
