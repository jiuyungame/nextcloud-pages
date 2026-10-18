/**
 * Node-level proof of src/GrantPicker.vue — the picker that replaced the three native multi-selects
 * of the settings page.
 *
 * The admin page has no browser test, so the component is compiled with @vue/compiler-sfc, bundled
 * with esbuild (the app's own dependencies, `vue` shared with this process) and rendered with Vue's
 * server renderer. That is enough to assert the parts the settings page depends on:
 *
 *   - modelValue → the granted groups are drawn as tags (two groups, two tags)
 *   - an empty modelValue → no tag, and the documented default is written out
 *   - the props the page relies on: the option list, `displayName` as the label, `reduce` to the id,
 *     `multiple`, and the placeholder text
 *   - a selection emitted by NcSelect reaches the parent as the group-id array, which is the v-model
 *     contract the admin bundle turns into the hidden input value
 *   - no template identifier is unresolved (every component the template names is registered)
 *
 * Styles are not compiled here: this is about the rendered selection and the emitted array.
 *
 *   node tests/smoke-grant-picker.mjs
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
const sfcPath = join(appRoot, 'src', 'GrantPicker.vue')

// ---- compile the SFC the way the Vite build does (minus the scoped CSS) ----

const { descriptor, errors: parseErrors } = parse(readFileSync(sfcPath, 'utf8'), { filename: sfcPath })
assert.deepEqual(parseErrors, [], 'the SFC must parse')
assert.ok(descriptor.template, 'the SFC must have a template')

const id = 'smoke-grant-picker'
const script = compileScript(descriptor, { id })
const template = compileTemplate({ source: descriptor.template.content, filename: sfcPath, id })
assert.deepEqual(template.errors, [], 'the template must compile')

// the temp dir lives inside the app so that `vue` (external) resolves to the same module as here
const workDir = mkdtempSync(join(here, '.smoke-grant-picker-'))
try {
	writeFileSync(join(workDir, 'component.mjs'), script.content)
	writeFileSync(join(workDir, 'render.mjs'), template.code)
	writeFileSync(join(workDir, 'picker.mjs'), [
		"import component from './component.mjs'",
		"import { render } from './render.mjs'",
		'component.render = render',
		'export default component',
		'',
	].join('\n'))

	const outfile = join(workDir, 'picker.bundle.mjs')
	await build({
		entryPoints: [join(workDir, 'picker.mjs')],
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
	}
	globalThis.document = {
		documentElement: { dataset: {}, lang: 'en', dir: 'ltr' },
		body: {},
	}

	const { default: GrantPicker } = await import(pathToFileURL(outfile).href)

	const OPTIONS = [
		{ id: 'group-a', displayName: 'Group A' },
		{ id: 'group-b', displayName: 'Group B' },
	]
	const DEFAULT_AUTHOR = 'every signed-in user'

	/**
	 * Render the picker with a parent that records what it emits — the same v-model wiring the admin
	 * bundle uses.
	 *
	 * @param {string[]} modelValue the granted group ids
	 * @returns {Promise<{html: string, emitted: Array<string[]>, warnings: string[], root: object}>}
	 */
	async function mountGrantPicker(modelValue) {
		const emitted = []
		const root = h(GrantPicker, {
			label: 'Author',
			options: OPTIONS,
			placeholder: DEFAULT_AUTHOR,
			modelValue,
			'onUpdate:modelValue': (value) => emitted.push(value),
		})
		const app = createSSRApp({ render: () => root })
		const warnings = []
		app.config.warnHandler = (message) => warnings.push(message)
		const html = await renderToString(app)
		return { html, emitted, warnings, root }
	}

	/** Depth-first search for a vnode, following component subtrees as well as element children */
	function findVNode(vnode, predicate) {
		if (!vnode || typeof vnode !== 'object') {
			return null
		}
		if (predicate(vnode)) {
			return vnode
		}
		if (Array.isArray(vnode.children)) {
			for (const child of vnode.children) {
				const found = findVNode(child, predicate)
				if (found) {
					return found
				}
			}
		}
		return vnode.component?.subTree ? findVNode(vnode.component.subTree, predicate) : null
	}

	/** The tag count of a rendered selection: one `vs__selected` span per granted group */
	function tagCount(html) {
		return (html.match(/vs__selected"/g) ?? []).length
	}

	// ---- modelValue → rendered selection ----

	const two = await mountGrantPicker(['group-a', 'group-b'])
	assert.deepEqual(two.warnings, [], 'rendering must not warn (an unresolved template identifier would)')
	assert.equal(tagCount(two.html), 2, 'two granted groups must render as two tags')
	assert.match(two.html, /Group A/, 'the first granted group must be labelled by displayName')
	assert.match(two.html, /Group B/, 'the second granted group must be labelled by displayName')
	assert.equal((two.html.match(/vs__deselect/g) ?? []).length, 2, 'each tag must be removable')
	assert.match(two.html, />Author<\/label>/, 'the capability name must be the visible label')

	const ncSelect = findVNode(two.root, (vnode) => vnode.type?.name === 'NcSelect')
	assert.ok(ncSelect, 'the picker must render an NcSelect')
	// resolved props (not the raw `model-value` attributes) are what the component really receives
	const selectProps = ncSelect.component?.props
	assert.ok(selectProps, 'the NcSelect must be a mounted component')
	assert.equal(selectProps.multiple, true, 'the picker must allow several groups')
	assert.equal(selectProps.label, 'displayName', 'the option label must be the display name')
	assert.equal(selectProps.placeholder, DEFAULT_AUTHOR, 'the documented default must be passed on')
	assert.deepEqual(selectProps.options, OPTIONS, 'the option list must be handed over unchanged')
	assert.deepEqual(selectProps.modelValue, ['group-a', 'group-b'], 'the model must be bound to the array')
	assert.equal(selectProps.reduce(OPTIONS[1]), 'group-b', 'the emitted value must be the group id')

	// the visible <label> points at the picker's own input, so the control has a name
	const forId = two.html.match(/<label[^>]*for="([^"]+)"/)?.[1]
	assert.ok(forId, 'the label must point at the picker input')
	assert.equal(selectProps.inputId, forId, 'the label target must be the select input id')
	assert.match(two.html, new RegExp(`<input[^>]*id="${forId}"`), 'the search input must carry that id')

	// ---- empty array = the documented default ----

	const empty = await mountGrantPicker([])
	assert.deepEqual(empty.warnings, [], 'the empty state must render without warnings')
	assert.equal(tagCount(empty.html), 0, 'no group granted must render no tag')
	assert.equal((empty.html.match(/vs__deselect/g) ?? []).length, 0, 'no tag means nothing to remove')
	assert.match(empty.html, new RegExp(DEFAULT_AUTHOR), 'the documented default must be written out')

	// ---- emitting an update writes the parent's array ----

	const picked = await mountGrantPicker([])
	const picker = findVNode(picked.root, (vnode) => vnode.type?.name === 'NcSelect')
	picker.props['onUpdate:modelValue'](['group-b'])
	assert.deepEqual(picked.emitted, [['group-b']], 'the selection must reach the parent as the array')
	assert.equal(JSON.stringify(picked.emitted[0]), '["group-b"]', 'that array is the hidden input value')

	// `reduce` may hand back a single id; the parent still gets a list, never a bare string
	picker.props['onUpdate:modelValue']('group-a')
	assert.deepEqual(picked.emitted[1], ['group-a'], 'a single picked id must still become an array')

	// clearing every tag is an empty list, which is how a capability goes back to its default
	picker.props['onUpdate:modelValue'](null)
	assert.deepEqual(picked.emitted[2], [], 'clearing must send an empty array')

	// and re-rendering with what was emitted draws the picked group
	const again = await mountGrantPicker(picked.emitted[0])
	assert.equal(tagCount(again.html), 1, 'the picked group must render after the round trip')
	assert.match(again.html, /Group B/, 'the tag must carry the picked group name')

	console.log('GrantPicker: modelValue, empty default, props and update round trip OK')
} finally {
	rmSync(workDir, { recursive: true, force: true })
}
