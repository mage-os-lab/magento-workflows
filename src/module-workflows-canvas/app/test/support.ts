/**
 * Copyright (c) Mage-OS. Licensed under OSL-3.0.
 * See LICENSE.txt for license details.
 */

import { toGraph } from '../src/mapping';
import type { Definition, Graph, MountConfig, PaletteAction } from '../src/types';

/** A MountConfig with sensible Phase-B defaults, overridable per test. */
export function makeConfig(overrides: Partial<MountConfig> = {}): MountConfig {
  return {
    workflowId: 1,
    executionId: null,
    knownSchemaVersion: 3,
    grants: { manage: true, dryRun: true },
    endpoints: {
      executionSteps: '/steps',
      dryRun: '/dry',
      validate: '/validate',
      options: '/options',
      conditionMeta: '/conditionMeta',
      conditions: '/conditions',
      save: '/save',
    },
    formKey: 'FKEY',
    workflow: {
      id: 1,
      name: 'W',
      status: 2,
      entityType: 'sales_order',
      triggerType: 'event',
      triggerRef: 'sales.order.created',
      conditionsSerialized: null,
      loopGuardDepth: 1,
      websiteIds: [],
      fanOutRelation: '',
      fanOutCap: '',
      definition: null,
    },
    actions: {},
    actionsMeta: [],
    triggers: [],
    secrets: [],
    workflowOptions: { entityTypes: [], triggerTypes: [], statuses: [], websites: [] },
    i18n: {},
    approvalsAvailable: false,
    ...overrides,
  };
}

export function action(overrides: Partial<PaletteAction> = {}): PaletteAction {
  return {
    code: 'order.add_comment',
    label: 'Add Order Comment',
    group: 'Sales',
    applicableEntities: ['sales_order'],
    configForm: [],
    aclResource: null,
    ...overrides,
  };
}

/** Build a Graph from a definition through the real mapping layer. */
export function makeGraph(def: Definition, config: MountConfig = makeConfig()): Graph {
  // Register action codes so nothing degrades.
  const cfg = { ...config, actions: { ...config.actions } };
  for (const step of Object.values(def.steps ?? {})) {
    if (step.type === 'action' && typeof step.action === 'string') {
      cfg.actions[step.action] = { label: step.action, group: 'T' };
    }
  }
  return toGraph(def, cfg);
}
