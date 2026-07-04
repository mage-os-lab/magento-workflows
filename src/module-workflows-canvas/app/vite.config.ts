import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';
import { resolve } from 'node:path';

/**
 * Builds a single self-contained IIFE bundle to ../web/js/dist/canvas.js.
 *
 * CSP posture (docs/discovery/canvas.md §3): no CDN, no runtime fetch to any
 * third-party host, no inline script. React + @xyflow/react + elkjs are all
 * bundled in (no externals) so the admin loads one static, same-origin file.
 * The production build is minified by esbuild, which does not emit eval /
 * new Function — verified by the canvas.yml CI drift + a grep gate.
 */
export default defineConfig({
  plugins: [react()],
  define: {
    'process.env.NODE_ENV': JSON.stringify('production'),
  },
  build: {
    outDir: resolve(__dirname, '../web/js/dist'),
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
        // One file, styles inlined by the bundle at runtime.
        inlineDynamicImports: true,
        assetFileNames: 'canvas.[ext]',
      },
    },
  },
});
