/**
 * Front-end proof: the templates of this app read *values* and call *methods*.
 *
 * The app has hit one bug four times: a template read a name that was a method (`nodeIcon`,
 * `currentActions`, `fileEntries`, `authorPath`), so the render threw instead of showing the control.
 * `vite build` compiles the template and cannot see it (the names are resolved at render time), which
 * is why this script exists and is run after every change to runtime component code.
 *
 * It does two things, against the real sources:
 *
 *   1. **placement** — every root identifier a template *reads* must be a computed, a data key or a
 *      prop, and every identifier it *calls* must be a method (or a known global / instance property).
 *      Scope is tracked: `v-for` aliases and `v-slot` props are locals, not component members.
 *   2. **render** — each touched SFC is compiled with @vue/compiler-sfc, bundled with esbuild (the
 *      Nextcloud components, icons and CodeMirror replaced by stubs) and mounted with the real Vue
 *      runtime, server-side, with state seeded so the rows, badges and buttons are actually rendered.
 *      A wrong placement throws here, exactly where it would throw in the browser.
 *
 *   node tests/code/proof-frontend.mjs
 *
 * Exit code 1 lists every violation; 0 means both proofs passed for every component.
 */
import { readFileSync } from 'node:fs'
import { createRequire } from 'node:module'
import { dirname, resolve } from 'node:path'
import { fileURLToPath } from 'node:url'
import * as esbuild from 'esbuild'
import { parse as babelParse } from '@babel/parser'
import { parse as domParse } from '@vue/compiler-dom'
import { compileTemplate, parse as parseSfc } from '@vue/compiler-sfc'
import { createSSRApp, h } from 'vue'
import { renderToString } from 'vue/server-renderer'

const root = resolve(dirname(fileURLToPath(import.meta.url)), '../..')
const require = createRequire(import.meta.url)

/** Component-names known to the templates plus the instance API they may touch. */
const GLOBALS = new Set([
	'String', 'Number', 'Boolean', 'Array', 'Object', 'Math', 'Date', 'JSON', 'parseInt',
	'parseFloat', 'isNaN', 'isFinite', 'undefined', 'null', 'true', 'false', 'NaN', 'Infinity',
	'console', 'window', 'document',
])

const SFC_DIRECTIVE_KEYS = new Set(['if', 'else-if', 'show', 'for', 'on', 'bind', 'model', 'slot', 'html', 'text', 'once', 'memo', 'pre', 'cloak'])

/* ------------------------------------------------------------------ template analysis */

/**
 * The identifiers one template expression reads as a value and the ones it calls.
 *
 * `MemberExpression` roots count as reads (`busy.has` reads `busy`), computed members are analysed
 * too (`notes[key]`), and a callee that is a member expression is not a name to check (`rowKey` is,
 * `busy.has` is not: `busy` is read, `.has` is its own method).
 *
 * @param {string} expression
 * @param {{reads: Set<string>, calls: Set<string>, locals: Set<string>}} out
 */
function collectNames(expression, out) {
	if (typeof expression !== 'string' || expression.trim() === '') {
		return
	}
	let ast
	try {
		// parenthesised: an expression that starts with `{` is an object literal here, not a block
		ast = babelParse(`(${expression})`, { sourceType: 'module' }).program.body[0].expression
	} catch (error) {
		throw new Error(`cannot parse template expression "${expression}": ${error.message}`)
	}
	const walk = (node, shadow = new Set()) => {
		if (!node || typeof node.type !== 'string') {
			return
		}
		switch (node.type) {
		case 'Identifier':
			if (!shadow.has(node.name)) {
				out.reads.add(node.name)
			}
			return
		case 'CallExpression':
		case 'OptionalCallExpression':
			if (node.callee.type === 'Identifier') {
				if (shadow.has(node.callee.name)) {
					out.locals.add(node.callee.name)
				} else {
					out.calls.add(node.callee.name)
				}
			} else {
				walk(node.callee, shadow)
			}
			for (const argument of node.arguments) {
				walk(argument, shadow)
			}
			return
		case 'ArrowFunctionExpression':
		case 'FunctionExpression': {
			const inner = new Set(shadow)
			for (const param of node.params) {
				for (const name of patternNames(param)) {
					inner.add(name)
				}
			}
			walk(node.body, inner)
			return
		}
		case 'ObjectProperty':
		case 'ObjectMethod':
			// a non-computed key is the name of a property, not a member this component must have
			if (node.computed) {
				walk(node.key, shadow)
			}
			if (node.type === 'ObjectMethod') {
				walk(node.body, shadow)
			} else {
				walk(node.value, shadow)
			}
			return
		case 'MemberExpression':
		case 'OptionalMemberExpression':
			walk(node.object, shadow)
			if (node.computed) {
				walk(node.property, shadow)
			}
			return
		default:
			for (const key of Object.keys(node)) {
				const value = node[key]
				if (Array.isArray(value)) {
					for (const entry of value) {
						if (entry && typeof entry === 'object') {
							walk(entry, shadow)
						}
					}
				} else if (value && typeof value === 'object') {
					walk(value, shadow)
				}
			}
		}
	}
	walk(ast)
}

/** The names a binding pattern introduces (`(article)`, `{ article }`, `[a, b]`). */
function patternNames(node) {
	if (!node || typeof node !== 'object') {
		return []
	}
	if (node.type === 'Identifier') {
		return [node.name]
	}
	if (node.type === 'AssignmentPattern') {
		return patternNames(node.left)
	}
	if (node.type === 'ObjectPattern' || node.type === 'ObjectExpression') {
		return node.properties.flatMap((property) => {
			if (property.type === 'SpreadElement' || property.type === 'RestElement') {
				return patternNames(property.argument)
			}
			// `{ article }` parses as an object literal in expression position; its key is the name
			if (property.shorthand || property.type === 'ObjectMethod') {
				return patternNames(property.key)
			}
			return patternNames(property.value)
		})
	}
	if (node.type === 'ArrayPattern' || node.type === 'ArrayExpression') {
		return node.elements.flatMap((element) => patternNames(element))
	}
	if (node.type === 'RestElement') {
		return patternNames(node.argument)
	}
	return []
}

/**
 * Every name the template of one SFC reads and calls, with locals scoped, plus the components its
 * template uses.
 *
 * The template is parsed by Vue's own parser (the one `vite` builds from) and compiled as well, so a
 * template that does not compile fails here first.
 */
function analyzeTemplate(source, filename) {
	const { descriptor, errors } = parseSfc(source, { filename })
	if (errors.length) {
		throw new Error(`${filename}: ${errors.map((error) => error.message).join('; ')}`)
	}
	const out = { reads: new Set(), calls: new Set(), locals: new Set(), components: new Set(), boundValues: new Set() }
	if (!descriptor.template) {
		return out
	}
	const compiled = compileTemplate({
		source: descriptor.template.content,
		filename,
		id: 'proof',
		compilerOptions: { prefixIdentifiers: false },
	})
	if (compiled.errors.length) {
		throw new Error(`${filename}: ${compiled.errors.map((error) => String(error)).join('; ')}`)
	}
	// the DOM parser knows the void elements (`<input>` without a closing tag) and SFC comments
	const ast = domParse(descriptor.template.content, { prefixIdentifiers: false })

	const walk = (node, scope) => {
		if (!node) {
			return
		}
		switch (node.type) {
		case 2: // TEXT
			return
		case 5: // INTERPOLATION
			collectNames(node.content?.content, out)
			return
		case 1: { // ELEMENT
			const local = new Set(scope)
			if (typeof node.tag === 'string' && /^[A-Z]/.test(node.tag)) {
				out.components.add(node.tag)
			}
			for (const prop of node.props ?? []) {
				if (prop.type === 7) { // DIRECTIVE
					if (prop.name === 'for') {
						const exp = prop.exp?.content ?? ''
						const match = /^\s*(?:\(([^)]*)\)|([\w$]+))\s+(?:in|of)\s+([\s\S]+)$/.exec(exp)
						if (match) {
							const alias = match[1] ?? match[2] ?? ''
							for (const name of alias.split(',').map((part) => part.trim())) {
								if (/^[\w$]+$/.test(name)) {
									local.add(name)
									out.locals.add(name)
								}
							}
							collectNames(match[3], out)
						}
					} else if (prop.name === 'slot') {
						for (const name of patternNames(babelObjectPattern(prop.exp?.content ?? ''))) {
							local.add(name)
							out.locals.add(name)
						}
					} else if (prop.name === 'on') {
						const exp = prop.exp?.content ?? ''
						// `@click="save"` passes a method as the handler: the framework calls it, so a
						// bare function reference is legitimate and must be a method
						if (/^[A-Za-z_$][\w$]*(\.[\w$]+)*$/.test(exp.trim())) {
							out.calls.add(exp.trim().split('.')[0])
						} else {
							collectNames(exp, out)
						}
					} else if (prop.exp) {
						// a bare identifier handed to a prop may be a callback the child calls
						// (`:show-banner="showBanner"`), so it counts as a reference, not as a value
						const content = String(prop.exp.content ?? '').trim()
						if (prop.name === 'bind' && /^[A-Za-z_$][\w$]*(\.[\w$]+)*$/.test(content)) {
							out.boundValues.add(content.split('.')[0])
						}
						collectNames(prop.exp.content, out)
					}
				}
			}
			for (const child of node.children ?? []) {
				walk(child, local)
			}
			return
		}
		case 11: // FOR
			walk(node.source, scope)
			for (const child of node.children ?? []) {
				walk(child, scope)
			}
			return
		case 12: // IF
			for (const branch of node.branches ?? []) {
				walk(branch.condition, scope)
				for (const child of branch.children ?? []) {
					walk(child, scope)
				}
			}
			return
		default:
			for (const child of node.children ?? []) {
				walk(child, scope)
			}
			for (const branch of node.branches ?? []) {
				walk(branch, scope)
			}
		}
	}
	walk(ast, new Set())
	return out
}

/** An object-destructuring pattern out of a `v-slot` expression, or null. */
function babelObjectPattern(expression) {
	if (!expression || expression.trim() === '') {
		return null
	}
	try {
		const node = babelParse(`(${expression.trim()})`, { sourceType: 'module' }).program.body[0].expression
		return node?.type === 'ObjectPattern' || node?.type === 'ObjectExpression' ? node : null
	} catch (error) {
		return null
	}
}

/* ------------------------------------------------------------------ component loading */

/** Toast calls the stubbed `@nextcloud/dialogs` records, so `showBanner`'s mapping can be checked. */
const toastCalls = []
/** Every axios call the stubs saw, so a test can assert *which* endpoint and body an action used. */
const axiosCalls = []
/**
 * The body a stubbed axios call answers with. `{}` unless a test sets a shape it needs to see handled
 * (the delete answer's `mode_applied` / `transfer` block, for instance); every test that changes it
 * puts it back, so no other check sees the value.
 */
let axiosAnswer = {}

/** Stub components for the Nextcloud UI, the icons and CodeMirror: the proof is about our logic. */
function stubs() {
	const component = (name) => ({
		name,
		render() {
			const children = []
			for (const key of Object.keys(this.$slots ?? {})) {
				children.push(h('div', { class: `slot-${key}` }, this.$slots[key]()))
			}
			return h('div', { class: `stub stub-${name}` }, children)
		},
	})
	const t = (app, text, vars) => {
		let out = String(text)
		if (Array.isArray(vars)) {
			let index = 0
			out = out.replace(/%(\d+)\$s/g, (match, position) => String(vars[Number(position) - 1] ?? ''))
			out = out.replace(/%s/g, () => String(vars[index++] ?? ''))
		}
		return out
	}
	return (id) => {
		if (id === 'vue') {
			return require('vue')
		}
		if (id.startsWith('@nextcloud/vue/')) {
			return { __esModule: true, default: component(id.split('/').pop()) }
		}
		if (id.startsWith('vue-material-design-icons/')) {
			return { __esModule: true, default: component('icon') }
		}
		if (id === '@nextcloud/router') {
			return { __esModule: true, generateUrl: (path) => `/index.php${path}` }
		}
		if (id === '@nextcloud/dialogs') {
			// The toasts are the app's feedback channel, not state the proof can inspect: rendering
			// one needs Nextcloud's toast container and a browser DOM. The stub records the calls
			// (type, message, options) so `showBanner`'s dispatch can still be checked.
			const record = (type) => (message, options) => toastCalls.push({ type, message, options })
			return { __esModule: true, showError: record('error'), showInfo: record('info'), showSuccess: record('success'), showWarning: record('warning') }
		}
		if (id === '@nextcloud/axios') {
			// the recording is what lets an action's *request* be asserted, not only its toast: the
			// same endpoint answers approve and reject, and which one a button sends is the rule under
			// test (see checkRejectMessages)
			const record = (method) => (url, body) => {
				axiosCalls.push({ method, url, body })
				// a fixed body answers every call; a function can answer by request (the request panel
				// asks two filters of the same endpoint and has to see both)
				const data = typeof axiosAnswer === 'function' ? axiosAnswer({ method, url, body }) : axiosAnswer
				return Promise.resolve({ status: 200, data })
			}
			const axios = { get: record('get'), post: record('post'), put: record('put'), delete: record('delete') }

			// both shapes: a bundled `import axios from '…'` resolves `.default` or the module object
			// itself, depending on the interop the bundler emits for an external commonjs module
			return { __esModule: true, default: axios, ...axios }
		}
		if (id === '@nextcloud/l10n') {
			return { __esModule: true, t, n: (app, singular, plural, count) => (count === 1 ? singular : plural) }
		}
		if (id === '@nextcloud/auth') {
			return { __esModule: true, getCurrentUser: () => ({ uid: 'proof-user' }) }
		}
		if (id === 'codemirror' || id.startsWith('@codemirror/')) {
			return { __esModule: true }
		}
		throw new Error(`the proof has no stub for "${id}" — add one instead of letting the bundle resolve a browser-only module`)
	}
}

/**
 * One bundle as the runtime would see it: esbuild resolved every import (the `.vue` files through the
 * plugin below, everything else through the stub `require`), and the emitted CommonJS module is run
 * here. `exports` is what the entry exported, so the caller picks the named or the default one.
 *
 * @param {string} entry the entry source handed to esbuild
 * @param {boolean} stubEditor whether CodeMirror is stubbed (the editor module imports it)
 * @returns {Promise<object>} `module.exports` of the emitted bundle
 */
async function loadBundle(entry, stubEditor = true) {
	const external = ['vue', '@nextcloud/*', 'vue-material-design-icons/*']
	if (stubEditor) {
		external.push('@codemirror/*', 'codemirror')
	}
	const built = await esbuild.build({
		stdin: { contents: entry, resolveDir: root, loader: 'js' },
		bundle: true,
		write: false,
		format: 'cjs',
		platform: 'node',
		external,
		plugins: [{
			name: 'proof:vue',
			setup(build) {
				build.onLoad({ filter: /\.vue$/ }, async (args) => {
					const source = readFileSync(args.path, 'utf8')
					const { descriptor } = parseSfc(source, { filename: args.path })
					const script = descriptor.script?.content ?? 'export default {}'
					const code = `${script}\nconst __render = (() => { return function render() {} })()\n`
					// compile the template into a render function attached to the default export
					const compiled = compileTemplate({
						source: descriptor.template?.content ?? '',
						filename: args.path,
						id: 'proof',
						compilerOptions: { prefixIdentifiers: true },
					})
					if (compiled.errors.length) {
						throw new Error(`${args.path}: ${compiled.errors.map((error) => String(error)).join('; ')}`)
					}
					return {
						contents: `${script.replace(/export default/, 'const __component =')}\n${compiled.code.replace(/export function render/, 'function __render')}\n__component.render = __render\n__component.__file = ${JSON.stringify(args.path)}\nexport default __component\n`,
						loader: 'js',
					}
				})
			},
		}],
	})
	const code = built.outputFiles[0].text
	const module = { exports: {} }
	const localRequire = (id) => stubs()(id)
	// eslint-disable-next-line no-new-func
	new Function('exports', 'module', 'require', code)(module.exports, module, localRequire)
	return module.exports
}

/**
 * One SFC as the component object the runtime would see: compiled by @vue/compiler-sfc, bundled by
 * esbuild, loaded with the stub `require` above.
 */
async function loadComponent(relative, { stubEditor = true } = {}) {
	const file = resolve(root, relative)
	const entry = `
import component from ${JSON.stringify(file)}
export default component
`
	return (await loadBundle(entry, stubEditor)).default
}

/**
 * One plain JS module of `src/` with its named exports (and its default), for the parts of the app
 * that are not components: `src/api.js` is asserted this way, because which parameters a request
 * carries is not visible in a render.
 *
 * @param {string} relative e.g. `src/api.js`
 * @param {{stubEditor?: boolean}} [options]
 * @returns {Promise<object>} the module's exports
 */
async function loadModule(relative, { stubEditor = true } = {}) {
	const file = resolve(root, relative)
	const entry = `
export * from ${JSON.stringify(file)}
export { default } from ${JSON.stringify(file)}
`
	return loadBundle(entry, stubEditor)
}

/**
 * One plain JS module of `src/` with **only** its named exports, for a module that has no default
 * export (`src/applySource.js`): the bundle is built and run with the same stubs, so a test can call
 * the flow the page uses.
 *
 * @param {string} relative e.g. `src/applySource.js`
 * @returns {Promise<object>} the module's named exports
 */
async function loadNamedModule(relative) {
	const file = resolve(root, relative)
	return loadBundle(`export * from ${JSON.stringify(file)}`)
}

/* ------------------------------------------------------------------ the proof itself */

const failures = []
const note = (message) => failures.push(message)

/** The names a `setup()` returns: composition-API bindings are values the template may read. */
function setupBindings(component) {
	const names = new Set()
	if (typeof component.setup !== 'function') {
		return names
	}
	let ast
	try {
		// the method syntax (`setup() {…}`) is re-wrapped as an object to parse it as written
		ast = babelParse(`({${component.setup.toString()}})`, { sourceType: 'module' })
			.program.body[0].expression.properties[0]
	} catch (error) {
		return names
	}
	const walk = (node) => {
		if (!node || typeof node.type !== 'string') {
			return
		}
		if (node.type === 'ReturnStatement' && node.argument?.type === 'ObjectExpression') {
			for (const property of node.argument.properties) {
				if (property.type === 'ObjectProperty' && property.key.type === 'Identifier') {
					names.add(property.key.name)
				}
			}
		}
		for (const key of Object.keys(node)) {
			const value = node[key]
			if (Array.isArray(value)) {
				value.forEach(walk)
			} else if (value && typeof value === 'object') {
				walk(value)
			}
		}
	}
	walk(ast)
	return names
}

/**
 * 1. placement: for every component, every read is data/computed/prop, every call is a method, and
 * every capitalized tag in the template is a registered component (an unresolved one renders nothing).
 */
async function checkPlacement(components) {
	for (const relative of components) {
		const source = readFileSync(resolve(root, relative), 'utf8')
		const names = analyzeTemplate(source, relative)
		const component = await loadComponent(relative)
		const computed = Object.keys(component.computed ?? {})
		const methods = Object.keys(component.methods ?? {})
		const props = Object.keys(component.props ?? {})
		const data = Object.keys(component.data?.call({ shell: {}, ...seededProps(relative) }) ?? {})
		const setup = [...setupBindings(component)]
		const values = new Set([...computed, ...data, ...props, ...setup])
		const methodSet = new Set(methods)
		const registered = new Set(Object.keys(component.components ?? {}))
		for (const name of names.reads) {
			if (GLOBALS.has(name) || name.startsWith('$') || names.locals.has(name)) {
				continue
			}
			if (!values.has(name) && !(names.boundValues.has(name) && methodSet.has(name))) {
				note(`${relative}: template reads "${name}", which is ${methodSet.has(name) ? 'a method (call it: "' + name + '()")' : 'not a computed, data key or prop'}`)
			}
		}
		for (const name of names.calls) {
			if (GLOBALS.has(name) || name.startsWith('$') || names.locals.has(name)) {
				continue
			}
			// a prop of function type is a callback the parent supplies (a "showBanner" prop)
			if (!methodSet.has(name) && !props.includes(name)) {
				note(`${relative}: template calls "${name}", which is not a method${values.has(name) ? ' (it is a value — drop the parentheses)' : ''}`)
			}
		}
		for (const tag of names.components) {
			// `<component>`/`<template>`/`<slot>` are lowercase; only registered names or builtins resolve
			if (!registered.has(tag)) {
				note(`${relative}: template uses <${tag}>, which is not registered in "components" (it renders nothing)`)
			}
		}
		process.stdout.write(`  placement ${relative}: ${names.reads.size} read(s), ${names.calls.size} call(s), ${names.components.size} component(s)\n`)
	}
}

/** Props that a component's `data()` reads while it is being created (App.vue's shell). */
function seededProps(relative) {
	if (relative.endsWith('App.vue')) {
		return { shell: shellState() }
	}
	return {}
}

function shellState(overrides = {}) {
	// one page: there is no `page`, no second URL, and no `sourceUrl` any more
	return {
		uid: 'alice',
		site: { display: 'Proof site' },
		canAuthor: true,
		canEdit: false,
		canPublish: false,
		canReview: false,
		isAdmin: false,
		...overrides,
	}
}

/** A tree node as the tree endpoint reports it, for the seeded App render. */
function treeNode(overrides) {
	return {
		path: 'notes/hello.md',
		target: '/tmp/alice/notes/hello.md',
		name: 'hello.md',
		type: 'file',
		kind: 'page',
		state: 'internal',
		intent: '',
		owner: 'alice',
		live: false,
		...overrides,
	}
}

/**
 * 2. render: mount the real compiled component with real state, so a bad placement throws.
 *
 * Each case returns the HTML; the assertions look for the texts a user must see.
 */
async function renderCases() {
	const cases = []

	// the tree + "My articles" on the content page, as an author with one entry in each state
	const App = await loadComponent('src/App.vue')
	const appShell = shellState()
	cases.push({
		name: 'App.vue (content page, one entry per state)',
		component: {
			...App,
			data() {
				const base = App.data.call(this)
				return {
					...base,
					nodes: [
						treeNode({ path: 'notes/internal.md', target: '/tmp/alice/notes/internal.md', name: 'internal.md', state: 'internal' }),
						treeNode({ path: 'notes/requested.md', target: '/tmp/alice/notes/requested.md', name: 'requested.md', state: 'internal', intent: 'online', candidate: true }),
						treeNode({ path: 'notes/external.md', target: '/tmp/alice/notes/external.md', name: 'external.md', state: 'external', live: true }),
						treeNode({ path: 'notes/approved.md', target: '/tmp/alice/notes/approved.md', name: 'approved.md', state: 'external', live: false }),
					],
					currentFile: '/tmp/alice/notes/requested.md',
					targetPrefix: '/tmp/alice',
					view: 'site',
				}
			},
		},
		props: { shell: appShell },
		// the three states; `external` reads the same short label whatever its commit did.
		// "Request online" comes from the tree/list rows now: the editor header no longer offers it
		// (the header itself is asserted separately, below).
		expect: ['Internal', 'Waiting for review', 'Online', 'Request online', 'Withdraw the request', 'Request offline'],
	})

	/*
	 * The editor header no longer says which editor is active and no longer shows the file's ETag:
	 * both sat next to the path as noise. The seam between the panes is now a control of its own —
	 * a separator that carries its share of the width and is only rendered while the preview is
	 * actually shown, because with the preview closed there is nothing to divide.
	 *
	 * The share reaches the layout only through the `:style` binding on the split, so the seeded
	 * ratio must show up in the rendered attribute: a hard-coded percentage (or a binding that reads
	 * the wrong key) fails here.
	 */
	const SplitApp = await loadComponent('src/App.vue')
	cases.push({
		name: 'App.vue (split: the divider between the editor and the preview)',
		component: {
			...SplitApp,
			data() {
				const base = SplitApp.data.call(this)
				return {
					...base,
					nodes: [treeNode({ path: 'notes/split.md', target: '/tmp/alice/notes/split.md', name: 'split.md' })],
					currentFile: '/tmp/alice/notes/split.md',
					targetPrefix: '/tmp/alice',
					view: 'site',
					splitRatio: 0.65,
				}
			},
		},
		props: { shell: appShell },
		expect: [
			'--pages-split-editor:65%',
			'role="separator"',
			'aria-valuenow="65"',
			'aria-valuemin="25"',
			'aria-valuemax="75"',
			'Drag to resize the panes; double-click to reset',
		],
		expectCount: [{ text: 'pages-split__handle', count: 1 }],
		absent: ['pages-pane__mode', 'ETag'],
	})

	const SplitSoloApp = await loadComponent('src/App.vue')
	cases.push({
		name: 'App.vue (split: no divider while the preview is closed)',
		component: {
			...SplitSoloApp,
			data() {
				const base = SplitSoloApp.data.call(this)
				return {
					...base,
					currentFile: '/tmp/alice/notes/split.md',
					targetPrefix: '/tmp/alice',
					view: 'site',
					previewVisible: false,
				}
			},
		},
		props: { shell: appShell },
		expect: ['pages-split--solo', '--pages-split-editor:50%'],
		absent: ['pages-split__handle'],
	})

	// an author's online entry: the update of its content is an online request under the snapshot
	// model ("Request the update online"), and a page with something pending offers the withdrawal.
	// The retired content-review wording - "modified", "review pending" and its own withdrawal
	// action - must not come back.
	const SnapshotApp = await loadComponent('src/App.vue')
	cases.push({
		name: 'App.vue (an online entry: request the update, request a take-off, or withdraw)',
		component: {
			...SnapshotApp,
			data() {
				const base = SnapshotApp.data.call(this)
				return {
					...base,
					nodes: [
						treeNode({ path: 'notes/live.md', target: '/tmp/alice/notes/live.md', name: 'live.md', state: 'external', live: true, published: true }),
						treeNode({ path: 'notes/waiting.md', target: '/tmp/alice/notes/waiting.md', name: 'waiting.md', state: 'external', intent: 'online', published: true, candidate: true, submittedAt: 1700000000 }),
					],
					currentFile: '',
					targetPrefix: '/tmp/alice',
					view: 'site',
				}
			},
		},
		props: { shell: shellState() },
		expect: ['Request the update online', 'Request offline', 'Withdraw the request'],
		absent: ['Withdraw the update request', 'Online · modified', 'Online · review pending'],
	})

	// the same page with the three commit/push facts an `external` row can carry: none of them changes
	// the label any more - the badge says Online for all three, and no old sub-label survives
	const ExternalLabelApp = await loadComponent('src/App.vue')
	cases.push({
		name: 'App.vue (external entries: live, approved, and a failed push - one label)',
		component: {
			...ExternalLabelApp,
			data() {
				const base = ExternalLabelApp.data.call(this)
				return {
					...base,
					nodes: [
						treeNode({ path: 'notes/live.md', target: '/tmp/alice/notes/live.md', name: 'live.md', state: 'external', live: true }),
						treeNode({ path: 'notes/waiting.md', target: '/tmp/alice/notes/waiting.md', name: 'waiting.md', state: 'external', live: false }),
						treeNode({ path: 'notes/failed.md', target: '/tmp/alice/notes/failed.md', name: 'failed.md', state: 'external', live: true, pushFailed: true }),
					],
					targetPrefix: '/tmp/alice',
					view: 'site',
				}
			},
		},
		props: { shell: appShell },
		expect: ['Online'],
		absent: ['Approved (to be published)', 'Live (push failed)', 'Live'],
	})

	// the publish confirmation says whether a push will happen - both worlds, from the status the
	// bridge reports (the trimmed site object carries no repository for a non-administrator)
	const NoRemoteApp = await loadComponent('src/App.vue')
	cases.push({
		name: 'App.vue (publish confirmation: no remote = commit only)',
		component: {
			...NoRemoteApp,
			data() {
				const base = NoRemoteApp.data.call(this)
				return { ...base, status: { remote_configured: false }, publishConfirm: true }
			},
		},
		props: { shell: appShell },
		expect: ['Publish to the site?', 'This site has no git remote: a publish commits to the local repository only.'],
	})
	const RemoteApp = await loadComponent('src/App.vue')
	cases.push({
		name: 'App.vue (publish confirmation: remote = commit and push)',
		component: {
			...RemoteApp,
			data() {
				const base = RemoteApp.data.call(this)
				return { ...base, status: { remote_configured: true }, publishConfirm: true }
			},
		},
		props: { shell: appShell },
		expect: ['Publish to the site?', 'The commit is pushed to the configured remote.'],
	})

	// the same page as a reviewer: the decision buttons must exist for somebody else's entry. The
	// direct take-down is not one of them any more: it is the manage view's action, not a row action
	// on a pending request.
	const ReviewerApp = await loadComponent('src/App.vue')
	cases.push({
		name: 'App.vue (reviewer, decision buttons)',
		component: {
			...ReviewerApp,
			data() {
				const base = ReviewerApp.data.call(this)
				return {
					...base,
					nodes: [
						treeNode({ path: 'notes/bob.md', target: '/tmp/alice/notes/bob.md', name: 'bob.md', state: 'requested', intent: 'offline', owner: 'bob', downRequest: true, published: true }),
					],
					currentFile: '',
					targetPrefix: '/tmp/alice',
				}
			},
		},
		props: { shell: shellState({ canReview: true }) },
		expect: ['Approve', 'Reject'],
		// a pending take-off is approved in the request panel; no row action takes a page down
		absent: ['Take off the site'],
	})

	// ... and the same buttons for the reviewer's own request: the server allows a reviewer to decide
	// about an entry they own, so the front end must not hide them (hiding them was the bug)
	const OwnRequestApp = await loadComponent('src/App.vue')
	cases.push({
		name: 'App.vue (reviewer, own request: decision buttons too)',
		component: {
			...OwnRequestApp,
			data() {
				const base = OwnRequestApp.data.call(this)
				return {
					...base,
					nodes: [
						treeNode({ path: 'notes/mine.md', target: '/tmp/alice/notes/mine.md', name: 'mine.md', state: 'internal', intent: 'online', owner: 'alice', candidate: true, published: false }),
					],
					currentFile: '/tmp/alice/notes/mine.md',
					targetPrefix: '/tmp/alice',
				}
			},
		},
		props: { shell: shellState({ canReview: true }) },
		expect: ['Approve', 'Reject', 'Withdraw the request'],
		// no wording may tell the reviewer their own entry cannot be decided: the server allows it
		absent: ['You cannot decide about your own request.'],
	})

	/*
	 * The entry point the bug report was about used to be a take-down action on the reviewer's own
	 * online article; that action is gone from every Edit surface now (tree, My articles, editor
	 * header), because taking a page down is a decision about the *site* and lives in the manage
	 * view. What must survive here is the author's own "Request offline": a reviewer decides about
	 * somebody else's take-off request in the request panel.
	 */
	const OwnOnlineApp = await loadComponent('src/App.vue')
	cases.push({
		name: 'App.vue (reviewer, own online article: no take-down action any more)',
		component: {
			...OwnOnlineApp,
			data() {
				const base = OwnOnlineApp.data.call(this)
				return {
					...base,
					nodes: [
						treeNode({ path: 'notes/live.md', target: '/tmp/alice/notes/live.md', name: 'live.md', state: 'external', live: true, owner: 'alice' }),
					],
					currentFile: '/tmp/alice/notes/live.md',
					targetPrefix: '/tmp/alice',
				}
			},
		},
		props: { shell: shellState({ canReview: true }) },
		expect: ['Request offline'],
		absent: ['Take off the site'],
	})

	/*
	 * The publish button of the content page (the review grant is 审核与发布, so a reviewer may
	 * publish). Three things have to hold for the same template: a permitted caller sees it, an
	 * unpermitted one does not, and while a publish is running it is still there but disabled - a
	 * button that vanished mid-request would look like a crash.
	 *
	 * The assertions name the rendered button, not the bare word "Publish": the template's own
	 * comments are part of the SSR output, so a substring test would pass on a comment.
	 */
	const PUBLISH_BUTTON = 'slot-default"> Publish</div>'
	const PublisherApp = await loadComponent('src/App.vue')
	cases.push({
		name: 'App.vue (content page, reviewer: Publish in the toolbar)',
		component: {
			...PublisherApp,
			data() {
				const base = PublisherApp.data.call(this)
				return { ...base, nodes: [], currentFile: '', targetPrefix: '/tmp/alice' }
			},
		},
		props: { shell: shellState({ canReview: true, canPublish: true }) },
		expect: [PUBLISH_BUTTON, 'My articles'],
		// the source page's own publish control is the other page's, never this one's
		absent: ['Review and publish', 'Commit and push'],
	})

	const AuthorApp = await loadComponent('src/App.vue')
	cases.push({
		name: 'App.vue (content page, author without the grant: no Publish button)',
		component: {
			...AuthorApp,
			data() {
				const base = AuthorApp.data.call(this)
				return { ...base, nodes: [], currentFile: '', targetPrefix: '/tmp/alice' }
			},
		},
		props: { shell: shellState() },
		expect: ['My articles', 'New article'],
		absent: [PUBLISH_BUTTON],
	})

	cases.push({
		name: 'App.vue (publishing: the button stays, disabled)',
		component: {
			...PublisherApp,
			data() {
				const base = PublisherApp.data.call(this)
				return { ...base, nodes: [], currentFile: '', targetPrefix: '/tmp/alice', publishing: true }
			},
		},
		props: { shell: shellState({ canReview: true, canPublish: true }) },
		// a running publish disables the control instead of removing it (the fallthrough attribute of
		// the stubbed NcButton is the rendered `disabled`)
		expect: [PUBLISH_BUTTON, 'stub-NcButton" variant="primary" disabled'],
	})

	const ConfirmApp = await loadComponent('src/App.vue')
	cases.push({
		name: 'App.vue (publish confirmation says what will happen)',
		component: {
			...ConfirmApp,
			data() {
				const base = ConfirmApp.data.call(this)
				return { ...base, nodes: [], currentFile: '', targetPrefix: '/tmp/alice', publishConfirm: true }
			},
		},
		props: { shell: shellState({ canReview: true, canPublish: true }) },
		expect: [
			'name="Publish to the site?"',
			'The pages marked external go out with this commit; the pages taken down disappear from the site with it.',
			'Commit message (optional)',
		],
	})

	/*
	 * Deleting a page the site serves is one question with three answers, so the delete cannot be the
	 * flat `window.confirm` any more: the file leaves with a take-off request that waits for a
	 * reviewer, or with an immediate hand-over to the orphan owner directory, or not at all. The
	 * dialog's buttons live in a computed (the NcDialog stub renders slots, not props), so the title
	 * and the explanation are asserted from the render and the three labels from the computed.
	 */
	const DeleteApp = await loadComponent('src/App.vue')
	cases.push({
		name: 'App.vue (deleting an online page: the three-way dialog)',
		component: {
			...DeleteApp,
			data() {
				const base = DeleteApp.data.call(this)
				return {
					...base,
					nodes: [treeNode({ path: 'notes/live.md', target: '/tmp/alice/notes/live.md', name: 'live.md', state: 'external', live: true, published: true })],
					currentFile: '',
					targetPrefix: '/tmp/alice',
					view: 'site',
					deleteChoice: { name: 'live.md' },
				}
			},
		},
		props: { shell: appShell },
		expect: [
			'name="Delete “live.md”?"',
			'The page is online. Choose what happens to it.',
		],
	})

	const deleteLabels = DeleteApp.computed.deleteDialogButtons.call({}).map((button) => button.label)
	for (const label of ['Cancel', 'Keep on the site', 'Request take-off']) {
		if (!deleteLabels.includes(label)) {
			note(`src/App.vue: the three-way delete dialog has no "${label}" button (got ${JSON.stringify(deleteLabels)})`)
		}
	}
	if (deleteLabels.length !== 3) {
		note(`src/App.vue: the three-way delete dialog offers ${deleteLabels.length} button(s), not 3 (${JSON.stringify(deleteLabels)})`)
	}
	const deletePrimary = DeleteApp.computed.deleteDialogButtons.call({}).find((button) => button.variant === 'primary')
	if (deletePrimary?.label !== 'Request take-off') {
		note(`src/App.vue: "Request take-off" is not the primary delete answer (got ${JSON.stringify(deletePrimary?.label ?? null)})`)
	}

	// the request panel carries a publish action too, but only as an event: no endpoint call of its own
	const RequestsPublish = await loadComponent('src/Requests.vue')
	cases.push({
		name: 'Requests.vue (publish action next to the batch buttons)',
		component: RequestsPublish,
		expect: ['Requests to go live', PUBLISH_BUTTON],
	})

	/*
	 * The push retry (part 2): a save whose push failed marks its entry, and the mark is a control -
	 * "not pushed · retry" - on the tree row and in the "My articles" table. Both surfaces read the same
	 * client-side map, so one case proves both; the count proves that only the failed entry carries it,
	 * and the second case proves the mark disappears once the push went through.
	 */
	const PushFailedApp = await loadComponent('src/App.vue')
	cases.push({
		name: 'App.vue (a save whose push failed marks the entry, tree and table)',
		component: {
			...PushFailedApp,
			data() {
				const base = PushFailedApp.data.call(this)
				return {
					...base,
					nodes: [
						treeNode({ path: 'notes/lost.md', target: '/tmp/alice/notes/lost.md', name: 'lost.md', state: 'external', live: true }),
						treeNode({ path: 'notes/pushed.md', target: '/tmp/alice/notes/pushed.md', name: 'pushed.md', state: 'external', live: true }),
					],
					currentFile: '',
					targetPrefix: '/tmp/alice',
					view: 'site',
					// exactly what save() records from `bridge.pushed === false`
					pushFailed: {
						'/tmp/alice/notes/lost.md': { code: 'UNREACHABLE', message: 'Cannot reach pages-bridge', retryable: true },
					},
				}
			},
		},
		props: { shell: shellState() },
		expect: ['My articles', 'pages-node__push'],
		// the failed entry appears twice: once in the navigation tree, once in the article table
		expectCount: [{ text: 'not pushed · retry', count: 2 }],
	})

	// no mark when the push went through, and the button is disabled while a retry is in flight
	const PushCleanApp = await loadComponent('src/App.vue')
	cases.push({
		name: 'App.vue (a pushed entry carries no mark)',
		component: {
			...PushCleanApp,
			data() {
				const base = PushCleanApp.data.call(this)
				return {
					...base,
					nodes: [treeNode({ path: 'notes/ok.md', target: '/tmp/alice/notes/ok.md', name: 'ok.md', state: 'external', live: true })],
					currentFile: '',
					targetPrefix: '/tmp/alice',
					view: 'site',
					pushFailed: {},
				}
			},
		},
		props: { shell: shellState() },
		expect: ['My articles'],
		absent: ['not pushed · retry'],
	})

	const PushRetryingApp = await loadComponent('src/App.vue')
	cases.push({
		name: 'App.vue (a retry in flight disables the mark)',
		component: {
			...PushRetryingApp,
			data() {
				const base = PushRetryingApp.data.call(this)
				return {
					...base,
					nodes: [treeNode({ path: 'notes/lost.md', target: '/tmp/alice/notes/lost.md', name: 'lost.md', state: 'external', live: true })],
					currentFile: '',
					targetPrefix: '/tmp/alice',
					view: 'site',
					retryingPush: true,
					pushFailed: {
						'/tmp/alice/notes/lost.md': { code: 'UNREACHABLE', message: 'Cannot reach pages-bridge', retryable: true },
					},
				}
			},
		},
		props: { shell: shellState() },
		expect: ['pages-node__push" disabled', 'not pushed · retry'],
	})

	/*
	 * The request panel with one entry of each kind. A candidate (a first publication here) carries
	 * its preview button and the ordinary Approve/Reject; a take-off request carries "Approve
	 * take-off". A row reads its path the same way the Manage view's rows do: the complete stored
	 * target, exactly as `article.path` reports it - never the author's own `Pages/…` reading.
	 */
	const Requests = await loadComponent('src/Requests.vue')
	cases.push({
		name: 'Requests.vue (a candidate and a take-off request)',
		component: {
			...Requests,
			data() {
				return {
					...Requests.data.call(this),
					previewBase: '/index.php/apps/pages/preview/tok/',
					articles: [
						{ path: '/tmp/alice/a.md', title: 'A', author: 'alice', owner: 'alice', state: 'internal', intent: 'online', candidate: true, published: false, url: '/authors/alice/a.html', submittedAt: 1700000000, reviewNote: '', live: false },
						{ path: '/tmp/alice/b.md', title: 'B', author: 'alice', owner: 'alice', state: 'requested', intent: 'offline', downRequest: true, published: true, submittedAt: 1700000000, reviewNote: 'not yet', live: true },
					],
				}
			},
		},
		expect: [
			'Requests to go live', 'Online requested on', 'Take-off request', 'Preview',
			'Approve', 'Reject', 'Approve take-off', 'Approve all (2)',
			// each row shows its complete stored target
			'pages-articles__path">/tmp/alice/a.md</span>',
			'pages-articles__path">/tmp/alice/b.md</span>',
		],
		absent: ['pages-articles__path">Pages/', 'Approve changes', 'Reject the update'],
	})

	/*
	 * A take-off request the author filed by deleting the source file (`sourceDeleted`): the row has to
	 * say so - the reviewer is deciding about a page whose author is gone - and the rejection has to
	 * warn that it hands the page to the orphan owner directory instead of keeping it online. The
	 * reason field is open here, so the warning is on screen as well as on the Reject button's title.
	 */
	const RequestsDeleted = await loadComponent('src/Requests.vue')
	cases.push({
		name: 'Requests.vue (a take-off request whose author deleted the file)',
		component: {
			...RequestsDeleted,
			data() {
				return {
					...RequestsDeleted.data.call(this),
					articles: [
						{ path: '/tmp/alice/gone.md', title: 'Gone', author: 'alice', owner: 'alice', state: 'requested', intent: 'offline', downRequest: true, published: true, sourceDeleted: true, submittedAt: 1700000000, reviewNote: '', live: true },
					],
					rejecting: new Set(['/tmp/alice/gone.md']),
				}
			},
		},
		expect: [
			'Take-off request',
			'The author deleted the file.',
			'Approve take-off',
			// rejecting a take-off whose file is gone transfers the page, so the button says so
			'Reject and transfer',
			'Rejecting transfers the page to the orphan directory.',
			// the same sentence on the Reject button, for the reviewer who has not opened the reason field
			'title="Rejecting transfers the page to the orphan directory."',
		],
	})

	// ... and a row the author did not delete the file for carries neither sentence
	const RequestsIntact = await loadComponent('src/Requests.vue')
	cases.push({
		name: 'Requests.vue (an ordinary take-off request: no deleted-source note)',
		component: {
			...RequestsIntact,
			data() {
				return {
					...RequestsIntact.data.call(this),
					articles: [
						{ path: '/tmp/alice/here.md', title: 'Here', author: 'alice', owner: 'alice', state: 'requested', intent: 'offline', downRequest: true, published: true, sourceDeleted: false, submittedAt: 1700000000, reviewNote: '', live: true },
					],
					rejecting: new Set(['/tmp/alice/here.md']),
				}
			},
		},
		expect: ['Take-off request', 'Approve take-off', 'Reason for rejecting'],
		absent: ['The author deleted the file.', 'Rejecting transfers the page to the orphan directory.'],
	})

	// the count line the review panel now carries instead of the settings page's Status row
	const RequestsCount = await loadComponent('src/Requests.vue')
	cases.push({
		name: 'Requests.vue (approved-for-the-site count in the header)',
		component: {
			...RequestsCount,
			data() {
				return { ...RequestsCount.data.call(this), articles: [], approvedCount: 3 }
			},
		},
		expect: ['Approved for the site: 3'],
	})

	// the candidate of a version the site already serves: the reviewer can look at the frozen version
	// before deciding, and the two answers are the ordinary approve/reject of a pending candidate.
	// The preview iframe is built from the session's base plus the row's own url, with
	// `?tree=onlinepreview` so the proxy serves the candidate tree.
	const RequestsUpdate = await loadComponent('src/Requests.vue')
	const pendingUpdate = {
		path: '/tmp/alice/a.md', title: 'A', author: 'alice', owner: 'alice',
		state: 'external', intent: 'online', candidate: true, published: true,
		url: '/authors/alice/a.html', submittedAt: 1700000000,
		reviewNote: '', live: true,
	}
	cases.push({
		name: 'Requests.vue (a pending update: preview the frozen version, approve or reject)',
		component: {
			...RequestsUpdate,
			data() {
				return {
					...RequestsUpdate.data.call(this),
					previewBase: '/index.php/apps/pages/preview/tok/',
					previewing: pendingUpdate,
					articles: [pendingUpdate],
				}
			},
		},
		expect: [
			'Requests to go live', 'Pending update', 'Preview', 'Approve', 'Reject',
			'src="/index.php/apps/pages/preview/tok/authors/alice/a.html?tree=onlinepreview"',
			// the pending version is named before the frame, so the reviewer knows what is on screen
			'Preview of the pending version',
			'Approve all (1)',
			'pages-articles__path">/tmp/alice/a.md</span>',
		],
		// the old wording described a decision that no longer exists, and previewing must not shorten
		// the stored target
		absent: ['Approve changes', 'Reject the update', 'Discard changes', 'pages-articles__path">Pages/'],
	})

	/*
	 * Two authors, two storage roots, both waiting for a decision: each row must read its own complete
	 * target. Shortening a target to `Pages/…` made requests of different authors look like one tree -
	 * the prefix is what says where the file really is, and the author column already names whose it
	 * is, so the reviewer never has to guess between `authors-src/alice/…` and `tmp/bob/…`.
	 */
	const RequestsTargets = await loadComponent('src/Requests.vue')
	cases.push({
		name: 'Requests.vue (two authors: each pending row shows its own complete target)',
		component: {
			...RequestsTargets,
			data() {
				return {
					...RequestsTargets.data.call(this),
					articles: [
						{ path: 'authors-src/alice/notes/a.md', title: 'A', author: 'alice', owner: 'alice', state: 'internal', intent: 'online', candidate: true, submittedAt: 1700000000, reviewNote: '', live: false },
						{ path: 'tmp/bob/x.md', title: 'B', author: 'bob', owner: 'bob', state: 'internal', intent: 'online', candidate: true, submittedAt: 1700000000, reviewNote: '', live: false },
					],
				}
			},
		},
		expect: [
			// each path cell carries its own target, and the author cell says whose it is
			'pages-articles__path">authors-src/alice/notes/a.md</span>',
			'pages-articles__path">tmp/bob/x.md</span>',
			'pages-articles__author">alice</span>',
			'pages-articles__author">bob</span>',
		],
		// neither target is shortened; the alice row must not read as if it lived under bob's root
		absent: [
			'pages-articles__path">Pages/',
			'pages-articles__path">notes/a.md',
			'pages-articles__path">x.md',
		],
	})

	/*
	 * The manage view (the fourth one): every online article of every author in one table, with the
	 * one action that takes a page off the site. The set is the list endpoint's `external=true`
	 * answer, so the rows are the API's own shape - no client-side filtering by owner anywhere.
	 *
	 * A row's path is the complete stored target, exactly as `article.path` reports it. With several
	 * authors in one table the prefix is the information, so nothing is shortened to `Pages/…`.
	 */
	const Manage = await loadComponent('src/Manage.vue')
	cases.push({
		name: 'Manage.vue (three online articles by three authors)',
		component: {
			...Manage,
			data() {
				return {
					...Manage.data.call(this),
					articles: [
						{ path: '/tmp/authors/alice/a.md', title: 'A', author: 'alice', owner: 'alice', state: 'external', date: 1700000000, live: true },
						{ path: '/tmp/authors/bob/b.md', title: 'B', author: 'bob', owner: 'bob', state: 'external', date: 1700000000, live: true },
						{ path: '/tmp/authors/carol/c.md', title: 'C', author: 'carol', owner: 'carol', state: 'external', date: 1700000000, live: true },
					],
					approvedCount: 3,
				}
			},
		},
		expect: [
			'pages-manage__title',
			// the three rows, each with its title and the author who wrote it
			'pages-articles__title">A</span>', 'pages-articles__title">B</span>', 'pages-articles__title">C</span>',
			'alice', 'bob', 'carol',
			'Take off the site',
			'Approved for the site: 3',
			// the complete stored target of each row - never shortened to `Pages/…`
			'pages-articles__path">/tmp/authors/alice/a.md</span>',
			'pages-articles__path">/tmp/authors/bob/b.md</span>',
			'pages-articles__path">/tmp/authors/carol/c.md</span>',
		],
		// one button per row: the action is on every online article, whoever wrote it
		expectCount: [{ text: 'Take off the site', count: 3 }],
		absent: ['Review note', 'pages-articles__path">Pages/'],
	})

	const ManageEmpty = await loadComponent('src/Manage.vue')
	cases.push({
		name: 'Manage.vue (nothing is online)',
		component: ManageEmpty,
		expect: ['Manage', 'No article is online yet.'],
		absent: ['Take off the site'],
	})

	/*
	 * Two authors, two storage roots: each row must read its own complete target. Shortening a
	 * target to `Pages/…` made rows of different authors look like one tree - the prefix is what
	 * says where the file really is (`authors-src/…` is the author area, `tmp/…` another root),
	 * and the author column already names whose file it is.
	 */
	const ManageTargets = await loadComponent('src/Manage.vue')
	cases.push({
		name: 'Manage.vue (two authors: each row shows its own complete target)',
		component: {
			...ManageTargets,
			data() {
				return {
					...ManageTargets.data.call(this),
					articles: [
						{ path: 'authors-src/alice/notes/a.md', title: 'A', author: 'alice', owner: 'alice', state: 'external', date: 1700000000, live: true },
						{ path: 'tmp/bob/x.md', title: 'B', author: 'bob', owner: 'bob', state: 'external', date: 1700000000, live: true },
					],
					approvedCount: 2,
				}
			},
		},
		expect: [
			// each path cell carries its own target, and the author cell says whose it is
			'pages-articles__path">authors-src/alice/notes/a.md</span>',
			'pages-articles__path">tmp/bob/x.md</span>',
			'pages-articles__author">alice</span>',
			'pages-articles__author">bob</span>',
		],
		// neither target is shortened; the alice row must not read as if it lived under bob's root
		absent: [
			'pages-articles__path">Pages/',
			'pages-articles__path">notes/a.md',
			'pages-articles__path">x.md',
		],
	})

	/*
	 * The manage entry of the view switch: only the review grant sees it, and the view it opens is
	 * the real component. Clicking cannot happen in a server render, so the second case starts on
	 * `view: 'manage'` - which is exactly the state the button sets.
	 */
	const ManageApp = await loadComponent('src/App.vue')
	cases.push({
		name: 'App.vue (reviewer: the Manage view in the toolbar and its content)',
		component: {
			...ManageApp,
			data() {
				const base = ManageApp.data.call(this)
				return {
					...base,
					nodes: [],
					currentFile: '',
					targetPrefix: '/tmp/authors/alice',
					view: 'manage',
				}
			},
		},
		props: { shell: shellState({ canReview: true }) },
		expect: ['slot-default">Manage</div>', 'No article is online yet.'],
	})

	const ManageAuthorApp = await loadComponent('src/App.vue')
	cases.push({
		name: 'App.vue (author without the grant: no Manage view)',
		component: {
			...ManageAuthorApp,
			data() {
				const base = ManageAuthorApp.data.call(this)
				return { ...base, nodes: [], currentFile: '', targetPrefix: '/tmp/authors/alice' }
			},
		},
		props: { shell: shellState() },
		expect: ['My articles'],
		absent: ['slot-default">Manage</div>', 'No article is online yet.'],
	})

	// the shared table with one row per state
	const Rows = await loadComponent('src/ArticleRows.vue')
	cases.push({
		name: 'ArticleRows.vue (three badges)',
		component: Rows,
		props: {
			rows: [
				{ path: '/a.md', title: 'A', state: 'internal', live: false },
				{ path: '/b.md', title: 'B', state: 'internal', intent: 'online', submittedAt: 1700000000, candidate: true, published: false, live: false },
				{ path: '/c.md', title: 'C', state: 'external', live: true },
				{ path: '/d.md', title: 'D', state: 'external', live: false },
				{ path: '/e.md', title: 'E', state: 'external', live: true, pushFailed: true },
			],
			showWaiting: true,
			showReviewNote: true,
		},
		// every `external` row carries the same short label, whatever its `live` flag or push did;
		// there is no separate "on the site" column left to contradict it
		expect: ['Internal', 'Waiting for review', 'Online', 'Online requested on'],
	})

	// the three states of the snapshot model, plus the two kinds of pending row: the badge says the
	// state, the marker under the title says what waits (an update of a served version, or a take-off).
	cases.push({
		name: 'ArticleRows.vue (three states, a pending update and a take-off request)',
		component: Rows,
		props: {
			rows: [
				{ path: '/a.md', title: 'A', state: 'internal', live: false },
				{ path: '/b.md', title: 'B', state: 'external', intent: 'online', submittedAt: 1700000000, candidate: true, published: true },
				{ path: '/c.md', title: 'C', state: 'requested', intent: 'offline', submittedAt: 1700000000, downRequest: true, published: true },
				{ path: '/d.md', title: 'D', state: 'external', live: true, published: true },
			],
		},
		expect: ['Internal', 'Waiting for review', 'Online', 'Pending update', 'Take-off request'],
		// the retired content-review labels are gone, and the plain badge renders once (the plain
		// "Online" is the only settled row)
		absent: ['Online · modified', 'Online · review pending'],
		expectCount: [{ text: 'badge--success">Online</span>', count: 1 }],
	})

	// the reading view with the three states in one list
	const Read = await loadComponent('src/Read.vue')
	cases.push({
		name: 'Read.vue (three badges, votes and comments on every row)',
		component: {
			...Read,
			data() {
				return {
					...Read.data.call(this),
					articles: [
						{ path: '/a.md', title: 'A', state: 'internal', up: 1, down: 0, comments: 2 },
						{ path: '/b.md', title: 'B', state: 'internal', intent: 'online', candidate: true, up: 0, down: 0, comments: 0 },
						{ path: '/c.md', title: 'C', state: 'external', up: 3, down: 1, comments: 4 },
						{ path: '/d.md', title: 'D', state: 'external', live: true, up: 0, down: 0, comments: 0 },
					],
				}
			},
		},
		props: {},
		// 'External' is still the name of the *filter* (the state, not a row), while the rows read
		// Online for the same state
		expect: ['Internal', 'Waiting for review', 'External', 'Online', '↑ 1', '💬 2', 'All states'],
	})

	/*
	 * The site preview's header is three parts and nothing else: the address bar (input + Go), the
	 * reload button, the close button. The address field already shows the page the frame is on, so
	 * the separate read-only path display was removed; the rebuild button moved to the admin
	 * settings page (Apply source), so this view must not offer that either.
	 *
	 * The field holds the readable path (`中文.html`), while `frameUrl` carries the encoded one: the
	 * header must render the form a reader can edit, not `%E4%B8%AD%E6%96%87.html`.
	 */
	const ReadPlain = await loadComponent('src/Read.vue')
	cases.push({
		name: 'Read.vue (site preview: address field with Go, reload, close - no path display, no rebuild)',
		component: {
			...ReadPlain,
			data() {
				return {
					...ReadPlain.data.call(this),
					sitePreview: true,
					frameUrl: '/index.php/apps/pages/preview/tok-123/index.html',
					// the tracked path is encoded; the field shows the same page, readable
					sitePreviewPath: encodeURIComponent('中文.html'),
					pathInput: '中文.html',
				}
			},
		},
		props: { previewBase: '/index.php/apps/pages/preview/tok-123/' },
		expect: [
			// the editable half: a labelled text field, a Go button, and no browser autofill
			'class="pages-read__address"',
			'autocomplete="off"',
			'spellcheck="false"',
			'value="中文.html"',
			'>Go</div>',
			// reload: an icon button with its own label, then close
			'slot-icon',
			'aria-label="Refresh"',
			// the visible label (the stub wraps slotted text, so only the text itself is asserted)
			'Refresh</div>',
			'Close the preview',
			// the frame is pointed at the address the component holds, not at a fixed index.html
			'src="/index.php/apps/pages/preview/tok-123/index.html"',
		],
		// the duplicated path display, and the rebuild button the settings page owns now
		absent: ['Page in the preview', 'pages-read__path', 'Rebuild preview'],
	})

	/*
	 * The settings page's dialogs and toasts. They are the only UI the plain-DOM settings script talks
	 * to, so their rendering is the contract: the reset dialog has to carry the explanation, a password
	 * field and the confirm/cancel buttons, an error has to stay inside the dialog (a wrong password is
	 * correctable in place), and the "previous value was kept" failure is a toast.
	 */
	const SettingsUI = await loadComponent('src/SettingsUI.vue')
	cases.push({
		name: 'SettingsUI.vue (reset dialog: explanation + password field)',
		component: {
			...SettingsUI,
			data() {
				return { ...SettingsUI.data.call(this), resetOpen: true }
			},
		},
		props: {},
		expect: [
			'name="Reset Pages?"',
			'id="pages-reset-password"',
			'type="password"',
			'Nextcloud password',
			'never touched',
		],
	})

	cases.push({
		name: 'SettingsUI.vue (reset dialog: the wrong-password error stays in the dialog)',
		component: {
			...SettingsUI,
			data() {
				return {
					...SettingsUI.data.call(this),
					resetOpen: true,
					resetPassword: 'wrong',
					resetError: 'Wrong password; nothing was reset.',
				}
			},
		},
		props: {},
		expect: ['id="pages-reset-password"', 'Wrong password; nothing was reset.'],
	})

	cases.push({
		name: 'SettingsUI.vue (confirmation dialog: the secrets rotation)',
		component: {
			...SettingsUI,
			data() {
				return {
					...SettingsUI.data.call(this),
					confirmOpen: true,
					confirmName: 'Rotate secrets?',
					confirmLines: ['Replace both secrets with new ones? The bridge is updated in the same request.'],
					confirmLabel: 'Rotate',
				}
			},
		},
		props: {},
		expect: ['name="Rotate secrets?"', 'Replace both secrets with new ones?', 'Rotate'],
	})

	cases.push({
		name: 'SettingsUI.vue (a refused write-through: the revert toast)',
		component: {
			...SettingsUI,
			data() {
				return {
					...SettingsUI.data.call(this),
					toasts: [
						{ id: 1, type: 'error', message: 'The backend rejected the change (bad folder); the previous value was kept.' },
						{ id: 2, type: 'success', message: 'Applied source: 3 files updated in both trees · preview build OK' },
					],
				}
			},
		},
		props: {},
		expect: ['The backend rejected the change (bad folder); the previous value was kept.', 'Applied source: 3 files updated in both trees'],
	})

	for (const testCase of cases) {
		const app = createSSRApp(testCase.component, testCase.props ?? {})
		app.config.warnHandler = (message) => note(`${testCase.name}: Vue warning: ${message}`)
		let html
		try {
			html = await renderToString(app)
		} catch (error) {
			note(`${testCase.name}: render threw: ${error.message}`)
			continue
		}
		const missing = testCase.expect.filter((text) => !html.includes(text))
		if (missing.length) {
			note(`${testCase.name}: rendered HTML is missing ${missing.map((text) => `"${text}"`).join(', ')}`)
		}
		const wrong = (testCase.absent ?? []).filter((text) => html.includes(text))
		if (wrong.length) {
			note(`${testCase.name}: rendered HTML must not carry ${wrong.map((text) => `"${text}"`).join(', ')}`)
		}
		// an exact number of occurrences: "rendered once" is a different claim from "rendered at all"
		const miscounted = (testCase.expectCount ?? []).filter(({ text, count }) => html.split(text).length - 1 !== count)
		if (miscounted.length) {
			note(`${testCase.name}: expected ${miscounted.map(({ text, count }) => `${count}× "${text}" (found ${html.split(text).length - 1})`).join(', ')}`)
		}
		if (missing.length || wrong.length || miscounted.length) {
			continue
		}
		process.stdout.write(`  render    ${testCase.name}: ${testCase.expect.length + (testCase.absent ?? []).length + (testCase.expectCount ?? []).length} assertion(s) ok\n`)
	}
}

/**
 * One element of the rendered HTML, by the exact single class it carries: the slice from its
 * opening tag to its matching close. Every stub and every container here is a `<div>`, so counting
 * `<div`/`</div>` pairs finds the end without a DOM implementation.
 */
function elementHtml(html, className) {
	const marker = `class="${className}"`
	const found = html.indexOf(marker)
	if (found < 0) {
		return ''
	}
	const open = html.lastIndexOf('<', found)
	const tags = /<div\b|<\/div>/g
	tags.lastIndex = open
	let depth = 0
	for (let match; (match = tags.exec(html));) {
		depth += match[0] === '<div' ? 1 : -1
		if (depth === 0) {
			return html.slice(open, tags.lastIndex)
		}
	}
	return ''
}

/**
 * 3a. Where the two editor controls live.
 *
 * Save acts on the text of the open entry and the Preview toggle shows that text as the site
 * serves it, so both belong to the editor pane's header — the row directly above them — and to
 * nowhere else. The same header used to carry the entry's "Request online" action; that action
 * stays in the tree and the article list, where it is a row action, not an editor action. The
 * assertions therefore split the rendered HTML into the toolbar, the header and the tree: a
 * control that moved must be absent from the old container, not absent from the page.
 */
async function checkEditorPaneHeader() {
	const App = await loadComponent('src/App.vue')
	const app = createSSRApp({
		...App,
		data() {
			const base = App.data.call(this)
			return {
				...base,
				// `internal` is the state whose "Request online" the header used to show; with the
				// file open in the editor this is exactly where that button must not come back.
				nodes: [treeNode({ path: 'notes/internal.md', target: '/tmp/alice/notes/internal.md', name: 'internal.md', state: 'internal' })],
				currentFile: '/tmp/alice/notes/internal.md',
				targetPrefix: '/tmp/alice',
				view: 'site',
			}
		},
	}, { shell: shellState({ canEdit: true }) })
	app.config.warnHandler = (message) => note(`App.vue (editor pane header): Vue warning: ${message}`)

	let html
	try {
		html = await renderToString(app)
	} catch (error) {
		note(`App.vue (editor pane header): render threw: ${error.message}`)
		return
	}

	const problems = []
	const toolbar = elementHtml(html, 'pages-toolbar')
	const header = elementHtml(html, 'pages-pane__head')
	if (!toolbar) {
		problems.push('the toolbar is not in the render')
	}
	if (!header) {
		problems.push('the editor pane header is not in the render')
	}

	// the two controls moved out of the toolbar and into the header
	for (const label of ['Save', 'Preview']) {
		if (toolbar.includes(label)) {
			problems.push(`the toolbar still carries "${label}"`)
		}
		if (!header.includes(label)) {
			problems.push(`the editor pane header is missing "${label}"`)
		}
	}

	// the header's own "Request online" is gone, while the tree still offers it for that entry
	if (header.includes('Request online')) {
		problems.push('the editor pane header still carries "Request online"')
	}
	const tree = html.slice(0, html.indexOf('class="pages-toolbar"'))
	if (!tree.includes('Request online')) {
		problems.push('the tree/list no longer offers "Request online" (the header is not the only place it may live)')
	}

	// Back is the way out of the open entry, so it is the row's first control and comes with it.
	// The rendered button, not the word: the template's own comments are part of the SSR output.
	if (!header.includes('slot-default"> Back</div>')) {
		problems.push('the editor pane header is missing its Back button')
	}

	// both sit after the spacer that pushes them to the inline end, Save before Preview
	const positions = ['pages-pane__spacer', 'Save', 'Preview'].map((needle) => header.indexOf(needle))
	if (positions.includes(-1)) {
		problems.push('the editor pane header does not carry the spacer, Save and Preview')
	} else if (positions[1] < positions[0] || positions[2] < positions[1]) {
		problems.push('the editor pane header orders the controls wrongly (expected spacer → Save → Preview)')
	}

	// The `currentFile` guard on the editor pane's row must stay that row's own: the preview pane
	// keeps a header of its own (its address, Reload), and a file is open here, so both rows exist.
	// A guard that swallowed both would leave exactly one head marker.
	const heads = html.split('class="pages-pane__head"').length - 1
	if (heads !== 2) {
		problems.push(`expected two pane headers with a file open (editor + preview), found ${heads}`)
	}
	const previewPane = html.indexOf('pages-pane--preview')
	if (previewPane < 0) {
		problems.push('the preview pane is not in the render (its header is a row of its own)')
	} else if (!html.slice(previewPane).includes('class="pages-pane__head"')) {
		problems.push('the preview pane lost its header (the editor header guard must not reach it)')
	}

	if (problems.length) {
		for (const problem of problems) {
			note(`App.vue (editor pane header): ${problem}`)
		}
		return
	}
	process.stdout.write(`  header    App.vue: Save/Preview in the pane header (spacer → Save → Preview), neither in the toolbar, "Request online" only in the tree/list; the preview pane keeps its own header\n`)
}

/**
 * 3a′. The same header with nothing open: in the list view it must not render at all.
 *
 * `Back`, the path and every visibility action are conditioned on `currentFile`, so on "My
 * articles" the row kept only its spacer (and Save, for whoever may edit) — a padded, bordered
 * strip above the list that belongs to no part of the interface. The header's `v-if` is
 * `currentFile`, which is exactly what this checks from the rendered HTML: with `currentFile: ''`
 * and the list shown, neither the header nor its Save button is there, and the list is.
 */
async function checkEditorPaneHeaderWithoutFile() {
	const App = await loadComponent('src/App.vue')
	// `canEdit` is what makes the header's Save button render, so the bug needs this grant
	const app = createSSRApp({
		...App,
		data() {
			const base = App.data.call(this)
			return {
				...base,
				nodes: [
					treeNode({ path: 'notes/internal.md', target: '/tmp/alice/notes/internal.md', name: 'internal.md', state: 'internal' }),
					treeNode({ path: 'notes/external.md', target: '/tmp/alice/notes/external.md', name: 'external.md', state: 'external', live: true }),
				],
				currentFile: '',
				targetPrefix: '/tmp/alice',
				view: 'site',
			}
		},
	}, { shell: shellState({ canEdit: true }) })
	app.config.warnHandler = (message) => note(`App.vue (editor pane header, list view): Vue warning: ${message}`)

	let html
	try {
		html = await renderToString(app)
	} catch (error) {
		note(`App.vue (editor pane header, list view): render threw: ${error.message}`)
		return
	}

	const problems = []
	// the header is the open file's row: no file, no row (neither pane renders one here)
	if (html.includes('class="pages-pane__head"')) {
		problems.push('the editor pane header is rendered while no file is open')
	}
	// ... and with it the Save button that had nothing to act on. The rendered control, not the
	// word: the template's own comments are part of the SSR output.
	if (html.includes('slot-default"> Save</div>')) {
		problems.push('the list view renders the header\'s Save button (no file is open, nothing to save)')
	}
	// the spacer is all that was left of that row, so its absence is the empty line's absence
	if (html.includes('pages-pane__spacer')) {
		problems.push('the list view renders the header spacer (an empty, bordered row above the list)')
	}
	// what the pane shows instead of the header still has to be there
	for (const needle of ['My articles', 'pages-articles-view__head', 'internal.md', 'external.md']) {
		if (!html.includes(needle)) {
			problems.push(`the list view no longer renders "${needle}"`)
		}
	}

	if (problems.length) {
		for (const problem of problems) {
			note(`App.vue (editor pane header, list view): ${problem}`)
		}
		return
	}
	process.stdout.write(`  header    App.vue list view: no editor pane header (no blank row, no Save) while "My articles" is shown\n`)
}

/**
 * 3a″. The rejection note above the open article.
 *
 * The reviewer types a reason into the request panel, and the author's own list has no note column:
 * without this banner the author never learns why a request was rejected. It belongs to the open
 * article (no file, no banner), it comes from the row the list already built (`reviewNote`), and
 * dismissing it is session-only state - `dismissRejectNote` must hide *that* article's note and
 * leave every other row's note alone.
 */
async function checkRejectNote() {
	const App = await loadComponent('src/App.vue')
	const target = '/tmp/alice/notes/rejected.md'
	const reason = 'The layout breaks on narrow screens.'
	const rejected = (overrides = {}) => treeNode({
		path: 'notes/rejected.md',
		target,
		name: 'rejected.md',
		state: 'internal',
		reviewNote: reason,
		...overrides,
	})
	const render = async (overrides = {}) => {
		const app = createSSRApp({
			...App,
			data() {
				const base = App.data.call(this)
				return {
					...base,
					nodes: [rejected()],
					currentFile: target,
					targetPrefix: '/tmp/alice',
					view: 'site',
					...overrides,
				}
			},
		}, { shell: shellState() })
		app.config.warnHandler = (message) => note(`App.vue (rejection note): Vue warning: ${message}`)
		return renderToString(app)
	}

	const problems = []
	let html
	try {
		html = await render()
	} catch (error) {
		note(`App.vue (rejection note): render threw: ${error.message}`)
		return
	}

	// 1. an open article whose row carries a note shows it, with the way to dismiss it. The sentence
	// names no direction: the manifest keeps the reason, not the kind of request it answered.
	if (!html.includes(`The last request about this page was rejected: ${reason}`)) {
		problems.push('the open article does not show its rejection note')
	}
	if (!html.includes('aria-label="Dismiss"')) {
		problems.push('the rejection note has no Dismiss control')
	}

	// 2. no note on the row: nothing to show
	let empty
	let closed
	try {
		empty = await render({ nodes: [rejected({ reviewNote: '' })] })
		closed = await render({ currentFile: '' })
	} catch (error) {
		note(`App.vue (rejection note): render threw: ${error.message}`)
		return
	}
	if (empty.includes('pages-reject-note')) {
		problems.push('a row without a note still renders the banner')
	}
	if (closed.includes('pages-reject-note')) {
		problems.push('the banner renders while no file is open')
	}

	/*
	 * 3. the dismissal. The method only touches browser state, so it is exercised on the component
	 * itself: the computed then hides this article's note, while another row keeps its own.
	 */
	const first = { path: target, reviewNote: reason }
	const second = { path: '/tmp/alice/notes/other.md', reviewNote: 'Another reason.' }
	const state = {
		...App.methods,
		currentFile: target,
		articleRows: [first, second],
		dismissedRejectNotes: [],
	}
	if (App.computed.rejectionNote.call(state) !== reason) {
		problems.push('rejectionNote does not read the note of the open row')
	}
	App.methods.dismissRejectNote.call(state)
	if (App.computed.rejectionNote.call(state) !== '') {
		problems.push('dismissRejectNote does not hide the note of the open article')
	}
	if (!state.dismissedRejectNotes.includes(target)) {
		problems.push('dismissRejectNote does not remember the dismissed target')
	}
	const other = { ...state, currentFile: '/tmp/alice/notes/other.md' }
	if (App.computed.rejectionNote.call(other) !== 'Another reason.') {
		problems.push('dismissing one note hides another article\'s note too')
	}
	let dismissed
	try {
		dismissed = await render({ dismissedRejectNotes: [target] })
	} catch (error) {
		note(`App.vue (rejection note): render threw: ${error.message}`)
		return
	}
	if (dismissed.includes('pages-reject-note')) {
		problems.push('a note dismissed in this session renders again')
	}

	if (problems.length) {
		for (const problem of problems) {
			note(`App.vue (rejection note): ${problem}`)
		}
		return
	}
	process.stdout.write('  rejection App.vue: note shown for the open article, hidden without one, dismissal is per file and session-only\n')
}

/**
 * 3b. The site preview's address line, exercised on the component itself (SSR cannot fire `@load`):
 * the preview token segment is stripped, the tracked path stays encoded while the field follows it
 * in readable form, an unreadable frame keeps the last path, and closing resets to index.html.
 */
async function checkReadPathTracking() {
	const Read = await loadComponent('src/Read.vue')
	const withFrame = (pathname) => ({
		$refs: { siteFrame: { contentWindow: { location: { pathname } } } },
	})

	const tracked = { ...Read.methods, ...withFrame('/index.php/apps/pages/preview/tok-123/blog/post.html'), sitePreviewPath: 'index.html', pathInput: 'index.html' }
	Read.methods.onSiteFrameLoad.call(tracked)
	if (tracked.sitePreviewPath !== 'blog/post.html') {
		note(`src/Read.vue: the preview path was not stripped to the site-relative path (got "${tracked.sitePreviewPath}")`)
	}
	// the address field follows the frame: a link click must be visible where an address is typed
	if (tracked.pathInput !== 'blog/post.html') {
		note(`src/Read.vue: the address field did not follow the frame (got "${tracked.pathInput}")`)
	}

	/*
	 * A page named in Chinese. The frame's pathname is percent-encoded (that is what the browser
	 * loaded), so the tracked path stays encoded — it is spelled into URLs — while the field gets
	 * the characters the reader can actually read and edit.
	 */
	const chinese = {
		...Read.methods,
		...withFrame('/preview/tok-123/%E4%B8%AD%E6%96%87.html'),
		sitePreviewPath: 'index.html',
		pathInput: 'index.html',
	}
	Read.methods.onSiteFrameLoad.call(chinese)
	if (chinese.sitePreviewPath !== '%E4%B8%AD%E6%96%87.html') {
		note(`src/Read.vue: a Chinese page must stay encoded in the tracked path (got "${chinese.sitePreviewPath}")`)
	}
	if (chinese.pathInput !== '中文.html') {
		note(`src/Read.vue: a Chinese page must show decoded in the address field (got "${chinese.pathInput}")`)
	}

	const root = { ...Read.methods, ...withFrame('/index.php/apps/pages/preview/tok-123/'), sitePreviewPath: 'blog/post.html', pathInput: 'blog/post.html' }
	Read.methods.onSiteFrameLoad.call(root)
	if (root.sitePreviewPath !== 'index.html') {
		note(`src/Read.vue: the site root should show as index.html (got "${root.sitePreviewPath}")`)
	}
	if (root.pathInput !== 'index.html') {
		note(`src/Read.vue: the site root should fill the address field with index.html (got "${root.pathInput}")`)
	}

	// a page path that itself starts with `preview` must not be mistaken for the mount
	const nested = { ...Read.methods, ...withFrame('/index.php/apps/pages/preview/tok-123/preview/2026/index.html'), sitePreviewPath: 'index.html', pathInput: 'index.html' }
	Read.methods.onSiteFrameLoad.call(nested)
	if (nested.sitePreviewPath !== 'preview/2026/index.html') {
		note(`src/Read.vue: a page under a "preview" folder was over-stripped (got "${nested.sitePreviewPath}")`)
	}

	// a frame the view may not inspect (cross-origin / sandbox refusal) must keep the last path
	const unreadable = {}
	Object.defineProperty(unreadable, 'location', {
		get() {
			throw new Error('SecurityError: the frame location is not readable')
		},
	})
	const kept = { ...Read.methods, $refs: { siteFrame: { contentWindow: unreadable } }, sitePreviewPath: 'blog/post.html', pathInput: 'blog/post.html' }
	// the component reports the refusal on the console; the proof's own output stays readable
	const debug = console.debug
	console.debug = () => {}
	try {
		Read.methods.onSiteFrameLoad.call(kept)
	} finally {
		console.debug = debug
	}
	if (kept.sitePreviewPath !== 'blog/post.html') {
		note(`src/Read.vue: an unreadable frame changed the path to "${kept.sitePreviewPath}"`)
	}
	if (kept.pathInput !== 'blog/post.html') {
		note(`src/Read.vue: an unreadable frame changed the address field to "${kept.pathInput}"`)
	}

	const closed = { sitePreview: true, sitePreviewPath: 'blog/post.html', pathInput: 'blog/post.html', pathError: 'stale' }
	Read.methods.closeSitePreview.call(closed)
	if (closed.sitePreview !== false || closed.sitePreviewPath !== 'index.html' || closed.pathInput !== 'index.html') {
		note(`src/Read.vue: closing the preview must hide it and reset the path (got sitePreview=${closed.sitePreview}, path="${closed.sitePreviewPath}", input="${closed.pathInput}")`)
	}
	if (closed.pathError !== '') {
		note('src/Read.vue: closing the preview must clear the address error')
	}

	// opening points the frame at the front page through the current token
	const BASE = '/index.php/apps/pages/preview/tok-123/'
	const opened = { previewBase: BASE, sitePreview: false, sitePreviewPath: 'blog/post.html', pathInput: 'blog/post.html', pathError: 'stale', frameUrl: '', selected: { path: '/a.md' } }
	Read.methods.openSitePreview.call(opened)
	if (!opened.sitePreview || opened.sitePreviewPath !== 'index.html' || opened.pathInput !== 'index.html' || opened.pathError !== '' || opened.selected !== null) {
		note(`src/Read.vue: opening the preview must reset it (sitePreview=${opened.sitePreview}, path="${opened.sitePreviewPath}", input="${opened.pathInput}", error="${opened.pathError}", selected=${opened.selected})`)
	}
	if (opened.frameUrl !== BASE + 'index.html') {
		note(`src/Read.vue: opening the preview must point the frame at index.html (got "${opened.frameUrl}")`)
	}
	process.stdout.write('  path      Read.vue site preview: strip token, decode for the field, keep on refusal, reset on close\n')
}

/**
 * 3b2. The address field (SSR cannot type): normalisation, the refusal of anything that is not a
 *      page inside the site, the readable↔encoded boundary, the reload key, and the token rotation
 *      that keeps the page the reader is on.
 */
async function checkReadAddress() {
	const Read = await loadComponent('src/Read.vue')
	const BASE = '/index.php/apps/pages/preview/tok-123/'
	const state = (overrides = {}) => ({
		...Read.methods,
		previewBase: BASE,
		sitePreview: true,
		sitePreviewPath: 'index.html',
		pathInput: 'index.html',
		pathError: '',
		frameUrl: BASE + 'index.html',
		...overrides,
	})

	// what the reader types → the site-relative path the frame is pointed at
	const accepted = [
		['/about.html', 'about.html'],
		['  2026/05/x.html  ', '2026/05/x.html'],
		['docs/', 'docs/index.html'],
		['/', 'index.html'],
	]
	for (const [typed, expected] of accepted) {
		const subject = state({ pathInput: typed })
		const ok = Read.methods.goToPath.call(subject)
		if (ok !== true || subject.frameUrl !== BASE + expected || subject.sitePreviewPath !== expected || subject.pathInput !== expected || subject.pathError !== '') {
			note(`src/Read.vue: "${typed}" should load "${expected}" (accepted=${ok}, frameUrl="${subject.frameUrl}", path="${subject.sitePreviewPath}", input="${subject.pathInput}", error="${subject.pathError}")`)
		}
	}

	// a full URL or an escape out of the mount is refused inline, and the frame does not move
	for (const typed of ['https://example.com/x', '../etc/passwd', '2026/../x.html', '   ']) {
		const subject = state({ pathInput: typed })
		const before = subject.frameUrl
		const ok = Read.methods.goToPath.call(subject)
		if (ok !== false || subject.pathError === '' || subject.frameUrl !== before) {
			note(`src/Read.vue: "${typed}" must be refused inline without moving the frame (accepted=${ok}, error="${subject.pathError}", frameUrl="${subject.frameUrl}")`)
		}
	}

	// an error never sticks: the next address that is accepted clears it and syncs both fields
	const recovered = state({ pathInput: 'about.html', pathError: 'stale' })
	Read.methods.goToPath.call(recovered)
	if (recovered.pathError !== '' || recovered.pathInput !== 'about.html' || recovered.sitePreviewPath !== 'about.html') {
		note(`src/Read.vue: an accepted address must clear the error and sync the fields (error="${recovered.pathError}", input="${recovered.pathInput}", path="${recovered.sitePreviewPath}")`)
	}

	/*
	 * The field is readable text, the URL is encoded. A Chinese name with a space: the reader keeps
	 * seeing `中文 页面.html`, the frame is pointed at the encoded form of exactly that path.
	 * `encodeURIComponent` computes the expected URL, so the proof does not spell out an escape
	 * sequence of its own.
	 */
	const chineseEncoded = encodeURIComponent('中文 页面.html')
	const chinese = state({ pathInput: '中文 页面.html' })
	const chineseOk = Read.methods.goToPath.call(chinese)
	if (chineseOk !== true || chinese.frameUrl !== BASE + chineseEncoded || chinese.sitePreviewPath !== chineseEncoded || chinese.pathInput !== '中文 页面.html' || chinese.pathError !== '') {
		note(`src/Read.vue: a Chinese address must load its encoded path and stay readable in the field (accepted=${chineseOk}, frameUrl="${chinese.frameUrl}", path="${chinese.sitePreviewPath}", input="${chinese.pathInput}", error="${chinese.pathError}")`)
	}
	if (chinese.sitePreviewPath === chinese.pathInput) {
		note('src/Read.vue: the tracked path must be the encoded form, the field the readable one')
	}

	// a pasted encoded address is decoded into the field and encoded again for the URL
	const pasted = state({ pathInput: '%E4%B8%AD%E6%96%87.html' })
	Read.methods.goToPath.call(pasted)
	if (pasted.pathInput !== '中文.html' || pasted.sitePreviewPath !== '%E4%B8%AD%E6%96%87.html' || pasted.frameUrl !== BASE + '%E4%B8%AD%E6%96%87.html') {
		note(`src/Read.vue: a pasted encoded address must be decoded for the field and re-encoded once (input="${pasted.pathInput}", path="${pasted.sitePreviewPath}", frameUrl="${pasted.frameUrl}")`)
	}

	// the two halves of the encoding boundary on their own: decoding never throws, and encoding is
	// per segment (the `/` separators stay, the query and the anchor are not touched)
	if (Read.methods.readablePath('%E4%B8%AD%E6%96%87.html') !== '中文.html') {
		note(`src/Read.vue: readablePath must decode the path (got "${Read.methods.readablePath('%E4%B8%AD%E6%96%87.html')}")`)
	}
	if (Read.methods.readablePath('100%.html') !== '100%.html') {
		note(`src/Read.vue: readablePath must not throw on an invalid escape (got "${Read.methods.readablePath('100%.html')}")`)
	}
	if (Read.methods.urlPath('docs/中文 页面.html') !== 'docs/' + encodeURIComponent('中文 页面.html')) {
		note(`src/Read.vue: urlPath must keep the "/" separators while encoding each segment (got "${Read.methods.urlPath('docs/中文 页面.html')}")`)
	}
	if (Read.methods.urlPath('中文.html?q=1#top') !== encodeURIComponent('中文.html') + '?q=1#top') {
		note(`src/Read.vue: urlPath must leave the query and the anchor alone (got "${Read.methods.urlPath('中文.html?q=1#top')}")`)
	}
	if (Read.methods.urlPath('100%.html') !== '100%25.html') {
		note(`src/Read.vue: urlPath must encode a literal "%" (got "${Read.methods.urlPath('100%.html')}")`)
	}

	/*
	 * Reload: the key on the iframe changes, so the browser requests the same address again; the
	 * address, the tracked path and the inline error are not rewritten by a reload. An empty frame
	 * has nothing to request, so the key must not move.
	 */
	const refreshed = state({ pathInput: '中文 页面.html', frameReloadKey: 0 })
	Read.methods.goToPath.call(refreshed)
	const beforeReload = {
		frameUrl: refreshed.frameUrl,
		pathInput: refreshed.pathInput,
		sitePreviewPath: refreshed.sitePreviewPath,
		pathError: refreshed.pathError,
	}
	Read.methods.reloadSitePreview.call(refreshed)
	if (refreshed.frameReloadKey !== 1 || refreshed.frameUrl !== beforeReload.frameUrl || refreshed.pathInput !== beforeReload.pathInput || refreshed.sitePreviewPath !== beforeReload.sitePreviewPath || refreshed.pathError !== beforeReload.pathError) {
		note(`src/Read.vue: a reload must bump frameReloadKey only (key=${refreshed.frameReloadKey}, frameUrl="${refreshed.frameUrl}", input="${refreshed.pathInput}", path="${refreshed.sitePreviewPath}", error="${refreshed.pathError}")`)
	}
	Read.methods.reloadSitePreview.call(refreshed)
	if (refreshed.frameReloadKey !== 2) {
		note(`src/Read.vue: every reload must rebuild the frame again (got key=${refreshed.frameReloadKey})`)
	}
	const nothingToReload = state({ frameUrl: '', frameReloadKey: 0 })
	Read.methods.reloadSitePreview.call(nothingToReload)
	if (nothingToReload.frameReloadKey !== 0) {
		note(`src/Read.vue: an empty frame must not be "reloaded" (got key=${nothingToReload.frameReloadKey})`)
	}

	// A rotated token re-points the frame at the page the reader is on, not the page it opened with:
	// index.html was opened, a link inside the frame landed on about.html, the rotation must keep
	// about.html. `sitePreviewPath` already tracks that landing (onSiteFrameLoad), `frameUrl` does not.
	const rotated = state({ pathInput: 'index.html' })
	Read.methods.goToPath.call(rotated)
	rotated.sitePreviewPath = 'about.html'
	Read.methods.followBase.call(rotated, '/index.php/apps/pages/preview/tok-999/', BASE)
	if (rotated.frameUrl !== '/index.php/apps/pages/preview/tok-999/about.html') {
		note(`src/Read.vue: a rotated token must keep the page the reader is on (got "${rotated.frameUrl}")`)
	}
	if (rotated.sitePreviewPath !== 'about.html') {
		note(`src/Read.vue: a rotated token must not rewrite the current path (got "${rotated.sitePreviewPath}")`)
	}
	if (rotated.pathInput !== 'about.html') {
		note(`src/Read.vue: a rotated token must show the current page in the field (got "${rotated.pathInput}")`)
	}
	// the watcher itself delegates to the rule (a wrong method name there would not show otherwise)
	const watched = state({ sitePreviewPath: 'about.html', pathInput: 'about.html', frameUrl: BASE + 'about.html' })
	Read.watch.previewBase.call(watched, '/index.php/apps/pages/preview/tok-999/', BASE)
	if (watched.frameUrl !== '/index.php/apps/pages/preview/tok-999/about.html') {
		note(`src/Read.vue: watch.previewBase does not delegate to followBase (got "${watched.frameUrl}")`)
	}

	// a directory address is normalised on the way in, so the rotation keeps docs/index.html
	const directory = state({ pathInput: 'docs/' })
	Read.methods.goToPath.call(directory)
	Read.methods.followBase.call(directory, '/index.php/apps/pages/preview/tok-999/', BASE)
	if (directory.frameUrl !== '/index.php/apps/pages/preview/tok-999/docs/index.html') {
		note(`src/Read.vue: a rotated token must keep a directory address's page (got "${directory.frameUrl}")`)
	}

	// with no tracked path yet, the old frame URL is the fallback...
	const fallback = state({ sitePreviewPath: '', frameUrl: BASE + 'x.html' })
	Read.methods.followBase.call(fallback, '/index.php/apps/pages/preview/tok-999/', BASE)
	if (fallback.frameUrl !== '/index.php/apps/pages/preview/tok-999/x.html') {
		note(`src/Read.vue: a rotated token without a tracked path must fall back to the frame URL (got "${fallback.frameUrl}")`)
	}

	// ...and a fallback taken from the old URL is still encoded: the field gets the readable form
	const fallbackChinese = state({ sitePreviewPath: '', frameUrl: BASE + encodeURIComponent('中文.html') })
	Read.methods.followBase.call(fallbackChinese, '/index.php/apps/pages/preview/tok-999/', BASE)
	if (fallbackChinese.frameUrl !== '/index.php/apps/pages/preview/tok-999/' + encodeURIComponent('中文.html')) {
		note(`src/Read.vue: a rotated token must keep a Chinese page encoded in the address (got "${fallbackChinese.frameUrl}")`)
	}
	if (fallbackChinese.pathInput !== '中文.html') {
		note(`src/Read.vue: a rotated token must show a Chinese page readable in the field (got "${fallbackChinese.pathInput}")`)
	}

	// while the preview is closed there is no frame to re-point
	const dormant = state({ sitePreview: false })
	Read.methods.followBase.call(dormant, '/index.php/apps/pages/preview/tok-999/', BASE)
	if (dormant.frameUrl !== BASE + 'index.html') {
		note(`src/Read.vue: a rotated token moved a closed preview (got "${dormant.frameUrl}")`)
	}

	// ... and an empty frame has nothing to re-point either
	const emptyFrame = state({ frameUrl: '' })
	Read.methods.followBase.call(emptyFrame, '/index.php/apps/pages/preview/tok-999/', BASE)
	if (emptyFrame.frameUrl !== '') {
		note(`src/Read.vue: a rotated token pointed an empty frame somewhere (got "${emptyFrame.frameUrl}")`)
	}

	// a vanished token leaves no address at all
	const withoutToken = state({ frameUrl: BASE + 'about.html', sitePreviewPath: 'about.html' })
	Read.methods.followBase.call(withoutToken, '', BASE)
	if (withoutToken.frameUrl !== '') {
		note(`src/Read.vue: a missing token must leave the frame without an address (got "${withoutToken.frameUrl}")`)
	}
	process.stdout.write('  address   Read.vue site preview: normalise, refuse, clear, encode for the URL, reload, follow the current page\n')
}

/**
 * 3c. The read view no longer rebuilds anything: the action moved to the admin settings page, so the
 *     button, its `canRebuild` prop and the client function must all be gone - a leftover of either
 *     would be a second, ungated way to run a build.
 */
function checkReadRebuildRemoved() {
	const app = readFileSync(resolve(root, 'src/App.vue'), 'utf8')
	if (app.includes('can-rebuild') || app.includes('canRebuild')) {
		note('src/App.vue: the Read view is still given a canRebuild grant (the settings page owns Apply source)')
	}
	const read = readFileSync(resolve(root, 'src/Read.vue'), 'utf8')
	for (const dead of ['canRebuild', 'Rebuild preview', 'rebuildPreview', 'rebuilding']) {
		if (read.includes(dead)) {
			note(`src/Read.vue: the removed rebuild control is still referenced (${dead})`)
		}
	}
	const api = readFileSync(resolve(root, 'src/api.js'), 'utf8')
	if (/\bbuild:\s*\(/.test(api)) {
		note('src/api.js: the removed build() client function is still there')
	}
	process.stdout.write('  removed   Read.vue rebuild button, canRebuild prop and api.build()\n')
}

/**
 * 3d0. The two dialogs' buttons: the labels live in computed arrays that the NcDialog stub cannot
 *      render as props, so they are checked on the computed values themselves.
 */
async function checkDialogButtons() {
	const SettingsUI = await loadComponent('src/SettingsUI.vue')
	const resetLabels = SettingsUI.computed.resetButtons.call({}).map((button) => button.label)
	const confirmLabels = SettingsUI.computed.confirmButtons.call({ confirmLabel: 'Rotate' }).map((button) => button.label)
	if (!resetLabels.includes('Reset Pages') || !resetLabels.includes('Cancel')) {
		note(`src/SettingsUI.vue: the reset dialog's buttons are wrong (${resetLabels.join(', ')})`)
	}
	if (!confirmLabels.includes('Rotate') || !confirmLabels.includes('Cancel')) {
		note(`src/SettingsUI.vue: the confirmation dialog's buttons are wrong (${confirmLabels.join(', ')})`)
	}
	process.stdout.write('  dialogs   SettingsUI.vue: Cancel / Reset Pages, Cancel / Rotate\n')
}

/**
 * 3d1. The state badge is one line, always. A wrapped label doubled its row's height and broke the
 *      table's rhythm, so the fix is `white-space: nowrap` plus an ellipsis for an extreme width. The
 *      State column must keep its `minmax(0, …fr)` track: the header and each row are separate grids,
 *      and a `min-content` track would size itself from its own content and move the columns apart.
 */
function checkBadgeLayout() {
	const rows = readFileSync(resolve(root, 'src/ArticleRows.vue'), 'utf8')
	const rule = /\.pages-articles__badge\s*\{([^}]*)\}/.exec(rows)
	if (!rule) {
		note('src/ArticleRows.vue: .pages-articles__badge is gone (the state badge lost its styling)')
	} else {
		const body = rule[1]
		if (!/white-space:\s*nowrap/.test(body)) {
			note('src/ArticleRows.vue: .pages-articles__badge must not wrap (white-space: nowrap) - a two-line label doubles its row height')
		}
		if (!/overflow:\s*hidden/.test(body) || !/text-overflow:\s*ellipsis/.test(body)) {
			note('src/ArticleRows.vue: a badge narrower than its label must ellipsize (overflow: hidden; text-overflow: ellipsis)')
		}
	}
	if (!/state:\s*'minmax\(0, [\d.]+fr\)'/.test(rows)) {
		note('src/ArticleRows.vue: the state column must stay a minmax(0, …fr) track (the header and the rows are separate grids)')
	}
	process.stdout.write('  badge     ArticleRows.vue: State column is single-line (nowrap + ellipsis), width by ratio\n')
}

/**
 * 3d1b. The four labels of the folder model, checked on the helper itself (the tables' render cases
 *       prove the same strings reach the badge).
 *
 * Since `pending/` and `online/` are the truth, the state alone no longer says what waits:
 *
 *   candidate           → "Waiting for review" (a first publication or an update waits)
 *   state 'requested'   → "Take-off requested" (only a downRequest produces this state now)
 *   published           → "Online"
 *   otherwise           → "Internal"
 *
 * The regression this step fixes: a candidate whose stale answer still says `requested` must read
 * as waiting for review, never as a take-off request. The filter labels stay the three states, with
 * `requested` naming the take-off.
 */
async function checkEntryStateLabels() {
	const { pathToFileURL } = await import('node:url')
	const articles = await import(pathToFileURL(resolve(root, 'src/articles.js')).href)
	const cases = [
		['internal', articles.entryStateLabel('internal', {}), 'Internal'],
		['internal, candidate', articles.entryStateLabel('internal', { candidate: true }), 'Waiting for review'],
		['external, candidate', articles.entryStateLabel('external', { candidate: true, published: true }), 'Waiting for review'],
		['requested, candidate (stale answer)', articles.entryStateLabel('requested', { candidate: true }), 'Waiting for review'],
		['requested, downRequest', articles.entryStateLabel('requested', { downRequest: true }), 'Take-off requested'],
		['external, published', articles.entryStateLabel('external', { published: true }), 'Online'],
		['internal, published', articles.entryStateLabel('internal', { published: true }), 'Online'],
		['external, state only', articles.entryStateLabel('external'), 'Online'],
		['external, retired marks', articles.entryStateLabel('external', { awaitingReview: true, revisionRequested: true }), 'Online'],
	]
	const wrong = cases.filter(([, got, want]) => got !== want)
	for (const [name, got, want] of wrong) {
		note(`src/articles.js: entryStateLabel(${name}) is "${got}", expected "${want}"`)
	}
	// the filter's `requested` names the take-off; the three states themselves do not change
	if (articles.stateLabel('requested') !== 'Take-off requested') {
		note(`src/articles.js: stateLabel('requested') is "${articles.stateLabel('requested')}", expected "Take-off requested"`)
	}
	if (articles.stateLabel('external') !== 'External' || articles.stateLabel('internal') !== 'Internal') {
		note('src/articles.js: stateLabel() no longer keeps the three filter labels')
	}

	// the request panel merges two lists; the helper keeps the first row of a duplicate and drops
	// a row no endpoint could address
	const merged = articles.mergeRows(
		[
			{ path: '/tmp/alice/a.md', candidate: true },
			{ path: '/tmp/alice/b.md', downRequest: true },
		],
		[
			{ path: '/tmp/alice/b.md', downRequest: true, stale: true },
			{ path: '/tmp/alice/c.md', downRequest: true },
			{ path: '' },
		],
	)
	const paths = merged.map((row) => articles.rowKey(row))
	if (paths.join('|') !== '/tmp/alice/a.md|/tmp/alice/b.md|/tmp/alice/c.md') {
		note(`src/articles.js: mergeRows() returned ${JSON.stringify(paths)}`)
	}
	if (merged[1]?.stale !== undefined) {
		note('src/articles.js: mergeRows() let the second list replace the first row of a duplicate')
	}

	// the panel asks for both answer shapes of the list endpoint and merges them, instead of relying
	// on one filter that would carry only candidates (or only take-offs)
	const requests = readFileSync(resolve(root, 'src/Requests.vue'), 'utf8')
	for (const needle of ['api.awaitingArticles()', 'api.visibilityRequests()', 'mergeRows(']) {
		if (!requests.includes(needle)) {
			note(`src/Requests.vue: the request panel does not use ${needle}`)
		}
	}
	process.stdout.write(`  label     articles.js entryStateLabel: ${cases.length - wrong.length}/${cases.length} four-state case(s), filter label, mergeRows dedupe, Requests.vue asks both\n`)
}

/**
 * 3d1c. The request panel's two sources, called for real.
 *
 * `?awaiting=1` answers candidates and `?state=requested` answers take-offs, so the panel has to ask
 * both and merge them. This drives `refresh()` against the axios stub, which answers by request here:
 * a panel that calls one endpoint (or overwrites one list with the other) fails.
 */
async function checkRequestsRefresh() {
	const Requests = await loadComponent('src/Requests.vue')
	const candidate = { path: '/tmp/alice/a.md', title: 'A', state: 'internal', candidate: true, candidateAt: 1700000000 }
	const takeOff = { path: '/tmp/alice/b.md', title: 'B', state: 'requested', downRequest: true, sourceDeleted: true, submittedAt: 1700000001 }
	axiosAnswer = ({ body }) => {
		const params = body && typeof body === 'object' ? (body.params ?? {}) : {}
		return params.awaiting
			? { articles: [candidate], counts: { external: 4 } }
			: { articles: [takeOff, candidate], counts: { external: 4 } }
	}
	axiosCalls.length = 0
	const subject = {
		...Requests.methods,
		articles: [],
		loading: false,
		approvedCount: null,
		error: '',
		rejecting: new Set(),
		notes: {},
		previewing: null,
	}
	const problems = []
	try {
		await Requests.methods.refresh.call(subject)
	} catch (error) {
		problems.push(`refresh() threw: ${error.message}`)
	} finally {
		axiosAnswer = {}
	}
	const paths = subject.articles.map((row) => row.path)
	if (paths.join('|') !== '/tmp/alice/a.md|/tmp/alice/b.md') {
		problems.push(`the merged list is ${JSON.stringify(paths)}, expected the candidate once and the take-off once`)
	}
	const asked = axiosCalls.map((call) => JSON.stringify(call.body?.params ?? {}))
	if (!asked.some((params) => params.includes('"awaiting":true'))) {
		problems.push(`refresh() did not ask for the candidates (${asked.join(' · ')})`)
	}
	if (!asked.some((params) => params.includes('"state":"requested"'))) {
		problems.push(`refresh() did not ask for the take-off requests (${asked.join(' · ')})`)
	}
	if (subject.approvedCount !== 4) {
		problems.push(`the approved-for-the-site count is ${subject.approvedCount}, expected 4`)
	}
	if (problems.length) {
		note(`src/Requests.vue: ${problems.join('; ')}`)
		return
	}
	process.stdout.write(`  requests  Requests.vue refresh(): candidates + take-offs in parallel, merged once (${paths.length} row(s)), count 4\n`)
}

/**
 * 3d2. Every row of the shared table renders exactly as many cells as the grid has columns.
 *
 * The header and each row are *separate* CSS grids that share one `grid-template-columns`, so a row
 * that renders one cell fewer does not leave a hole: every later cell shifts one column left. That is
 * exactly what happened when a cell was conditional on the *row* (`v-if="row.state !== 'external'"`):
 * on an `external` row the Date rendered under the next heading and the actions under "Date". Cell
 * presence is therefore a column-level decision (a `show-*` prop, plus `grouped` for the state
 * column), and the row-level rule belongs *inside* the cell.
 *
 * Counting the cells of each rendered row is the assertion that catches it: rows that disagree with
 * each other - or with the template the header is built from - are the bug, whatever prop combination
 * (My articles, the request panel with its author/waiting/review-note columns) produced them.
 */
async function checkRowCellAlignment() {
	const Rows = await loadComponent('src/ArticleRows.vue')
	const tables = [
		{
			// "My articles": title, state, date, actions = 4 columns
			name: 'ArticleRows.vue (My articles)',
			props: {
				rows: [
					{ path: '/a.md', title: 'A', state: 'internal', live: false },
					{ path: '/b.md', title: 'B', state: 'external', live: true },
					{ path: '/c.md', title: 'C', state: 'external', live: false },
					{ path: '/d.md', title: 'D', state: 'requested', intent: 'online', submittedAt: 1700000000, live: true },
				],
			},
		},
		{
			// the request panel: title, author, state, date, waiting, review note, actions = 7
			name: 'ArticleRows.vue (request panel)',
			props: {
				rows: [
					{ path: '/a.md', title: 'A', author: 'alice', state: 'requested', intent: 'online', submittedAt: 1700000000, live: false, reviewNote: '' },
					{ path: '/b.md', title: 'B', author: 'bob', state: 'external', live: true, reviewNote: 'not yet' },
					{ path: '/c.md', title: 'C', author: 'carol', state: 'internal', live: false, reviewNote: '' },
				],
				showAuthor: true,
				showWaiting: true,
				showReviewNote: true,
				waitingHeader: 'Requested',
			},
		},
	]
	const problems = []
	for (const table of tables) {
		const app = createSSRApp(Rows, table.props)
		app.config.warnHandler = (message) => note(`${table.name}: Vue warning: ${message}`)
		let html
		try {
			html = await renderToString(app)
		} catch (error) {
			note(`${table.name}: render threw: ${error.message}`)
			continue
		}
		// the column count is the one the shared grid template declares (`minmax(0, …fr)` per column)
		const template = /grid-template-columns:([^";]*)/.exec(html)
		const columns = template ? template[1].split('minmax(').length - 1 : 0
		if (columns === 0) {
			note(`${table.name}: the table carries no grid-template-columns (the header and the rows cannot line up)`)
			continue
		}
		// one chunk per row: from its opening tag to the next row's, or to the end of the table
		const starts = [...html.matchAll(/<div class="pages-articles__row"/g)].map((match) => match.index)
		if (starts.length !== table.props.rows.length) {
			note(`${table.name}: ${starts.length} row(s) rendered for ${table.props.rows.length} row(s) given`)
			continue
		}
		// `pages-articles__cell` is followed by a space or the closing quote in a cell's class list,
		// while a modifier (`pages-articles__cell--tight`) is the same cell, not a second one
		const counts = starts.map((start, index) => {
			const chunk = html.slice(start, starts[index + 1] ?? html.length)
			return (chunk.match(/<div class="pages-articles__cell(?![-\w])/g) ?? []).length
		})
		const misaligned = counts.filter((count) => count !== columns).length
		if (misaligned) {
			note(`${table.name}: the grid has ${columns} column(s) but its rows render ${counts.join(', ')} cell(s) - a row with a cell fewer shifts every later cell one column left`)
		}
		problems.push(...counts.filter((count) => count !== columns))
		// the header is a grid of its own: one name per track, or it starts left of its own rows
		const head = html.slice(html.indexOf('<div class="pages-articles__head"'), starts[0])
		const headCells = (head.match(/<span[ >]/g) ?? []).length
		if (headCells !== columns) {
			note(`${table.name}: the header renders ${headCells} column name(s) for a ${columns}-track grid - the header and its rows would not share the same columns`)
			problems.push(headCells)
		}
		process.stdout.write(`  cells     ${table.name}: header ${headCells}/${columns}, ${counts.length} row(s) rendered ${counts.join('/')} cell(s)\n`)
	}
	// the check is about *equality* between rows; it must also have seen a row of each state
	const multiState = tables.every((table) => table.props.rows.some((row) => row.state === 'external') && table.props.rows.some((row) => row.state !== 'external'))
	if (!multiState) {
		note('ArticleRows.vue: the alignment check no longer covers a table holding both an external and a non-external row')
	}
	if (problems.length === 0) {
		process.stdout.write('  cells     every row agrees with the grid template (no row-level cell conditions)\n')
	}
}

/**
 * 3d. The write-through and auto-save rules, unit-checked on src/settings.js.
 *
 * These are the decisions the plain-DOM script makes on every save (what a failure means, how many
 * files an apply touched, which stage of a build answer failed); asserting them here keeps the rules
 * out of a file a test cannot import.
 */
async function checkSettingsLogic() {
	const { pathToFileURL } = await import('node:url')
	const module = await import(pathToFileURL(resolve(root, 'src/settings.js')).href)

	if (module.AUTO_SAVE_DELAY !== 800) {
		note(`src/settings.js: the URL debounce is ${module.AUTO_SAVE_DELAY} ms, the design says 800`)
	}
	const unreachable = [
		module.isUnreachable({ response: { status: 503 } }),
		module.isUnreachable({ response: { status: 502 } }),
		module.isUnreachable({ message: 'Network Error' }),
		module.isUnreachable(undefined),
	]
	if (!unreachable.every(Boolean)) {
		note(`src/settings.js: an unreachable failure is not recognised as such (${unreachable.join(', ')})`)
	}
	if (module.isUnreachable({ response: { status: 400 } }) || module.isUnreachable({ response: { status: 500 } })) {
		note('src/settings.js: a refused change (400/500) is misread as "unreachable"')
	}
	if (module.failureMessage({ response: { data: { message: 'bad folder' } } }, 'fallback') !== 'bad folder') {
		note('src/settings.js: the server message is not preferred over the fallback')
	}

	// Apply source reports three rebuilt trees; the answer's `files` block is the only source of
	// those counts (the mirror's copied/retimed/deleted counters are gone)
	const counts = module.sourceCounts({ work: 11, preview: 22, onlinepreview: 33 })
	if (counts.work !== 11 || counts.preview !== 22 || counts.onlinepreview !== 33) {
		note(`src/settings.js: sourceCounts() returns ${JSON.stringify(counts)} instead of work 11/preview 22/onlinepreview 33`)
	}
	const noFiles = module.sourceCounts(null)
	if (noFiles.work !== 0 || noFiles.preview !== 0 || noFiles.onlinepreview !== 0) {
		note(`src/settings.js: sourceCounts() of a missing files block is ${JSON.stringify(noFiles)}, expected all zeros`)
	}
	if (typeof module.sourceSummary === 'function') {
		note('src/settings.js: sourceSummary() still exists - the apply answer no longer carries a mirror block')
	}

	// one build block decides the failure, and its stage says which step failed
	const mirrorFailure = module.buildFailure({ ok: true, rebuilt: false, files: {}, build: { ok: false, stage: 'mirror', message: 'delete guard' } })
	if (mirrorFailure?.stage !== 'mirror' || mirrorFailure.message !== 'delete guard') {
		note(`src/settings.js: a failed mirror is not reported as one (${JSON.stringify(mirrorFailure)})`)
	}
	const buildFailure = module.buildFailure({ ok: true, rebuilt: true, files: {}, build: { ok: false, stage: 'build', message: 'Liquid error' } })
	if (buildFailure?.stage !== 'build' || buildFailure.message !== 'Liquid error') {
		note(`src/settings.js: a failed build is not reported as one (${JSON.stringify(buildFailure)})`)
	}
	if (module.buildFailure({ ok: true, rebuilt: true, files: {}, build: { ok: true, stage: 'build', message: '' } }) !== null) {
		note('src/settings.js: a successful apply is reported as a failure')
	}
	if (module.buildFailure({ ok: false, message: 'bridge down' })?.message !== 'bridge down') {
		note('src/settings.js: a top-level apply failure loses the server message')
	}

	// the delete guard is the one Apply source failure that is a question; everything else is an error
	const guard = module.deleteGuard({
		ok: false,
		error: 'MIRROR_DELETE_GUARD',
		tree: 'work',
		old: 40,
		new: 12,
		removed: 28,
		guard: { ratio: 0.5, min: 3 },
		message: 'the assembly would remove 28 of the 40 files',
	})
	if (guard?.tree !== 'work' || guard.old !== 40 || guard.new !== 12 || guard.removed !== 28
		|| guard.guard?.ratio !== 0.5 || guard.guard?.min !== 3) {
		note(`src/settings.js: deleteGuard() does not carry the guard's numbers (${JSON.stringify(guard)})`)
	}
	if (module.deleteGuard({ ok: false, error: 'SOURCE_EMPTY', message: 'nothing to build' }) !== null) {
		note('src/settings.js: deleteGuard() treats SOURCE_EMPTY as a deletion to confirm')
	}
	if (module.deleteGuard({ ok: false, error: 'SOURCE_UNREADABLE' }) !== null || module.deleteGuard({ ok: true }) !== null) {
		note('src/settings.js: deleteGuard() answers a guard where there is none')
	}

	// Apply source lives in its own module (admin.js owns the DOM); the page still wires it
	const applySourceJs = readFileSync(resolve(root, 'src/applySource.js'), 'utf8')
	const admin = readFileSync(resolve(root, 'src/admin.js'), 'utf8')
	for (const [needle, why] of [
		['sourceCounts(data?.files)', 'Apply source does not read the three per-tree counts'],
		["t('pages', 'Rebuilt 3 trees: work %1$s, preview %2$s, onlinepreview %3$s'", 'Apply source does not name the three rebuilt trees'],
		['buildFailure(data)', 'Apply source no longer reads the build block'],
		['deleteGuard(data)', 'Apply source does not use the delete-guard helper'],
		['{ force: true }', 'Apply source never repeats the request with force'],
	]) {
		if (!applySourceJs.includes(needle)) {
			note(`src/applySource.js: ${why} (${needle})`)
		}
	}
	if (applySourceJs.includes('sourceSummary') || applySourceJs.includes('data?.mirror')) {
		note('src/applySource.js: Apply source still reads the removed mirror-block counters')
	}
	if (!admin.includes('runApplySource({')) {
		note('src/admin.js: the Apply source button no longer delegates to the tested flow')
	}

	/*
	 * The flow itself, executed against the stubbed axios: the guard asks first and the confirmed
	 * answer repeats the same endpoint with `force: true`; a cancelled dialog sends nothing more; an
	 * empty source is an error and is never "confirmed"; a plain success asks nothing.
	 */
	const { applySource } = await loadNamedModule('src/applySource.js')
	const drive = async (answer) => {
		const asks = []
		const toasts = []
		axiosCalls.length = 0
		axiosAnswer = (request) => (typeof answer === 'function' ? answer(request) : answer)
		const result = await applySource({
			confirm: (title, lines, label) => {
				asks.push({ title, lines, label })
				return drive.confirmAnswer
			},
			toast: (type, message) => toasts.push({ type, message }),
		})
		return { result, calls: [...axiosCalls], asks, toasts }
	}
	const successBody = { ok: true, rebuilt: true, files: { work: 3, preview: 4, onlinepreview: 5 }, build: { ok: true } }
	const guardBody = {
		ok: false,
		error: 'MIRROR_DELETE_GUARD',
		tree: 'preview',
		old: 40,
		new: 12,
		removed: 28,
		guard: { tree: 'preview', ratio: 0.5, min: 3 },
		message: 'the assembly would remove 28 of the 40 files',
	}

	drive.confirmAnswer = true
	let run = await drive((request) => (request.body?.force === true ? successBody : guardBody))
	if (run.asks.length !== 1 || run.asks[0].lines[0].includes('28') === false
		|| run.asks[0].lines[0].includes('preview') === false
		|| run.asks[0].lines[0].includes('40') === false || run.asks[0].lines[0].includes('12') === false) {
		note(`src/applySource.js: the delete-guard dialog does not name the numbers (${JSON.stringify(run.asks)})`)
	}
	if (run.calls.length !== 2 || run.calls[0].body?.force !== false || run.calls[1].body?.force !== true) {
		note(`src/applySource.js: the confirmed guard does not retry with force (${JSON.stringify(run.calls.map((call) => call.body))})`)
	}
	if (run.result?.applied !== true || run.result?.forced !== true || run.toasts.at(-1)?.type !== 'success') {
		note(`src/applySource.js: a confirmed force retry is not reported as applied (${JSON.stringify(run)})`)
	}

	drive.confirmAnswer = false
	run = await drive((request) => (request.body?.force === true ? successBody : guardBody))
	if (run.asks.length !== 1 || run.calls.length !== 1 || run.result?.applied !== false || run.result?.forced !== false) {
		note(`src/applySource.js: cancelling the guard still sends the forced request (${JSON.stringify(run)})`)
	}
	if (run.toasts.length !== 0) {
		note('src/applySource.js: cancelling the guard reports a success or an error')
	}

	drive.confirmAnswer = true
	run = await drive({ ok: false, error: 'SOURCE_EMPTY', message: 'the source folder is empty' })
	if (run.asks.length !== 0 || run.calls.length !== 1 || run.result?.applied !== false || run.toasts.at(-1)?.type !== 'error') {
		note(`src/applySource.js: SOURCE_EMPTY is not reported as an error without a force offer (${JSON.stringify(run)})`)
	}

	drive.confirmAnswer = true
	run = await drive(successBody)
	if (run.asks.length !== 0 || run.calls.length !== 1 || run.result?.applied !== true || run.result?.forced !== false) {
		note(`src/applySource.js: a plain successful apply asks for confirmation (${JSON.stringify(run)})`)
	}
	axiosAnswer = {}
	axiosCalls.length = 0

	process.stdout.write('  logic     settings.js/applySource.js: unreachable vs refused, three rebuilt trees, build stage/message, delete guard → confirm → force, SOURCE_EMPTY without force, 800 ms debounce\n')
}

/**
 * 3e. The admin settings page: a PHP template plus a plain-DOM script, so the structure is checked
 *     where it is written. A "field control" is what a browser renders as an editable input: a
 *     text/password-style `<input>` or a `<select>`. Hidden inputs (the grant values the pickers write
 *     into) and the file picker are counted, but the file picker is not a field the administrator
 *     types into; the limit below is "one per setting this page owns".
 */
function checkSettingsPage() {
	const file = 'templates/settings/admin.php'
	const source = readFileSync(resolve(root, file), 'utf8')
	const adminJs = readFileSync(resolve(root, 'src/admin.js'), 'utf8')
	// comments are stripped first: this file documents every removed key, and prose is not a control
	const markup = source
		.replace(/<\?php[\s\S]*?\?>/g, (block) => block.replace(/\/\*[\s\S]*?\*\//g, '').replace(/^\s*\/\/.*$/gm, ''))
		.replace(/<!--[\s\S]*?-->/g, '')
	const controls = [...markup.matchAll(/<input\b[^>]*>/g)]
		.map((match) => match[0])
		.filter((tag) => !/type="hidden"/.test(tag))
	const selects = (markup.match(/<select\b/g) ?? []).length
	const total = controls.length + selects
	// URL + pairing code + four site fields + author directory + orphan owner directory + archive picker
	if (total !== 9) {
		note(`${file}: ${total} field controls rendered (expected 9): ${controls.map((tag) => (tag.match(/id="([^"]+)"/) ?? [])[1] ?? '?').join(', ')}`)
	}
	process.stdout.write(`  settings  ${file}: ${total} field control(s) (URL, pairing code, 4 site fields, author directory, orphan owner directory, archive picker)\n`)

	// the four blocks and the danger zone have to be there, with the anchors their script uses
	const present = [
		'pages-bridge-panel', 'pages-bridge-url', 'pages-pair-code', 'pages-pair', 'pages-rotate',
		'pages-pair-again', 'pages-fingerprints', 'pages-bridge-status',
		'pages-site', 'pages-site-nc-user', 'pages-site-nc-path', 'pages-site-repo', 'pages-site-branch',
		'pages-site-apply', 'pages-site-sync', 'pages-site-import',
		'pages-site-import-file', 'pages-site-summary', 'pages-site-head', 'pages-site-ahead',
		'pages-authors', 'pages-author-root', 'pages-orphan-owner', 'pages-permissions', 'pages-grants-state',
		'pages-reset', 'pages-settings-ui',
	]
	for (const anchor of present) {
		if (!markup.includes(`id="${anchor}"`)) {
			note(`${file}: the control "${anchor}" is missing`)
		}
	}
	// one hint for the content lock (the author content directory is never locked: the bridge
	// decides a move through its own channel, so it has no hint and is always editable)
	for (const anchor of ['pages-site-lock-hint']) {
		if (!markup.includes(`id="${anchor}"`)) {
			note(`${file}: the lock hint "${anchor}" is missing`)
		}
	}
	if (markup.includes('id="pages-author-lock-hint"')) {
		note(`${file}: the author content directory still renders a lock hint`)
	}
	if (!source.includes('<?php if ($locked): ?>')) {
		note(`${file}: the lock state is not rendered conditionally`)
	}
	if (!adminJs.includes('isUnreachable') || !source.includes('$lockHint')) {
		note(`${file}/src/admin.js: the lock hint or the revert classification is not wired`)
	}

	// every removed control really is gone from the markup
	const removed = [
		// the whole Status section, the two save buttons and every inline feedback line
		'pages-status', 'pages-sync-state', 'pages-config-revision', 'pages-external-count',
		'pages-unsynced-count', 'pages-site-save', 'pages-save', 'pages-feedback', 'pages-site-feedback',
		'pages-restart-note',
		// the §14 migration template and its two choices
		'pages-site-migration', 'pages-site-migration-template',
		// the old secret inputs and the removed site/global fields
		'pages-shared-secret', 'pages-signing-secret', 'pages-site-exclude', 'pages-site-output',
		'pages-site-command', 'pages-site-install', 'pages-cmd', 'pages-site-advanced',
		'pages-bridge-nc-data-root', 'pages-bridge-work-root', 'pages-bridge-state-dir',
		'pages-bridge-override-dir', 'pages-bridge-log-dir', 'pages-bridge-build-timeout-s',
		'pages-bridge-build-debounce-ms', 'pages-bridge-log-tail-lines', 'pages-bridge-mirror-verify',
		'pages-bridge-mirror-delete-guard-ratio', 'pages-bridge-mirror-delete-guard-min',
		'pages-bridge-mirror-preserve-exec', 'pages-bridge-mirror-use-gitignore',
		'pages-bridge-exclude', 'pages-bridge-unexclude', 'pages-bridge-listen',
	]
	const stillThere = removed.filter((id) => markup.includes(`id="${id}"`))
	if (stillThere.length) {
		note(`${file}: removed control(s) still rendered: ${stillThere.join(', ')}`)
	}
	for (const text of ['Clear it (rebuild later with Import or Pull)', 'Migrate the site folder']) {
		if (source.includes(text)) {
			note(`${file}: the retired §14 text is still in the template ("${text}")`)
		}
	}
	if (adminJs.includes('Save site') || adminJs.includes('Save and push')) {
		note('src/admin.js: a removed save action is still referenced')
	}
	if (adminJs.includes('preflight') || adminJs.includes('showMigration')) {
		note('src/admin.js: the retired §14 flow is still referenced')
	}
	process.stdout.write(`  settings  ${removed.length} removed key(s) absent, no save button, no §14, danger zone present\n`)

	// the two layout fields are rendered disabled while locked: the attribute comes from one guard;
	// the author content directory is deliberately outside that guard (always editable)
	for (const id of ['pages-site-nc-user', 'pages-site-nc-path']) {
		const at = source.indexOf(`id="${id}"`)
		if (at === -1) {
			continue
		}
		const tag = source.slice(at, at + 500)
		const end = tag.indexOf('/>')
		if (!tag.slice(0, end === -1 ? tag.length : end).includes('$layoutDisabled')) {
			note(`${file}: the input "${id}" is not rendered with the lock guard`)
		}
	}
	// the two pickers are hidden inputs (the Vue pickers write into them), not controls
	for (const id of ['pages-author-groups', 'pages-review-groups']) {
		if (!source.includes(`'${id}'`)) {
			note(`${file}: the grant anchor "${id}" is missing (the pickers would start without a value)`)
		}
	}
	if (!/type="hidden"[^>]*class="pages-grant-value"|class="pages-grant-value"[^>]*type="hidden"/.test(markup)) {
		note(`${file}: the hidden input the grant anchors live in is missing`)
	}

	// write-through + auto-save wiring, in the script that owns it
	const wiring = [
		['push: true', 'the site block is not pushed together with the save'],
		["addEventListener('blur', saveSiteBlock)", 'the site fields do not commit on blur'],
		["addEventListener('blur', saveAuthorRoot)", 'the author directory does not commit on blur'],
		["addEventListener('blur', saveOrphanOwner)", 'the orphan owner directory does not commit on blur'],
		['AUTO_SAVE_DELAY', 'the bridge URL is not debounced'],
		['saveGrants()', 'the grant pickers do not save on change'],
		['openReset', 'the danger zone does not open the reset dialog'],
	]
	for (const [needle, message] of wiring) {
		if (!adminJs.includes(needle)) {
			note(`src/admin.js: ${message} (${needle})`)
		}
	}
	// both replacement paths confirm before anything is replaced
	for (const phrase of ['Replace the whole site folder with this archive?', 'will be replaced with the remote content']) {
		if (!adminJs.includes(phrase)) {
			note(`src/admin.js: a replacement confirmation is missing (${phrase})`)
		}
	}
	// the Files-app hint points at the explicit apply, which is now the settings page's own button
	if (!source.includes('Files-app edits do not rebuild the preview automatically')) {
		note(`${file}: the "Files-app edits do not rebuild the preview" hint is missing`)
	}
	if (!source.includes('use Apply source')) {
		note(`${file}: the Files-app hint does not point at Apply source`)
	}
}

/**
 * 4. The publish banners: one commit result, one push result, one "nothing to publish", one failure.
 *
 * A commit is always published; a push happens only with a remote, and the answer says whether it
 * went out. The answer is the outcome alone (`commit`, `pushed`) since the folders decide what a
 * commit carries: the page must not read a `paths`/`skipped`/`stale`/`partial`/`stage_errors`
 * summary any more. The strings are checked in the method that owns them, so a later edit cannot
 * quietly bring the removed bookkeeping back.
 */
function checkPublishBanners() {
	const source = readFileSync(resolve(root, 'src/App.vue'), 'utf8')
	const start = source.indexOf('\t\tasync publish() {')
	const end = source.indexOf('\t\tasync refreshStatus(', start)
	if (start === -1 || end === -1) {
		note('src/App.vue: publish() not found where expected')
		return
	}
	const body = source.slice(start, end)
	const wanted = [
		"t('pages', 'Published: %s', [hash])",
		"t('pages', 'Published: %s · pushed', [hash])",
		"t('pages', 'Nothing to publish')",
		"t('pages', 'Publishing failed') + ': ' + message",
	]
	const missing = wanted.filter((text) => !body.includes(text))
	if (missing.length) {
		note(`src/App.vue: publish banner(s) missing: ${missing.join(' | ')}`)
	}
	// the removed publish bookkeeping (a full-tree path list, skipped/stale/partial counts, staging
	// errors) has no place in the front end: the answer no longer carries it, and a page that reads
	// it would silently report nothing
	const banned = ['data.paths', 'data.skipped', 'data.stale', 'data.partial', 'stage_errors', 'outcome.', 'not pushed', 'not_pushed', 'unpushed']
	const present = banned.filter((text) => body.toLowerCase().includes(text.toLowerCase()))
	if (present.length) {
		note(`src/App.vue: publish() carries removed bookkeeping or wording: ${present.join(', ')}`)
	}
	process.stdout.write(`  banners   publish(): ${wanted.length - missing.length}/${wanted.length} banner(s), ${present.length} removed bookkeeping reference(s)\n`)
}

/**
 * 4a″. A rejection says which kind of pending request it answered.
 *
 * `reject` answers the pending snapshot and no longer takes a page down: refusing a take-off keeps
 * the page on the site, refusing a first-publication candidate leaves it internal, and refusing an
 * update keeps the version the site already serves. One sentence for all three told the reviewer the
 * page was "back to internal" even while it was still served - that is the bug this pins.
 *
 * The direct take-down is not part of it any more: it is the manage view's own action
 * (`/api/article/take-down`), and the Edit side no longer offers it. The request panel keeps its
 * defensive sentence for a row that carries no pending request at all.
 */
async function checkRejectMessages() {
	const Requests = await loadComponent('src/Requests.vue')
	const notices = []
	const context = (articles) => ({
		...Requests.methods,
		articles,
		busy: new Set(),
		rejecting: new Set(),
		notes: {},
		showNotice: (type, message) => notices.push({ type, message }),
	})
	const row = (path, overrides = {}) => ({ path, state: 'requested', intent: '', ...overrides })
	const problems = []

	const cases = [
		['a first-publication candidate', row('/tmp/alice/a.md', { intent: 'online', candidate: true, published: false }), 'Rejected: /tmp/alice/a.md is back to internal.'],
		['an update candidate', row('/tmp/alice/c.md', { intent: 'online', candidate: true, published: true }), 'Rejected: /tmp/alice/c.md keeps the published version.'],
		['a take-off request', row('/tmp/alice/b.md', { intent: 'offline', downRequest: true, published: true }), 'Take-offline declined: /tmp/alice/b.md stays online.'],
		['a direct take-down row', row('/tmp/alice/d.md', { state: 'external', intent: '' }), 'Took “/tmp/alice/d.md” off the site.'],
	]
	for (const [what, article, expected] of cases) {
		notices.length = 0
		await Requests.methods.decideOne.call(context([article]), article.path, 'reject', 'no')
		const got = notices[0]?.message ?? ''
		if (got !== expected) {
			problems.push(`${what} says ${JSON.stringify(got)}, expected ${JSON.stringify(expected)}`)
		}
	}
	// an approval keeps its one sentence, and never claims a rejection
	notices.length = 0
	await Requests.methods.decideOne.call(context([row('/tmp/alice/e.md', { intent: 'online', candidate: true })]), '/tmp/alice/e.md', 'approve', '')
	if (notices[0]?.message !== 'Approved: /tmp/alice/e.md') {
		problems.push(`an approval says ${JSON.stringify(notices[0]?.message ?? '')}`)
	}

	const App = await loadComponent('src/App.vue')
	const text = (kind, entry, path = '/tmp/alice/a.md') => App.methods.actionText.call({}, kind, path, entry)
	if (text('reject', { intent: 'offline' }) !== 'Take-offline declined: /tmp/alice/a.md stays online.') {
		problems.push(`the header's rejection of a take-off says ${JSON.stringify(text('reject', { intent: 'offline' }))}`)
	}
	if (text('reject', { intent: 'online' }) !== 'Rejected') {
		problems.push(`the header's rejection of an online request says ${JSON.stringify(text('reject', { intent: 'online' }))}`)
	}
	// requestOnline means a first publication on an internal entry and an update on a served one
	if (text('requestOnline', { state: 'internal' }) !== 'Online requested') {
		problems.push(`a first requestOnline says ${JSON.stringify(text('requestOnline', { state: 'internal' }))}`)
	}
	if (text('requestOnline', { state: 'external' }) !== 'Request the update online') {
		problems.push(`an update requestOnline says ${JSON.stringify(text('requestOnline', { state: 'external' }))}`)
	}
	// the retired content-review kinds have no message any more, and a `reject` of an external entry
	// with nothing pending is the plain rejection (that entry is taken down in Manage.vue)
	for (const kind of ['requestRevision', 'cancelRevision']) {
		if (text(kind, { state: 'external' }) !== 'Done') {
			problems.push(`the removed ${kind} still has a message (${JSON.stringify(text(kind, { state: 'external' }))})`)
		}
	}
	if (text('reject', { state: 'external', intent: '' }) !== 'Rejected') {
		problems.push(`a rejection of an external entry says ${JSON.stringify(text('reject', { state: 'external', intent: '' }))}`)
	}

	// which decision the two buttons actually send: approve and reject share one endpoint and one body.
	// The direct take-down is not one of them any more - it is the manage view's own action.
	const decisionOf = async (kind, entry) => {
		axiosCalls.length = 0
		await App.methods.callAction.call({}, kind, '/tmp/alice/a.md', entry)
		const call = axiosCalls[0] ?? {}
		if (!String(call.url || '').endsWith('/api/article/decision')) {
			return `the ${kind} action called ${String(call.url || 'nothing')}`
		}
		return String(call.body?.decision ?? '')
	}
	const sent = [
		['the header approval of a pending take-off', 'approve', { state: 'requested', intent: 'offline', downRequest: true }, 'approve'],
		['the header rejection of a pending take-off', 'reject', { state: 'requested', intent: 'offline', downRequest: true }, 'reject'],
		['the header rejection of a pending online request', 'reject', { state: 'requested', intent: 'online', candidate: true }, 'reject'],
	]
	for (const [what, kind, entry, expected] of sent) {
		const got = await decisionOf(kind, entry)
		if (got !== expected) {
			problems.push(`${what} sends "${got}", expected "${expected}"`)
		}
	}

	if (problems.length) {
		note(`reject messages: ${problems.join('; ')}`)
		return
	}
	process.stdout.write(`  reject    ${cases.length} toast(s) by kind, header text and the approve/reject each button sends\n`)
}

/**
 * 4a‴. Who may decide: the reviewer, on any entry - the caller's own included - and which side of
 * the snapshot model each action belongs to.
 *
 * The server has one condition for a decision, the review grant (`ArticleReviewService::decide()`):
 * a reviewer may decide about an entry they own too, because an instance whose only reviewer is also
 * the author would otherwise have no way to decide anything; `decidedBy` records who answered. Both
 * kinds of pending row - a candidate and a downRequest - are decided through the same endpoint, so
 * both carry `approve`/`reject`.
 *
 * The **direct take-down** left this rule on purpose: taking a page off the site is a decision about
 * the site, so it is the manage view's action alone (Manage.vue). It is `api.takeDown` there, not a
 * decision.
 */
async function checkActionRules() {
	const App = await loadComponent('src/App.vue')
	const entry = (overrides = {}) => ({ type: 'file', state: 'internal', intent: '', owner: 'alice', ...overrides })
	// the subject carries `uid` on purpose: reintroducing the ownership exception must fail here
	const actions = (subject, overrides = {}) => App.methods.actionsFor.call(subject, entry(overrides))
	const problems = []
	const reviewer = { canReview: true, uid: 'alice' }

	// the author's side: internal asks for a first publication, external can ask for an update or a
	// take-off, and a pending entry can be withdrawn - the flags decide, not the state alone
	for (const [what, overrides, expected] of [
		['an internal entry', {}, ['requestOnline']],
		['a served entry', { state: 'external', published: true }, ['requestOffline', 'requestOnline']],
		['a pending candidate on an internal entry', { state: 'internal', candidate: true }, ['cancel']],
		['a pending candidate on a served entry', { state: 'external', candidate: true, published: true }, ['cancel']],
		['a pending take-off', { state: 'requested', downRequest: true }, ['cancel']],
	]) {
		const got = actions(reviewer, overrides)
		for (const action of expected) {
			if (!got.includes(action)) {
				problems.push(`a reviewer's ${what} must offer "${action}" (got ${JSON.stringify(got)})`)
			}
		}
	}

	// a pending entry offers no second request: the server would answer 409 to it. This is the
	// regression the folder model introduces when the request flags are ignored (the state of a
	// candidate is `internal` or `external`, not `requested`).
	for (const [what, overrides] of [
		['an internal candidate', { state: 'internal', candidate: true }],
		['an external candidate', { state: 'external', candidate: true, published: true }],
		['a take-off request', { state: 'requested', downRequest: true, published: true }],
	]) {
		const got = actions(reviewer, overrides)
		for (const action of ['requestOnline', 'requestOffline']) {
			if (got.includes(action)) {
				problems.push(`a reviewer's ${what} must not offer "${action}" (got ${JSON.stringify(got)}) - the entry already has a pending request`)
			}
		}
	}

	// the reviewer's two answers, for both kinds and on their own entries too
	for (const [what, overrides] of [
		['own pending candidate', { state: 'internal', candidate: true }],
		['own pending take-off', { state: 'requested', downRequest: true }],
	]) {
		const got = actions(reviewer, overrides)
		for (const action of ['approve', 'reject']) {
			if (!got.includes(action)) {
				problems.push(`a reviewer's ${what} must offer "${action}" (got ${JSON.stringify(got)})`)
			}
		}
	}

	// a `requested` entry with neither flag has nothing the server could answer
	for (const got of [actions(reviewer, { state: 'requested' })]) {
		for (const action of ['approve', 'reject']) {
			if (got.includes(action)) {
				problems.push(`a pending row with nothing to decide still offers "${action}" (got ${JSON.stringify(got)})`)
			}
		}
	}

	// without the grant, no decision is offered at all
	const author = actions({ canReview: false, uid: 'alice' }, { state: 'internal', candidate: true })
	for (const action of ['approve', 'reject']) {
		if (author.includes(action)) {
			problems.push(`an author without the review grant must not get "${action}" (got ${JSON.stringify(author)})`)
		}
	}

	/*
	 * The regression: no Edit surface may offer the direct take-down any more, and the retired
	 * content-review actions must not come back either. Checked on the rule itself, for every entry
	 * shape the old code covered - an online entry, somebody else's, a site-owned one (no owner),
	 * and both kinds of pending request.
	 */
	for (const [what, overrides] of [
		['an online article', { state: 'external', intent: '' }],
		['a site-owned online article', { state: 'external', intent: '', owner: '' }],
		['somebody else\'s online article', { state: 'external', intent: '', owner: 'bob' }],
		['a pending take-off request', { state: 'requested', intent: 'offline', downRequest: true }],
		['a pending candidate', { state: 'internal', intent: 'online', candidate: true }],
	]) {
		const got = actions(reviewer, overrides)
		for (const action of ['offline', 'requestRevision', 'cancelRevision']) {
			if (got.includes(action)) {
				problems.push(`a reviewer's ${what} still offers "${action}" (got ${JSON.stringify(got)}) - the take-down belongs to the manage view and the content-review model is retired`)
			}
		}
	}

	// the label of `requestOnline` names the update only on an entry the site already serves
	const label = (state) => App.methods.requestOnlineLabel.call({}, { state })
	if (label('internal') !== 'Request online' || label('external') !== 'Request the update online') {
		problems.push(`requestOnlineLabel gives ${JSON.stringify({ internal: label('internal'), external: label('external') })}`)
	}

	// the retired endpoints and their client functions are gone from the API surface
	const apiSource = readFileSync(resolve(root, 'src/api.js'), 'utf8')
	for (const dead of ['requestRevision', 'cancelRevision', '/api/article/request-revision', '/api/article/cancel-revision']) {
		if (apiSource.includes(dead)) {
			problems.push(`src/api.js still carries the retired ${dead}`)
		}
	}
	if (!apiSource.includes("'/api/article/take-down'")) {
		problems.push('src/api.js has no takeDown() call to /api/article/take-down')
	}

	if (problems.length) {
		note(`src/App.vue actionsFor(): ${problems.join('; ')}`)
		return
	}
	process.stdout.write('  actions   App.vue actionsFor(): flags over state (candidate/downRequest), both pending kinds decideable, no "offline"/content-review action anywhere, api.takeDown present\n')
}

/**
 * 4a⁵. The delete endpoint's mode is a query parameter, and every delete carries it.
 *
 * `DELETE /api/site/file?path=…&mode=…` is three workflows behind one endpoint: the mode decides
 * whether the file merely leaves (`''`), leaves with a take-off request (`request-offline`) or is
 * handed to the orphan owner directory (`keep`). A client that dropped the parameter would delete a
 * published page without ever asking the question, so the recorded request is asserted for every
 * answer - including the default, which has to travel as `''` rather than be omitted.
 */
async function checkDeleteFileMode() {
	const module = await loadModule('src/api.js')
	const problems = []
	const send = async (...args) => {
		axiosCalls.length = 0
		await module.api.deleteFile(...args)
		return axiosCalls[0] ?? {}
	}
	for (const [mode, expected] of [['request-offline', 'request-offline'], ['keep', 'keep'], ['', '']]) {
		const call = await send('/tmp/alice/a.md', mode)
		if (call.method !== 'delete') {
			problems.push(`mode ${JSON.stringify(mode)} went out as ${String(call.method)}`)
			continue
		}
		if (!String(call.url || '').endsWith('/api/site/file')) {
			problems.push(`mode ${JSON.stringify(mode)} was sent to ${String(call.url || 'nothing')}`)
		}
		if (String(call.body?.params?.path ?? '') !== '/tmp/alice/a.md') {
			problems.push(`mode ${JSON.stringify(mode)} carried path ${JSON.stringify(call.body?.params?.path ?? null)}`)
		}
		if (!('mode' in (call.body?.params ?? {})) || String(call.body.params.mode) !== expected) {
			problems.push(`mode ${JSON.stringify(mode)} carried ${JSON.stringify(call.body?.params?.mode ?? null)} in the query`)
		}
	}
	// no argument at all is the plain deletion, and it still sends the parameter as ''
	const call = await send('/tmp/alice/a.md')
	if (String(call.body?.params?.mode ?? 'missing') !== '') {
		problems.push(`a delete without a mode sent ${JSON.stringify(call.body?.params?.mode ?? null)} (the parameter must be '' and present)`)
	}
	if (problems.length) {
		note(`src/api.js deleteFile(): ${problems.join('; ')}`)
		return
	}
	process.stdout.write(`  delete    src/api.js deleteFile(): path + mode ("request-offline" / "keep" / "") in the query\n`)
}

/**
 * 4a⁶. Which deletion asks the three-way question.
 *
 * A file the site serves (`published`, or `external` when the answer carries no such flag) can be
 * deleted in two meaningful ways, so it gets the dialog; a folder and an unpublished entry keep the
 * flat confirmation. `confirmDelete` is the one place that decides it, and both entry points (the
 * tree menu and the editor header) go through it. The dialog is a promise, so the test drives it the
 * way a user does: open it, then answer. `deleteNode` is driven through the same answers, which is
 * what proves the chosen mode reaches the request and the answer's own block decides the toast.
 */
async function checkDeleteDialog() {
	const App = await loadComponent('src/App.vue')
	const problems = []
	const entry = (overrides = {}) => ({
		type: 'file',
		path: 'notes/live.md',
		target: '/tmp/alice/notes/live.md',
		name: 'live.md',
		state: 'external',
		published: true,
		...overrides,
	})
	const context = (nodes, overrides = {}) => ({
		...App.methods,
		canWrite: true,
		nodes,
		articleRows: [],
		deleteChoice: null,
		deleteResolve: null,
		...overrides,
	})

	// one published entry, every answer of the dialog (cancel included)
	for (const [answer, expected] of [['request-offline', { mode: 'request-offline' }], ['keep', { mode: 'keep' }], [null, null]]) {
		const subject = context([entry()])
		const pending = App.methods.confirmDelete.call(subject, '/tmp/alice/notes/live.md', subject.nodes[0])
		if (subject.deleteChoice?.name !== 'live.md') {
			problems.push(`a published entry did not open the three-way dialog (deleteChoice=${JSON.stringify(subject.deleteChoice)})`)
		}
		App.methods.answerDeleteDialog.call(subject, answer)
		const result = await pending
		if (JSON.stringify(result) !== JSON.stringify(expected)) {
			problems.push(`answering the delete dialog with ${JSON.stringify(answer)} gave ${JSON.stringify(result)}, expected ${JSON.stringify(expected)}`)
		}
		if (subject.deleteChoice !== null) {
			problems.push('the delete dialog stayed open after it was answered')
		}
	}

	const previousWindow = globalThis.window
	const confirmCalls = []
	globalThis.window = {
		confirm(message) {
			confirmCalls.push(message)
			return true
		},
	}
	try {
		// an entry the site does not serve keeps the flat confirmation
		const unpublished = context([entry({ path: 'notes/draft.md', target: '/tmp/alice/notes/draft.md', name: 'draft.md', state: 'internal', published: false })])
		confirmCalls.length = 0
		const plain = await App.methods.confirmDelete.call(unpublished, '/tmp/alice/notes/draft.md', unpublished.nodes[0])
		if (unpublished.deleteChoice !== null) {
			problems.push('an unpublished entry opened the three-way dialog')
		}
		if (confirmCalls.length !== 1) {
			problems.push(`an unpublished entry asked ${confirmCalls.length} flat confirmation(s), expected 1`)
		}
		if (plain?.mode !== '') {
			problems.push(`an unpublished entry answered ${JSON.stringify(plain)}, expected {"mode":""}`)
		}

		// a folder is never a published page, even if a stale answer carries the flag
		const folder = context([entry({ path: 'notes', target: '/tmp/alice/notes', name: 'notes', type: 'dir', state: '', published: true })])
		confirmCalls.length = 0
		const folderAnswer = await App.methods.confirmDelete.call(folder, '/tmp/alice/notes', folder.nodes[0])
		if (folder.deleteChoice !== null) {
			problems.push('a folder opened the three-way dialog')
		}
		if (!String(confirmCalls[0] || '').includes('Delete this folder?')) {
			problems.push(`a folder asked ${JSON.stringify(confirmCalls[0] ?? null)}`)
		}
		if (folderAnswer?.mode !== '') {
			problems.push(`a folder answered ${JSON.stringify(folderAnswer)}, expected {"mode":""}`)
		}

		/*
		 * The composition both entry points run (`deleteNode`): the dialog's answer reaches the
		 * request, and the answer's own `mode_applied` / `transfer` block decides the toast. The axios
		 * stub is given the shape the backend documents, so all three outcomes are exercised - the
		 * files are not merely deleted, the user is told which workflow ran.
		 */
		const drive = async (mode, data) => {
			const subject = context([entry()])
			const banners = []
			subject.refreshNav = async () => {}
			subject.showBanner = (type, message) => banners.push({ type, message })
			subject.deleting = false
			subject.currentFile = ''
			axiosAnswer = data
			axiosCalls.length = 0
			const pending = App.methods.deleteNode.call(subject, subject.nodes[0])
			App.methods.answerDeleteDialog.call(subject, mode)
			await pending
			axiosAnswer = {}

			return { call: axiosCalls[0] ?? {}, banners, subject }
		}

		const takeOff = await drive('request-offline', { mode: 'request-offline', mode_applied: true, requestOffline: true })
		if (String(takeOff.call.body?.params?.mode ?? null) !== 'request-offline') {
			problems.push(`the dialog's "request-offline" answer sent ${JSON.stringify(takeOff.call.body?.params?.mode ?? null)}`)
		}
		if (String(takeOff.call.body?.params?.path ?? '') !== '/tmp/alice/notes/live.md') {
			problems.push(`the delete after the dialog sent path ${JSON.stringify(takeOff.call.body?.params?.path ?? null)}`)
		}
		if (takeOff.banners[0]?.message !== 'Deleted. The take-off request waits for a reviewer.') {
			problems.push(`the take-off deletion toasted ${JSON.stringify(takeOff.banners[0]?.message ?? null)}`)
		}

		const keep = await drive('keep', {
			mode: 'keep',
			mode_applied: true,
			transfer: { orphan: 'Admin', target: 'tmp/Admin/live.md', message: 'Transferred live.md to the orphan directory Admin.' },
		})
		if (String(keep.call.body?.params?.mode ?? null) !== 'keep') {
			problems.push(`the dialog's "keep" answer sent ${JSON.stringify(keep.call.body?.params?.mode ?? null)}`)
		}
		if (keep.banners[0]?.message !== 'Transferred live.md to the orphan directory Admin.') {
			problems.push(`the hand-over toasted ${JSON.stringify(keep.banners[0]?.message ?? null)} instead of the answer's transfer.message`)
		}

		// a transfer without a message falls back to the sentence built from `orphan`
		const keepShort = await drive('keep', { mode: 'keep', mode_applied: true, transfer: { orphan: 'Admin' } })
		if (keepShort.banners[0]?.message !== 'Transferred to Admin.') {
			problems.push(`a transfer without a message toasted ${JSON.stringify(keepShort.banners[0]?.message ?? null)}, expected "Transferred to Admin."`)
		}

		// a mode the server did not apply (a folder or an entry it does not serve) is the plain deletion
		const degraded = await drive('keep', { mode: 'keep', mode_applied: false, reason: 'not-published' })
		if (degraded.banners[0]?.message !== 'Deleted, rebuilding…') {
			problems.push(`a degraded delete toasted ${JSON.stringify(degraded.banners[0]?.message ?? null)}, expected the plain deletion`)
		}

		/*
		 * A folder is settled file by file: the server files a take-off request for every page inside
		 * it the site still serves and forgets the rest. It has no mode (`mode_applied: false`), so
		 * the count in `offline_requests` is the only thing that tells the user N pages now wait for a
		 * reviewer - without it the deletion reads as the plain one.
		 */
		const driveFolder = async (offlineRequests) => {
			const subject = context([entry({ path: 'notes', target: '/tmp/alice/notes', name: 'notes', type: 'dir', state: '', published: false })])
			const banners = []
			subject.refreshNav = async () => {}
			subject.showBanner = (type, message) => banners.push({ type, message })
			subject.deleting = false
			subject.currentFile = ''
			axiosAnswer = {
				folder: true,
				offline_requests: offlineRequests,
				forgotten: 1,
				mode_applied: false,
				reason: null,
				requestOffline: offlineRequests > 0,
			}
			axiosCalls.length = 0
			globalThis.window.confirm = () => true
			await App.methods.deleteNode.call(subject, subject.nodes[0])
			axiosAnswer = {}

			return { call: axiosCalls[0] ?? {}, banners }
		}

		const folderWaiting = await driveFolder(2)
		if (folderWaiting.banners[0]?.message !== 'Deleted. 2 take-off request(s) wait for a reviewer.') {
			problems.push(`a folder whose pages wait for a take-off toasted ${JSON.stringify(folderWaiting.banners[0]?.message ?? null)}`)
		}
		if (String(folderWaiting.call.body?.params?.path ?? '') !== '/tmp/alice/notes') {
			problems.push(`the folder delete sent path ${JSON.stringify(folderWaiting.call.body?.params?.path ?? null)}`)
		}
		if (String(folderWaiting.call.body?.params?.mode ?? 'missing') !== '') {
			problems.push(`the folder delete sent mode ${JSON.stringify(folderWaiting.call.body?.params?.mode ?? null)}, expected ""`)
		}

		const folderPlain = await driveFolder(0)
		if (folderPlain.banners[0]?.message !== 'Deleted, rebuilding…') {
			problems.push(`a folder without take-off requests toasted ${JSON.stringify(folderPlain.banners[0]?.message ?? null)}, expected the plain deletion`)
		}

		// an unpublished entry through the same path: no dialog, and the plain delete (mode '')
		const plainDelete = context([entry({ state: 'internal', published: false })])
		plainDelete.refreshNav = async () => {}
		plainDelete.showBanner = () => {}
		plainDelete.deleting = false
		plainDelete.currentFile = ''
		globalThis.window.confirm = () => true
		axiosCalls.length = 0
		await App.methods.deleteNode.call(plainDelete, plainDelete.nodes[0])
		const plainCall = axiosCalls[0] ?? {}
		if (String(plainCall.body?.params?.mode ?? 'missing') !== '') {
			problems.push(`an unpublished entry's delete sent mode ${JSON.stringify(plainCall.body?.params?.mode ?? null)}, expected ""`)
		}
		if (plainDelete.deleteChoice !== null) {
			problems.push('an unpublished entry opened the three-way dialog through deleteNode')
		}

		// the `state === 'external'` fallback for an answer that carries no `published` field
		const fallback = context([entry({ published: undefined, state: 'external' })])
		const fallbackPending = App.methods.confirmDelete.call(fallback, '/tmp/alice/notes/live.md', fallback.nodes[0])
		if (fallback.deleteChoice?.name !== 'live.md') {
			problems.push('an external entry without a `published` flag did not open the three-way dialog (the state fallback)')
		}
		App.methods.answerDeleteDialog.call(fallback, null)
		await fallbackPending

		// a cancelled flat confirmation sends nothing
		const cancelled = context([entry({ state: 'internal', published: false })])
		globalThis.window.confirm = () => false
		confirmCalls.length = 0
		const cancelledAnswer = await App.methods.confirmDelete.call(cancelled, '/tmp/alice/notes/live.md', cancelled.nodes[0])
		if (cancelledAnswer !== null) {
			problems.push(`a cancelled flat confirmation returned ${JSON.stringify(cancelledAnswer)} instead of null`)
		}
	} finally {
		if (previousWindow === undefined) {
			delete globalThis.window
		} else {
			globalThis.window = previousWindow
		}
	}

	if (problems.length) {
		note(`src/App.vue confirmDelete(): ${problems.join('; ')}`)
		return
	}
	process.stdout.write('  delete    App.vue confirmDelete()/deleteNode(): published → three-way dialog (3 answers, mode in the query), folder/unpublished → flat confirm, take-off/transfer/folder-count/plain toasts\n')
}

/**
 * 4a⁗. The manage view's take-down goes to its own endpoint.
 *
 * A decision on an entry with nothing pending is a 409 (`ArticleReviewService::decide()`), so the
 * view must call `POST /api/article/take-down`. The axios stub records exactly which URL and body
 * the confirmation sends, which is what pins the endpoint (and proves no `decision` field is sent).
 */
async function checkManageTakeDown() {
	const Manage = await loadComponent('src/Manage.vue')
	const previousWindow = globalThis.window
	globalThis.window = {
		confirm: () => true,
		clearTimeout: () => {},
		setTimeout: () => 0,
	}
	const context = {
		...Manage.methods,
		articles: [{ path: '/tmp/alice/a.md', title: 'A', state: 'external', url: '/authors/alice/a.html' }],
		busy: new Set(),
		showBanner: () => {},
		refresh: async () => {},
		$emit: () => {},
	}
	const problems = []
	try {
		axiosCalls.length = 0
		await Manage.methods.askTakeDown.call(context, context.articles[0])
		const call = axiosCalls[0] ?? {}
		if (call.method !== 'post' || !String(call.url || '').endsWith('/api/article/take-down')) {
			problems.push(`the take-down called ${String(call.method)} ${String(call.url || 'nothing')}`)
		}
		if (String(call.body?.path || '') !== '/tmp/alice/a.md') {
			problems.push(`the take-down sent ${JSON.stringify(call.body ?? null)}`)
		}
		if (String(call.body?.decision || '') !== '') {
			problems.push('the take-down still carries a decision field')
		}
	} finally {
		if (previousWindow === undefined) {
			delete globalThis.window
		} else {
			globalThis.window = previousWindow
		}
	}
	if (problems.length) {
		note(`Manage.vue take-down: ${problems.join('; ')}`)
		return
	}
	process.stdout.write('  take-down Manage.vue: POST /api/article/take-down (not a decision)\n')
}

/**
 * 4b. `showBanner` is the one feedback entry point every caller uses. It has to map the type onto
 * the matching Nextcloud toast, keep the eight-second lifetime the in-page banner had, and send
 * anything unrecognised to the informational toast. The stub records what it is called with.
 */
async function checkToastDispatch() {
	const App = await loadComponent('src/App.vue')
	const cases = [
		['error', 'error'],
		['success', 'success'],
		['warning', 'warning'],
		['info', 'info'],
		['', 'info'],
		[undefined, 'info'],
	]
	const start = toastCalls.length
	for (const [type] of cases) {
		App.methods.showBanner.call({}, type, `message:${String(type)}`)
	}
	const got = toastCalls.slice(start)
	const problems = []
	if (got.length !== cases.length) {
		problems.push(`${got.length} toast(s) for ${cases.length} call(s)`)
	}
	cases.forEach(([type, expected], index) => {
		const call = got[index] || {}
		if (call.type !== expected) {
			problems.push(`type "${String(type)}" went to the ${String(call.type)} toast (expected ${expected})`)
		}
		if (call.message !== `message:${String(type)}`) {
			problems.push(`type "${String(type)}" carried the wrong message ("${String(call.message)}")`)
		}
		if (call.options?.timeout !== 8000) {
			problems.push(`type "${String(type)}" used timeout ${String(call.options?.timeout)} (expected 8000)`)
		}
	})
	if (problems.length) {
		note(`src/App.vue: showBanner does not dispatch to the right toast: ${problems.join('; ')}`)
	}
	process.stdout.write(`  toast     App.vue showBanner: ${cases.length} type(s) checked, ${problems.length} problem(s), timeout 8000\n`)
}

/**
 * 4c. "Is there anything unsaved?" is decided by the content, never by the editor's own flag.
 *
 * `dirty` is set by any `docChanged`, and the `setDoc()` a load or a save performs is one: using it
 * to decide whether to ask "Discard them?" made the dialog appear right after a successful save.
 * `editorDirty` compares the body and the fields against the baselines instead (through `hasChanges`
 * in src/articleGeometry.js). The cases below pin both directions of that comparison, the
 * empty-file fallback, the conflict rule, and the `confirmDiscard` default built on it.
 */
async function checkEditorDirty() {
	const App = await loadComponent('src/App.vue')
	const fields = (overrides = {}) => ({
		title: 'A title',
		date: '2026-01-02',
		tagsText: 'one, two',
		categoriesText: 'News',
		excerpt: '',
		extra: {},
		...overrides,
	})
	const state = (overrides = {}) => ({
		currentFile: 'Pages/alice/article.md',
		conflict: false,
		bodyText: 'The same body',
		bodyBaseline: 'The same body',
		fields: fields(),
		fieldsBaseline: fields(),
		dirty: false,
		...overrides,
	})
	const dirtyOf = (overrides) => App.computed.editorDirty.call(state(overrides))

	const problems = []
	// the regression: a save calls setDoc(), whose docChanged sets `dirty`, yet nothing is unsaved
	if (dirtyOf({ dirty: true }) !== false) {
		problems.push('dirty=true with the body and the fields equal to the baselines is reported as unsaved (the post-save false positive)')
	}
	// the other direction: a changed body must be seen even when the flag is (still) false
	if (dirtyOf({ bodyText: 'An edited body' }) !== true) {
		problems.push('a changed body with dirty=false is not reported as unsaved')
	}
	// and a changed field alone is a change too
	if (dirtyOf({ fields: fields({ title: 'Another title' }) }) !== true) {
		problems.push('a changed title with an unchanged body is not reported as unsaved')
	}
	// no file: there is no baseline to compare with, so the editor's own flag is the fallback
	if (dirtyOf({ currentFile: '', dirty: true }) !== true || dirtyOf({ currentFile: '', dirty: false }) !== false) {
		problems.push('editorDirty does not fall back to dirty while no file is open')
	}
	// a conflict always asks, whatever the content says
	if (dirtyOf({ conflict: true, bodyText: '', bodyBaseline: '', dirty: false }) !== true) {
		problems.push('an open conflict does not report unsaved work')
	}

	/*
	 * The default parameter must be `editorDirty`, not `dirty`: the object below says "the flag is
	 * set but the content is clean", and no dialog may appear for it. A second call with the opposite
	 * state proves the answer of `window.confirm` is returned (cancel really keeps the text).
	 */
	const previousWindow = globalThis.window
	const confirmCalls = []
	globalThis.window = {
		confirm(message) {
			confirmCalls.push(message)
			return false
		},
	}
	try {
		const cleanAnswer = await App.methods.confirmDiscard.call({ dirty: true, editorDirty: false })
		if (confirmCalls.length !== 0 || cleanAnswer !== true) {
			problems.push(`confirmDiscard called window.confirm ${confirmCalls.length} time(s) for a clean editorDirty=false state`)
		}
		const unsavedAnswer = await App.methods.confirmDiscard.call({ dirty: false, editorDirty: true })
		if (confirmCalls.length !== 1) {
			problems.push(`confirmDiscard called window.confirm ${confirmCalls.length} time(s) for editorDirty=true (expected 1)`)
		} else if (confirmCalls[0] !== 'The current file has unsaved changes. Discard them?') {
			problems.push(`confirmDiscard asked the wrong question ("${String(confirmCalls[0])}")`)
		}
		if (unsavedAnswer !== false) {
			problems.push('confirmDiscard does not return the answer of window.confirm')
		}
	} finally {
		if (previousWindow === undefined) {
			delete globalThis.window
		} else {
			globalThis.window = previousWindow
		}
	}

	/*
	 * The leave guard is a watcher on `editorDirty` (assertion 3 of the fix): watching `dirty` again
	 * would arm the browser's own "unsaved changes" dialog after a save, which no rendered case can
	 * see because the handler lives on `window`.
	 */
	const source = readFileSync(resolve(root, 'src/App.vue'), 'utf8')
	const watchAt = source.indexOf('\twatch: {')
	const mountAt = source.indexOf('\tasync mounted()')
	const watchSource = watchAt === -1 || mountAt === -1 ? '' : source.slice(watchAt, mountAt)
	if (!watchSource.includes('editorDirty(value)')) {
		problems.push('src/App.vue: the onbeforeunload watcher does not follow editorDirty')
	}
	if (/\n\t\tdirty\(value\)/.test(watchSource)) {
		problems.push('src/App.vue: the onbeforeunload watcher still follows dirty')
	}

	if (problems.length) {
		note(`src/App.vue: editorDirty/confirmDiscard no longer follow the content: ${problems.join('; ')}`)
	}
	process.stdout.write(`  unsaved   App.vue: 5 content cases, confirmDiscard default, onbeforeunload watcher (${problems.length} problem(s))\n`)
}

/**
 * 4d. The divider between the editor and the preview: one share of the width, clamped and kept.
 *
 * A share outside 25–75% would leave one pane unusable at any window width, so the clamp is the
 * contract of the whole handle, not a nicety: the pointer drag, the arrow keys and a value restored
 * from an older build all pass through `setSplitRatio`, and none of them may hide a pane. Every
 * accepted share is written back, so the next visit opens the same split - and a `localStorage` that
 * throws, as private mode does, may cost the memory but not the drag.
 *
 * `readSplitRatio` is private to the module, so its half is proven through `mounted`, whose first
 * statement is that read. `createEditor` cannot run outside a browser, which ends the call right
 * after it: the ratio the fake instance ends up with is the observable result.
 */
async function checkSplitRatio() {
	const App = await loadComponent('src/App.vue')
	const store = new Map()
	let readThrows = false
	let writeThrows = false
	const previousWindow = globalThis.window
	globalThis.window = {
		localStorage: {
			getItem: (key) => {
				if (readThrows) {
					throw new Error('private mode')
				}
				return store.has(key) ? store.get(key) : null
			},
			setItem: (key, value) => {
				if (writeThrows) {
					throw new Error('private mode')
				}
				store.set(key, String(value))
			},
		},
	}
	const problems = []
	// one fresh instance per call: the methods are copied so `this` carries them
	const state = (overrides = {}) => ({ ...App.methods, splitRatio: 0.5, ...overrides })
	const same = (left, right) => Math.abs(left - right) < 1e-9
	const mountedRatio = async () => {
		const target = { splitRatio: 0.5, $refs: {} }
		try {
			await App.mounted.call(target)
		} catch (error) {
			// createEditor needs a browser; the ratio read precedes it, which is what this checks
		}
		return target.splitRatio
	}
	try {
		for (const [value, expected] of [[0.1, 0.25], [0.9, 0.75], ['abc', 0.5], ['0.4', 0.4]]) {
			const target = state()
			target.setSplitRatio(value)
			if (target.splitRatio !== expected) {
				problems.push(`setSplitRatio(${JSON.stringify(value)}) is ${target.splitRatio}, expected ${expected}`)
			}
			if (store.get('pages-split-ratio') !== String(expected)) {
				problems.push(`setSplitRatio(${JSON.stringify(value)}) stored ${JSON.stringify(store.get('pages-split-ratio') ?? null)}, expected ${JSON.stringify(String(expected))}`)
			}
		}

		const keyboard = state({ splitRatio: 0.5 })
		const press = (key) => {
			let prevented = false
			keyboard.onSplitKeydown({ key, preventDefault: () => { prevented = true } })
			return prevented
		}
		press('ArrowRight')
		if (!same(keyboard.splitRatio, 0.55)) {
			problems.push(`ArrowRight moved the divider to ${keyboard.splitRatio}, expected 0.55`)
		}
		press('ArrowLeft')
		if (!same(keyboard.splitRatio, 0.5)) {
			problems.push(`ArrowLeft moved the divider to ${keyboard.splitRatio}, expected 0.5`)
		}
		keyboard.splitRatio = 0.74
		if (!press('ArrowRight') || !same(keyboard.splitRatio, 0.75)) {
			problems.push(`ArrowRight at the edge is ${keyboard.splitRatio} (default prevented: expected), expected the 0.75 clamp`)
		}
		keyboard.splitRatio = 0.26
		press('ArrowLeft')
		if (!same(keyboard.splitRatio, 0.25)) {
			problems.push(`ArrowLeft at the edge is ${keyboard.splitRatio}, expected the 0.25 clamp`)
		}
		keyboard.splitRatio = 0.7
		press('Home')
		if (!same(keyboard.splitRatio, 0.5)) {
			problems.push(`Home left the divider at ${keyboard.splitRatio}, expected 0.5`)
		}

		const reset = state({ splitRatio: 0.75 })
		reset.resetSplit()
		if (reset.splitRatio !== 0.5) {
			problems.push(`resetSplit left the divider at ${reset.splitRatio}, expected 0.5`)
		}

		// the restore path, one stored value at a time (`null` = nothing stored at all)
		for (const [raw, expected] of [['0.3', 0.3], ['0.25', 0.25], ['0.75', 0.75], ['not a number', 0.5], ['0.9', 0.5], ['0.1', 0.5], ['', 0.5], [null, 0.5]]) {
			if (raw === null) {
				store.delete('pages-split-ratio')
			} else {
				store.set('pages-split-ratio', raw)
			}
			const restored = await mountedRatio()
			if (restored !== expected) {
				problems.push(`a stored value of ${JSON.stringify(raw)} restores ${restored}, expected ${expected}`)
			}
		}
		readThrows = true
		const refused = await mountedRatio()
		readThrows = false
		if (refused !== 0.5) {
			problems.push(`a storage that refuses to answer (private mode) restores ${refused}, expected 0.5`)
		}

		// a storage that refuses the write must not throw out of the drag either
		writeThrows = true
		const frozen = state()
		let threw = false
		try {
			frozen.setSplitRatio(0.4)
		} catch (error) {
			threw = true
		}
		writeThrows = false
		if (threw || frozen.splitRatio !== 0.4) {
			problems.push(`setSplitRatio with a refusing storage ${threw ? 'threw out of the drag' : 'did not apply the value'}`)
		}
	} finally {
		if (previousWindow === undefined) {
			delete globalThis.window
		} else {
			globalThis.window = previousWindow
		}
	}

	if (problems.length) {
		note(`src/App.vue: the split's share is not clamped and kept: ${problems.join('; ')}`)
	}
	process.stdout.write(`  split     App.vue: 4 clamp/store case(s), 5 key(s), 8 stored value(s), private mode, no throw\n`)
}

/**
 * 4e. The split's stylesheet, which the SSR render cannot see.
 *
 * The editor's share has to be the one variable the handle overwrites (`--pages-split-editor`); a
 * percentage hard-coded in the CSS would make the drag invisible. The divider line moved from the
 * preview pane's border to the handle, so leaving that border in place would draw a second line
 * next to the first. And with the preview closed nothing divides the split: the editor takes the
 * whole width.
 */
function checkSplitLayout() {
	const source = readFileSync(resolve(root, 'src/App.vue'), 'utf8')
	const needed = [
		['--pages-split-editor: 50%', 'the split has no default editor share'],
		['flex: 0 0 var(--pages-split-editor)', 'the editor pane does not read the share variable'],
		['border-inline-start: 0', 'the preview pane still draws the divider as its own border'],
		['.pages-split__handle', 'the divider handle has no rule'],
		['cursor: col-resize', 'the handle does not show the resize cursor'],
		['touch-action: none', 'a touch drag is taken by the page instead of the handle'],
		['.pages-split--resizing', 'the dragging state has no rule'],
		['user-select: none', 'a drag selects text'],
		['.pages-split--solo .pages-pane--editor', 'the solo split does not give the editor the whole width'],
		['flex: 1 1 100%', 'the solo split has no full-width pane'],
	]
	const missing = needed.filter(([needle]) => !source.includes(needle))
	if (missing.length) {
		note(`src/App.vue: the split's layout is incomplete: ${missing.map(([, why]) => why).join('; ')}`)
	}
	process.stdout.write(`  layout    App.vue split: ${needed.length - missing.length}/${needed.length} rule(s) present\n`)
}

const components = [
	'src/App.vue',
	'src/Requests.vue',
	'src/Manage.vue',
	'src/ArticleRows.vue',
	'src/Read.vue',
	'src/ArticleFields.vue',
	'src/CommentThread.vue',
	'src/VoteWidget.vue',
	'src/GrantPicker.vue',
	'src/SettingsUI.vue',
]

checkPublishBanners()
process.stdout.write('reject messages (a rejection names the request it answered):\n')
await checkRejectMessages()
process.stdout.write('actions (the snapshot model: both pending kinds are decided, the take-down is the manage view\'s):\n')
await checkActionRules()
process.stdout.write('delete (the mode travels in the query; only an online entry asks the three-way question):\n')
await checkDeleteFileMode()
await checkDeleteDialog()
await checkManageTakeDown()
await checkToastDispatch()
process.stdout.write('unsaved (the discard question follows the content, not the editor flag):\n')
await checkEditorDirty()
process.stdout.write('split (the divider between the editor and the preview, its share kept):\n')
await checkSplitRatio()
checkSplitLayout()
process.stdout.write('placement (every read is a value, every call a method):\n')
await checkPlacement(components)
process.stdout.write('render (compiled SFC + esbuild + Vue SSR mount):\n')
await renderCases()
process.stdout.write('editor header (Save and Preview next to the text they act on):\n')
await checkEditorPaneHeader()
await checkEditorPaneHeaderWithoutFile()
process.stdout.write('rejection (the note of the open article and its dismissal):\n')
await checkRejectNote()
process.stdout.write('path tracking (the site preview address follows the frame):\n')
await checkReadPathTracking()
process.stdout.write('address (the site preview can be pointed anywhere inside the site):\n')
await checkReadAddress()
checkReadRebuildRemoved()
checkBadgeLayout()
await checkEntryStateLabels()
await checkRequestsRefresh()
process.stdout.write('cells (every ArticleRows row renders one cell per grid column):\n')
await checkRowCellAlignment()
process.stdout.write('logic (the settings page\'s write-through and auto-save rules):\n')
await checkSettingsLogic()
await checkDialogButtons()
process.stdout.write('settings (four blocks, danger zone, no save button, lock rendering):\n')
checkSettingsPage()

if (failures.length === 0) {
	process.stdout.write('\nproof passed\n')
	process.exit(0)
}
process.stdout.write(`\n${failures.length} violation(s):\n`)
for (const failure of failures) {
	process.stdout.write(`  - ${failure}\n`)
}
process.exit(1)
