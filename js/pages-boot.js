/**
 * Boot script — MUST load before pages-main.js (PageController adds them in that order).
 *
 * Its only job: when the frontend fails to start, the page would otherwise sit on
 * "Loading…" forever, with the real error only in a browser console we cannot see. So it
 *   1. registers the error handlers *before* the main bundle runs (a module body that
 *      throws cannot be caught if you register later), and
 *   2. a few seconds later, renders the captured errors plus the resources the page
 *      actually loaded straight into the page.
 *
 * Hand-written file: not part of the Vite build, lives directly in js/. It deliberately
 * depends on nothing (no l10n, no framework) because it has to work when everything else failed.
 */
(function () {
	'use strict'

	var errors = []

	window.addEventListener('error', function (event) {
		var where = event.filename ? ' @ ' + event.filename + ':' + event.lineno : ''
		errors.push((event.message || 'unknown error') + where)
	}, true)

	window.addEventListener('unhandledrejection', function (event) {
		var reason = event.reason
		errors.push('unhandled promise rejection: ' + ((reason && reason.message) || String(reason)))
	})

	window.__pagesBootErrors = errors

	function escapeHtml(text) {
		return String(text).replace(/[&<>"']/g, function (ch) {
			return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[ch]
		})
	}

	function report() {
		var host = document.getElementById('pages-app')
		// Already mounted: Vue replaced the placeholder, nothing to report.
		if (!host || !host.querySelector('.pages-loading')) {
			return
		}

		var scripts = Array.prototype.map.call(
			document.querySelectorAll('script[src]'),
			function (s) { return s.getAttribute('src') },
		)
		var styles = Array.prototype.map.call(
			document.querySelectorAll('link[rel="stylesheet"]'),
			function (l) { return l.getAttribute('href') },
		)

		var lines = []
		lines.push('The Pages frontend did not start. The information below can be handed to a developer:')
		lines.push('')
		lines.push('Main bundle executed: ' + (window.__pagesMainStarted ? 'yes (but mounting failed)' : 'no (script never ran, or failed to load)'))
		lines.push('Captured errors (' + errors.length + '):')
		if (errors.length === 0) {
			lines.push('  (no JS error captured — the script most likely never downloaded)')
		} else {
			errors.forEach(function (item, index) { lines.push('  ' + (index + 1) + '. ' + item) })
		}
		lines.push('')
		lines.push('Scripts loaded by the page:')
		scripts.forEach(function (src) { lines.push('  ' + src) })
		lines.push('')
		lines.push('Stylesheets loaded by the page:')
		styles.forEach(function (href) { lines.push('  ' + href) })
		lines.push('')
		lines.push('CSP: ' + (document.querySelector('meta[http-equiv="Content-Security-Policy"]') || {}).content)

		host.innerHTML = '<div class="pages-fatal"><pre>' + escapeHtml(lines.join('\n')) + '</pre></div>'
	}

	// 4s is enough for a slow machine to download and execute the bundle
	window.setTimeout(report, 4000)
})()
