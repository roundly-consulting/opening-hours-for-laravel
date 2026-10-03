<?php

declare(strict_types=1);

namespace RoundlyConsulting\OpeningHours\Support;

use RoundlyConsulting\OpeningHours\Models\ExceptionRule;
use RoundlyConsulting\PackageToolkit\Support\ModelResolver;

/**
 * The (possibly host-swapped) exception-rule model from
 * `opening-hours.models.exception_rule`.
 */
final class ExceptionRuleModel
{
    /**
     * @return class-string<ExceptionRule>
     */
    public static function class(): string
    {
        return ModelResolver::for('opening-hours.models.exception_rule', ExceptionRule::class);
    }
}
