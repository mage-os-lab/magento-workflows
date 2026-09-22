import { useState } from 'react';
import type { ConfigFieldOption, TriggerMeta, WorkflowOptions } from '../types';
import { eventOptionGroups, isCataloguedEvent } from '../configPanel';
import { t } from '../i18n';
import { metaSaveError, toggleWebsite, type EditableMeta } from '../workflowMeta';

/**
 * The workflow-settings panel: the general fields the classic form otherwise
 * owns (name, status, entity type, trigger, website scope), rendered
 * PERSISTENTLY above the canvas — not behind a modal — so the workflow's
 * identity and trigger are always visible while the graph is edited. Edits
 * commit to the editor's meta state on every change; there is no Apply
 * buffer, the save POST is the commit point and the server re-validates
 * everything.
 *
 * Markup deliberately reuses the Magento admin form vocabulary
 * (admin__field / admin__field-label / admin__control-*) so the panel renders
 * as a native part of the admin theme; .wf-settings only contributes the
 * one-row grid the theme has no pattern for.
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
}

/** The name input's DOM id — the save gate focuses it on a nameless save. */
export const SETTINGS_NAME_INPUT_ID = 'wf-settings-name';

export function WorkflowSettings({
  meta,
  options,
  triggers,
  readOnly,
  onChange,
}: Props): JSX.Element {
  const set = (patch: Partial<EditableMeta>): void => {
    onChange({ ...meta, ...patch });
  };

  const nameError = metaSaveError(meta);

  return (
    <section className="wf-settings admin__fieldset" aria-label={t('Workflow settings')}>
      <div className={`admin__field wf-settings__field wf-settings__field--name required${nameError !== null ? ' _error' : ''}`}>
        <label className="admin__field-label" htmlFor={SETTINGS_NAME_INPUT_ID}>
          <span>{t('Name')}</span>
        </label>
        <div className="admin__field-control">
          <input
            id={SETTINGS_NAME_INPUT_ID}
            className="admin__control-text"
            type="text"
            value={meta.name}
            disabled={readOnly}
            onChange={(e) => set({ name: e.target.value })}
          />
          {nameError !== null && (
            <label className="admin__field-error" role="alert">
              {nameError}
            </label>
          )}
        </div>
      </div>

      <MetaSelect
        id="wf-settings-status"
        label={t('Status')}
        options={options.statuses}
        value={String(meta.status)}
        readOnly={readOnly}
        onChange={(v) => set({ status: Number(v) })}
        note={
          // "Shadow" is a third state next to Enabled/Disabled that nothing
          // else on screen explains — say what it does where it's chosen.
          options.statuses
            .find((o) => o.value === String(meta.status))
            ?.label.toLowerCase()
            .includes('shadow')
            ? t('Shadow mode runs silently: conditions are evaluated on live traffic and every action logs what it WOULD do, without doing it.')
            : undefined
        }
      />

      <MetaSelect
        id="wf-settings-entity-type"
        label={t('Applies to')}
        options={options.entityTypes}
        value={meta.entityType}
        placeholder={t('— select —')}
        readOnly={readOnly}
        onChange={(v) => set({ entityType: v })}
      />

      <MetaSelect
        id="wf-settings-trigger-type"
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

      {options.websites.length > 1 && (
        <fieldset className="admin__field wf-settings__field wf-settings__websites">
          <legend className="admin__field-label">
            <span>{t('Websites')}</span>
          </legend>
          <div className="admin__field-control">
            {options.websites.map((o) => (
              <label key={o.value} className="admin__field-option wf-settings__website">
                <input
                  className="admin__control-checkbox"
                  type="checkbox"
                  checked={meta.websiteIds.includes(Number(o.value))}
                  disabled={readOnly}
                  onChange={(e) =>
                    set({ websiteIds: toggleWebsite(meta.websiteIds, Number(o.value), e.target.checked) })
                  }
                />
                <span>{o.label}</span>
              </label>
            ))}
          </div>
        </fieldset>
      )}
    </section>
  );
}

function MetaSelect({
  id,
  label,
  options,
  value,
  placeholder,
  readOnly,
  note,
  onChange,
}: {
  id: string;
  label: string;
  options: ConfigFieldOption[];
  value: string;
  /** Offered as the empty option when the stored value may legally be ''. */
  placeholder?: string;
  readOnly: boolean;
  /** Contextual explanation shown under the select (e.g. shadow mode). */
  note?: string;
  onChange: (value: string) => void;
}): JSX.Element {
  return (
    <div className="admin__field wf-settings__field">
      <label className="admin__field-label" htmlFor={id}>
        <span>{label}</span>
      </label>
      <div className="admin__field-control">
        <select
          id={id}
          className="admin__control-select"
          value={value}
          disabled={readOnly}
          onChange={(e) => onChange(e.target.value)}
        >
          {placeholder !== undefined && <option value="">{placeholder}</option>}
          {options.map((o) => (
            <option key={o.value} value={o.value}>
              {o.label}
            </option>
          ))}
        </select>
        {note !== undefined && (
          <div className="admin__field-note wf-settings__note">
            <span>{note}</span>
          </div>
        )}
      </div>
    </div>
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
      <div className="admin__field wf-settings__field">
        <label className="admin__field-label" htmlFor="wf-settings-trigger-ref">
          <span>{t('Schedule (cron)')}</span>
        </label>
        <div className="admin__field-control">
          <input
            id="wf-settings-trigger-ref"
            className="admin__control-text"
            type="text"
            value={value}
            disabled={readOnly}
            spellCheck={false}
            onChange={(e) => onChange(e.target.value)}
          />
          <div className="admin__field-note wf-settings__note">
            <span>{t("A cron expression in the store's timezone, e.g. 0 3 * * * for daily at 03:00.")}</span>
          </div>
        </div>
      </div>
    );
  }

  if (triggerType !== 'event') {
    return null;
  }

  const free = manual || groups.length === 0;

  return (
    <div className="admin__field wf-settings__field">
      <label className="admin__field-label" htmlFor="wf-settings-trigger-ref">
        <span>{t('Trigger event')}</span>
      </label>
      <div className="admin__field-control">
        {free ? (
          <input
            id="wf-settings-trigger-ref"
            className="admin__control-text"
            type="text"
            value={value}
            disabled={readOnly}
            spellCheck={false}
            onChange={(e) => onChange(e.target.value)}
          />
        ) : (
          <select
            id="wf-settings-trigger-ref"
            className="admin__control-select"
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
        {/* A typo here saves fine and then the workflow simply never runs —
            warn while typing, not just at save time (preSave checks it too). */}
        {free && value !== '' && !catalogued && groups.length > 0 && (
          <div className="admin__field-note wf-settings__note wf-settings__note--warning" role="alert">
            <span>{t('This does not match any registered event, so the workflow may never run. Check the spelling, or pick from the registered list.')}</span>
          </div>
        )}
      </div>
    </div>
  );
}
