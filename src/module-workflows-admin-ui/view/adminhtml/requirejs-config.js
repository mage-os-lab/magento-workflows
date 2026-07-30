/**
 * admin-ui's RequireJS widget map (the module had zero JavaScript before Phase
 * B). Registers the shared condition slide-out used by the classic form's
 * Conditions tab, the definition preview panel, and the two template
 * install-form parameter widgets (a source-backed picker and the duration
 * composite). Every one of them boots declaratively via data-mage-init — none
 * is referenced from an inline script.
 *
 * The canvas (a separate React bundle) shares the same serialized-tree contract
 * and the same apply endpoint, not these RequireJS modules.
 */
var config = {
    map: {
        '*': {
            mageosWorkflowsConditions: 'MageOS_WorkflowsAdminUi/js/conditions-slideout',
            mageosWorkflowsDefinitionPreview: 'MageOS_WorkflowsAdminUi/js/definition-preview',
            mageosWorkflowsParamSearch: 'MageOS_WorkflowsAdminUi/js/param-search-select',
            mageosWorkflowsParamDuration: 'MageOS_WorkflowsAdminUi/js/param-duration'
        }
    }
};
