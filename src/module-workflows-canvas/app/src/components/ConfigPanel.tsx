import { useCallback, useEffect, useState } from 'react';
import type { ConfigFieldOption, GraphNode, MountConfig, StepNode } from '../types';
import {
  normalizeConfigForm,
  readValue,
  shouldSearch,
  writeValue,
  type NormalizedField,
} from '../configPanel';
import { actionByCode } from '../palette';
import type { ConditionTarget } from '../conditionTarget';
import { buildVariablePaths } from '../variablePicker';

/**
 * The node config side panel (Phase B), generated entirely from getConfigForm()
 * metadata (F6). Every value is rendered into a form control — never eval'd,
 * never innerHTML. Option selects resolve the F6 union: inline lists render
 * statically, options_search fields hit the same-origin meta/options proxy as
 * the user types (min_chars-gated). The variable picker lists context paths +
 * upstream step outputs + secret NAMES (values never leave the server).
 *
 * The panel edits an immutable copy of the step and hands the result up via
 * onChange; the parent commits it to the graph (and the undo history).
 */
interface Props {
  node: GraphNode;
  config: MountConfig;
  graph: import('../types').Graph;
  readOnly: boolean;
  onChange: (stepKey: string, step: StepNode) => void;
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

      {/* A switch step has no tree of its own: each case carries one. Only the
          per-case entry points into the slide-out live here — the case LIST
          editor (add / rename / remove / reorder, keeping `case:<key>` edges
          consistent) is a separate follow-up and owns this panel. */}
      {step.type === 'switch' && (
        <div className="wf-panel__conditions">
          {(Array.isArray(step.cases) ? step.cases : []).map((c, index) => (
            <button
              key={`${c.key}-${index}`}
              type="button"
              disabled={readOnly}
              onClick={() =>
                onEditConditions({
                  scope: 'case',
                  stepKey: node.id,
                  caseIndex: index,
                  caseKey: c.key,
                })
              }
            >
              {`Edit conditions: ${c.key}…`}
            </button>
          ))}
          {(step.cases?.length ?? 0) === 0 && (
            <p className="wf-field__notice">This switch has no cases yet.</p>
          )}
        </div>
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
          <TextField
            label="Event name"
            value={String((step.config as Record<string, unknown>)?.event ?? '')}
            readOnly={readOnly}
            onChange={(v) => set('event', v)}
          />
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
  return (
    <TextField
      label="Duration (ISO-8601, e.g. PT1H)"
      value={String(config.duration ?? '')}
      readOnly={readOnly}
      onChange={(v) => onChange('duration', v)}
    />
  );
}

/**
 * Approval gate config (schema 4). Same fidelity as the wait/switch panels
 * above: plain fields for the scalar config (title/instructions/timeout/
 * assignee_role/allow_bulk), and — for the two array-shaped fields
 * (payload_fields, notify_emails) — the same "edit as JSON" fallback already
 * established for structured config in this codebase (ConditionSlideOut's
 * condition-tree textarea), rather than a bespoke per-row array editor. The
 * server re-validates everything on save (Definition::assertApprovalStep).
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
      <TextField
        label="Timeout (ISO-8601, e.g. P3D) *"
        value={String(config.timeout ?? '')}
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
      <JsonArrayField
        label="Payload fields (JSON array, e.g. [{&quot;key&quot;:&quot;amount&quot;,&quot;label&quot;:&quot;Amount&quot;,&quot;type&quot;:&quot;number&quot;}])"
        value={config.payload_fields}
        readOnly={readOnly}
        onChange={(v) => onChange('payload_fields', v)}
      />
      <JsonArrayField
        label="Notify emails (JSON array of strings)"
        value={config.notify_emails}
        readOnly={readOnly}
        onChange={(v) => onChange('notify_emails', v)}
      />
    </>
  );
}

/**
 * A JSON-array-backed field: local text buffer, committed on blur only when
 * it parses as valid JSON array (or is empty, which clears the config key via
 * writeValue's undefined convention). Invalid JSON is left uncommitted with an
 * inline notice — the same buffer/apply shape as ConditionSlideOut, just
 * inline instead of in a slide-out (these fields are short, optional lists).
 */
function JsonArrayField({
  label,
  value,
  readOnly,
  onChange,
}: {
  label: string;
  value: unknown;
  readOnly: boolean;
  onChange: (value: unknown) => void;
}): JSX.Element {
  const [text, setText] = useState(() => (value === undefined ? '' : JSON.stringify(value, null, 2)));
  const [error, setError] = useState('');

  useEffect(() => {
    setText(value === undefined ? '' : JSON.stringify(value, null, 2));
    setError('');
  }, [value]);

  const commit = (raw: string): void => {
    const trimmed = raw.trim();
    if (trimmed === '') {
      setError('');
      onChange(undefined);
      return;
    }
    let parsed: unknown;
    try {
      parsed = JSON.parse(trimmed);
    } catch {
      setError('Not valid JSON.');
      return;
    }
    if (!Array.isArray(parsed)) {
      setError('Must be a JSON array.');
      return;
    }
    setError('');
    onChange(parsed);
  };

  return (
    <label className="wf-field">
      <span className="wf-field__label">{label}</span>
      <textarea
        value={text}
        disabled={readOnly}
        spellCheck={false}
        rows={4}
        onChange={(e) => setText(e.target.value)}
        onBlur={(e) => commit(e.target.value)}
      />
      {error && (
        <span className="wf-field__notice" role="alert">
          {error}
        </span>
      )}
    </label>
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
  if (field.optionMode === 'search') {
    return (
      <SearchSelectField
        field={field}
        value={String(value ?? '')}
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
 * A search-typed select (F6 options_search): fetches from the same-origin
 * meta/options proxy once the query reaches min_chars. Debouncing is trivial
 * here (fetch on change ≥ threshold); the endpoint caps results.
 */
function SearchSelectField({
  field,
  value,
  config,
  readOnly,
  onChange,
}: {
  field: NormalizedField;
  value: string;
  config: MountConfig;
  readOnly: boolean;
  onChange: (value: string) => void;
}): JSX.Element {
  const [query, setQuery] = useState('');
  const [options, setOptions] = useState<ConfigFieldOption[]>([]);
  const [loading, setLoading] = useState(false);

  useEffect(() => {
    if (!field.searchSource) {
      return;
    }
    if (!shouldSearch(field, query)) {
      return;
    }
    let cancelled = false;
    setLoading(true);
    const url = `${config.endpoints.options}?source=${encodeURIComponent(field.searchSource)}&q=${encodeURIComponent(query)}`;
    fetch(url, { credentials: 'same-origin', headers: { Accept: 'application/json' } })
      .then((r) => (r.ok ? r.json() : { options: [] }))
      .then((body: { options?: ConfigFieldOption[] }) => {
        if (!cancelled) {
          setOptions(body.options ?? []);
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
  }, [query, field, config.endpoints.options]);

  return (
    <div className="wf-field wf-field--search">
      <span className="wf-field__label">{field.label}{field.required ? ' *' : ''}</span>
      <input
        type="text"
        placeholder={`Search (min ${field.minChars} chars)…`}
        value={query}
        disabled={readOnly}
        onChange={(e) => setQuery(e.target.value)}
      />
      {loading && <span className="wf-field__notice">Searching…</span>}
      <select value={value} disabled={readOnly} onChange={(e) => onChange(e.target.value)}>
        <option value="">{value ? value : '— select —'}</option>
        {options.map((o) => (
          <option key={o.value} value={o.value}>
            {o.label}
          </option>
        ))}
      </select>
      {field.notice && <span className="wf-field__notice">{field.notice}</span>}
    </div>
  );
}
