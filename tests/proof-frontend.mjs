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
 *   node tests/proof-frontend.mjs
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

const root = resolve(dirname(fileURLToPath(import.meta.url)), '..')
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
		if (id === '@nextcloud/axios') {
			const axios = { get: () => Promise.resolve({ status: 200, data: {} }), post: () => Promise.resolve({ status: 200, data: {} }), put: () => Promise.resolve({ status: 200, data: {} }), delete: () => Promise.resolve({ status: 200, data: {} }) }

			return { __esModule: true, default: axios }
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
 * One SFC as the component object the runtime would see: compiled by @vue/compiler-sfc, bundled by
 * esbuild, loaded with the stub `require` above.
 */
async function loadComponent(relative, { stubEditor = true } = {}) {
	const file = resolve(root, relative)
	const entry = `
import component from ${JSON.stringify(file)}
export default component
`
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
	return module.exports.default
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
	return {
		page: 'content',
		uid: 'alice',
		site: { display: 'Proof site' },
		canAuthor: true,
		canEdit: false,
		canPublish: false,
		canReview: false,
		isAdmin: false,
		contentUrl: '/index.php/apps/pages/',
		sourceUrl: '/index.php/apps/pages/source',
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
						treeNode({ path: 'notes/requested.md', target: '/tmp/alice/notes/requested.md', name: 'requested.md', state: 'requested', intent: 'online' }),
						treeNode({ path: 'notes/external.md', target: '/tmp/alice/notes/external.md', name: 'external.md', state: 'external', live: true }),
					],
					currentFile: '/tmp/alice/notes/requested.md',
					targetPrefix: '/tmp/alice',
					view: 'site',
				}
			},
		},
		props: { shell: appShell },
		expect: ['Internal', 'Waiting for review', 'External', 'Request online', 'Withdraw the request', 'Request offline'],
	})

	// the same page as a reviewer: the decision buttons must exist for somebody else's entry
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
						treeNode({ path: 'notes/bob.md', target: '/tmp/alice/notes/bob.md', name: 'bob.md', state: 'requested', intent: 'offline', owner: 'bob' }),
					],
					currentFile: '',
					targetPrefix: '/tmp/alice',
				}
			},
		},
		props: { shell: shellState({ canReview: true }) },
		expect: ['Approve', 'Reject', 'Take off the site'],
	})

	// ... and no decision button for the reviewer's own request (nobody decides about their own)
	const OwnRequestApp = await loadComponent('src/App.vue')
	cases.push({
		name: 'App.vue (reviewer, own request: no decision buttons)',
		component: {
			...OwnRequestApp,
			data() {
				const base = OwnRequestApp.data.call(this)
				return {
					...base,
					nodes: [
						treeNode({ path: 'notes/mine.md', target: '/tmp/alice/notes/mine.md', name: 'mine.md', state: 'requested', intent: 'online', owner: 'alice' }),
					],
					currentFile: '/tmp/alice/notes/mine.md',
					targetPrefix: '/tmp/alice',
				}
			},
		},
		props: { shell: shellState({ canReview: true }) },
		expect: ['Withdraw the request'],
		absent: ['Approve', 'Reject'],
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

	// the request panel carries a publish action too, but only as an event: no endpoint call of its own
	const RequestsPublish = await loadComponent('src/Requests.vue')
	cases.push({
		name: 'Requests.vue (publish action next to the batch buttons)',
		component: RequestsPublish,
		props: { targetPrefix: '/tmp/alice' },
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

	// the request panel with one request of each direction
	const Requests = await loadComponent('src/Requests.vue')
	cases.push({
		name: 'Requests.vue (one online and one offline request)',
		component: {
			...Requests,
			data() {
				return {
					...Requests.data.call(this),
					articles: [
						{ path: '/tmp/alice/a.md', title: 'A', author: 'alice', owner: 'alice', state: 'requested', intent: 'online', submittedAt: 1700000000, reviewNote: '', live: false },
						{ path: '/tmp/alice/b.md', title: 'B', author: 'alice', owner: 'alice', state: 'requested', intent: 'offline', submittedAt: 1700000000, reviewNote: 'not yet', live: true },
					],
				}
			},
		},
		props: { targetPrefix: '/tmp/alice' },
		expect: ['Requests to go live', 'Online requested on', 'Offline requested on', 'Approve', 'Reject', 'Approve all (2)'],
	})

	// the shared table with one row per state
	const Rows = await loadComponent('src/ArticleRows.vue')
	cases.push({
		name: 'ArticleRows.vue (three badges)',
		component: Rows,
		props: {
			rows: [
				{ path: '/a.md', title: 'A', state: 'internal', live: false },
				{ path: '/b.md', title: 'B', state: 'requested', intent: 'online', submittedAt: 1700000000, live: false },
				{ path: '/c.md', title: 'C', state: 'external', live: true },
			],
			showLive: true,
			showWaiting: true,
			showReviewNote: true,
		},
		expect: ['Internal', 'Waiting for review', 'External', 'Not online', 'Online', 'Online requested on'],
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
						{ path: '/b.md', title: 'B', state: 'requested', intent: 'online', up: 0, down: 0, comments: 0 },
						{ path: '/c.md', title: 'C', state: 'external', up: 3, down: 1, comments: 4 },
					],
				}
			},
		},
		props: {},
		expect: ['Internal', 'Waiting for review', 'External', '↑ 1', '💬 2', 'All states'],
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
 * 3. The admin settings page: it is a PHP template, so its control count is checked where it is written
 *    (the plan's target is "≤ 9 field controls", and the absence of every removed key). A "field
 *    control" is what a browser renders as an editable input: a text/number/password-style `<input>` or
 *    a `<select>`. Hidden inputs (the grant values the pickers write into) and anything inside an inert
 *    `<template>` (the §14 migration choice, materialised only while a migration is pending) are not
 *    controls - a browser shows neither.
 */
function checkSettingsPage() {
	const file = 'templates/settings/admin.php'
	const source = readFileSync(resolve(root, file), 'utf8')
	// comments are stripped first: this file documents every removed key, and prose is not a control
	const markup = source
		.replace(/<\?php[\s\S]*?\?>/g, (block) => block.replace(/\/\*[\s\S]*?\*\//g, '').replace(/^\s*\/\/.*$/gm, ''))
		.replace(/<!--[\s\S]*?-->/g, '')
	// the inert §14 template is not part of the rendered page
	const live = markup.replace(/<template[\s\S]*?<\/template>/g, '')
	const controls = [...live.matchAll(/<input\b[^>]*>/g)]
		.map((match) => match[0])
		.filter((tag) => !/type="hidden"/.test(tag))
	const selects = (live.match(/<select\b/g) ?? []).length
	const total = controls.length + selects
	if (total > 9) {
		note(`${file}: ${total} field controls rendered (limit 9): ${controls.map((tag) => (tag.match(/id="([^"]+)"/) ?? [])[1] ?? '?').join(', ')}`)
	}
	process.stdout.write(`  settings  ${file}: ${total} field control(s) (limit 9)\n`)

	// every key the plan removed must really be gone from the markup
	const removed = [
		'pages-shared-secret', 'pages-signing-secret', 'pages-site-exclude', 'pages-site-output',
		'pages-site-command', 'pages-site-install', 'pages-cmd', 'pages-site-advanced',
		// the 16 global bridge parameters (they live in the bridge TOML and `occ pages:config` now)
		'pages-bridge-nc-data-root', 'pages-bridge-work-root', 'pages-bridge-state-dir',
		'pages-bridge-override-dir', 'pages-bridge-log-dir', 'pages-bridge-build-timeout-s',
		'pages-bridge-build-debounce-ms', 'pages-bridge-log-tail-lines', 'pages-bridge-mirror-verify',
		'pages-bridge-mirror-delete-guard-ratio', 'pages-bridge-mirror-delete-guard-min',
		'pages-bridge-mirror-preserve-exec', 'pages-bridge-mirror-use-gitignore',
		'pages-bridge-exclude', 'pages-bridge-unexclude', 'pages-bridge-listen',
	]
	const stillThere = removed.filter((id) => live.includes(`id="${id}"`))
	if (stillThere.length) {
		note(`${file}: removed control(s) still rendered: ${stillThere.join(', ')}`)
	}
	process.stdout.write(`  settings  ${removed.length} removed key(s) absent\n`)

	// the two pickers are hidden inputs (the Vue pickers write into them), not controls. The template
	// renders the id through a loop, so the anchors are asserted by the grant table it loops over.
	for (const id of ['pages-author-groups', 'pages-review-groups']) {
		if (!source.includes(`'${id}'`)) {
			note(`${file}: the grant anchor "${id}" is missing (the pickers would start without a value)`)
		}
	}
	if (!/type="hidden"[^>]*class="pages-grant-value"|class="pages-grant-value"[^>]*type="hidden"/.test(live)) {
		note(`${file}: the hidden input the grant anchors live in is missing`)
	}
}

const components = [
	'src/App.vue',
	'src/Requests.vue',
	'src/ArticleRows.vue',
	'src/Read.vue',
	'src/ArticleFields.vue',
	'src/CommentThread.vue',
	'src/VoteWidget.vue',
	'src/GrantPicker.vue',
]

process.stdout.write('placement (every read is a value, every call a method):\n')
await checkPlacement(components)
process.stdout.write('render (compiled SFC + esbuild + Vue SSR mount):\n')
await renderCases()
process.stdout.write('settings (the admin page keeps 9 controls and drops the rest):\n')
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
