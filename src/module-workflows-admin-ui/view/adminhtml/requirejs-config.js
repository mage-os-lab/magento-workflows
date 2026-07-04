/**
 * admin-ui's FIRST web asset (the module had zero JavaScript before Phase B).
 * Registers the shared condition slide-out widget used by the classic form's
 * Conditions tab. The canvas (a separate React bundle) shares the same
 * serialized-tree contract and the same apply endpoint, not this RequireJS
 * module.
 */
var config = {
    map: {
        '*': {
            mageosWorkflowsConditions: 'MageOS_WorkflowsAdminUi/js/conditions-slideout'
        }
    }
};
