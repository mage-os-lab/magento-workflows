import type { ConditionNode, NodeMeta } from '../../conditionTree';
import {
  addChildNode,
  buildNode,
  emptyTree,
  removeNode,
  setNodeProp,
  splitTypeSpec,
  updateNode,
} from '../../conditionTree';
import { t } from '../../i18n';
import { ConditionNodeRow, type TreeHandlers } from './ConditionNodeRow';

/**
 * The condition tree builder. Owns every tree mutation (all of them pure
 * functions from conditionTree), knows nothing about transport: metadata
 * lookup/fetch is injected by the slide-out, which also owns the serialized
 * value and the apply round-trip.
 *
 * State is threaded through a functional updater so that an add-child (which
 * awaits the chosen type's metadata before it can know the node's shape) can
 * never overwrite a concurrent edit with a stale snapshot.
 */
interface Props {
  root: ConditionNode | null;
  /** The entity's root combine FQCN, from the metadata endpoint. */
  rootType: string | null;
  readOnly: boolean;
  /** True while any node metadata request is outstanding. */
  loading: boolean;
  metaFor: (type: string) => NodeMeta | null;
  ensureMeta: (type: string) => Promise<NodeMeta | null>;
  onChange: (updater: (current: ConditionNode | null) => ConditionNode | null) => void;
  /**
   * Suppress the generic "metadata unavailable" hint when the host already
   * renders a more specific explanation (missing entity type, server error
   * with a Retry button) right above this editor.
   */
  showUnavailableHint?: boolean;
}

export function ConditionTreeEditor({
  root,
  rootType,
  readOnly,
  loading,
  metaFor,
  ensureMeta,
  onChange,
  showUnavailableHint = true,
}: Props): JSX.Element {
  const handlers: TreeHandlers = {
    metaFor,
    readOnly,
    onSetProp: (id, key, value) => onChange((current) => (current ? setNodeProp(current, id, key, value) : current)),
    onReplace: (id, node) => onChange((current) => (current ? updateNode(current, id, () => node) : current)),
    onRemove: (id) => onChange((current) => removeNode(current, id)),
    onAddChild: (parentId, spec) => {
      const { type } = splitTypeSpec(spec);
      // The new node's shape (combine vs leaf vs related) is the server's call;
      // buildNode falls back to an inferred shape only if the fetch failed.
      void ensureMeta(type).then((meta) => {
        onChange((current) => (current ? addChildNode(current, parentId, buildNode(spec, meta)) : current));
      });
    },
  };

  return (
    <div className="wf-cond">
      {loading && (
        <p className="wf-cond__loading" role="status">
          {t('Loading condition metadata…')}
        </p>
      )}

      {root === null ? (
        <div className="wf-cond__empty">
          <p>{t('No conditions — this always runs.')}</p>
          <button
            type="button"
            disabled={readOnly || rootType === null}
            onClick={() => onChange(() => emptyTree(rootType ?? ''))}
          >
            {t('Add conditions')}
          </button>
          {rootType === null && !loading && showUnavailableHint && (
            <p className="wf-cond__hint">
              {t("The condition metadata for this workflow's entity type is unavailable, so the builder cannot start a tree. Use “Edit as JSON” below.")}
            </p>
          )}
        </div>
      ) : (
        <>
          <ul className="wf-cond__tree">
            <ConditionNodeRow node={root} isRoot handlers={handlers} />
          </ul>
          <button
            type="button"
            className="wf-cond__clear"
            disabled={readOnly}
            onClick={() => onChange(() => null)}
          >
            {t('Remove all conditions')}
          </button>
        </>
      )}
    </div>
  );
}
