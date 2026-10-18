/**
 * Node-level proof of src/ArticleFields.vue — the panel that now also holds the additional-metadata
 * table and the `NcDialog` that edits it.
 *
 * The content page has no browser test, so the component is compiled with @vue/compiler-sfc,
 * bundled with esbuild (the app's own dependencies, `vue` shared with this process) and rendered
 * with Vue's server renderer; the dialog logic is driven by calling the component's own methods on a
 * stub instance, which is the same code a click runs.
 *
 * What this asserts:
 *
 *   - the panel renders without warnings (an unresolved template identifier would warn)
 *   - the read-only table shows at most five additional keys, the file's key order, and counts the
 *     rest ("N more")
 *   - the dialog renders one editable row per key, with the type selector and "Add row"
 *   - opening the dialog maps text/number/boolean/list values to the right row type
 *   - saving validates the keys, refuses a bad one (and keeps the dialog open), and emits the whole
 *     map with real number/boolean/list types
 *   - `changedMeta` sends a removed key as an empty value and a changed one as its new value
 *
 * Styles are not compiled here: this is about what is rendered and what is emitted.
 *
 *   node tests/smoke-article-fields.mjs
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
const appRoot = join(here, '..')
const sfcPath = join(appRoot, 'src', 'ArticleFields.vue')

// ---- compile the SFC the way the Vite build does (minus the scoped CSS) ----

const { descriptor, errors: parseErrors } = parse(readFileSync(sfcPath, 'utf8'), { filename: sfcPath })
assert.deepEqual(parseErrors, [], 'the SFC must parse')
assert.ok(descriptor.template, 'the SFC must have a template')

const id = 'smoke-article-fields'
const script = compileScript(descriptor, { id })
const template = compileTemplate({ source: descriptor.template.content, filename: sfcPath, id })
assert.deepEqual(template.errors, [], 'the template must compile')

/**
 * The compiled script keeps the component's own relative imports (`./i18n.js`, …), which do not
 * exist next to the temp copy: they are rewritten to the real absolute paths, so esbuild bundles the
 * app's own modules rather than a copy.
 */
function absoluteImports(source) {
	return source.replace(/from '(\.[^']+)'/g, (match, specifier) => {
		const absolute = join(appRoot, 'src', specifier.replace(/^\.\//, '')).replace(/\\/g, '/')
		return `from '${absolute}'`
	})
}

// the temp dir lives inside the app so that `vue` (external) resolves to the same module as here
const workDir = mkdtempSync(join(here, '.smoke-article-fields-'))
try {
	writeFileSync(join(workDir, 'component.mjs'), absoluteImports(script.content))
	writeFileSync(join(workDir, 'render.mjs'), template.code)
	writeFileSync(join(workDir, 'proof.mjs'), [
		"import component from './component.mjs'",
		"import { render } from './render.mjs'",
		`import { changedMeta } from '${join(appRoot, 'src', 'articleGeometry.js').replace(/\\/g, '/')}'`,
		'component.render = render',
		'export { component, changedMeta }',
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
		logLevel: 'silent',
	})

	// NcDialog's modal declares component props typed as DOM classes, so the names have to exist
	globalThis.HTMLElement = class {}
	globalThis.SVGElement = class {}
	globalThis.Element = class {}

	/*
	 * @nextcloud/vue reads `window` (version, element id counter) and `window.getComputedStyle(
	 * document.body)` while it loads, so the renderer needs the two globals before the bundle is
	 * imported. Nothing here simulates a browser beyond what those module-level reads need.
	 */
	globalThis.window = {
		OC: { config: { version: '34.0.4' } },
		_nc_vue_element_id: 0,
		navigator: { language: 'en' },
		getComputedStyle: () => ({ getPropertyValue: () => '' }),
		// NcDialog's modal registers a global pointer listener while it loads
		addEventListener: () => {},
		removeEventListener: () => {},
		// ... and checks whether the page is fullscreen, which needs these three numbers
		outerHeight: 0,
		innerHeight: 0,
		screen: { height: 0, width: 0 },
		matchMedia: () => ({ matches: false, addEventListener: () => {}, removeEventListener: () => {} }),
	}
	globalThis.document = {
		documentElement: { dataset: {}, lang: 'en', dir: 'ltr' },
		body: {},
		head: {},
		addEventListener: () => {},
		removeEventListener: () => {},
		// @nextcloud/logger reads the current user off a meta tag while it loads
		getElementsByTagName: () => [],
		querySelector: () => null,
		createElement: () => ({ style: {}, setAttribute: () => {}, appendChild: () => {} }),
	}

	const { component: ArticleFields, changedMeta } = await import(pathToFileURL(outfile).href)

	const PATH = '/tmp/alice/notes/2026-05-01-hello.md'

	/** Render the panel and return its html plus the teleported dialog content (NcDialog uses one). */
	async function mount(meta, extraData = {}) {
		const Base = extraData.data
			? {
				...ArticleFields,
				data() {
					return { ...ArticleFields.data.call(this), ...extraData.data }
				},
			}
			: ArticleFields
		const app = createSSRApp({
			render: () => h(Base, {
				current: { title: 'Hello', date: '2026-05-01', tagsText: 'a, b', categoriesText: 'guides', excerpt: '', extra: {} },
				meta,
				path: PATH,
				autoExcerpt: 'first paragraph',
			}),
		})
		const warnings = []
		app.config.warnHandler = (message) => warnings.push(message)
		const context = {}
		const html = await renderToString(app, context)
		const teleports = Object.values(context.teleports ?? {}).join('\n')
		return { html: `${html}\n${teleports}`, warnings }
	}

	// ---- the read-only table: the file's order, five rows at most, the rest counted ----

	const meta = {
		title: 'Hello',
		tags: ['a', 'b'],
		date: '2026-05-01',
		excerpt: '',
		modified_date: '2026-05-02',
		author: 'alice',
		permalink: '',
		url: '/hello.html',
		keys: ['layout', 'hero', 'featured', 'weight', 'topics', 'a', 'b', 'c'],
		layout: 'post',
		hero: 'a.png',
		featured: true,
		weight: 3,
		topics: ['one', 'two'],
		a: '1',
		b: '2',
		c: '3',
	}
	const panel = await mount(meta)
	assert.deepEqual(panel.warnings, [], 'the panel must render without warnings')
	assert.match(panel.html, /Additional metadata/, 'the read-only table must be labelled')
	assert.match(panel.html, /Edit metadata/, 'the dialog must be reachable from the table')
	// the categories are a field of the panel, not additional metadata: they decide the page's address
	assert.match(panel.html, /Categories/, 'the panel must offer the categories field')
	assert.match(panel.html, /value="guides"/, 'the categories field must show the stored value')
	assert.match(panel.html, /only takes effect/, 'the field must say that a category needs a site page')
	assert.doesNotMatch(panel.html, /<th scope="row">categories/, 'categories must not be additional metadata')
	for (const key of ['layout', 'hero', 'featured', 'weight', 'topics']) {
		assert.match(panel.html, new RegExp(key), `the table must show ${key}`)
	}
	assert.match(panel.html, /one, two/, 'a list value must be shown comma-separated')
	assert.match(panel.html, />3</, 'a number value must be shown as it is')
	// eight unknown keys, five shown, three left over
	assert.doesNotMatch(panel.html, /title<\/th>/, 'a known key must not appear in the table')
	assert.doesNotMatch(panel.html, /\bblank\b/, 'nothing else may be invented')
	const extraRows = (panel.html.match(/<tr><th scope="row">/g) ?? []).length
	assert.equal(extraRows, 5, 'the table must show at most five rows')
	assert.match(panel.html, /3 more/, 'the table must count the rows it left out')

	// a panel with no additional keys renders no table at all
	const empty = await mount({ ...meta, keys: ['title', 'tags', 'date', 'excerpt', 'modified_date', 'author', 'permalink', 'url'] })
	assert.deepEqual(empty.warnings, [], 'a panel without additional keys must render without warnings')
	assert.doesNotMatch(empty.html, /Edit metadata/, 'no additional keys means no dialog button')

	// ---- the dialog's own markup: one editable row per key, the type selector, "Add row" ----

	const open = await mount(meta, {
		data: {
			metaOpen: true,
			rows: [
				{ key: 'hero', value: 'a.png', type: 'text' },
				{ key: 'featured', value: true, type: 'boolean' },
				{ key: 'topics', value: 'one, two', type: 'list' },
			],
		},
	})
	assert.deepEqual(open.warnings, [], 'the open dialog must render without warnings')
	assert.match(open.html, /Add row/, 'the dialog must offer a new row')
	assert.match(open.html, /value="hero"/, 'the dialog must render the key input')
	assert.match(open.html, /value="one, two"/, 'the dialog must render the list value')
	assert.match(open.html, /Boolean/, 'the dialog must render the type selector')
	assert.match(open.html, /<option value="list"/, 'the list type must be selectable')
	assert.match(open.html, /type="checkbox"/, 'a boolean row must be a checkbox')

	// ---- driving the dialog: open, type mapping, save, removal ----

	/** A stub instance with the state the methods touch, and a recorder for the emitted map. */
	function instance(extras) {
		const emitted = []
		const ctx = {
			meta: { keys: Object.keys(extras), ...extras },
			rows: [],
			metaOpen: false,
			metaError: '',
			$emit: (name, payload) => emitted.push([name, payload]),
			emitted,
		}
		// the dialog methods call each other (`openMeta` -> `typeOf`/`editValue`, `applyMeta` -> `t`),
		// so every method is bound onto the stub exactly like a mounted instance would have them
		for (const [name, method] of Object.entries(ArticleFields.methods)) {
			ctx[name] = method.bind(ctx)
		}
		return ctx
	}

	const typed = instance({ layout: 'post', hero: 'a.png', featured: true, weight: 3, topics: ['one', 'two'] })
	ArticleFields.methods.openMeta.call(typed)
	assert.equal(typed.metaOpen, true, 'openMeta must open the dialog')
	assert.deepEqual(
		typed.rows.map((row) => row.type),
		['text', 'text', 'boolean', 'number', 'list'],
		'the dialog must map each value to an editable type',
	)
	assert.equal(typed.rows[4].value, 'one, two', 'a list must be edited comma-separated')

	ArticleFields.methods.addRow.call(typed)
	assert.equal(typed.rows.length, 6, 'Add row must add a row')
	ArticleFields.methods.removeRow.call(typed, 5)
	assert.equal(typed.rows.length, 5, 'the per-row delete must remove it')

	const saved = instance({ hero: 'a.png', featured: true, weight: 3, topics: ['one'] })
	ArticleFields.methods.openMeta.call(saved)
	saved.rows.push({ key: 'draft_note', value: 'later', type: 'text' })
	const weightRow = saved.rows.find((row) => row.key === 'weight')
	weightRow.value = '7'
	assert.notEqual(ArticleFields.methods.applyMeta.call(saved), false, 'a valid map must be emitted')
	assert.equal(saved.metaOpen, false, 'a successful save closes the dialog')
	assert.equal(saved.emitted.length, 1, 'the save must emit exactly one map')
	assert.equal(saved.emitted[0][0], 'meta-save', 'the app listens for meta-save')
	assert.deepEqual(saved.emitted[0][1], {
		hero: 'a.png',
		featured: true,
		weight: 7,
		topics: ['one'],
		draft_note: 'later',
	}, 'the emitted map must carry real types, not strings')

	const bad = instance({ hero: 'a.png' })
	ArticleFields.methods.openMeta.call(bad)
	bad.rows[0].key = 'not a key'
	assert.equal(ArticleFields.methods.applyMeta.call(bad), false, 'an invalid key must keep the dialog open')
	assert.match(bad.metaError, /lower-case letters/, 'the refusal must say what a key may be')
	assert.equal(bad.emitted.length, 0, 'nothing may be emitted for an invalid key')

	const duplicate = instance({ hero: 'a.png' })
	ArticleFields.methods.openMeta.call(duplicate)
	duplicate.rows.push({ key: 'hero', value: 'b.png', type: 'text' })
	assert.equal(ArticleFields.methods.applyMeta.call(duplicate), false, 'a duplicate key must be refused')
	assert.match(duplicate.metaError, /same key/, 'the refusal must name the duplicate')

	const badNumber = instance({ weight: 1 })
	ArticleFields.methods.openMeta.call(badNumber)
	badNumber.rows[0].value = 'not a number'
	assert.equal(ArticleFields.methods.applyMeta.call(badNumber), false, 'a non-number must be refused')
	assert.match(badNumber.metaError, /not a number/, 'the refusal must say the value is not a number')

	// ---- the save payload: a removed row is an empty value, a changed one is the new value ----

	const original = { title: '', extra: { hero: 'a.png', featured: true, weight: 3 } }
	assert.deepEqual(
		changedMeta({ title: '', extra: {} }, original),
		{ hero: '', featured: '', weight: '' },
		'every removed key must be sent as an empty value',
	)
	assert.deepEqual(
		changedMeta({ title: '', extra: { hero: 'b.png', featured: true, weight: 3 } }, original),
		{ hero: 'b.png' },
		'only the changed key may be sent',
	)
	assert.deepEqual(
		changedMeta({ title: '', extra: { hero: 'a.png', featured: true, weight: 3 } }, original),
		{},
		'an unchanged map must send nothing',
	)

	// ---- the categories: a list of single-level names, sent only when it changed ----

	// the panel holds one line; the server takes a list and writes it as an inline list
	assert.deepEqual(
		changedMeta(
			{ title: '', categoriesText: 'guides, notes' },
			{ title: '', categoriesText: 'guides' },
		),
		{ categories: ['guides', 'notes'] },
		'a changed category list must be sent as a list',
	)
	assert.deepEqual(
		changedMeta(
			{ title: '', categoriesText: 'notes, guides' },
			{ title: '', categoriesText: 'guides, notes' },
		),
		{},
		'reordering the same categories is not a change (one copy per category)',
	)
	assert.deepEqual(
		changedMeta({ title: '', categoriesText: '' }, { title: '', categoriesText: 'guides' }),
		{ categories: [] },
		'an emptied field must send an empty list, which removes the key',
	)
	// and it must not reach the additional-metadata table, which owns the keys the panel does not show
	const withCategories = await mount({ ...meta, categories: ['guides'] })
	assert.doesNotMatch(withCategories.html, /<th scope="row">categories/, 'categories is a panel field')

	console.log('ArticleFields: extra table, metadata dialog, type mapping and emitted delta OK')
} finally {
	rmSync(workDir, { recursive: true, force: true })
}
