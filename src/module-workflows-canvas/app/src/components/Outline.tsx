import type { Graph } from '../types';
import { getStepEdges, edgeLabel } from '../edges';
import type { Overlay } from '../overlay';

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
}: {
  graph: Graph;
  overlay: Overlay;
  onSelect?: (stepKey: string) => void;
  selected?: string | null;
}): JSX.Element {
  return (
    <section className="wf-outline" aria-label="Workflow outline">
      <h3 className="wf-outline__title">Outline</h3>
      <ol className="wf-outline__list">
        {graph.nodes.map((n) => {
          const status = overlay.nodeStatus[n.id];
          const edges = Object.entries(getStepEdges(n.data.step)).filter(([, t]) => t !== null);
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
              {n.data.isEntry && <span className="wf-outline__entry"> (entry)</span>}
              {': '}
              <span className="wf-outline__summary">{n.data.summary}</span>
              {status && <span className="wf-outline__status"> — {status}</span>}
              {n.data.degraded && <span className="wf-outline__degraded"> — unavailable action</span>}
              {edges.length > 0 && (
                <ul className="wf-outline__edges">
                  {edges.map(([name, target]) => (
                    <li key={name}>
                      {edgeLabel(name) || 'next'} → {target}
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
