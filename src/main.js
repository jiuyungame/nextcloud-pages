/**
 * Pages frontend entry point. Loaded as a classic script by Util::addScript, so the
 * build output must be an IIFE (see lib.formats in vite.config.js).
 */
import { createApp } from 'vue'
import { t } from './i18n.js'
import App from './App.vue'
import { readShellState } from './pageState.js'
import './global.css'

// Lets the boot script (js/pages-boot.js) tell "bundle never ran" from "bundle ran but mount failed"
window.__pagesMainStarted = true

const host = document.getElementById('pages-app')
if (host) {
	try {
		/*
		 * The shell state is read here, once, and handed to the root component as a prop: the page
		 * (`content` or `source`) and the two URLs are part of it, and reading the element a second
		 * time inside the component would let the two reads drift apart. App.vue falls back to the
		 * element itself when it is mounted without the prop.
		 */
		createApp(App, { shell: readShellState() }).mount(host)
	} catch (error) {
		window.__pagesBootErrors.push(t('pages', 'Mount failed:') + (error && error.stack ? error.stack : String(error)))
		throw error
	}
} else {
	window.__pagesBootErrors.push(t('pages', 'Mount point #pages-app not found'))
}
