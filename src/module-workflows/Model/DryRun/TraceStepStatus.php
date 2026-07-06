<?php
declare(strict_types=1);

namespace MageOS\Workflows\Model\DryRun;

/**
 * The outcome dry-run records for one visited step (docs/discovery/dry-run.md §4).
 *
 *  - WOULD_RUN            the step's simulate()/evaluation succeeded and it would execute
 *  - WOULD_FAIL           a failed simulate() or an unevaluable condition tree; unlike
 *                         production the walk does NOT stop — every problem surfaces in one pass
 *  - SKIPPED              a simulate() that returned "skipped" (e.g. a guard opted the action out)
 *  - PRODUCTION_STOPS_HERE a step reached only because dry-run continues past a terminal
 *                         failure that production would have stopped on
 */
enum TraceStepStatus: string
{
    case WOULD_RUN = 'would_run';
    case WOULD_FAIL = 'would_fail';
    case SKIPPED = 'skipped';
    case PRODUCTION_STOPS_HERE = 'production_stops_here';
}
