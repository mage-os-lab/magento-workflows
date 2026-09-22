import type { DragEvent } from 'react';
import type { MountConfig } from '../types';
import { t } from '../i18n';
import { buildPalette, type PaletteItem } from '../palette';

/**
 * The editor palette (Phase B). Drag an item onto the canvas to add a node. The
 * drag payload is a small JSON descriptor carried in dataTransfer — read back
 * on drop by the Editor, which computes the flow-space position and adds the
 * node through the pure graphOps.addNode. Keyboard users add via the "Add"
 * button (click-to-add at a default position), so the palette is not
 * drag-only (a11y).
 *
 * The action list is already ACL-filtered server-side (the provider hides
 * actions the admin cannot author); the palette only re-shapes it.
 */
export interface PaletteDragPayload {
  type: string;
  action?: string;
}

export function payloadOf(item: PaletteItem): PaletteDragPayload {
  return item.kind === 'action' ? { type: 'action', action: item.code } : { type: item.type };
}

interface Props {
  config: MountConfig;
  onAdd: (payload: PaletteDragPayload) => void;
}

export function Palette({ config, onAdd }: Props): JSX.Element {
  const groups = buildPalette(config);

  const onDragStart = (event: DragEvent, item: PaletteItem): void => {
    event.dataTransfer.setData('application/mageos-workflow-node', JSON.stringify(payloadOf(item)));
    event.dataTransfer.effectAllowed = 'copy';
  };

  return (
    <nav className="wf-palette" aria-label={t('Step palette')}>
      {groups.map((group) => (
        <section key={group.label} className="wf-palette__group">
          <h4 className="wf-palette__group-title">{group.label}</h4>
          <ul className="wf-palette__list">
            {group.items.map((item) => {
              const label = item.kind === 'action' ? item.label : item.label;
              const key = item.kind === 'action' ? item.code : item.type;
              return (
                <li key={key}>
                  <div
                    className="wf-palette__item"
                    draggable
                    onDragStart={(e) => onDragStart(e, item)}
                  >
                    <span className="wf-palette__item-label">{label}</span>
                    <button
                      type="button"
                      className="wf-palette__add"
                      aria-label={`${t('Add')} ${label}`}
                      onClick={() => onAdd(payloadOf(item))}
                    >
                      {t('Add')}
                    </button>
                  </div>
                </li>
              );
            })}
          </ul>
        </section>
      ))}
      {/* The connect gesture is invisible until someone tells you it exists. */}
      <p className="wf-palette__howto">
        {t('To connect steps, drag from a dot on the bottom edge of one step to the top of another. Click a step or connection and press Delete to remove it.')}
      </p>
    </nav>
  );
}
