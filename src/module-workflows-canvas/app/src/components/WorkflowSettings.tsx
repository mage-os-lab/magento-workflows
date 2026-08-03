import { useEffect, useRef, useState } from 'react';
import type { ConfigFieldOption, TriggerMeta, WorkflowOptions } from '../types';
import { eventOptionGroups, isCataloguedEvent } from '../configPanel';
import { t } from '../i18n';
import { metaSaveError, toggleWebsite, type EditableMeta } from '../workflowMeta';

/**
 * The workflow-settings slide-out (canvas-first authoring): the general fields
 * the classic form otherwise owns, edited beside the graph so a NEW workflow
 * can be authored end-to-end on the canvas. Same slide-out shell as the
 * condition editor (backdrop, Escape, focus hand-back); edits commit to the
 * editor's meta state on every change — there is no local Apply buffer, the
 * save POST is the commit point and the server re-validates everything.
 *
 * Option lists come from config.workflowOptions — the SAME sources the classic
 * form's selects use (Mount.php) — and the event trigger reference reuses the
 * bootstrapped trigger catalogue with the wait step's free-entry escape hatch.
 */
interface Props {
  meta: EditableMeta;
  options: WorkflowOptions;
  triggers: TriggerMeta[];
  readOnly: boolean;
  onChange: (meta: EditableMeta) => void;
  onClose: () => void;
}

export function WorkflowSettings({
  meta,
  options,
  triggers,
  readOnly,
  onChange,
  onClose,
}: Props): JSX.Element {
  const panelRef = useRef<HTMLDivElement | null>(null);

  // Focus in on open, back to the opener on close; Escape closes.
  useEffect(() => {
    const opener = document.activeElement as HTMLElement | null;
    panelRef.current?.focus();
    return () => opener?.focus?.();
  }, []);

  useEffect(() => {
    const onKey = (e: KeyboardEvent): void => {
      if (e.key === 'Escape') {
        e.stopPropagation();
        onClose();
      }
    };
    window.addEventListener('keydown', onKey);
    return () => window.removeEventListener('keydown', onKey);
  }, [onClose]);

  const set = (patch: Partial<EditableMeta>): void => {
    onChange({ ...meta, ...patch });
  };

  const nameError = metaSaveError(meta);

  return (
    <div className="wf-slideout" role="dialog" aria-modal="true" aria-label={t('Workflow settings')}>
      <div className="wf-slideout__backdrop" onClick={onClose} />
      <div className="wf-slideout__panel" ref={panelRef} tabIndex={-1}>
        <header className="wf-slideout__head">
          <h3>{t('Workflow settings')}</h3>
          <button type="button" aria-label={t('Close')} onClick={onClose}>
            ×
          </button>
        </header>

        <label className="wf-field">
          <span className="wf-field__label">{t('Name')} *</span>
          <input
            type="text"
            value={meta.name}
            disabled={readOnly}
            onChange={(e) => set({ name: e.target.value })}
          />
        </label>
        {nameError !== null && (
          <span className="wf-field__notice wf-field__notice--error" role="alert">
            {nameError}
          </span>
        )}

        <MetaSelect
          label={t('Status')}
          options={options.statuses}
          value={String(meta.status)}
          readOnly={readOnly}
          onChange={(v) => set({ status: Number(v) })}
        />

        <MetaSelect
          label={t('Entity type')}
          options={options.entityTypes}
          value={meta.entityType}
          placeholder={t('— select —')}
          readOnly={readOnly}
          onChange={(v) => set({ entityType: v })}
        />

        <MetaSelect
          label={t('Trigger type')}
          options={options.triggerTypes}
          value={meta.triggerType}
          readOnly={readOnly}
          onChange={(v) => set({ triggerType: v })}
        />

        <TriggerRefField
          triggerType={meta.triggerType}
          value={meta.triggerRef}
          triggers={triggers}
          readOnly={readOnly}
          onChange={(v) => set({ triggerRef: v })}
        />

        <fieldset className="wf-field wf-settings__websites">
          <legend className="wf-field__label">{t('Websites')}</legend>
          {options.websites.map((o) => (
            <label key={o.value} className="wf-field--bool wf-settings__website">
              <input
                type="checkbox"
                checked={meta.websiteIds.includes(Number(o.value))}
                disabled={readOnly}
                onChange={(e) =>
                  set({ websiteIds: toggleWebsite(meta.websiteIds, Number(o.value), e.target.checked) })
                }
              />
              {o.label}
            </label>
          ))}
        </fieldset>

        <footer className="wf-slideout__foot">
          <div className="wf-slideout__buttons">
            <button type="button" onClick={onClose}>
              {t('Done')}
            </button>
          </div>
        </footer>
      </div>
    </div>
  );
}

function MetaSelect({
  label,
  options,
  value,
  placeholder,
  readOnly,
  onChange,
}: {
  label: string;
  options: ConfigFieldOption[];
  value: string;
  /** Offered as the empty option when the stored value may legally be ''. */
  placeholder?: string;
  readOnly: boolean;
  onChange: (value: string) => void;
}): JSX.Element {
  return (
    <label className="wf-field">
      <span className="wf-field__label">{label}</span>
      <select value={value} disabled={readOnly} onChange={(e) => onChange(e.target.value)}>
        {placeholder !== undefined && <option value="">{placeholder}</option>}
        {options.map((o) => (
          <option key={o.value} value={o.value}>
            {o.label}
          </option>
        ))}
      </select>
    </label>
  );
}

/**
 * The trigger reference, shaped by the trigger type:
 *   - event    : the bootstrapped trigger catalogue as a grouped select (the
 *                same optgroup pattern as the wait step's EventField), with a
 *                free-entry escape hatch for an unregistered event;
 *   - schedule : the cron expression as plain text;
 *   - manual   : nothing — a manual workflow has no reference.
 */
function TriggerRefField({
  triggerType,
  value,
  triggers,
  readOnly,
  onChange,
}: {
  triggerType: string;
  value: string;
  triggers: TriggerMeta[];
  readOnly: boolean;
  onChange: (value: string) => void;
}): JSX.Element | null {
  const groups = eventOptionGroups(triggers);
  const catalogued = isCataloguedEvent(triggers, value);
  const [manual, setManual] = useState(
    () => groups.length === 0 || (value !== '' && !catalogued),
  );

  if (triggerType === 'schedule') {
    return (
      <label className="wf-field">
        <span className="wf-field__label">{t('Schedule (cron)')}</span>
        <input
          type="text"
          value={value}
          disabled={readOnly}
          spellCheck={false}
          onChange={(e) => onChange(e.target.value)}
        />
        <span className="wf-field__notice">
          {t("A cron expression in the store's timezone, e.g. 0 3 * * * for daily at 03:00.")}
        </span>
      </label>
    );
  }

  if (triggerType !== 'event') {
    return null;
  }

  const free = manual || groups.length === 0;

  return (
    <div className="wf-field">
      <span className="wf-field__label" id="wf-settings-trigger-ref">
        {t('Trigger event')}
      </span>
      {free ? (
        <input
          type="text"
          aria-labelledby="wf-settings-trigger-ref"
          value={value}
          disabled={readOnly}
          spellCheck={false}
          onChange={(e) => onChange(e.target.value)}
        />
      ) : (
        <select
          aria-labelledby="wf-settings-trigger-ref"
          value={value}
          disabled={readOnly}
          onChange={(e) => onChange(e.target.value)}
        >
          <option value="">{t('— select —')}</option>
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
          {free ? t('Choose a registered event instead') : t('Enter an event name instead')}
        </button>
      )}
    </div>
  );
}
