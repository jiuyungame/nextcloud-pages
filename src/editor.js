/**
 * CodeMirror 6 wrapper, deliberately a standalone module: it only exposes
 * `{ requestMeasure, setDoc, getDoc, setPath, setReadOnly, focus, destroy }`.
 */
import { basicSetup } from 'codemirror'
import { EditorView, keymap } from '@codemirror/view'
import { EditorState, Compartment } from '@codemirror/state'
import { indentWithTab } from '@codemirror/commands'
import { markdown } from '@codemirror/lang-markdown'
import { yaml } from '@codemirror/lang-yaml'
import { html } from '@codemirror/lang-html'
import { css as cssLanguage } from '@codemirror/lang-css'
import { javascript } from '@codemirror/lang-javascript'

/** Pick the language by extension; Markdown is the fallback, it is the most common in a site. */
function languageFor(path) {
	const ext = String(path || '').toLowerCase().split('.').pop()
	switch (ext) {
		case 'md':
		case 'markdown':
			return markdown()
		case 'yml':
		case 'yaml':
			return yaml()
		case 'html':
		case 'htm':
		case 'liquid':
			return html()
		case 'css':
		case 'scss':
		case 'sass':
			return cssLanguage()
		case 'js':
		case 'mjs':
		case 'cjs':
		case 'json':
			return javascript()
		default:
			return markdown()
	}
}

/**
 * Line endings: always saved as LF. CodeMirror joins lines with "\n", so preserving CRLF
 * would rewrite whole files on save and make git diffs unreadable.
 *
 * @param {object} options
 * @param {HTMLElement} options.parent mount point
 * @param {string} options.doc initial content
 * @param {(value: string) => void} options.onChange
 * @param {() => void} options.onSave Ctrl/Cmd+S callback
 */
export function createEditor({ parent, doc = '', onChange, onSave }) {
	const language = new Compartment()
	const readOnly = new Compartment()

	const state = EditorState.create({
		doc,
		extensions: [
			/*
			 * `basicSetup` is an extension array, not a configurable object: it has no `.configure()`,
			 * and calling one threw while the state was being created, which left the editor pane
			 * empty. The line numbers are hidden in CSS instead (a `.cm-gutters` rule in App.vue).
			 */
			basicSetup,
			keymap.of([
				indentWithTab,
				{
					key: 'Mod-s',
					preventDefault: true,
					run: () => {
						onSave?.()
						return true
					},
				},
			]),
			language.of([]),
			readOnly.of([]),
			EditorView.updateListener.of((update) => {
				if (update.docChanged) {
					onChange?.(update.state.doc.toString())
				}
			}),
			EditorView.theme({
				'&': { height: '100%', fontSize: '13px' },
				'.cm-scroller': {
					fontFamily: 'var(--font-face-monospace, ui-monospace, SFMono-Regular, Menlo, monospace)',
					lineHeight: '1.6',
				},
				'.cm-content': { padding: '8px 0' },
				'&.cm-focused': { outline: 'none' },
			}),
		],
	})

	const view = new EditorView({ state, parent })

	let currentPath = ''

	return {
		/**
		 * Ask CodeMirror to measure itself again. The editor may have been created inside a hidden
		 * container (that is how a user without the edit grant gets one at all), and a view that
		 * measured a zero-sized box renders nothing until it is told to look again.
		 */
		requestMeasure() {
			view.requestMeasure()
		},
		setDoc(value) {
			view.dispatch({
				changes: { from: 0, to: view.state.doc.length, insert: value ?? '' },
			})
		},
		getDoc() {
			return view.state.doc.toString()
		},
		setPath(path) {
			if (path === currentPath) {
				return
			}
			currentPath = path
			view.dispatch({
				effects: language.reconfigure(languageFor(path)),
			})
		},
		setReadOnly(value) {
			view.dispatch({
				effects: readOnly.reconfigure(value ? EditorState.readOnly.of(true) : []),
			})
		},
		focus() {
			view.focus()
		},
		destroy() {
			view.destroy()
		},
	}
}

export default createEditor
