import { readFileSync } from 'node:fs'
import { fileURLToPath, URL } from 'node:url'
import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'

/**
 * No bare `process.env.NODE_ENV` may survive in the build output: the browser has no
 * `process`, so it would throw at runtime and the page would hang on loading.
 * Only the unguarded form is an error — semver/axios/CodeMirror use guarded
 * `typeof process<"u" && process.env…` reads, which are safe.
 */
function guardProcessEnv(artifact) {
	return {
		name: 'pages:guard-process-env',
		closeBundle() {
			let code
			try {
				code = readFileSync(artifact, 'utf8')
			} catch {
				return
			}
			if (/process\.env\.NODE_ENV/.test(code)) {
				this.error(
					`Bare \`process.env.NODE_ENV\` left in the output ${artifact}. The browser has no `
					+ 'process, so it will throw at runtime. Add the replacement to define in '
					+ 'vite.config.js (Vite does not do it automatically in lib mode).',
				)
			}
			const others = (code.match(/process\.env/g) ?? []).length
			if (others > 0) {
				this.warn(
					`${others} more process.env references in the output — usually the guarded `
					+ '`typeof process<"u" && process.env…` form, harmless at runtime; check here if '
					+ 'the page reports process-related errors.',
				)
			}
		},
	}
}

/**
 * Pages frontend build. Deliberately not using @nextcloud/vite-config: the output is
 * pinned to IIFE at js/pages-main.js and css/pages-main.css, and outDir '.' with
 * emptyOutDir: false keeps the artifacts in the app directory without wiping it.
 *
 * Two entries means two builds, because IIFE does not support multi-entry.
 */
export default defineConfig(({ mode }) => {
	const admin = mode === 'admin'
	const entry = admin ? './src/admin.js' : './src/main.js'
	const outFile = admin ? 'js/pages-admin.js' : 'js/pages-main.js'

	return {
		plugins: [
			// Both bundles carry SFCs: the app shell, and the grant pickers of the settings page
			vue(),
			guardProcessEnv(fileURLToPath(new URL('./' + outFile, import.meta.url))),
		],
		resolve: {
			alias: {
				'@': fileURLToPath(new URL('./src', import.meta.url)),
			},
		},
		define: {
			// Vite does not replace `process.env.NODE_ENV` in lib mode; without this Vue would keep
			// its dev branches and the browser would hit a ReferenceError
			'process.env.NODE_ENV': JSON.stringify('production'),
		},
		build: {
			outDir: '.',
			emptyOutDir: false,
			cssCodeSplit: false,
			sourcemap: false,
			target: 'es2022',
			lib: {
				entry: fileURLToPath(new URL(entry, import.meta.url)),
				name: admin ? 'PagesAdmin' : 'PagesApp',
				formats: ['iife'],
				fileName: () => outFile,
			},
			rollupOptions: {
				output: {
					// CSS output paths must differ per build: both builds write to the same directory
					assetFileNames: (info) => {
						const names = info.names ?? (info.name ? [info.name] : [])
						if (names.some((name) => String(name).endsWith('.css'))) {
							return admin ? 'css/pages-admin.css' : 'css/pages-main.css'
						}
						return 'js/[name][extname]'
					},
				},
			},
		},
	}
})
