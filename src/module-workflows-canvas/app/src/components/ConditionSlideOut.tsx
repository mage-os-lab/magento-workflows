import { useEffect, useState } from 'react';
import type { StepNode } from '../types';

/**
 * Condition editor slide-out (E1 host). Stage 4 ships the "edit as JSON"
 * fallback (E3) unconditionally; Stage 5 loads the shared admin-ui
 * server-rendered rule-widget fragment into the fragment host below when it is
 * available, and posts the serialized tree back here. The JSON toggle is
 * retained for power users either way (docs/discovery/canvas.md §6).
 *
 * The value is a serialized condition tree (same shape as salesrule/catalogrule)
 * carried as `conditions_serialized`. It is rendered into a textarea — never
 * eval'd, never innerHTML. On apply it is handed back to the editor, which
 * commits it to the step; the server re-validates its shape on save
 * (ConditionsShapeCheck).
 */
interface Props {
  step: StepNode | null;
  stepKey: string;
  onApply: (stepKey: string, conditionsSerialized: string | null) => void;
  onClose: () => void;
}

export function ConditionSlideOut({ step, stepKey, onApply, onClose }: Props): JSX.Element {
  const initial = typeof step?.conditions_serialized === 'string' ? step.conditions_serialized : '';
  const [value, setValue] = useState(initial);
  const [error, setError] = useState('');

  useEffect(() => {
    setValue(initial);
    setError('');
  }, [initial]);

  const apply = (): void => {
    const trimmed = value.trim();
    if (trimmed === '') {
      onApply(stepKey, null);
      return;
    }
    try {
      JSON.parse(trimmed);
    } catch {
      setError('Conditions must be valid JSON (a serialized condition tree). The server re-validates on save.');
      return;
    }
    onApply(stepKey, trimmed);
  };

  return (
    <div className="wf-slideout" role="dialog" aria-modal="true" aria-label={`Conditions for ${stepKey}`}>
      <div className="wf-slideout__backdrop" onClick={onClose} />
      <div className="wf-slideout__panel">
        <header className="wf-slideout__head">
          <h3>Conditions — {stepKey}</h3>
          <button type="button" aria-label="Close" onClick={onClose}>
            ×
          </button>
        </header>

        {/* Stage 5 mounts the server-rendered rule-widget fragment here when the
            shared admin-ui asset is present; the JSON editor stays as the
            documented fallback + power-user toggle. */}
        <div className="wf-slideout__fragment" data-role="mageos-workflows-conditions-fragment" />

        <label className="wf-field">
          <span className="wf-field__label">Condition tree (JSON)</span>
          <textarea
            className="wf-slideout__json"
            value={value}
            onChange={(e) => setValue(e.target.value)}
            spellCheck={false}
            rows={14}
          />
        </label>
        {error && (
          <p className="wf-slideout__error" role="alert">
            {error}
          </p>
        )}

        <footer className="wf-slideout__foot">
          <button type="button" onClick={onClose}>
            Cancel
          </button>
          <button type="button" className="wf-slideout__apply" onClick={apply}>
            Apply
          </button>
        </footer>
      </div>
    </div>
  );
}
