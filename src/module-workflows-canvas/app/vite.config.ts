/**
 * Copyright (c) Mage-OS. Licensed under OSL-3.0.
 * See LICENSE.txt for license details.
 */

import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';
import { resolve } from 'node:path';

/**
 * Builds a single self-contained IIFE bundle to
 * ../view/adminhtml/web/js/dist/canvas.js.
 *
 * The output MUST live under view/<area>/web/ — a module-root web/ directory is
 * not a static-file location in Magento, so getViewFileUrl() /
 * setup:static-content:deploy cannot resolve anything there (issue #26).
 *
 * CSP posture (docs/discovery/canvas.md §3): no CDN, no runtime fetch to any
 * third-party host, no inline script. React + @xyflow/react + elkjs are all
 * bundled in (no externals) so the admin loads one static, same-origin file.
 * The production build is minified by esbuild, which does not emit eval /
 * new Function — verified by the canvas.yml CI drift + a grep gate.
 */
/**
 * Attribution banner prepended to the built bundle. The bundle inlines
 * third-party libraries (no externals), several under licenses that require
 * their notices to travel with the distributed code — elkjs is EPL-2.0. The
 * full list, license texts and the EPL-2.0 source-availability statement live
 * in THIRD-PARTY-NOTICES.txt at the root of this package; regenerate that file
 * whenever app/package-lock.json changes.
 */
const banner = [
  '/*!',
  ' * Mage-OS Workflows canvas. Copyright (c) Mage-OS. Licensed under OSL-3.0.',
  ' * Bundles third-party libraries under MIT, ISC, BSD-3-Clause and EPL-2.0',
  ' * terms, including React, @xyflow/react and elkjs (EPL-2.0). Attribution',
  ' * and full license texts: THIRD-PARTY-NOTICES.txt in the',
  ' * mage-os/workflows-canvas package root.',
  ' */',
].join('\n');

export default defineConfig({
  plugins: [react()],
  define: {
    'process.env.NODE_ENV': JSON.stringify('production'),
  },
  build: {
    outDir: resolve(__dirname, '../view/adminhtml/web/js/dist'),
    emptyOutDir: true,
    target: 'es2019',
    minify: 'esbuild',
    cssCodeSplit: false,
    lib: {
      entry: resolve(__dirname, 'src/index.tsx'),
      name: 'MageosWorkflowsCanvas',
      formats: ['iife'],
      fileName: () => 'canvas.js',
    },
    rollupOptions: {
      output: {
        banner,
        // One file, styles inlined by the bundle at runtime.
        inlineDynamicImports: true,
        assetFileNames: 'canvas.[ext]',
      },
    },
  },
});
