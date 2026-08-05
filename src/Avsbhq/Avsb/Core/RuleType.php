<?php

declare(strict_types=1);

namespace Avsbhq\Avsb\Core;

/**
 * The kind of rule that produced a decision.
 *
 * Declared in its own file because PSR-4 maps one class per file: this enum
 * and EvaluationSource used to share a Core/Types.php, which means neither was
 * autoloadable and every `use Avsbhq\Avsb\Core\RuleType` was a fatal error at
 * runtime.
 */
enum RuleType: string
{
    /** A rollout: everyone matching the audience gets the same variation. */
    case TargetedDelivery = 'targeted_delivery';
    /** An experiment: matching traffic is split across variations by hash. */
    case AbTest = 'ab_test';
    /** A holdout kept this context out of the flag's experiments. */
    case Holdout = 'holdout';
    /** A bandit chose the arm. */
    case Bandit = 'bandit';
}
