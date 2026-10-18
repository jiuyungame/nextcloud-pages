/**
 * Node-level proof of the front-end capability logic that changed with the permission model.
 *
 * The two pages, the review queue and the publish button are gated on the shell state, and the shell
 * state is plain data: `pageState.js` is free of Vue and of the network on purpose, so the decisions
 * can be run here without a browser. This script exercises the two functions the change touches —
 * `reviewGrant` (the review queue and, since the merge, publishing: one permission 审核与发布) and
 * `resolvePage` (which page a load is) — against the shapes the server hands over.
 *
 *   node tests/smoke-pageState.mjs
 */
import assert from 'node:assert/strict'
import { reviewGrant, resolvePage, CONTENT_PAGE, SOURCE_PAGE } from '../src/pageState.js'

// ---- reviewGrant: the one 审核与发布 grant --------------------------------

// The server reports `review` and `publish` from the same group list now, so this shape is the normal
// one: a reviewer may decide **and** publish. The function answers the review flag itself.
assert.equal(reviewGrant({ canReview: true, canPublish: true }), true,
	'a reviewer may decide and publish (one grant)')
assert.equal(reviewGrant({ canReview: false, canPublish: false }), false,
	'no grant, no queue and no publish button')

// The two keys disagreeing can no longer come from the server (they are computed from one list), and
// the rule is kept anyway: an explicit `canReview` wins, so a grant taken away while a cached shell is
// open is honoured instead of being re-invented from the other key.
assert.equal(reviewGrant({ canReview: true, canPublish: false }), true,
	'an explicit review grant still opens the queue')
assert.equal(reviewGrant({ canReview: false, canPublish: true }), false,
	'an explicit refusal is trusted, even next to a stale publish key')

// a shell rendered before the grant was split carries no canReview: its canPublish was the gate
assert.equal(reviewGrant({ canPublish: true }), true,
	'a cached shell without canReview must fall back to canPublish')
assert.equal(reviewGrant({ canPublish: false }), false,
	'the fallback must not invent a grant')

// and it always answers a boolean, never undefined (a control mounted on undefined is the crash shape)
for (const state of [undefined, null, {}, { canReview: undefined }, { canPublish: 'yes' }, { canReview: 1 }]) {
	assert.equal(typeof reviewGrant(state), 'boolean', 'reviewGrant must always answer a boolean')
}
assert.equal(reviewGrant(undefined), false, 'no state means no grant')
assert.equal(reviewGrant({ canReview: 1, canPublish: true }), true,
	'a non-boolean canReview is not trusted, the published fallback decides')

// ---- resolvePage: the source page is the same grant -----------------------

assert.equal(resolvePage({ page: 'content', canReview: true, canPublish: true }), CONTENT_PAGE,
	'a reviewer starts on the content page, where the publish button is')
assert.equal(resolvePage({ page: 'source', canReview: true, canPublish: true }), SOURCE_PAGE,
	'the review grant is the publishing grant: a reviewer opens the source page')
assert.equal(resolvePage({ page: 'source', canPublish: true }), SOURCE_PAGE,
	'the publish name still opens the source page (the shell sets both names)')
assert.equal(resolvePage({ page: 'source', canEdit: true }), SOURCE_PAGE,
	'the edit name is the same grant')
// the server never renders /source for a caller without the grant (it redirects), so this is the
// defensive case: a shell claiming the page without a capability falls back to the content page
assert.equal(resolvePage({ page: 'source', canReview: true }), CONTENT_PAGE,
	'the name that gates the page is canEdit/canPublish; a shell without either stays on content')
assert.equal(resolvePage({ page: 'source' }), CONTENT_PAGE,
	'no grant means the content page')

console.log('pageState: reviewGrant / resolvePage OK')
