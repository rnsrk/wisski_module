<?php

/**
 * contains \Drupal\wisski_salz\QueryPlanner
 */
namespace Drupal\wisski_salz\Query;

class QueryPlanner {
    /**
     * Creates a new QueryPlanner object.
     * 
     * Because of dynamic conditions this might involve evaluating parts of the plan already.
     * To do this the dynamic_evaluator function must be provided. 
     * It takes as argument a single FILTER and should return a list of bundle ids involved.
     */
    public function __construct(?callback $dynamic_evaluator) {
        $this->dynamic_evaluator = $dynamic_evaluator;
    }

    const TYPE_EMPTY_PLAN = 'empty';

    const TYPE_SINGLE_ADAPTER_PLAN = 'single_adapter';
    const TYPE_SINGLE_FEDERATION_PLAN = 'single_federation';
    const TYPE_SINGLE_PARTITION_PLAN = 'single_partition';

    const TYPE_MULTI_FEDERATION_PLAN = 'multi_federation';
    const TYPE_MULTI_PARTITION_PLAN = 'multi_partition';
    
    /**
     * Plan makes a plan for the provided ast. 
     */
    public function plan(?array $aast) {
        $plan = $this->make_plan($aast, $dynamic_evaluator);
        if ($plan['type'] == self::TYPE_EMPTY_PLAN) {
            Debuggable::debug("Query Planner returned an empty plan!");
            return NULL;
        }
        return $plan;
    }

    private function make_plan(?array $aast) {

        /*
            An AST of a Query is represented as follows:   
        
            PLAN = SINGLE_PLAN | MULTI_PLAN
            AST = NODE (see ASTBuilder class)

            // empty plan is a plan that introduces a new condition on an existing plan. 
            // it is not a valid top-level plan. 
            EMPTY_PLAN = {
                "type": "empty",
                "ast": AST,
            }

            // single plans are plans that occur in the leaves of the 'plan' tree.
            // they originate in the leaves of the AST, but may be merged to create new single plans. 
            // These always involve *exactly* one query (which may touch more than one adapter)
            SINGLE_PLAN = SINGLE_ADAPTER_PLAN | SINGLE_FEDERATION_PLAN | SINGLE_PARTITION_PLAN

            // a single plan is a plan that involves exactly one adapter.
            SINGLE_ADAPTER_PLAN = {
                "type": "single_adapter",
                "ast": AST,
                "reason": "dynamic" | "static"
                "adapter": ....
            }

            // a single query that involves fetching data from multiple adapters. 
            // and they're all federatable.
            // TODO: How to represent which part goes to which adapter?
            SINGLE_FEDERATION_PLAN = {
                "type": "single_federation"
                "ast": AST
                "reason": "dynamic" | "static"
                "adapters": ...
            }

            // a single (simple) query (i.e. one bundle) that involes fetching data from multiple adapters
            // but these are not federatable. 
            SINGLE_PARTITION_PLAN = {
                "type": "single_partition"
                "ast": AST
                "reason": "dynamic" | "static"
                "adapters": ...
            }
            // TODO: Rename this to something more useful.
            // Maybe SINGLE_NOT_FEDERATABLE_PLAN?
        
            // multi plans are plans that involve more than one subquery.
            MULTI_PLAN = MULTI_PLAN_FEDERATION | MULTI_PLAN_PARTITION

            MULTI_PLAN_FEDERATION = {
                "type": "multi_federation"
                "ast": ??? // todo: annotated AST
                "adapters": ...
            }

            MULTI_PLAN_PARTITION = {
                type: "multi_partition",
                "ast": AST
                "plans": PLAN +
            }

            A NULL plan indicates that there is nothing to be done. 
            
            */

        if ($aast == NULL) {
            Debuggable::debug("Query Planner encounted NULL");
            return array(
                'type' => self::TYPE_EMPTY_PLAN,
                'ast' => NULL,
            );
        }

        if ($aast['type'] == ASTBuilder::TYPE_FILTER) {
            return $this->make_filter_plan($aast);
        }

        return $this->make_logical_aggregate_plan($aast);
    }

    /** merges plans of the children of the aggregate */
    protected function make_logical_aggregate_plan(array $aast) {

        // generate plans for each of the children
        $childPlans = array();
        foreach ($aast['children'] as $child) {
            $child = $this->make_plan($child);
            array_push($childPlans, $child);
        }

        // if the plans are compatible, find the pivot to merge them!
        $pivot = self::plans_get_pivot($childPlans);
        if ($pivot !== NULL) {
            return $this->merge_compatible_plans($aast, $pivot, $childPlans);
        }

        // TODO: Here be dragons!
        return array(
            'type' => 'UNIMPLEMENTED_MERGE_PLANS',
            'ast' => $aast,
            'children' => $childPlans,
        );
    }

    /**
     * Return the "pivot" plan we can use to merge semantically compatible plans. 
     * When the plans are not compatible, return NULL.
     */
    protected static function plans_get_pivot(array $plans) {
        // iterate over the plans and check that each pair of plans is identical
        $left = NULL;
        $pivot = NULL;
        foreach ($plans as $right) {
            if ($left != NULL) {
                if (!self::has_compatible_semantics($left, $right)) {
                    return NULL;
                }
            }

            // pick the first non-empty plan as the pivot
            // in the absence, use the "last" empty plan. 
            if ($pivot == NULL || $pivot['type'] == self::TYPE_EMPTY_PLAN) {
                $pivot = $right;
            }
            
            $left = $right; // check the next plan
        }
        return $pivot;
    }

    /* check if two plans are "the same" */
    protected static function has_compatible_semantics(array $left, array $right) {
        // two plans are considered as having compatible semantics if
        // they can merged by only merging the ASTs.


        $leftType = $left['type'];
        $rightType = $right['type'];

        // special case: empty plans are compatible with every other plan. 
        if ($leftType == self::TYPE_EMPTY_PLAN || $rightType == self::TYPE_EMPTY_PLAN ) {
            return TRUE;
        }

        // normal case: check type-specific equality
        // both only on the adapter(s), not the AST.
        
        if ($leftType != $rightType) {
            return FALSE;
        }

        // TYPE_SINGLE_ADAPTER_PLAN requires the same adapter
        if ($leftType == self::TYPE_SINGLE_ADAPTER_PLAN) {
            return $left['adapter'] == $right['adapter'];
        }


        // TYPE_MULTI_PARTITION_PLAN can never be compatible with anything.
        if ($leftType == self::TYPE_MULTI_PARTITION_PLAN) {
            return FALSE;
        }

        // for all other types we check that the adapters are equal.

        $leftAdapters = self::adapter_to_normstring($left['adapters']);
        $rightAdapters = self::adapter_to_normstring($right['adapters']);
        
        return $leftAdapters == $rightAdapters;

    }

    /** turn an adapter array into a normalized string */
    protected static function adapter_to_normstring(array $adapters) {
        $adapters = array_unique($adapters);
        sort($adapters);
        return implode("\n", $adapters);
    }

    /** creates a new plan from a leaf ast */
    protected function make_filter_plan(array $aast) {
        // Because we are at the bottom of the AST we will *always* return a SINGLE_PLAN or EMPTY_PLAN.

        // based on the involved adapters, decide which single plan is needed.
        // i.e. do we need an EMPTY_PLAN | SINGLE_ADAPTER_PLAN | SINGLE_FEDERATION_PLAN | SINGLE_PARTITION_PLAN
        return $this->decide_single_plan($aast, $aast['annotations']['adapters']);
    }

    private function decide_single_plan(array $aast, array $adapters) {
        // now figure out which of the plans we need by counting the number of adapters.
        $count = count($adapters);

        // no adapters => use TYPE_EMPTY_PLAN
        if ($count == 0) {
            return array(
                "type" => self::TYPE_EMPTY_PLAN,
                "ast" => $aast,
            );
        }

        // if we have exactly one adapter, use TYPE_SINGLE_ADAPTER_PLAN.
        if ($count == 1) {
            return array(
                "type" => self::TYPE_SINGLE_ADAPTER_PLAN,
                "reason" => $reason,
                "adapter" => $adapters[0],
                "ast" => $aast
            );
        }

        $all_are_federatable = $aast['annotations']['federatable'];

        // all adapters are federatable => use a TYPE_SINGLE_FEDERATION_PLAN
        if ($all_are_federatable) {
            return array(
                "type" => self::TYPE_SINGLE_FEDERATION_PLAN,
                "ast" => $aast,
                "reason" => $reason, 
                "adapters" => $adapters,
            );
        }

        // at least one of the adapters is not federatable.
        // so use TYPE_SINGLE_PARTITION_PLAN

        return array(
            "type" => self::TYPE_SINGLE_PARTITION_PLAN,
            "ast" => $aast,
            "reason" => $reason,
            "adapters" => $adapters,
        );
    }

}