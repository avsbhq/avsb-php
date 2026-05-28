<?php

declare(strict_types=1);

namespace Avsbhq\Avsb\Core;

/**
 * RuleType mirrors §2.7 FlagDatafileRule.type from the canonical TS types.
 */
enum RuleType: string
{
    case TargetedDelivery = 'targeted_delivery';
    case AbTest           = 'ab_test';
    case Holdout          = 'holdout';
    case Bandit           = 'bandit';
}

/**
 * EvaluationSource mirrors §2.12 EvaluationSource from the canonical TS types.
 */
enum EvaluationSource: string
{
    case DatafileOverride = 'datafileOverride';
    case RuntimeOverride  = 'runtimeOverride';
    case Sticky           = 'sticky';
    case Rule             = 'rule';
    case Holdout          = 'holdout';
    case Bandit           = 'bandit';
    case Default          = 'default';
    case NotFound         = 'not_found';
    case Disabled         = 'disabled';
    // Legacy source values produced by the core evaluator before the public
    // EvaluationSource enum was introduced — kept so the PHP evaluator can
    // map them on receipt without an extra translation step.
    case Override         = 'override';
}
