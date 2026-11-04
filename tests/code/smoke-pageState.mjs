/**
 * Node-level proof of the front-end capability logic that changed with the permission model.
 *
 * The review queue and the publish button are gated on the shell state, and the shell state is plain
 * data: `pageState.js` is free of Vue and of the network on purpose, so the decisions can be run here
 * without a browser. This script exercises the two functions the shell still owns - `reviewGrant` (the
 * review queue and, since the merge, publishing: one permission 审核与发布) and `readShellState` (the
 * state itself) - against the shapes the server hands over. `resolvePage` left with the second page.
 *
 *   node tests/code/smoke-pageState.mjs
 */
import assert from 'node:assert/strict'
import { readShellState, reviewGrant } from '../../src/pageState.js'

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

// ---- readShellState: the one page's state -------------------------------

// The state element is the server's own JSON; an unreadable or missing one must never crash the app.
const fake = (text) => ({ getElementById: () => (text === null ? null : { textContent: text }) })
assert.deepEqual(readShellState(fake('{"canAuthor":true,"canReview":false}')), { canAuthor: true, canReview: false })
assert.deepEqual(readShellState(fake(null)), {}, 'a missing element is an empty state')
assert.deepEqual(readShellState(fake('not json')), {}, 'unreadable JSON is an empty state')
assert.deepEqual(readShellState(fake('[1,2]')), {}, 'a JSON array is not a state object')
assert.deepEqual(readShellState(null), {}, 'no document is an empty state')

// the page key is gone: the one page does not need to be told which page it is
assert.equal('page' in readShellState(fake('{"uid":"alice"}')), false)

console.log('pageState: reviewGrant / readShellState OK')
