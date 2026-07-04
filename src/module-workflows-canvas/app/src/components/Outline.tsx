import type { Graph } from '../types';
import { getStepEdges, edgeLabel } from '../edges';
import type { Overlay } from '../overlay';

/**
 * Read-only outline/list rendering of the graph on the same page — the
 * screen-reader path (docs/discovery/canvas.md §7, C3's legacy). Plain semantic
 * HTML: a list of steps, each with its type, summary, status, and outgoing
 * edges. All strings are React text nodes.
 */
export function Outline({ graph, overlay }: { graph: Graph; overlay: Overlay }): JSX.Element {
  return (
    <section className="wf-outline" aria-label="Workflow outline">
      <h3 className="wf-outline__title">Outline</h3>
      <ol className="wf-outline__list">
        {graph.nodes.map((n) => {
          const status = overlay.nodeStatus[n.id];
          const edges = Object.entries(getStepEdges(n.data.step)).filter(([, t]) => t !== null);
          return (
            <li key={n.id} className="wf-outline__item">
              <span className="wf-outline__key">{n.id}</span>
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
