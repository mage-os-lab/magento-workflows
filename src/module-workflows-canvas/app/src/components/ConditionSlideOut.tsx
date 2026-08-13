import { useCallback, useEffect, useRef, useState } from 'react';
import type { MountConfig } from '../types';
import { t } from '../i18n';
import type { ConditionNode, NodeMeta } from '../conditionTree';
import {
  collectNodeTypes,
  parseConditionTree,
  serializeNode,
  serializeTree,
} from '../conditionTree';
import {
  applyConditions,
  cachedNodeMeta,
  loadNodeMeta,
  type ConditionApplyResult,
} from '../conditionsClient';
import { type ConditionTarget, targetTitle } from '../conditionTarget';
import { ConditionTreeEditor } from './conditions/ConditionTreeEditor';

/**
 * The condition slide-out: one editor for all three homes of a condition tree
 * (a branch step, one switch case, or the workflow root — see conditionTarget).
 *
 * The BUILDER is primary. It is metadata-driven: every attribute, operator,
 * value element, relation and "Add condition" group comes from the server's
 * `conditionMeta` endpoint, fetched lazily per node type and cached for the page
 * session, so the server (and its DI pools) stays the single authority and a
 * third-party condition class appears without a client change. A node type the
 * server cannot describe renders read-only with its raw JSON and is preserved
 * verbatim — the builder never destroys data it does not understand.
 *
 * "Edit as JSON" stays as the documented power-user escape hatch
 * (docs/11-admin-ui.md, docs/discovery/canvas.md §6) and is two-way: builder
 * edits rewrite the text, and text that parses replaces the builder state. Text
 * that does not parse blocks Apply rather than overwriting the stored tree.
 *
 * Apply round-trips through the shared `conditions` endpoint (::manage, form
 * key) and commits the ECHOED normalized tree; server findings render inline in
 * an aria-live region. If that endpoint cannot be reached the tree is applied
 * from local serialization with a warning — the save path re-validates either
 * way. Nothing here is ever eval'd or written as HTML.
 */
interface Props {
  target: ConditionTarget;
  /** The tree currently stored at the target (null = "always run"). */
  value: string | null;
  /**
   * The step's shared `revalidate_entity` flag for branch/switch targets, or
   * null when the control does not apply (workflow root). Absent server-side
   * means true (docs/06-conditions.md).
   */
  revalidateEntity: boolean | null;
  config: MountConfig;
  readOnly?: boolean;
  onApply: (
    target: ConditionTarget,
    conditionsSerialized: string | null,
    revalidateEntity: boolean | null,
    notice?: string | null,
  ) => void;
  onClose: () => void;
  /** Test seam (mirrors validateClient/postValidate). */
  fetchImpl?: typeof fetch;
}

export function ConditionSlideOut({
  target,
  value,
  revalidateEntity,
  config,
  readOnly = false,
  onApply,
  onClose,
  fetchImpl,
}: Props): JSX.Element {
  // Parsed once per open: re-parsing on every render would mint fresh node ids
  // (React keys) and lose focus mid-edit.
  const [initial] = useState(() => parseConditionTree(value));
  const rootRef = useRef<ConditionNode | null>(initial.root);
  const [root, setRoot] = useState<ConditionNode | null>(initial.root);
  const [jsonText, setJsonText] = useState(() => prettyJson(initial.root, value));
  const [jsonError, setJsonError] = useState<string | null>(initial.error);
  const [showJson, setShowJson] = useState(initial.error !== null);

  const [rootType, setRootType] = useState<string | null>(null);
  const [metaByType, setMetaByType] = useState<Record<string, NodeMeta | null>>({});
  const [pending, setPending] = useState(0);
  /** Why the builder cannot start a tree (missing entity type, meta failure). */
  const [rootError, setRootError] = useState<string | null>(null);

  const [applying, setApplying] = useState(false);
  const [result, setResult] = useState<ConditionApplyResult | null>(null);
  const [revalidate, setRevalidate] = useState(revalidateEntity !== false);

  const entityType = config.workflow?.entityType ?? '';
  const panelRef = useRef<HTMLDivElement | null>(null);

  // ---- focus handling ----------------------------------------------------
  // Move focus into the dialog on open and hand it back to whatever opened it
  // on close; Escape closes, as an admin modal is expected to.
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

  // ---- metadata (lazy, cached per node type) ------------------------------
  const metaFor = useCallback(
    (type: string): NodeMeta | null => {
      if (type in metaByType) {
        return metaByType[type];
      }
      return cachedNodeMeta(entityType, type)?.node ?? null;
    },
    [metaByType, entityType],
  );

  const ensureMeta = useCallback(
    async (type: string): Promise<NodeMeta | null> => {
      if (type === '') {
        return null;
      }
      const cached = cachedNodeMeta(entityType, type);
      if (cached) {
        return cached.node;
      }
      setPending((p) => p + 1);
      const res = await loadNodeMeta(config, entityType, type, fetchImpl);
      setPending((p) => p - 1);
      setMetaByType((m) => ({ ...m, [type]: res.node }));
      return res.node;
    },
    [config, entityType, fetchImpl],
  );

  // Root metadata: it names the entity's root combine class (needed to start a
  // tree from empty) and describes that class in one round-trip. Runs on open
  // and again whenever the entity type changes under the open dialog (the
  // settings panel edits it live), and is re-runnable via the Retry button —
  // a transient fetch failure must not leave every "Add condition" control
  // dead until a page reload.
  const loadRoot = useCallback((): (() => void) => {
    if (entityType === '') {
      setRootType(null);
      setRootError(t('Choose an entity type in Workflow settings first — the available condition attributes depend on it.'));
      return () => undefined;
    }
    let cancelled = false;
    setRootError(null);
    setPending((p) => p + 1);
    void loadNodeMeta(config, entityType, null, fetchImpl).then((res) => {
      setPending((p) => p - 1);
      if (cancelled) {
        return;
      }
      if (res.root !== null) {
        setRootType(res.root);
      }
      if (res.node) {
        const node = res.node;
        setMetaByType((m) => ({ ...m, [node.type]: node }));
      }
      if (!res.ok) {
        setRootType(null);
        setRootError(res.error ?? t('Condition metadata could not be loaded.'));
      }
    });
    return () => {
      cancelled = true;
    };
  }, [config, entityType, fetchImpl]);

  useEffect(() => loadRoot(), [loadRoot]);

  // Every distinct type present in the tree gets described, once.
  useEffect(() => {
    for (const type of collectNodeTypes(root)) {
      if (!(type in metaByType) && cachedNodeMeta(entityType, type) === null) {
        void ensureMeta(type);
      }
    }
  }, [root, metaByType, entityType, ensureMeta]);

  // ---- builder <-> JSON, two-way -----------------------------------------
  const updateRoot = useCallback(
    (updater: (current: ConditionNode | null) => ConditionNode | null): void => {
      const next = updater(rootRef.current);
      rootRef.current = next;
      setRoot(next);
      setJsonText(prettyJson(next, null));
      setJsonError(null);
      setResult(null);
    },
    [],
  );

  const onJsonInput = (text: string): void => {
    setJsonText(text);
    setResult(null);
    const parsed = parseConditionTree(text);
    if (parsed.error !== null) {
      setJsonError(parsed.error);
      return;
    }
    rootRef.current = parsed.root;
    setRoot(parsed.root);
    setJsonError(null);
  };

  // ---- apply -------------------------------------------------------------
  const apply = (): void => {
    if (jsonError !== null || applying) {
      return;
    }
    const serialized = serializeTree(rootRef.current);
    setApplying(true);
    void applyConditions(config, serialized, fetchImpl).then((res) => {
      setApplying(false);
      setResult(res);
      if (res.outcome === 'invalid') {
        return;
      }
      onApply(
        target,
        res.conditionsSerialized,
        revalidateEntity === null ? null : revalidate,
        res.warning,
      );
      onClose();
    });
  };

  const title = targetTitle(target);

  return (
    <div className="wf-slideout" role="dialog" aria-modal="true" aria-label={title}>
      <div className="wf-slideout__backdrop" onClick={onClose} />
      <div className="wf-slideout__panel" ref={panelRef} tabIndex={-1}>
        <header className="wf-slideout__head">
          <h3>{title}</h3>
          <button type="button" aria-label={t('Close')} onClick={onClose}>
            ×
          </button>
        </header>

        {/* Seam retained from the E1 spike: a server-rendered rule-widget
            fragment can still mount here on an install that ships one. The
            builder below is the shipped editor. */}
        <div className="wf-slideout__fragment" data-role="mageos-workflows-conditions-fragment" />

        {rootError !== null && (
          <div className="wf-slideout__meta-error message message-warning" role="alert">
            <p>{rootError}</p>
            {entityType !== '' && (
              <button type="button" className="wf-slideout__retry" onClick={loadRoot}>
                {t('Retry')}
              </button>
            )}
          </div>
        )}

        <ConditionTreeEditor
          root={root}
          rootType={rootType}
          readOnly={readOnly}
          loading={pending > 0}
          metaFor={metaFor}
          ensureMeta={ensureMeta}
          onChange={updateRoot}
          showUnavailableHint={rootError === null}
        />

        <details
          className="wf-slideout__json-toggle"
          open={showJson}
          onToggle={(e) => setShowJson((e.currentTarget as HTMLDetailsElement).open)}
        >
          <summary>{t('Edit as JSON')}</summary>
          <label className="wf-field">
            <span className="wf-field__label">{t('Condition tree (JSON)')}</span>
            <textarea
              className="wf-slideout__json"
              value={jsonText}
              disabled={readOnly}
              onChange={(e) => onJsonInput(e.target.value)}
              spellCheck={false}
              rows={12}
            />
          </label>
        </details>

        {jsonError !== null && (
          <p className="wf-slideout__error" role="alert">
            {jsonError}
          </p>
        )}

        <div className="wf-slideout__messages" role="status" aria-live="polite">
          {result?.error && <p className="wf-slideout__error">{result.error}</p>}
          {result?.warning && <p className="wf-slideout__warning">{result.warning}</p>}
          {result && result.messages.length > 0 && (
            <ul className="wf-slideout__message-list">
              {result.messages.map((m, i) => (
                <li key={`${m.code}-${i}`} className={`wf-msg wf-msg--${m.severity}`}>
                  {m.message}
                </li>
              ))}
            </ul>
          )}
          {result?.outcome === 'invalid' && result.messages.length === 0 && !result.error && (
            <p className="wf-slideout__error">{t('The server rejected this condition tree.')}</p>
          )}
        </div>

        <footer className="wf-slideout__foot">
          {revalidateEntity !== null && (
            <label className="wf-field wf-field--bool wf-slideout__revalidate">
              <input
                type="checkbox"
                checked={revalidate}
                disabled={readOnly}
                onChange={(e) => setRevalidate(e.target.checked)}
              />
              {t('Check against the latest data (recommended)')}
              <span className="wf-field__notice">
                {t('The record is re-loaded fresh before these conditions run, so a decision made after a delay uses current data. Turn off to use the data exactly as it was when the workflow started.')}
              </span>
            </label>
          )}
          <div className="wf-slideout__buttons">
            <button type="button" className="action-default" onClick={onClose}>
              {t('Cancel')}
            </button>
            <button
              type="button"
              className="action-primary wf-slideout__apply"
              disabled={readOnly || applying || jsonError !== null}
              onClick={apply}
            >
              {applying ? t('Validating…') : t('Apply')}
            </button>
          </div>
        </footer>
      </div>
    </div>
  );
}

/**
 * Pretty JSON for the text tab. An unparseable stored value keeps its ORIGINAL
 * text (that is the whole point of not clobbering it), so `fallback` wins when
 * there is no parsed root to print.
 */
function prettyJson(root: ConditionNode | null, fallback: string | null): string {
  if (root === null) {
    return fallback ?? '';
  }
  return JSON.stringify(serializeNode(root), null, 2);
}
