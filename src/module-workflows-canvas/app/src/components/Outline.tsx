import type { Graph } from '../types';
import { getStepEdges, edgeLabel } from '../edges';
import { t } from '../i18n';
import type { Overlay } from '../overlay';
import type { TriggerCard } from '../triggerNode';

/**
 * Read-only outline/list rendering of the graph on the same page — the
 * screen-reader path (docs/discovery/canvas.md §7, C3's legacy). Plain semantic
 * HTML: an ordered list of steps, each with its type, summary, status, and
 * outgoing edges. All strings are React text nodes.
 *
 * When `onSelect` is supplied (the editor), each step key becomes a real
 * <button>, giving keyboard/screen-reader users a focus-ordered path to open a
 * step's config panel without the pointer-driven canvas — the WCAG owned focus
 * order the canvas page commits to.
 */
export function Outline({
  graph,
  overlay,
  onSelect,
  selected,
  trigger,
}: {
  graph: Graph;
  overlay: Overlay;
  onSelect?: (stepKey: string) => void;
  selected?: string | null;
  /** The trigger card, rendered ahead of the steps (same data as the canvas). */
  trigger?: TriggerCard;
}): JSX.Element {
  return (
    <section className="wf-outline" aria-label={t('Workflow outline')}>
      <h3 className="wf-outline__title">{t('Outline')}</h3>
      {trigger && (
        <p className="wf-outline__trigger">
          <strong>{t('Trigger')}</strong>
          {': '}
          {trigger.title}
          {trigger.entity !== '' && ` · ${trigger.entity}`}
          {' — '}
          {trigger.conditions !== null
            ? `${t('Only if')}: ${trigger.conditions}`
            : t('No conditions — this always runs.')}
        </p>
      )}
      <ol className="wf-outline__list">
        {graph.nodes.map((n) => {
          const status = overlay.nodeStatus[n.id];
          // Routing comes from graph.edges — the SAME model a save serializes —
          // never from the step data, which connect() deliberately leaves
          // untouched. Reading the step here made the outline lie after every
          // rewire (it kept showing the pre-edit target). Handles with no edge
          // are listed as "(not connected)": a dead switch case or dangling
          // branch path is a routing fact the reader must see, not a blank.
          const handles = Object.keys(getStepEdges(n.data.step));
          const targetOf = new Map(
            graph.edges.filter((e) => e.source === n.id).map((e) => [e.sourceHandle, e.target]),
          );
          const edges = handles.map((name) => [name, targetOf.get(name) ?? null] as const);
          const keyNode = onSelect ? (
            <button
              type="button"
              className="wf-outline__key wf-outline__key--button"
              aria-pressed={selected === n.id}
              onClick={() => onSelect(n.id)}
            >
              {n.id}
            </button>
          ) : (
            <span className="wf-outline__key">{n.id}</span>
          );
          return (
            <li key={n.id} className="wf-outline__item">
              {keyNode}
              {n.data.isEntry && <span className="wf-outline__entry"> ({t('entry')})</span>}
              {': '}
              <span className="wf-outline__summary">{n.data.summary}</span>
              {status && <span className="wf-outline__status"> — {status}</span>}
              {n.data.degraded && <span className="wf-outline__degraded"> — {t('unavailable action')}</span>}
              {edges.length > 0 && (
                <ul className="wf-outline__edges">
                  {edges.map(([name, target]) => (
                    <li key={name}>
                      {edgeLabel(name) || t('next')} →{' '}
                      {target !== null ? (
                        target
                      ) : (
                        <span className="wf-outline__unconnected">{t('(not connected)')}</span>
                      )}
                    </li>
                  ))}
                </ul>
              )}
            </li>
          );
        })}
      </ol>
    </section>
  );
}
