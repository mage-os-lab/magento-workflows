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
  onEditConditions: (stepKey: string) => void;
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

      {(step.type === 'branch' || step.type === 'switch') && (
        <div className="wf-panel__conditions">
          <button type="button" disabled={readOnly} onClick={() => onEditConditions(node.id)}>
            Edit conditions…
          </button>
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
