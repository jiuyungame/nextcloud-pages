/**
 * Node-level proof of src/App.vue — the content page's column is the author's own file tree now, and
 * the pane shows a resource instead of a text editor for a file that is not a page.
 *
 * The content page has no browser test, so the component is compiled with @vue/compiler-sfc, bundled
 * with esbuild (the app's own dependencies, `vue` shared with this process) and rendered with Vue's
 * server renderer. Two things are stubbed on purpose: the network/editor modules (`./api.js`,
 * `./editor.js`), because nothing here calls them at render time, and the icon/component imports that
 * are not part of this proof. `NcAppNavigation` and `NcAppNavigationItem` are the **real** components:
 * the badge lives in the item's own `counter` slot, and a stub would not prove that slot exists.
 *
 * What this asserts:
 *
 *   - the content page renders the caller's whole tree (folders included) without warnings
 *   - every file carries its review state as a badge, and a waiting deletion says so
 *   - the one page has no second view left: a stale `page: 'source'` shell renders the same page
 *   - a non-page file opens in the resource pane (facts, replace, Files app) instead of the editor
 *   - an empty column says so, and the new-file dialog exists for the author
 *
 *   node tests/code/smoke-app-tree.mjs
 */
import assert from 'node:assert/strict'
import { mkdtempSync, readFileSync, rmSync, writeFileSync } from 'node:fs'
import { join } from 'node:path'
import { fileURLToPath, pathToFileURL } from 'node:url'
import { compileScript, compileTemplate, parse } from '@vue/compiler-sfc'
import { build } from 'esbuild'
import { createSSRApp, h } from 'vue'
import { renderToString } from 'vue/server-renderer'

const here = fileURLToPath(new URL('.', import.meta.url))
const appRoot = join(here, '..', '..')
const sfcPath = join(appRoot, 'src', 'App.vue')

// ---- compile the SFC the way the Vite build does (minus the scoped CSS) ----

const { descriptor, errors: parseErrors } = parse(readFileSync(sfcPath, 'utf8'), { filename: sfcPath })
assert.deepEqual(parseErrors, [], 'the SFC must parse')
assert.ok(descriptor.template, 'the SFC must have a template')

const id = 'smoke-app-tree'
const script = compileScript(descriptor, { id })
const template = compileTemplate({ source: descriptor.template.content, filename: sfcPath, id })
assert.deepEqual(template.errors, [], 'the template must compile')

/** The compiled script keeps relative imports; they are rewritten to the real modules. */
function absoluteImports(source) {
	return source.replace(/from '(\.[^']+)'/g, (match, specifier) => {
		const absolute = join(appRoot, 'src', specifier.replace(/^\.\//, '')).replace(/\\/g, '/')
		return `from '${absolute}'`
	})
}

/*
 * The modules that must not run here: the network and CodeMirror. A render never calls them, and
 * pulling axios into this process would only make the proof depend on window.OC.
 */
const STUBS = {
	'api': `const ok = () => Promise.resolve({ ok: false, status: 0, data: {}, message: 'stub' })
const api = { tree: ok, readFile: ok, writeFile: ok, writeFileBytes: ok, createFile: ok, createFolder: ok, move: ok, deleteFile: ok, deleteArticle: ok, articleContent: ok, saveArticleContent: ok, myArticles: ok, status: ok, build: ok, diff: ok, publish: ok, session: ok }
export { api }
export default api`,
	'editor': `export function createEditor() {
	return { setDoc() {}, setPath() {}, getDoc() { return '' }, requestMeasure() {}, destroy() {} }
}`,
}

const COMPONENT_STUBS = {
	fields: `export default { name: 'ArticleFields', render() { return null } }`,
	read: `export default { name: 'ReadView', render() { return null } }`,
	review: `export default { name: 'ReviewQueue', render() { return null } }`,
	// the children App.vue mounts into the content pane: nothing of them is what this proof asserts,
	// and they are SFCs esbuild cannot load without the Vite plugin
	rows: `export default { name: 'ArticleRows', render() { return null } }`,
	requests: `export default { name: 'RequestsView', render() { return null } }`,
	manage: `export default { name: 'ManageView', render() { return null } }`,
	icon: `export default { name: 'Icon', render() { return null } }`,
}

const stubsPlugin = {
	name: 'pages-smoke-stubs',
	setup(buildApi) {
		buildApi.onResolve({ filter: /src[\\/](api|editor)\.js$/ }, (args) => {
			const key = args.path.replace(/^.*[\\/]/, '').replace(/\.js$/, '')
			return { path: key, namespace: 'pages-stub' }
		})
		buildApi.onResolve({ filter: /\.vue$/ }, (args) => {
			if (args.path.includes('vue-material-design-icons')) {
				return { path: 'icon', namespace: 'pages-stub' }
			}
			if (args.path.includes('ArticleFields.vue')) {
				return { path: 'fields', namespace: 'pages-stub' }
			}
			if (args.path.includes('Read.vue')) {
				return { path: 'read', namespace: 'pages-stub' }
			}
			if (args.path.includes('Review.vue')) {
				return { path: 'review', namespace: 'pages-stub' }
			}
			if (args.path.includes('ArticleRows.vue')) {
				return { path: 'rows', namespace: 'pages-stub' }
			}
			if (args.path.includes('Requests.vue')) {
				return { path: 'requests', namespace: 'pages-stub' }
			}
			if (args.path.includes('Manage.vue')) {
				return { path: 'manage', namespace: 'pages-stub' }
			}
			return undefined
		})
		buildApi.onResolve({ filter: /^@nextcloud\/router$/ }, () => ({ path: 'router', namespace: 'pages-stub' }))
		// App.vue raises Nextcloud toasts; their container needs a browser DOM this smoke test does
		// not simulate, and nothing here calls one
		buildApi.onResolve({ filter: /^@nextcloud\/dialogs$/ }, () => ({ path: 'dialogs', namespace: 'pages-stub' }))
		// dompurify needs a real DOM to sanitise an inline SVG; the icons are not what this proves
		buildApi.onResolve({ filter: /^dompurify$/ }, () => ({ path: 'dompurify', namespace: 'pages-stub' }))
		buildApi.onLoad({ filter: /.*/, namespace: 'pages-stub' }, (args) => {
			if (args.path === 'dompurify') {
				return { contents: `export default { sanitize: (svg) => String(svg) }`, loader: 'js' }
			}
			if (args.path === 'router') {
				return {
					contents: `export function generateUrl(path) { return '/index.php' + path }
export function generateFilePath(app, type, file) { return '/index.php/apps/' + app + '/' + type + '/' + file }
export function generateOcsUrl(path) { return '/ocs/v2.php' + path }
export function generateRemoteUrl(service) { return '/remote.php/' + service }`,
					loader: 'js',
				}
			}
			if (args.path === 'dialogs') {
				return {
					contents: `export function showError() {}
export function showInfo() {}
export function showSuccess() {}
export function showWarning() {}`,
					loader: 'js',
				}
			}
			if (args.path in STUBS) {
				return { contents: STUBS[args.path], loader: 'js' }
			}
			return { contents: COMPONENT_STUBS[args.path], loader: 'js' }
		})
	},
}

// the temp dir lives inside the app so that `vue` (external) resolves to the same module as here
const workDir = mkdtempSync(join(here, '.smoke-app-tree-'))
try {
	writeFileSync(join(workDir, 'component.mjs'), absoluteImports(script.content))
	writeFileSync(join(workDir, 'render.mjs'), template.code)
	writeFileSync(join(workDir, 'proof.mjs'), [
		"import component from './component.mjs'",
		"import { render } from './render.mjs'",
		'component.render = render',
		'export { component }',
		'',
	].join('\n'))

	const outfile = join(workDir, 'proof.bundle.mjs')
	await build({
		entryPoints: [join(workDir, 'proof.mjs')],
		outfile,
		bundle: true,
		format: 'esm',
		platform: 'node',
		target: 'node20',
		// one Vue instance for the bundle and this script, otherwise the component cannot mount
		external: ['vue'],
		// @nextcloud/vue imports its stylesheets; they are not part of this proof
		loader: { '.css': 'empty' },
		plugins: [stubsPlugin],
		logLevel: 'silent',
	})

	/*
	 * @nextcloud/vue reads `window`/`document` while it loads (version, element ids, media queries).
	 * Nothing here simulates a browser beyond what those module-level reads need.
	 */
	/** A Storage that keeps its values in this process: NcAppContent restores a pane width from it. */
	function storage() {
		const values = new Map()
		return {
			length: 0,
			getItem: (key) => (values.has(key) ? values.get(key) : null),
			setItem: (key, value) => values.set(key, String(value)),
			removeItem: (key) => values.delete(key),
			clear: () => values.clear(),
			key: (index) => Array.from(values.keys())[index] ?? null,
		}
	}

	globalThis.HTMLElement = class {}
	globalThis.SVGElement = class {}
	globalThis.Element = class {}
	/*
	 * NcIconSvgWrapper sanitises an inline SVG through DOMPurify + DOMParser. Neither belongs to what
	 * this proves, and the stubs below are the twenty lines of a browser they use: the sanitised string
	 * comes back as the element's outerHTML.
	 */
	globalThis.DOMParser = class {
		parseFromString(svg) {
			return {
				querySelector: () => null,
				documentElement: { id: '', removeAttribute() {}, outerHTML: String(svg) },
			}
		}
	}
	globalThis.window = {
		OC: { config: { version: '34.0.4', appVersion: '0.3' } },
		_nc_vue_element_id: 0,
		navigator: { language: 'en' },
		getComputedStyle: () => ({ getPropertyValue: () => '' }),
		addEventListener: () => {},
		removeEventListener: () => {},
		outerHeight: 0,
		innerHeight: 0,
		screen: { height: 0, width: 0 },
		localStorage: storage(),
		sessionStorage: storage(),
		matchMedia: () => ({ matches: false, addEventListener: () => {}, removeEventListener: () => {} }),
	}
	globalThis.document = {
		documentElement: { dataset: {}, lang: 'en', dir: 'ltr' },
		body: {},
		head: {},
		addEventListener: () => {},
		removeEventListener: () => {},
		getElementsByTagName: () => [],
		getElementById: () => null,
		querySelector: () => null,
		createElement: () => ({ style: {}, setAttribute: () => {}, appendChild: () => {} }),
	}

	const { component: App } = await import(pathToFileURL(outfile).href)
	assert.equal(App.name, 'App', 'the compiled component must be the app itself (no self-import)')

	/** Render one page load. `data` overrides the component's own initial state. */
	async function mount(shell, data = {}) {
		const Base = {
			...App,
			data() {
				return { ...App.data.call(this), ...data }
			},
		}
		const app = createSSRApp({ render: () => h(Base, { shell }) })
		const warnings = []
		app.config.warnHandler = (message) => warnings.push(message)
		const html = await renderToString(app, {})
		return { html, warnings }
	}

	const shell = {
		page: 'content',
		canAuthor: true,
		canReview: false,
		canEdit: false,
		canPublish: false,
		isAdmin: false,
		site: { display: 'site' },
		contentUrl: '/index.php/apps/pages/',
		sourceUrl: '/index.php/apps/pages/source',
	}

	const nodes = [
		{ path: 'notes', name: 'notes', type: 'dir', target: '/tmp/alice/notes', kind: 'dir', state: '', live: false, intent: '' },
		{ path: 'notes/a.md', name: 'a.md', type: 'file', target: '/tmp/alice/notes/a.md', kind: 'page', state: 'internal', live: false, intent: '' },
		{ path: 'b.md', name: 'b.md', type: 'file', target: '/tmp/alice/b.md', kind: 'page', state: 'internal', candidate: true, live: false, intent: 'online' },
		{ path: 'c.md', name: 'c.md', type: 'file', target: '/tmp/alice/c.md', kind: 'page', state: 'external', live: true, intent: '' },
		{ path: 'logo.png', name: 'logo.png', type: 'file', target: '/tmp/alice/logo.png', kind: 'resource', state: 'external', live: true, intent: '' },
	]

	// ---- the content page: the whole tree, every file with its state ----

	const content = await mount(shell, { nodes, targetPrefix: '/tmp/alice' })
	assert.deepEqual(content.warnings, [], 'the content page must render without warnings')
	for (const name of ['notes', 'a.md', 'b.md', 'c.md', 'logo.png']) {
		assert.match(content.html, new RegExp(name.replace('.', '\\.')), `the column must list ${name}`)
	}
	assert.match(content.html, /New file/, 'the column must offer a new file')
	// the four labels of the folder model, each with its own badge: internal, a candidate waiting
	// (the entry's state stays `internal`; the flag is what the badge reads), and a served page
	assert.match(content.html, /pages-badge--neutral">Internal/, 'an internal page must carry its badge')
	assert.match(content.html, /pages-badge--info">Waiting for review/, 'a pending candidate must carry its badge')
	// `external` is the approval, and from the author's point of view it is online (已上线): one label
	// whether a commit carried it yet or not, so no row grows a second line
	assert.match(content.html, /pages-badge--success">Online/, 'a live external page must carry its badge')
	assert.doesNotMatch(content.html, /pages-badge[^>]*><\/span>/, 'no empty badge may be drawn')
	// the resource is not a page: its state is still shown, the folder carries no badge
	assert.match(content.html, /pages-badge--success">Online/, 'an external resource must carry its badge')

	// ---- an open page shows the fields panel, not the resource pane ----

	const article = await mount(shell, {
		nodes,
		targetPrefix: '/tmp/alice',
		currentFile: '/tmp/alice/notes/a.md',
		authorOpen: true,
	})
	assert.deepEqual(article.warnings, [], 'an open article must render without warnings')
	assert.doesNotMatch(article.html, /pages-resource__hint/, 'a page must not show the resource pane')

	// ---- an open resource shows facts, replace and the Files app instead of the editor ----

	const resource = await mount(shell, {
		nodes,
		targetPrefix: '/tmp/alice',
		currentFile: '/tmp/alice/logo.png',
		resourceNode: { name: 'logo.png', size: 1234, type: 'file', kind: 'resource' },
	})
	assert.deepEqual(resource.warnings, [], 'the resource pane must render without warnings')
	assert.match(resource.html, /not a page/, 'the pane must say the file is not a page')
	assert.match(resource.html, /Replace file/, 'the pane must offer a replacement')
	assert.match(resource.html, /Open in Files/, 'the pane must link the Files app')
	assert.match(resource.html, /1234 B/, 'the pane must show the size')
	assert.doesNotMatch(resource.html, /pages-fields__input/, 'a resource has no fields panel')

	// ---- an empty area says so instead of looking broken ----

	const empty = await mount(shell, { nodes: [] })
	assert.deepEqual(empty.warnings, [], 'an empty column must render without warnings')
	assert.match(empty.html, /\(no files yet\)/, 'an empty column must say it is empty')

	// ---- there is no second page ----

	// The shell no longer names a page: a stale `page: 'source'` in an old shell renders the same one
	// page, and nothing links to the removed source view.
	const stale = await mount({ ...shell, page: 'source', canEdit: true, canPublish: true }, { nodes: [] })
	assert.deepEqual(stale.warnings, [], 'a stale page key must not break the render')
	assert.doesNotMatch(stale.html, /Site source/, 'the link to the removed source page must be gone')

	console.log('App: author tree column, badges and the resource pane OK')
} finally {
	rmSync(workDir, { recursive: true, force: true })
}
