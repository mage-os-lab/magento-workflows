import { StrictMode } from 'react';
import { createRoot } from 'react-dom/client';
import { ReactFlowProvider } from '@xyflow/react';
import '@xyflow/react/dist/style.css';
import './styles.css';
import { CanvasApp } from './components/CanvasApp';
import { MOUNT_SELECTOR, readMountConfig } from './mountConfig';

/**
 * IIFE entry. Finds the CSP-safe mount div, reads its data-config, and mounts
 * React. No inline script, no eval — this file is bundled to a single
 * self-contained view/adminhtml/web/js/dist/canvas.js.
 */
function mount(): void {
  const el = document.querySelector(MOUNT_SELECTOR);
  const config = readMountConfig(el);
  if (!el || !config) {
    return;
  }
  createRoot(el as HTMLElement).render(
    <StrictMode>
      <ReactFlowProvider>
        <CanvasApp config={config} />
      </ReactFlowProvider>
    </StrictMode>,
  );
}

if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', mount, { once: true });
} else {
  mount();
}
