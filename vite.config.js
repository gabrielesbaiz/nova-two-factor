import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'
import { resolve } from 'node:path'

/**
 * Globals Nova places on `window` before it loads any tool script. Verified in
 * vendor/laravel/nova/resources/views/layout.blade.php (script order) and in
 * vendor/laravel/nova-devtool/nova.mix.js, which declares the same four.
 *
 * Declaring them external is what keeps the tool bundle small and, crucially,
 * makes our components share Nova's Vue instance rather than shipping a second.
 */
const externals = {
  vue: 'Vue',
  'laravel-nova': 'LaravelNova',
  'laravel-nova-ui': 'LaravelNovaUi',
  'laravel-nova-util': 'LaravelNovaUtil',
}

/**
 * Two bundles with genuinely different shapes, so two passes: an IIFE cannot
 * take multiple entry points, and these must not be merged anyway.
 *
 *  tool       Runs inside Nova's SPA. Uses Nova's Vue and component library.
 *  challenge  Runs on the server-rendered pre-authentication pages, which are
 *             always a cold load and sit on the login path. Deliberately
 *             dependency-free, and must work before Nova's SPA exists.
 */
const bundles = {
  tool: {
    entry: 'resources/js/tool.js',
    external: Object.keys(externals),
    globals: externals,
  },
  challenge: {
    entry: 'resources/js/challenge.js',
    external: [],
    globals: {},
  },
}

export default defineConfig(() => {
  const name = process.env.BUNDLE ?? 'tool'
  const bundle = bundles[name]

  if (!bundle) {
    throw new Error(`Unknown BUNDLE "${name}". Expected one of: ${Object.keys(bundles).join(', ')}.`)
  }

  return {
    plugins: [vue()],
    resolve: {
      alias: { '@': resolve(import.meta.dirname, 'resources/js') },
    },
    build: {
      outDir: 'dist',
      // Only the first pass clears the directory, or it would delete the other
      // bundle's output.
      emptyOutDir: name === 'tool',
      cssCodeSplit: false,
      target: 'es2022',
      lib: {
        entry: resolve(import.meta.dirname, bundle.entry),
        formats: ['iife'],
        name: name === 'tool' ? 'NovaTwoFactor' : 'NovaTwoFactorChallenge',
        fileName: () => `js/${name}.js`,
      },
      rollupOptions: {
        external: bundle.external,
        output: {
          globals: bundle.globals,
          assetFileNames: 'css/tool[extname]',
        },
      },
    },
  }
})
