import { useCallback, useEffect, useRef, useState } from 'react';
import type {
  ApprovalPayloadField,
  ConfigFieldOption,
  Graph,
  GraphNode,
  MountConfig,
  StepNode,
  TriggerMeta,
} from '../types';
import {
  addToMultiValue,
  eventOptionGroups,
  isCataloguedEvent,
  isValidEventName,
  isValidTimeOfDay,
  labelForValue,
  multiSelectOptions,
  normalizeConfigForm,
  optionsWithSelected,
  parseMultiValue,
  readValue,
  removeFromMultiValue,
  SEARCH_DEBOUNCE_MS,
  searchAddOptions,
  serializeMultiValue,
  shouldSearch,
  writeValue,
  type NormalizedField,
} from '../configPanel';
import {
  DURATION_UNITS,
  composeDuration,
  isIsoDuration,
  splitDuration,
  type DurationUnit,
} from '../duration';
import {
  blankPayloadField,
  notifyEmailError,
  payloadFieldError,
  payloadTypeOptions,
  readNotifyEmails,
  readPayloadFields,
  serializeNotifyEmails,
  serializePayloadFields,
} from '../approvalFields';
import { moveItem, removeAt, replaceAt } from '../listEdit';
import { addCase, casesOf, moveCase, removeCase, renameCase } from '../switchCases';
import { actionByCode } from '../palette';
import type { ConditionTarget } from '../conditionTarget';
import { buildVariablePaths } from '../variablePicker';

/**
 * The node config side panel (Phase B), generated entirely from getConfigForm()
 * metadata (F6). Every value is rendered into a form control — never eval'd,
 * never innerHTML. Option selects resolve the F6 union: inline lists render
 * statically, options_search fields hit the same-origin meta/options proxy as
 * the user types (min_chars-gated, debounced). The variable picker lists context
 * paths + upstream step outputs + secret NAMES (values never leave the server).
 *
 * Every control here writes a value the SERVER reads back verbatim, so each one
 * is pinned to its server-side assertion:
 *   duration/timeout -> Definition::assertDuration (ISO-8601), through the
 *                       amount+unit composite with a raw-ISO escape hatch;
 *   wait event       -> /^[a-z0-9_.\-]{1,128}$/, offered from the bootstrapped
 *                       trigger catalogue with a free-entry escape hatch;
 *   delay at         -> "HH:MM" 24-hour, business_days -> boolean;
 *   multiselect      -> the comma-separated string the runtime explodes
 *                       (AssignWebsites::parseWebsiteIds);
 *   approval lists   -> {key,label,type,required?} rows / email rows;
 *   switch cases     -> /^[a-zA-Z0-9_\-]{1,64}$/, unique, non-empty list.
 *
 * The panel edits an immutable copy of the step and hands the result up via
 * onChange; the parent commits it to the graph (and the undo history). Switch
 * case edits go up through onGraphChange instead, because a case owns an edge.
 */
interface Props {
  node: GraphNode;
  config: MountConfig;
  graph: Graph;
  readOnly: boolean;
  onChange: (stepKey: string, step: StepNode) => void;
  /**
   * Commit a whole-graph edit. Switch case add/remove/rename/reorder is a graph
   * op, not a step op: the case key IS the `case:<key>` edge handle, so a rename
   * has to re-point the edge in the same commit or the case's target would be
   * dropped on the next save (mapping re-points case targets by key).
   */
  onGraphChange: (graph: Graph) => void;
  onDelete: (stepKey: string) => void;
  /**
   * Open the condition slide-out on a TARGET, not a bare step key: a branch's
   * own tree is `{scope:'step', stepKey}`, while a switch case's tree is
   * `{scope:'case', stepKey, caseIndex, caseKey}` (a switch step must never be
   * given a step-level tree — see conditionTarget/mapping). The editor resolves
   * the current value and routes the applied tree back to that exact home.
   */
  onEditConditions: (target: ConditionTarget) => void;
}

export function ConfigPanel({
  node,
  config,
  graph,
  readOnly,
  onChange,
  onGraphChange,
  onDelete,
  onEditConditions,
}: Props): JSX.Element {
  const step = node.data.step;
  const action = step.type === 'action' ? actionByCode(config, String(step.action ?? '')) : undefined;
  const fields = normalizeConfigForm(action);
  const variablePaths = buildVariablePaths(config, graph, node.id);

  const set = useCallback(
    (name: string, value: unknown) => {
      onChange(node.id, writeValue(step, name, value));
    },
    [node.id, step, onChange],
  );

  return (
    <aside className="wf-panel" aria-label="Step configuration">
      <header className="wf-panel__head">
        <h3 className="wf-panel__title">{node.id}</h3>
        <span className="wf-panel__type">{step.type}</span>
      </header>

      {node.data.degraded && (
        <p className="wf-panel__degraded">
          This action code is not registered on this install. It can be deleted but not configured.
        </p>
      )}

      {step.type === 'action' && !node.data.degraded && (
        <p className="wf-panel__summary">{action ? action.label : String(step.action ?? '')}</p>
      )}

      {step.type === 'branch' && (
        <div className="wf-panel__conditions">
          <button
            type="button"
            disabled={readOnly}
            onClick={() => onEditConditions({ scope: 'step', stepKey: node.id })}
          >
            Edit conditions…
          </button>
        </div>
      )}

      {step.type === 'switch' && (
        <SwitchCasesEditor
          stepKey={node.id}
          step={step}
          graph={graph}
          actions={config.actions}
          readOnly={readOnly}
          onGraphChange={onGraphChange}
          onEditConditions={onEditConditions}
        />
      )}

      <div className="wf-panel__fields">
        {fields.map((field) => (
          <ConfigFieldControl
            key={field.name}
            field={field}
            value={readValue(step, field)}
            config={config}
            readOnly={readOnly}
            onChange={(v) => set(field.name, v)}
          />
        ))}
        {step.type === 'delay' && (
          <DelayFields step={step} readOnly={readOnly} onChange={set} />
        )}
        {step.type === 'wait' && (
          <WaitFields step={step} triggers={config.triggers} readOnly={readOnly} onChange={set} />
        )}
        {step.type === 'approval' && (
          <ApprovalFields step={step} readOnly={readOnly} onChange={set} />
        )}
      </div>

      {variablePaths.length > 0 && (
        <details className="wf-panel__vars">
          <summary>Available variables</summary>
          <ul>
            {variablePaths.map((v) => (
              <li key={v.path}>
                <code>{`{{ ${v.path} }}`}</code> <span className="wf-panel__var-label">{v.label}</span>
              </li>
            ))}
          </ul>
        </details>
      )}

      <footer className="wf-panel__foot">
        <button type="button" className="wf-panel__delete" disabled={readOnly} onClick={() => onDelete(node.id)}>
          Delete step
        </button>
      </footer>
    </aside>
  );
}

/**
 * The switch step's case list. A switch has no tree of its own — each case
 * carries one — so this is both the case manager (add / rename / remove /
 * reorder) and the per-case entry point into the condition slide-out.
 *
 * Order is semantics here: cases are first-match-wins, so "move up" moves a
 * case earlier in evaluation. Every mutation goes through switchCases, which
 * keeps the `case:<key>` edges consistent; this component only renders and
 * reports the refused ones.
 */
function SwitchCasesEditor({
  stepKey,
  step,
  graph,
  actions,
  readOnly,
  onGraphChange,
  onEditConditions,
}: {
  stepKey: string;
  step: StepNode;
  graph: Graph;
  actions: MountConfig['actions'];
  readOnly: boolean;
  onGraphChange: (graph: Graph) => void;
  onEditConditions: (target: ConditionTarget) => void;
}): JSX.Element {
  const [error, setError] = useState('');
  const cases = casesOf(step);

  const apply = (result: { graph: Graph; error: string | null }): void => {
    setError(result.error ?? '');
    if (result.error === null) {
      onGraphChange(result.graph);
    }
  };

  return (
    <div className="wf-panel__conditions wf-cases">
      <span className="wf-field__label" id={`${stepKey}-cases-label`}>
        Cases (first match wins)
      </span>
      <ul className="wf-cases__list" aria-labelledby={`${stepKey}-cases-label`}>
        {cases.map((c, index) => {
          const caseKey = String(c.key ?? '');
          return (
            // Keyed by POSITION on purpose: keying by case key would remount the
            // row on every keystroke of a rename and steal the caret.
            <li className="wf-cases__row" key={index}>
              <CaseKeyInput
                caseKey={caseKey}
                index={index}
                readOnly={readOnly}
                onRename={(raw) => apply(renameCase(graph, stepKey, index, raw, actions))}
              />
              <span className="wf-cases__state">
                {typeof c.conditions_serialized === 'string' && c.conditions_serialized.trim() !== ''
                  ? 'Conditions set'
                  : 'Always matches'}
              </span>
              <div className="wf-cases__buttons">
                <button
                  type="button"
                  disabled={readOnly}
                  onClick={() =>
                    onEditConditions({ scope: 'case', stepKey, caseIndex: index, caseKey })
                  }
                >
                  Edit conditions…
                </button>
                <button
                  type="button"
                  aria-label={`Move case "${caseKey}" earlier`}
                  disabled={readOnly || index === 0}
                  onClick={() => apply(moveCase(graph, stepKey, index, -1, actions))}
                >
                  ↑
                </button>
                <button
                  type="button"
                  aria-label={`Move case "${caseKey}" later`}
                  disabled={readOnly || index === cases.length - 1}
                  onClick={() => apply(moveCase(graph, stepKey, index, 1, actions))}
                >
                  ↓
                </button>
                <button
                  type="button"
                  aria-label={`Remove case "${caseKey}"`}
                  disabled={readOnly || cases.length === 1}
                  onClick={() => apply(removeCase(graph, stepKey, index, actions))}
                >
                  ×
                </button>
              </div>
            </li>
          );
        })}
      </ul>
      {cases.length === 0 && (
        <p className="wf-field__notice">
          This switch has no cases yet — it cannot be saved until it has one.
        </p>
      )}
      <button
        type="button"
        className="wf-cases__add"
        disabled={readOnly}
        onClick={() => apply(addCase(graph, stepKey, actions))}
      >
        Add case
      </button>
      {error && (
        <span className="wf-field__notice wf-field__notice--error" role="alert">
          {error}
        </span>
      )}
    </div>
  );
}

/**
 * One case's key input. It commits on EVERY keystroke (so nothing is held in an
 * uncommitted buffer), through renameCase, which sanitizes the input to the
 * server's grammar and refuses an emptied or duplicate key. The local draft
 * exists only so a momentarily-refused key stays on screen instead of snapping
 * back to the committed one mid-edit; it re-syncs whenever the committed key
 * changes underneath it.
 */
function CaseKeyInput({
  caseKey,
  index,
  readOnly,
  onRename,
}: {
  caseKey: string;
  index: number;
  readOnly: boolean;
  onRename: (raw: string) => void;
}): JSX.Element {
  const [draft, setDraft] = useState(caseKey);

  useEffect(() => {
    setDraft(caseKey);
  }, [caseKey]);

  return (
    <input
      type="text"
      className="wf-cases__key"
      aria-label={`Case ${index + 1} key`}
      value={draft}
      disabled={readOnly}
      spellCheck={false}
      onChange={(e) => {
        setDraft(e.target.value);
        onRename(e.target.value);
      }}
    />
  );
}

/**
 * Delay config: the ISO-8601 duration the engine adds, plus the two optional
 * calendar modifiers the server accepts (Definition::assertDelayExtras) —
 * business_days (day components count Mon-Fri in the store timezone) and `at`
 * (roll forward to the next occurrence of a store-local HH:MM).
 */
function DelayFields({
  step,
  readOnly,
  onChange,
}: {
  step: StepNode;
  readOnly: boolean;
  onChange: (name: string, value: unknown) => void;
}): JSX.Element {
  const config = (step.config ?? {}) as Record<string, unknown>;
  const at = String(config.at ?? '');
  return (
    <>
      <DurationField
        label="Duration *"
        value={String(config.duration ?? '')}
        readOnly={readOnly}
        onChange={(v) => onChange('duration', v)}
      />
      <label className="wf-field wf-field--bool">
        <input
          type="checkbox"
          checked={Boolean(config.business_days)}
          disabled={readOnly}
          onChange={(e) => onChange('business_days', e.target.checked)}
        />
        Count business days only (Mon–Fri, store timezone)
      </label>
      <TextField
        label="Run at (store-local HH:MM)"
        value={at}
        notice="Optional. After the duration, roll forward to the next occurrence of this time."
        readOnly={readOnly}
        onChange={(v) => onChange('at', v)}
      />
      {at !== '' && !isValidTimeOfDay(at) && (
        <span className="wf-field__notice wf-field__notice--error" role="alert">
          Use 24-hour HH:MM, e.g. 09:30.
        </span>
      )}
    </>
  );
}

/**
 * Wait config: the trigger event to park on and the REQUIRED timeout. Both are
 * mandatory server-side (`waitStep.config` requires event + timeout), which is
 * why the timeout is here at all — a wait step authored without it could never
 * be saved.
 */
function WaitFields({
  step,
  triggers,
  readOnly,
  onChange,
}: {
  step: StepNode;
  triggers: TriggerMeta[];
  readOnly: boolean;
  onChange: (name: string, value: unknown) => void;
}): JSX.Element {
  const config = (step.config ?? {}) as Record<string, unknown>;
  return (
    <>
      <EventField
        value={String(config.event ?? '')}
        triggers={triggers}
        readOnly={readOnly}
        onChange={(v) => onChange('event', v)}
      />
      <DurationField
        label="Timeout *"
        value={String(config.timeout ?? '')}
        notice="Required. The on-timeout path fires when the event has not arrived by then."
        readOnly={readOnly}
        onChange={(v) => onChange('timeout', v)}
      />
    </>
  );
}

/**
 * The wait step's event name: a grouped select over the bootstrapped trigger
 * catalogue (config.triggers, from Mount.php), with a free-entry escape hatch
 * for an event this install has not registered — a third-party module's event,
 * or one declared by a module that is not enabled here. The server validates
 * the name either way.
 */
function EventField({
  value,
  triggers,
  readOnly,
  onChange,
}: {
  value: string;
  triggers: TriggerMeta[];
  readOnly: boolean;
  onChange: (value: string) => void;
}): JSX.Element {
  const groups = eventOptionGroups(triggers);
  const catalogued = isCataloguedEvent(triggers, value);
  const [manual, setManual] = useState(
    () => groups.length === 0 || (value !== '' && !catalogued),
  );
  const free = manual || groups.length === 0;

  return (
    <div className="wf-field">
      <span className="wf-field__label" id="wf-wait-event-label">
        Event name *
      </span>
      {free ? (
        <input
          type="text"
          aria-labelledby="wf-wait-event-label"
          value={value}
          disabled={readOnly}
          spellCheck={false}
          onChange={(e) => onChange(e.target.value)}
        />
      ) : (
        <select
          aria-labelledby="wf-wait-event-label"
          value={value}
          disabled={readOnly}
          onChange={(e) => onChange(e.target.value)}
        >
          <option value="">— select —</option>
          {groups.map((group) => (
            <optgroup key={group.label} label={group.label}>
              {group.options.map((o) => (
                <option key={o.value} value={o.value}>
                  {o.label}
                </option>
              ))}
            </optgroup>
          ))}
        </select>
      )}
      {groups.length > 0 && (
        <button
          type="button"
          className="wf-field__toggle"
          disabled={readOnly}
          onClick={() => setManual(!manual)}
        >
          {free ? 'Choose a registered event instead' : 'Enter an event name instead'}
        </button>
      )}
      {value !== '' && !isValidEventName(value) && (
        <span className="wf-field__notice wf-field__notice--error" role="alert">
          Use lower-case letters, numbers, “.”, “_” or “-” (max 128 characters).
        </span>
      )}
    </div>
  );
}

/**
 * Approval gate config (schema 4): the scalar fields, plus real row editors for
 * the two list-shaped ones. Both list editors commit through
 * approvalFields on every row change — the JSON textarea they replace committed
 * only on blur and only when the text happened to parse, which silently kept
 * saving the previous value.
 */
function ApprovalFields({
  step,
  readOnly,
  onChange,
}: {
  step: StepNode;
  readOnly: boolean;
  onChange: (name: string, value: unknown) => void;
}): JSX.Element {
  const config = (step.config ?? {}) as Record<string, unknown>;
  return (
    <>
      <TextField
        label="Title *"
        value={String(config.title ?? '')}
        readOnly={readOnly}
        onChange={(v) => onChange('title', v)}
      />
      <label className="wf-field">
        <span className="wf-field__label">Instructions</span>
        <textarea
          value={String(config.instructions ?? '')}
          disabled={readOnly}
          onChange={(e) => onChange('instructions', e.target.value)}
        />
      </label>
      <DurationField
        label="Timeout *"
        value={String(config.timeout ?? '')}
        notice="Required. No indefinite parks — the on-timeout path fires when nobody decides."
        readOnly={readOnly}
        onChange={(v) => onChange('timeout', v)}
      />
      <TextField
        label="Assignee role"
        value={String(config.assignee_role ?? '')}
        readOnly={readOnly}
        onChange={(v) => onChange('assignee_role', v)}
      />
      <label className="wf-field wf-field--bool">
        <input
          type="checkbox"
          checked={Boolean(config.allow_bulk)}
          disabled={readOnly}
          onChange={(e) => onChange('allow_bulk', e.target.checked)}
        />
        Allow bulk decisions
      </label>
      <PayloadFieldsEditor
        rows={readPayloadFields(config.payload_fields)}
        readOnly={readOnly}
        onChange={(rows) => onChange('payload_fields', serializePayloadFields(rows))}
      />
      <NotifyEmailsEditor
        rows={readNotifyEmails(config.notify_emails)}
        readOnly={readOnly}
        onChange={(rows) => onChange('notify_emails', serializeNotifyEmails(rows))}
      />
    </>
  );
}

/**
 * payload_fields as structured rows — {key, label, type, required} — which is
 * the shape the server declares (approvalStep.config.payload_fields.items,
 * additionalProperties:false). Row order is the order the decider sees, so it is
 * reorderable. An incomplete row is still committed, with its problem shown
 * inline: withholding it is what the old textarea did, and that was the bug.
 */
function PayloadFieldsEditor({
  rows,
  readOnly,
  onChange,
}: {
  rows: ApprovalPayloadField[];
  readOnly: boolean;
  onChange: (rows: ApprovalPayloadField[]) => void;
}): JSX.Element {
  const update = (index: number, patch: Partial<ApprovalPayloadField>): void => {
    onChange(replaceAt(rows, index, { ...rows[index], ...patch }));
  };

  return (
    <div className="wf-field wf-rows">
      <span className="wf-field__label" id="wf-payload-fields-label">
        Payload fields
      </span>
      <span className="wf-field__notice">
        Values the decider fills in. Each key is exposed to later steps.
      </span>
      <ul className="wf-rows__list" aria-labelledby="wf-payload-fields-label">
        {rows.map((row, index) => {
          const error = payloadFieldError(rows, index);
          return (
            <li className="wf-rows__row" key={index}>
              <div className="wf-rows__controls">
                <input
                  type="text"
                  className="wf-rows__key"
                  aria-label={`Payload field ${index + 1} key`}
                  placeholder="key"
                  value={String(row.key ?? '')}
                  disabled={readOnly}
                  spellCheck={false}
                  onChange={(e) => update(index, { key: e.target.value })}
                />
                <input
                  type="text"
                  aria-label={`Payload field ${index + 1} label`}
                  placeholder="Label"
                  value={String(row.label ?? '')}
                  disabled={readOnly}
                  onChange={(e) => update(index, { label: e.target.value })}
                />
                <select
                  aria-label={`Payload field ${index + 1} type`}
                  value={String(row.type ?? 'string')}
                  disabled={readOnly}
                  onChange={(e) =>
                    update(index, { type: e.target.value as ApprovalPayloadField['type'] })
                  }
                >
                  {payloadTypeOptions(row.type).map((o) => (
                    <option key={o.value} value={o.value}>
                      {o.label}
                    </option>
                  ))}
                </select>
                <label className="wf-rows__check">
                  <input
                    type="checkbox"
                    checked={row.required === true}
                    disabled={readOnly}
                    onChange={(e) => update(index, { required: e.target.checked })}
                  />
                  Required
                </label>
                <button
                  type="button"
                  aria-label={`Move payload field ${index + 1} up`}
                  disabled={readOnly || index === 0}
                  onClick={() => onChange(moveItem(rows, index, -1))}
                >
                  ↑
                </button>
                <button
                  type="button"
                  aria-label={`Move payload field ${index + 1} down`}
                  disabled={readOnly || index === rows.length - 1}
                  onClick={() => onChange(moveItem(rows, index, 1))}
                >
                  ↓
                </button>
                <button
                  type="button"
                  aria-label={`Remove payload field ${index + 1}`}
                  disabled={readOnly}
                  onClick={() => onChange(removeAt(rows, index))}
                >
                  ×
                </button>
              </div>
              {error && (
                <span className="wf-field__notice wf-field__notice--error" role="alert">
                  {error}
                </span>
              )}
            </li>
          );
        })}
      </ul>
      <button
        type="button"
        disabled={readOnly}
        onClick={() => onChange([...rows, blankPayloadField(rows)])}
      >
        Add payload field
      </button>
    </div>
  );
}

/**
 * notify_emails as one input per recipient, format-checked inline. The server
 * only requires non-empty strings, so a refused-looking address is a warning,
 * not a block — the row is committed either way.
 */
function NotifyEmailsEditor({
  rows,
  readOnly,
  onChange,
}: {
  rows: string[];
  readOnly: boolean;
  onChange: (rows: string[]) => void;
}): JSX.Element {
  return (
    <div className="wf-field wf-rows">
      <span className="wf-field__label" id="wf-notify-emails-label">
        Notify emails
      </span>
      <ul className="wf-rows__list" aria-labelledby="wf-notify-emails-label">
        {rows.map((row, index) => {
          const error = notifyEmailError(row);
          return (
            <li className="wf-rows__row" key={index}>
              <div className="wf-rows__controls">
                <input
                  type="email"
                  aria-label={`Notify email ${index + 1}`}
                  placeholder="name@example.com"
                  value={row}
                  disabled={readOnly}
                  spellCheck={false}
                  onChange={(e) => onChange(replaceAt(rows, index, e.target.value))}
                />
                <button
                  type="button"
                  aria-label={`Remove notify email ${index + 1}`}
                  disabled={readOnly}
                  onClick={() => onChange(removeAt(rows, index))}
                >
                  ×
                </button>
              </div>
              {error && (
                <span className="wf-field__notice wf-field__notice--error" role="alert">
                  {error}
                </span>
              )}
            </li>
          );
        })}
      </ul>
      <button type="button" disabled={readOnly} onClick={() => onChange([...rows, ''])}>
        Add recipient
      </button>
    </div>
  );
}

/**
 * A duration field: an amount + unit composite over the ISO-8601 value the
 * server actually validates, with the raw-ISO escape hatch the install form's
 * `param-duration.js` peer established.
 *
 * The stored value is the single source of truth and is never re-encoded: a
 * value the composite cannot represent exactly (a compound interval such as
 * P1DT12H, weeks, seconds) opens straight into the ISO input and round-trips
 * verbatim. A half-typed amount composes to nothing and so leaves the stored
 * value alone — the same rule as the install form, so a stray keystroke can
 * never blank a duration the operator already had.
 */
function DurationField({
  label,
  value,
  notice,
  readOnly,
  onChange,
}: {
  label: string;
  value: string;
  notice?: string;
  readOnly: boolean;
  onChange: (value: string) => void;
}): JSX.Element {
  const parts = splitDuration(value);
  const [manual, setManual] = useState(() => value !== '' && parts === null);
  const [amount, setAmount] = useState(() => (parts ? String(parts.amount) : ''));
  const [unit, setUnit] = useState<DurationUnit>(() => parts?.unit ?? 'hours');

  // Re-sync the composite from the stored value (undo/redo, a re-selected node,
  // a value edited through the ISO input) without clobbering a half-typed
  // amount, which has no representable value to sync from.
  useEffect(() => {
    const split = splitDuration(value);
    if (split) {
      setAmount(String(split.amount));
      setUnit(split.unit);
    }
  }, [value]);

  // A stored value the composite cannot represent PINS the field to the ISO
  // input: there is no amount/unit pair to switch back to, so the picker is not
  // offered until the value becomes representable again.
  const pinnedToIso = value !== '' && parts === null;
  const iso = manual || pinnedToIso;

  const compose = (nextAmount: string, nextUnit: DurationUnit): void => {
    const composed = composeDuration(nextAmount, nextUnit);
    if (composed !== null) {
      onChange(composed);
    }
  };

  return (
    <div className="wf-field wf-duration">
      <span className="wf-field__label">{label}</span>
      {iso ? (
        <input
          type="text"
          className="wf-duration__iso"
          aria-label={label}
          placeholder="ISO-8601, e.g. PT1H"
          value={value}
          disabled={readOnly}
          spellCheck={false}
          onChange={(e) => {
            // Sticky: once the ISO input is in use it stays the control, so the
            // field cannot flip to the composite mid-keystroke the moment the
            // typed text happens to be representable.
            setManual(true);
            onChange(e.target.value);
          }}
        />
      ) : (
        <div className="wf-duration__composite">
          <input
            type="number"
            className="wf-duration__amount"
            min={1}
            step={1}
            aria-label={`${label} amount`}
            value={amount}
            disabled={readOnly}
            onChange={(e) => {
              setAmount(e.target.value);
              compose(e.target.value, unit);
            }}
          />
          <select
            className="wf-duration__unit"
            aria-label={`${label} unit`}
            value={unit}
            disabled={readOnly}
            onChange={(e) => {
              const next = e.target.value as DurationUnit;
              setUnit(next);
              compose(amount, next);
            }}
          >
            {DURATION_UNITS.map((o) => (
              <option key={o.value} value={o.value}>
                {o.label}
              </option>
            ))}
          </select>
        </div>
      )}
      {!pinnedToIso && (
        <button
          type="button"
          className="wf-field__toggle"
          disabled={readOnly}
          onClick={() => setManual(!iso)}
        >
          {iso ? 'Use the duration picker' : 'Enter an ISO-8601 duration instead'}
        </button>
      )}
      {pinnedToIso && isIsoDuration(value) && (
        <span className="wf-field__notice">
          This duration mixes units, so it is edited as ISO-8601.
        </span>
      )}
      {notice && <span className="wf-field__notice">{notice}</span>}
      {value === '' && (
        <span className="wf-field__notice wf-field__notice--error" role="alert">
          A duration is required.
        </span>
      )}
      {value !== '' && !isIsoDuration(value) && (
        <span className="wf-field__notice wf-field__notice--error" role="alert">
          Not an ISO-8601 duration (e.g. PT30M, PT2H, P3D).
        </span>
      )}
    </div>
  );
}

function ConfigFieldControl({
  field,
  value,
  config,
  readOnly,
  onChange,
}: {
  field: NormalizedField;
  value: unknown;
  config: MountConfig;
  readOnly: boolean;
  onChange: (value: unknown) => void;
}): JSX.Element {
  // Multiselect first: an option-bearing multiselect is still a multiselect, and
  // falling through to the inline branch is what limited AssignWebsites to a
  // single website.
  if (field.type === 'multiselect' && field.inlineOptions.length > 0) {
    return (
      <MultiSelectField
        label={field.label}
        options={field.inlineOptions}
        value={value}
        required={field.required}
        notice={field.notice}
        readOnly={readOnly}
        onChange={onChange}
      />
    );
  }
  if (field.optionMode === 'inline') {
    return (
      <SelectField
        label={field.label}
        options={field.inlineOptions}
        value={String(value ?? '')}
        required={field.required}
        notice={field.notice}
        readOnly={readOnly}
        onChange={onChange}
      />
    );
  }
  // Multi + search second: a multiselect over an options_search source renders
  // the same fetching control in its multi (chips) mode — the raw value goes
  // through so parseMultiValue can read a comma string or a legacy array.
  if (field.optionMode === 'search') {
    return (
      <SearchSelectField
        field={field}
        value={value}
        config={config}
        readOnly={readOnly}
        onChange={onChange}
      />
    );
  }
  if (field.isSecret) {
    return (
      <SelectField
        label={`${field.label} (secret)`}
        options={config.secrets.map((name) => ({ value: name, label: name }))}
        value={String(value ?? '')}
        required={field.required}
        notice={field.notice}
        readOnly={readOnly}
        onChange={onChange}
      />
    );
  }
  if (field.type === 'boolean') {
    return (
      <label className="wf-field wf-field--bool">
        <input
          type="checkbox"
          checked={Boolean(value)}
          disabled={readOnly}
          onChange={(e) => onChange(e.target.checked)}
        />
        {field.label}
      </label>
    );
  }
  if (field.type === 'textarea') {
    return (
      <label className="wf-field">
        <span className="wf-field__label">{field.label}{field.required ? ' *' : ''}</span>
        <textarea
          value={String(value ?? '')}
          disabled={readOnly}
          onChange={(e) => onChange(e.target.value)}
        />
        {field.notice && <span className="wf-field__notice">{field.notice}</span>}
      </label>
    );
  }
  return (
    <TextField
      label={`${field.label}${field.required ? ' *' : ''}`}
      value={String(value ?? '')}
      notice={field.notice}
      type={field.type === 'integer' ? 'number' : 'text'}
      readOnly={readOnly}
      onChange={onChange}
    />
  );
}

function TextField({
  label,
  value,
  notice,
  type = 'text',
  readOnly,
  onChange,
}: {
  label: string;
  value: string;
  notice?: string | null;
  type?: string;
  readOnly: boolean;
  onChange: (value: string) => void;
}): JSX.Element {
  return (
    <label className="wf-field">
      <span className="wf-field__label">{label}</span>
      <input type={type} value={value} disabled={readOnly} onChange={(e) => onChange(e.target.value)} />
      {notice && <span className="wf-field__notice">{notice}</span>}
    </label>
  );
}

function SelectField({
  label,
  options,
  value,
  required,
  notice,
  readOnly,
  onChange,
}: {
  label: string;
  options: ConfigFieldOption[];
  value: string;
  required?: boolean;
  notice?: string | null;
  readOnly: boolean;
  onChange: (value: string) => void;
}): JSX.Element {
  return (
    <label className="wf-field">
      <span className="wf-field__label">{label}{required ? ' *' : ''}</span>
      <select value={value} disabled={readOnly} onChange={(e) => onChange(e.target.value)}>
        <option value="">— select —</option>
        {options.map((o) => (
          <option key={o.value} value={o.value}>
            {o.label}
          </option>
        ))}
      </select>
      {notice && <span className="wf-field__notice">{notice}</span>}
    </label>
  );
}

/**
 * A real multiselect (F6 `type: multiselect`). The stored value is the
 * COMMA-SEPARATED string the runtime parses — AssignWebsites explodes on ","
 * and trims — so the control serializes to exactly that, and an emptied
 * selection removes the config key (writeValue's '' convention).
 */
function MultiSelectField({
  label,
  options,
  value,
  required,
  notice,
  readOnly,
  onChange,
}: {
  label: string;
  options: ConfigFieldOption[];
  value: unknown;
  required?: boolean;
  notice?: string | null;
  readOnly: boolean;
  onChange: (value: unknown) => void;
}): JSX.Element {
  const selected = parseMultiValue(value);
  const rendered = multiSelectOptions(options, selected);
  return (
    <label className="wf-field wf-field--multi">
      <span className="wf-field__label">{label}{required ? ' *' : ''}</span>
      <select
        multiple
        size={Math.min(8, Math.max(3, rendered.length))}
        value={selected}
        disabled={readOnly}
        onChange={(e) =>
          onChange(serializeMultiValue(Array.from(e.target.selectedOptions).map((o) => o.value)))
        }
      >
        {rendered.map((o) => (
          <option key={o.value} value={o.value}>
            {o.label}
          </option>
        ))}
      </select>
      <span className="wf-field__notice">Hold Ctrl (⌘ on macOS) to select more than one.</span>
      {notice && <span className="wf-field__notice">{notice}</span>}
    </label>
  );
}

/**
 * A search-typed select (F6 options_search): fetches from the same-origin
 * meta/options proxy once the query reaches min_chars, debounced by
 * SEARCH_DEBOUNCE_MS so a fast typist costs one request rather than one per
 * character (the install form's search widget uses the same 250ms).
 *
 * The persisted value is always rendered as a REAL option, never as the empty
 * placeholder: with the value sitting on `value=""`, re-picking the item the
 * field already showed cleared it. Its label is remembered from whichever fetch
 * first resolved it, so a restored value reads as a name rather than a bare id.
 *
 * A MULTISELECT field (field.multi) reuses the same fetch loop in chips mode:
 * the stored comma list renders as removable chips, and the select ADDS the
 * picked result to the list (addToMultiValue) instead of replacing it. Labels
 * are resolved per value through the same one-shot lookup as the single mode.
 */
function SearchSelectField({
  field,
  value,
  config,
  readOnly,
  onChange,
}: {
  field: NormalizedField;
  value: unknown;
  config: MountConfig;
  readOnly: boolean;
  onChange: (value: string) => void;
}): JSX.Element {
  const multi = field.multi;
  const selected = multi ? parseMultiValue(value) : [];
  const single = multi ? '' : String(value ?? '');
  // The values whose labels are worth resolving: the whole selection (multi) or
  // the one persisted value (single). Keyed as a string for effect deps.
  const wanted = multi ? selected : single !== '' ? [single] : [];
  const wantedKey = wanted.join(',');
  const [query, setQuery] = useState('');
  const [debounced, setDebounced] = useState('');
  const [options, setOptions] = useState<ConfigFieldOption[]>([]);
  const [labels, setLabels] = useState<Record<string, string>>({});
  const [loading, setLoading] = useState(false);
  // Values already looked up once — a source that cannot resolve one leaves the
  // bare id showing; there is no retry.
  const attempted = useRef<Set<string>>(new Set());
  const source = field.searchSource;
  const endpoint = config.endpoints.options;

  useEffect(() => {
    const timer = setTimeout(() => setDebounced(query), SEARCH_DEBOUNCE_MS);
    return () => clearTimeout(timer);
  }, [query]);

  // Resolve labels for the PERSISTED value(s) up front, by querying the proxy
  // for each value itself — the same trick the install form plays server-side
  // (Install::currentValueLabel calls the source's fetch($value)). Without it a
  // restored record reads as a bare id until the operator happens to type a
  // query that includes it.
  useEffect(() => {
    if (!source) {
      return;
    }
    const pending = wanted.filter((v) => !attempted.current.has(v));
    if (pending.length === 0) {
      return;
    }
    let cancelled = false;
    for (const v of pending) {
      attempted.current.add(v);
      const url = `${endpoint}?source=${encodeURIComponent(source)}&q=${encodeURIComponent(v)}`;
      fetch(url, { credentials: 'same-origin', headers: { Accept: 'application/json' } })
        .then((r) => (r.ok ? r.json() : { options: [] }))
        .then((body: { options?: ConfigFieldOption[] }) => {
          const resolved = labelForValue(body.options ?? [], v);
          if (!cancelled && resolved !== null) {
            setLabels((prev) => ({ ...prev, [v]: resolved }));
          }
        })
        .catch(() => undefined);
    }
    return () => {
      cancelled = true;
    };
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [wantedKey, source, endpoint]);

  useEffect(() => {
    if (!field.searchSource) {
      return;
    }
    if (!shouldSearch(field, debounced)) {
      return;
    }
    let cancelled = false;
    setLoading(true);
    const url = `${config.endpoints.options}?source=${encodeURIComponent(field.searchSource)}&q=${encodeURIComponent(debounced)}`;
    fetch(url, { credentials: 'same-origin', headers: { Accept: 'application/json' } })
      .then((r) => (r.ok ? r.json() : { options: [] }))
      .then((body: { options?: ConfigFieldOption[] }) => {
        if (!cancelled) {
          const fetched = body.options ?? [];
          setOptions(fetched);
          // Remember labels for persisted values the first time a fetch
          // resolves them, so they survive the next (narrower) query.
          setLabels((prev) => {
            let next = prev;
            for (const v of wanted) {
              const resolved = labelForValue(fetched, v);
              if (resolved !== null && prev[v] !== resolved) {
                next = next === prev ? { ...prev } : next;
                next[v] = resolved;
              }
            }
            return next;
          });
        }
      })
      .catch(() => {
        if (!cancelled) {
          setOptions([]);
        }
      })
      .finally(() => {
        if (!cancelled) {
          setLoading(false);
        }
      });
    return () => {
      cancelled = true;
    };
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [debounced, field, wantedKey, config.endpoints.options]);

  const rendered = multi
    ? searchAddOptions(options, selected)
    : optionsWithSelected(options, single, labels[single] ?? null);

  return (
    <div className="wf-field wf-field--search">
      <span className="wf-field__label" id={`wf-search-${field.name}`}>
        {field.label}{field.required ? ' *' : ''}
      </span>
      {multi && selected.length > 0 && (
        <ul className="wf-chips" aria-labelledby={`wf-search-${field.name}`}>
          {selected.map((v) => (
            <li className="wf-chips__chip" key={v}>
              <span className="wf-chips__label">{labels[v] ?? v}</span>
              <button
                type="button"
                className="wf-chips__remove"
                aria-label={`Remove ${labels[v] ?? v}`}
                disabled={readOnly}
                onClick={() => onChange(removeFromMultiValue(value, v))}
              >
                ×
              </button>
            </li>
          ))}
        </ul>
      )}
      <input
        type="text"
        aria-label={`Search ${field.label}`}
        placeholder={`Search (min ${field.minChars} chars)…`}
        value={query}
        disabled={readOnly}
        onChange={(e) => setQuery(e.target.value)}
      />
      {loading && <span className="wf-field__notice">Searching…</span>}
      {multi ? (
        // Always sits on the placeholder: picking a result ADDS it as a chip.
        <select
          aria-labelledby={`wf-search-${field.name}`}
          value=""
          disabled={readOnly}
          onChange={(e) => {
            if (e.target.value !== '') {
              onChange(addToMultiValue(value, e.target.value));
            }
          }}
        >
          <option value="">— add —</option>
          {rendered.map((o) => (
            <option key={o.value} value={o.value}>
              {o.label}
            </option>
          ))}
        </select>
      ) : (
        <select
          aria-labelledby={`wf-search-${field.name}`}
          value={single}
          disabled={readOnly}
          onChange={(e) => onChange(e.target.value)}
        >
          <option value="">— select —</option>
          {rendered.map((o) => (
            <option key={o.value} value={o.value}>
              {o.label}
            </option>
          ))}
        </select>
      )}
      {field.notice && <span className="wf-field__notice">{field.notice}</span>}
    </div>
  );
}
