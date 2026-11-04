<!--
	Pages main UI. One page (see pageState.js and appinfo/routes.php), with four views in the
	content area:

	  - **edit** (`view === 'site'`): the caller's own file tree in the navigation column, the
	    structured article editor, the resource pane for a file that is not a page, the article
	    preview and the flat list of the caller's own articles while nothing is open. Every
	    signed-in user gets it, and its navigation column is the author's own work — never the
	    site's skeleton.
	  - **requests** (`view === 'requests'`): the review queue, mounted only for the review grant.
	  - **manage** (`view === 'manage'`): every article the site is serving, whoever wrote it, with
	    the one action a reviewer takes there — taking a page off the site. Mounted for the same
	    grant as the request panel, because the take-down is a decision about the site.
	  - **read** (`view === 'read'`): the reading list and the site preview, open to every user.

	The editor host exists once, outside the view switch (`v-show`, not `v-if`), so switching views
	never creates a second CodeMirror instance and a half-written page keeps its text.

	NcContent > NcAppNavigation and NcAppContent: hand-rolled divs do not work here, the Nextcloud
	layout CSS depends on this component structure.
-->
<template>
	<NcContent app-name="pages">
		<!-- ============ the four views: edit / requests / manage / read ============ -->
		<NcAppNavigation ref="nav" :aria-label="t('pages', 'Files')">
			<template #list>
				<!--
					The caller's own file tree, and nothing else. Every operation on an author's area
					happens here: new file, new folder, rename (inline), delete and opening a file.
					The nodes come from the tree endpoint's author scope and carry their site target,
					so a row is addressed by what the server already calls the file.
				-->
				<template v-if="canWrite">
					<NcAppNavigationCaption :name="t('pages', 'Files')" />

					<li class="pages-nav-new">
						<NcButton
							variant="secondary"
							:wide="true"
							:disabled="!siteReady || loadingTree"
							@click="openCreate('file', '')">
							<template #icon><Plus :size="20" /></template>
							{{ t('pages', 'New file') }}
						</NcButton>
					</li>
					<!--
						A folder can be made from the root too. Before this the only "New folder" sat in a
						node's action menu, so an author area with no nodes offered no way to create the
						first folder at all.
					-->
					<li class="pages-nav-new">
						<NcButton
							variant="tertiary"
							:wide="true"
							:disabled="!siteReady || loadingTree"
							@click="openCreate('folder', '')">
							<template #icon><FolderPlus :size="20" /></template>
							{{ t('pages', 'New folder') }}
						</NcButton>
					</li>

					<!--
						Whole-tree visibility actions. A navigation tree cannot take per-row checkboxes without
						becoming a list, so the batch is "every entry in this state" — and it always asks first.
					-->
					<!--
						Plain buttons, not an NcActions menu: the batch is two named actions with their counts,
						and a hand-rolled popover here needed its own anchoring — which is exactly the kind of
						menu that flashes in the corner before it lands. A button cannot.
					-->
					<li v-if="internalCount > 0" class="pages-nav-new">
						<NcButton
							variant="tertiary"
							:wide="true"
							:disabled="!siteReady || loadingTree || batchBusy"
							@click="startBatch('online')">
							<template #icon><Upload :size="20" /></template>
							{{ t('pages', 'Request online for all internal pages (%s)', [String(internalCount)]) }}
						</NcButton>
					</li>
					<li v-if="externalCount > 0" class="pages-nav-new">
						<NcButton
							variant="tertiary"
							:wide="true"
							:disabled="!siteReady || loadingTree || batchBusy"
							@click="startBatch('offline')">
							<template #icon><EyeOff :size="20" /></template>
							{{ t('pages', 'Request offline for all external pages (%s)', [String(externalCount)]) }}
						</NcButton>
					</li>

					<NcAppNavigationItem
						v-for="node in visibleNodes"
						:key="node.path"
						:ref="(el) => setNodeRef(node.path, el)"
						class="pages-node"
						:name="node.name"
						:active="nodeTarget(node) === currentFile"
						:force-display-actions="true"
						:icon="nodeIcon(node)"
						:edit-placeholder="node.name"
						:style="{ paddingInlineStart: (8 + node.depth * 12) + 'px' }"
						@click.prevent.stop="onNodeClick(node)"
						@update:name="(name) => onRename(node, name)"
						@update:menu-open="(open) => onMenuToggle(node, open)">
						<!-- the visibility state of a page: 内部 / 待审核 / 外部 -->
						<template #counter>
							<span class="pages-node__marks">
								<span v-if="badgeOf(node)" class="pages-badge" :class="'pages-badge--' + badgeKind(node)">{{ badgeOf(node) }}</span>
								<!--
									The last save of this entry did not reach the bridge. The mark is a button on
									purpose: clicking it repeats exactly the push the save would have made, and it
									disappears when that push is accepted. It is browser state, not a stored flag:
									the durable record of the last failure is `occ pages:doctor`'s row.
								-->
								<button
									v-if="pushFailedFor(node)"
									type="button"
									class="pages-node__push"
									:disabled="retryingPush"
									:title="pushFailureHint(node)"
									@click.stop.prevent="retryPush(node)">
									{{ t('pages', 'not pushed · retry') }}
								</button>
							</span>
						</template>

						<template #actions>
							<NcActionButton @click="openCreate('file', targetDirFor(node))">
								<template #icon><FilePlus :size="20" /></template>
								{{ t('pages', 'New file') }}
							</NcActionButton>
							<NcActionButton @click="openCreate('folder', targetDirFor(node))">
								<template #icon><FolderPlus :size="20" /></template>
								{{ t('pages', 'New folder') }}
							</NcActionButton>

							<NcActionSeparator v-if="actionsFor(node).length > 0" />
							<!-- one group for the visibility actions, apart from creating and renaming -->

							<NcActionButton v-if="actionsFor(node).includes('requestOnline')" @click="runAction('requestOnline', nodeTarget(node))">
								<template #icon><Upload :size="20" /></template>
								{{ requestOnlineLabel(node) }}
							</NcActionButton>
							<NcActionButton v-if="actionsFor(node).includes('requestOffline')" @click="runAction('requestOffline', nodeTarget(node))">
								<template #icon><EyeOff :size="20" /></template>
								{{ t('pages', 'Request offline') }}
							</NcActionButton>
							<NcActionButton v-if="actionsFor(node).includes('cancel')" @click="runAction('cancel', nodeTarget(node))">
								<template #icon><ArrowLeft :size="20" /></template>
								{{ t('pages', 'Withdraw the request') }}
							</NcActionButton>
							<NcActionButton v-if="actionsFor(node).includes('approve')" @click="runAction('approve', nodeTarget(node))">
								<template #icon><Check :size="20" /></template>
								{{ t('pages', 'Approve') }}
							</NcActionButton>
							<NcActionButton v-if="actionsFor(node).includes('reject')" @click="runAction('reject', nodeTarget(node))">
								<template #icon><Undo :size="20" /></template>
								{{ t('pages', 'Reject') }}
							</NcActionButton>

							<NcActionSeparator />

							<NcActionButton @click="startRename(node)">
								<template #icon><Pencil :size="20" /></template>
								{{ t('pages', 'Rename') }}
							</NcActionButton>
							<NcActionButton @click="deleteNode(node)">
								<template #icon><Delete :size="20" /></template>
								{{ node.type === 'dir' ? t('pages', 'Delete folder') : t('pages', 'Delete file') }}
							</NcActionButton>

							<NcActionSeparator />

							<NcActionLink :href="filesAppUrl(node)" target="_blank" rel="noreferrer noopener">
								<template #icon><OpenInNew :size="20" /></template>
								{{ t('pages', 'Open in Files') }}
							</NcActionLink>
						</template>
					</NcAppNavigationItem>

					<!-- A failed load is said out loud: an empty column would read as "no files". -->
					<li v-if="authorError" class="pages-nav-error">{{ authorError }}</li>
					<NcAppNavigationItem
						v-else-if="!visibleNodes.length && !loadingTree"
						:name="t('pages', '(no files yet)')"
						:icon="iconFile" />
				</template>
			</template>

			<template #footer>
				<ul class="app-navigation-entry__settings">
					<NcAppNavigationItem
						v-if="isAdmin"
						:name="t('pages', 'Admin settings')"
						:href="settingsUrl"
						target="_blank">
						<template #icon><Cog :size="20" /></template>
					</NcAppNavigationItem>
				</ul>
			</template>
		</NcAppNavigation>

		<!-- NcAppContent also requires appName, not just NcContent. -->
		<NcAppContent
			app-name="pages"
			:page-heading="site?.display || t('pages', 'Pages')">
			<!-- Own flex column: NcAppContent's .app-content-wrapper is a plain block container. -->
			<div class="pages-root">
				<div class="pages-toolbar">
					<!--
						Which of the four views the content area shows. The nav and the toolbar stay in
						place, so switching is not a navigation: a half-written page keeps its editor.
					-->
					<div class="pages-viewswitch" role="group" :aria-label="t('pages', 'View')">
						<NcButton :variant="view === 'site' ? 'primary' : 'tertiary'" @click="view = 'site'">
							{{ t('pages', 'Edit') }}
						</NcButton>
						<NcButton v-if="showRequests" :variant="view === 'requests' ? 'primary' : 'tertiary'" @click="view = 'requests'">
							{{ t('pages', 'Requests to go live') }}
						</NcButton>
						<!-- Reading is open to every signed-in user, so this entry is never gated. -->
						<NcButton :variant="view === 'read' ? 'primary' : 'tertiary'" @click="view = 'read'">
							{{ t('pages', 'Read') }}
						</NcButton>
						<!-- The review surface that acts on the whole site: same grant as the request panel. -->
						<NcButton v-if="showManage" :variant="view === 'manage' ? 'primary' : 'tertiary'" @click="view = 'manage'">
							{{ t('pages', 'Manage') }}
						</NcButton>
					</div>

					<!--
						Publish is always offered to whoever holds the review-and-publish grant: a site
						without a remote is published too, because a publish is the commit. Only the
						confirmation says whether a push will happen.
					-->
					<NcButton
						v-if="canPublish"
						variant="primary"
						:disabled="publishing || !siteReady"
						@click="askPublish">
						<template #icon>
							<NcLoadingIcon v-if="publishing" :size="20" />
							<Upload v-else :size="20" />
						</template>
						{{ t('pages', 'Publish') }}
					</NcButton>

					<span class="pages-toolbar__spacer" />
				</div>

				<RequestsView
					v-if="view === 'requests' && requestsAllowed"
					@forbidden="onRequestsForbidden"
					@publish="askPublish"
					@decided="onRequestDecided" />

				<ReadView
					v-if="view === 'read'"
					:can-moderate="canReview"
					:preview-base="previewBase"
					:show-banner="showBanner" />

				<!--
					The whole site's online articles, whoever wrote them: the one place a reviewer takes
					a page off the site. `v-if` like the other switches - the editor host below is the
					only pane that survives a trip through the views.
				-->
				<ManageView
					v-if="view === 'manage' && showManage"
					:show-banner="showBanner"
					@forbidden="onRequestsForbidden"
					@decided="onRequestDecided" />

				<!-- `v-show`, not `v-if`: the editor host has to survive a trip through the other
				     views, otherwise the CodeMirror instance stays attached to a removed node -->
				<div
					v-show="view === 'site'"
					ref="splitEl"
					class="pages-split"
					:class="{ 'pages-split--solo': !canPreviewArticle || !previewVisible, 'pages-split--resizing': splitResizing }"
					:style="{ '--pages-split-editor': (splitRatio * 100) + '%' }">
					<div class="pages-pane pages-pane--editor">
						<!--
							The row below belongs to the open file: it names the entry, offers Back and
							carries that one entry's actions. In the list view nothing is open, so every
							one of those controls is gone and only the spacer (plus Save) would be left
							in a padded, bordered row that says nothing at all. No file, no row.
						-->
						<div v-if="currentFile" class="pages-pane__head">
							<!--
								Back closes the open entry and returns the pane to the flat list of everything
								this author owns. With unsaved edits it asks first — a click must never throw
								away typed text.
							-->
							<NcButton
								v-if="currentFile"
								class="pages-pane__back"
								variant="tertiary"
								:aria-label="t('pages', 'Back to the entry list')"
								:title="t('pages', 'Back to the entry list')"
								@click="backToList">
								<template #icon><ArrowLeft :size="20" /></template>
								{{ t('pages', 'Back') }}
							</NcButton>
							<span v-if="currentFile" class="pages-pane__title">{{ authorPath(currentFile) }}</span>
							<span class="pages-pane__spacer" />
							<!--
								The row only exists in the site view and only while a file is open, so Save
								and Preview need neither a view nor a file test here. Save keeps
								`!currentFile` in its :disabled on purpose: the guard above is the layout's
								condition, the disabled state is the action's own defence.
							-->
							<NcButton
								v-if="canEdit || authorOpen"
								variant="primary"
								:disabled="!currentFile || saving"
								@click="save">
								<template #icon>
									<NcLoadingIcon v-if="saving" :size="20" />
									<ContentSave v-else :size="20" />
								</template>
								{{ t('pages', 'Save') }}
							</NcButton>

							<!-- The article being written, as the site serves it: the toggle for the pane next to this one. -->
							<NcButton
								v-if="canPreviewArticle"
								:variant="previewVisible ? 'secondary' : 'tertiary'"
								:pressed="previewVisible"
								@click="togglePreview">
								<template #icon><Monitor :size="20" /></template>
								{{ t('pages', 'Preview') }}
							</NcButton>
							<!--
								The visibility actions of the open entry, one set for every place that offers
								them: `currentActions` decides which buttons are real for this state, so the
								header, the list and the tree cannot drift apart.
							-->
							<NcButton
								v-if="currentActions.includes('requestOffline')"
								variant="secondary"
								:disabled="acting"
								@click="runAction('requestOffline', currentFile)">
								<template #icon><EyeOff :size="20" /></template>
								{{ t('pages', 'Request offline') }}
							</NcButton>
							<NcButton
								v-if="currentActions.includes('cancel')"
								variant="tertiary"
								:disabled="acting"
								@click="runAction('cancel', currentFile)">
								{{ t('pages', 'Withdraw the request') }}
							</NcButton>
							<NcButton
								v-if="currentActions.includes('approve')"
								variant="primary"
								:disabled="acting"
								@click="runAction('approve', currentFile)">
								<template #icon><Check :size="20" /></template>
								{{ t('pages', 'Approve') }}
							</NcButton>
							<NcButton
								v-if="currentActions.includes('reject')"
								variant="secondary"
								:disabled="acting"
								@click="runAction('reject', currentFile)">
								<template #icon><Undo :size="20" /></template>
								{{ t('pages', 'Reject') }}
							</NcButton>
							<NcButton
								v-if="currentFile && (isResource || canEdit)"
								variant="tertiary"
								:disabled="deleting"
								:aria-label="t('pages', 'Delete current file')"
								:title="t('pages', 'Delete current file')"
								@click="deleteCurrentFile">
								<template #icon>
									<NcLoadingIcon v-if="deleting" :size="20" />
									<Delete v-else :size="20" />
								</template>
							</NcButton>
						</div>

						<!--
							The reason the last request about this entry was turned down. It lives in the
							editor pane, above the fields and the text it is about: the author's own list
							has no note column, so this is where they can read it. Dismissing is session
							state only; the manifest keeps the note.

							The sentence names no direction on purpose: the note is the reason of *a*
							refused request (to publish or to take off), and the manifest keeps the
							reason but not the direction it answered. The notification that went out
							says which one it was.
						-->
						<NcNoteCard v-if="rejectionNote" type="warning" class="pages-reject-note">
							<div class="pages-reject-note__row">
								<span>{{ t('pages', 'The last request about this page was rejected: %s', [rejectionNote]) }}</span>
								<NcButton variant="tertiary" :aria-label="t('pages', 'Dismiss')" @click="dismissRejectNote">
									<template #icon><Close :size="20" /></template>
								</NcButton>
							</div>
						</NcNoteCard>

						<!--
							The fields of an article. The editor below holds the body only, so this is the
							one place a title, the date, the tags, the categories or the summary can be
							changed; a raw file never shows it, which is what keeps its bytes untouched.
						-->
						<ArticleFields
							v-if="isArticle"
							:current="fields"
							:meta="articleMeta"
							:path="currentFile"
							:auto-excerpt="excerptHint"
							@update="onFieldUpdate"
							@meta-save="onMetaSave" />

						<!-- A refused save keeps the text: the only way on is a reload, so it is offered here. -->
						<div v-if="conflict" class="pages-conflict">
							<span>{{ t('pages', 'This page was changed elsewhere.') }}</span>
							<NcButton variant="primary" :disabled="saving" @click="reloadCurrent">
								{{ t('pages', 'Reload the page') }}
							</NcButton>
							<NcButton variant="tertiary" @click="conflict = false">
								{{ t('pages', 'Keep my text') }}
							</NcButton>
						</div>

						<!--
							A file that is not a page: no text to edit, so the pane offers what such a
							file really has - its facts, a replacement, the Files app and delete.
						-->
						<div v-if="isResource" class="pages-resource">
							<p class="pages-resource__hint">
								{{ t('pages', 'This file is not a page: the site publishes it as it is. You can replace it with a new version, open it in the Files app, or delete it.') }}
							</p>
							<dl class="pages-resource__facts">
								<div>
									<dt>{{ t('pages', 'Name') }}</dt>
									<dd>{{ (resourceNode && resourceNode.name) || currentFile }}</dd>
								</div>
								<div v-if="resourceNode && resourceNode.size">
									<dt>{{ t('pages', 'Size') }}</dt>
									<dd>{{ resourceNode.size }} B</dd>
								</div>
							</dl>
							<div class="pages-resource__actions">
								<label class="pages-resource__replace">
									<input
										type="file"
										:disabled="replacing"
										@change="onReplaceChosen">
									<span>{{ replacing ? t('pages', 'Replacing…') : t('pages', 'Replace file') }}</span>
								</label>
								<NcButton variant="tertiary" @click="openResourceInFiles">
									<template #icon><OpenInNew :size="20" /></template>
									{{ t('pages', 'Open in Files') }}
								</NcButton>
							</div>
						</div>

						<div v-show="currentFile && !isResource" ref="editorHost" class="pages-editor-host" />
						<div v-if="!currentFile" class="pages-articles-view">
							<div class="pages-articles-view__head">
								<h2 class="pages-articles-view__title">{{ t('pages', 'My articles') }}</h2>
								<span class="pages-articles-view__counts">{{ articleCounts }}</span>
								<span class="pages-articles-view__spacer" />
								<NcButton variant="primary" @click="openCreate('file', '')">
									<template #icon><Plus :size="20" /></template>
									{{ t('pages', 'New article') }}
								</NcButton>
								<NcButton variant="secondary" :disabled="loadingTree" @click="refreshNav()">
									<template #icon><Refresh :size="20" /></template>
									{{ t('pages', 'Refresh') }}
								</NcButton>
							</div>

							<NcNoteCard v-if="authorError" type="error">{{ authorError }}</NcNoteCard>

							<ArticleRows :rows="articleRows" @retry-push="retryPush">
								<template #actions="{ article }">
									<NcButton variant="secondary" @click="openEntry(article.entry)">
										<template #icon><Pencil :size="20" /></template>
										{{ t('pages', 'Edit') }}
									</NcButton>
									<NcButton
										v-if="actionsFor(article.entry).includes('requestOnline')"
										variant="secondary"
										:disabled="acting"
										@click="runAction('requestOnline', nodeTarget(article.entry))">
										<template #icon><Upload :size="20" /></template>
										{{ requestOnlineLabel(article.entry) }}
									</NcButton>
									<NcButton
										v-if="actionsFor(article.entry).includes('requestOffline')"
										variant="secondary"
										:disabled="acting"
										@click="runAction('requestOffline', nodeTarget(article.entry))">
										<template #icon><EyeOff :size="20" /></template>
										{{ t('pages', 'Request offline') }}
									</NcButton>
									<NcButton
										v-if="actionsFor(article.entry).includes('cancel')"
										variant="tertiary"
										:disabled="acting"
										@click="runAction('cancel', nodeTarget(article.entry))">
										{{ t('pages', 'Withdraw the request') }}
									</NcButton>
								</template>
							</ArticleRows>

							<div v-if="!articleRows.length" class="pages-articles-view__empty">
								<p>{{ t('pages', 'No files yet') }}</p>
								<p class="pages-articles-view__muted">{{ t('pages', 'Create your first file on the left.') }}</p>
							</div>
						</div>
					</div>

					<!--
						The divider between the two panes: the seam of the split is a control of its own,
						so it can be dragged with the pointer and moved with the arrow keys. It only exists
						while the preview is actually shown - without it there is nothing to divide.
					-->
					<div
						v-if="canPreviewArticle && previewVisible"
						class="pages-split__handle"
						role="separator"
						aria-orientation="vertical"
						:aria-valuenow="Math.round(splitRatio * 100)"
						aria-valuemin="25"
						aria-valuemax="75"
						tabindex="0"
						:title="t('pages', 'Drag to resize the panes; double-click to reset')"
						@pointerdown.prevent="startSplitResize"
						@dblclick.prevent="resetSplit"
						@keydown="onSplitKeydown" />

					<!--
						The preview of the article being written: its own built page, served through the
						same short-lived token as the site preview. An article that the site does not
						serve yet has no address, and an empty frame would look like a broken one —
						the pane says so instead.
					-->
					<div v-if="canPreviewArticle" v-show="previewVisible" class="pages-pane pages-pane--preview">
						<div class="pages-pane__head">
							<span class="pages-pane__title">{{ t('pages', 'Preview') }}</span>
							<span class="pages-pane__meta pages-pane__meta--tight">{{ articlePreviewUrl || t('pages', 'Not on the site yet') }}</span>
							<span class="pages-pane__spacer" />
							<NcButton
								v-if="articleFrameSrc"
								variant="tertiary"
								@click="openPreviewInNewTab">
								{{ t('pages', 'Open in new tab') }}
							</NcButton>
							<NcButton variant="tertiary" :disabled="saving" @click="loadPreview(true)">
								{{ t('pages', 'Reload') }}
							</NcButton>
							<span class="pages-pane__meta">{{ previewState }}</span>
						</div>
						<iframe
							v-if="articleFrameSrc"
							ref="articleFrame"
							class="pages-preview-frame"
							:src="articleFrameSrc"
							sandbox="allow-scripts allow-same-origin allow-popups allow-forms allow-modals"
							referrerpolicy="no-referrer"
							@load="onPreviewNavigated" />
						<p v-else class="pages-preview-hint">
							{{ t('pages', 'This article has no address in the built site yet.') }}
						</p>
					</div>
				</div>
			</div>

			<!--
				New file / new folder of the author's own tree: the dialog asks for a name only, and
				a name without an extension becomes a `.md` page. The folder it lands in is the one
				the action menu was opened on.
				Closing invariants: use v-model:open, never set createOpen synchronously in `closing`,
				and pass buttons via the `buttons` prop (a false callback keeps the dialog open).
			-->
			<NcDialog
				v-if="canWrite"
				:key="createDialogKey"
				v-model:open="createOpen"
				:name="createKind === 'folder' ? t('pages', 'New folder') : t('pages', 'New file')"
				:buttons="createButtons"
				size="small"
				@closing="onDialogClosing">
				<div class="pages-newfile">
					<p class="pages-newfile__where">
						{{ t('pages', 'Location:') }}
						<code>{{ createLocation }}</code>
					</p>

					<NcTextField
						:model-value="createName"
						:label="createKind === 'folder' ? t('pages', 'Folder name') : t('pages', 'Name')"
						label-outside
						:placeholder="createKind === 'folder' ? t('pages', 'e.g. notes') : t('pages', 'e.g. hello-world')"
						@update:model-value="onNameInput" />

					<p class="pages-newfile__hint">
						{{ createKind === 'folder'
							? t('pages', 'Empty folders are not mirrored: a folder shows up on the site once it holds its first file.')
							: t('pages', 'A name without an extension becomes a Markdown page (.md). The title is taken from the name and can be changed in the editor.') }}
					</p>
				</div>
			</NcDialog>
		</NcAppContent>

		<!-- the batch confirmation: what will be touched, and that it runs entry by entry -->
		<NcDialog
			v-if="batch"
			:name="batchName"
			:buttons="batchButtons"
			@closing="batch = null">
			<p>{{ batchHint }}</p>
			<ul class="pages-batch__list">
				<li v-for="path in batch.paths.slice(0, 10)" :key="path">{{ path }}</li>
			</ul>
			<p v-if="batch.paths.length > 10" class="pages-fields__hint">
				{{ t('pages', '%s more', [String(batch.paths.length - 10)]) }}
			</p>
			<p v-if="batchBusy" class="pages-fields__hint">{{ batchProgress }}</p>
		</NcDialog>

		<!--
			The confirmation of a publish, shared by every entry point: the toolbar and the request
			panel. One dialog and one implementation (`publish()`), so the buttons cannot drift apart
			and a publish always says what it will do before it does it.
		-->
		<NcDialog
			v-if="publishConfirm"
			:name="t('pages', 'Publish to the site?')"
			:buttons="publishButtons"
			@closing="publishConfirm = false">
			<p>{{ publishHint }}</p>
			<NcTextField
				:model-value="publishMessage"
				:label="t('pages', 'Commit message (optional)')"
				label-outside
				:placeholder="t('pages', 'Leave empty to use the default message')"
				@update:model-value="onPublishMessage" />
		</NcDialog>

		<!--
			Deleting an entry the site serves is one question with three answers, so it cannot be a
			tab confirm: the file goes with a take-off request that waits for a reviewer, or with an
			immediate hand-over to the orphan owner directory, or not at all. `confirmDelete()` below
			is the only way in, and the answer is the `mode` the delete sends.
		-->
		<NcDialog
			v-if="deleteChoice"
			:name="deleteDialogName"
			:buttons="deleteDialogButtons"
			size="small"
			@closing="answerDeleteDialog(null)">
			<p>{{ t('pages', 'The page is online. Choose what happens to it.') }}</p>
		</NcDialog>
	</NcContent>
</template>

<script>
import { t } from './i18n.js'
import { generateUrl } from '@nextcloud/router'
import { showError, showInfo, showSuccess, showWarning } from '@nextcloud/dialogs'
// Import per component path, not from the package barrel: the barrel pulls in components
// that need the optional `@nextcloud/files` peer dependency and breaks the build.
import NcActionButton from '@nextcloud/vue/components/NcActionButton'
import NcActionLink from '@nextcloud/vue/components/NcActionLink'
import NcActionSeparator from '@nextcloud/vue/components/NcActionSeparator'
import NcAppContent from '@nextcloud/vue/components/NcAppContent'
import NcAppNavigation from '@nextcloud/vue/components/NcAppNavigation'
import NcAppNavigationCaption from '@nextcloud/vue/components/NcAppNavigationCaption'
import NcAppNavigationItem from '@nextcloud/vue/components/NcAppNavigationItem'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcContent from '@nextcloud/vue/components/NcContent'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import ArrowLeft from 'vue-material-design-icons/ArrowLeft.vue'
import Check from 'vue-material-design-icons/Check.vue'
import Close from 'vue-material-design-icons/Close.vue'
import ContentSave from 'vue-material-design-icons/ContentSave.vue'
import Cog from 'vue-material-design-icons/Cog.vue'
import Delete from 'vue-material-design-icons/Delete.vue'
import FilePlus from 'vue-material-design-icons/FilePlus.vue'
import FolderPlus from 'vue-material-design-icons/FolderPlus.vue'
import Hammer from 'vue-material-design-icons/Hammer.vue'
import Monitor from 'vue-material-design-icons/Monitor.vue'
import OpenInNew from 'vue-material-design-icons/OpenInNew.vue'
import Pencil from 'vue-material-design-icons/Pencil.vue'
import Plus from 'vue-material-design-icons/Plus.vue'
import Refresh from 'vue-material-design-icons/Refresh.vue'
import SourceBranch from 'vue-material-design-icons/SourceBranch.vue'
import Undo from 'vue-material-design-icons/Undo.vue'
import Upload from 'vue-material-design-icons/Upload.vue'
import EyeOff from 'vue-material-design-icons/EyeOff.vue'

import api from './api.js'
import ArticleFields from './ArticleFields.vue'
import ArticleRows from './ArticleRows.vue'
import ManageView from './Manage.vue'
import ReadView from './Read.vue'
import RequestsView from './Requests.vue'
import {
	articlePut,
	autoExcerpt,
	dateFromFilename,
	formatTags,
	hasChanges,
	isoDate,
	isArticlePath,
	isFutureDay,
	normalizeText,
	parseTags,
} from './articleGeometry.js'
import { entryStateLabel, extrasOf, intentOf, pageFrontMatter, reasonFor, renameArticleTarget, stateKind, stateLabel } from './articles.js'
import { createEditor } from './editor.js'
import { readShellState, reviewGrant } from './pageState.js'

/** The key the divider position is kept under: one string, so the read and the write cannot drift. */
const SPLIT_RATIO_KEY = 'pages-split-ratio'

/**
 * The saved editor share of the split, clamped; 0.5 when nothing usable is stored.
 *
 * `localStorage` can be absent (the SSR renderer has no `window`) or refuse to answer (private
 * mode), and an older build may have left a value outside the range: all three are the middle, so a
 * broken store can never leave one pane unusable.
 *
 * @returns {number}
 */
function readSplitRatio() {
	if (typeof window === 'undefined') {
		return 0.5
	}
	try {
		const raw = window.localStorage.getItem(SPLIT_RATIO_KEY)
		const value = Number(raw)
		return Number.isFinite(value) && value >= 0.25 && value <= 0.75 ? value : 0.5
	} catch (error) {
		return 0.5
	}
}

// Folder collapsed state lives in memory only: every page load starts fully collapsed.

export default {
	name: 'App',

	props: {
		/**
		 * The server's initial state, handed over by main.js — the page this load is and the two page
		 * URLs are part of it. Read from the shell element when the component is mounted without it, so
		 * the page still works on its own.
		 */
		shell: {
			type: Object,
			default: null,
		},
	},

	components: {
		NcActionButton,
		NcActionLink,
		NcActionSeparator,
		NcAppContent,
		NcAppNavigation,
		NcAppNavigationCaption,
		NcAppNavigationItem,
		NcButton,
		NcContent,
		NcDialog,
		NcLoadingIcon,
		NcNoteCard,
		NcTextField,
		ArticleFields,
		ArticleRows,
		ArrowLeft,
		Check,
		Close,
		ContentSave,
		Cog,
		Delete,
		FilePlus,
		Plus,
		FolderPlus,
		Hammer,
		Monitor,
		OpenInNew,
		Pencil,
		Refresh,
		ManageView,
		ReadView,
		RequestsView,
		SourceBranch,
		Undo,
		Upload,
		EyeOff,
	},

	data() {
		// main.js hands the shell state over; without it (a mount from somewhere else) it is read
		// from the shell element itself.
		const initial = this.shell && typeof this.shell === 'object' ? this.shell : readShellState()
		// Single site: the server hands over one `site` object. It is trimmed for non-administrators
		// (only `display`, `connected` and `revision` are there), so nothing may dereference a field
		// an administrator would have. A missing object is treated as an empty one, which keeps the
		// preview and the capability checks working instead of breaking the whole page.
		const site = initial.site && typeof initial.site === 'object' ? initial.site : {}
		return {
			site,
			canEdit: initial.canEdit === true,
			canPublish: initial.canPublish === true,
			// the authoring grant: it decides whether the file tree is shown at all
			canAuthor: initial.canAuthor === true,
			// the review grant, with the publish grant as the fallback for a shell cached before the
			// review grant existed (see reviewGrant() in pageState.js)
			canReview: reviewGrant(initial),
			/**
			 * The signed-in user id, as the shell reports it. No action rule reads it any more: a
			 * reviewer may decide about an entry they own too (see `actionsFor`), so the entry's
			 * `owner` alone names the author and nothing compares it to the caller.
			 */
			uid: typeof initial.uid === 'string' ? initial.uid : '',
			isAdmin: initial.isAdmin === true,
			nodes: [],
			collapsed: new Set(),
			/** True while a review action is in flight, so its buttons cannot be double-fired. */
			acting: false,
			batch: null,
			batchBusy: false,
			batchProgress: '',
			currentFile: '',
			currentEtag: '',
			dirty: false,
			/** the editor's text, mirrored so computed values can react to typing */
			bodyText: '',
			saving: false,
			/**
			 * Entries whose last save did not reach the bridge, keyed by site target:
			 * `{target: {code, message, retryable}}`. Deliberately browser state and not a stored flag -
			 * the plan forbids a column for it - so a reload forgets the mark while `occ pages:doctor`
			 * keeps the durable record of the last failure.
			 */
			pushFailed: {},
			/** True while a retry push is in flight, so its mark cannot be clicked twice. */
			retryingPush: false,
			/** True while a publish request is in flight: the button is disabled, not removed. */
			publishing: false,
			/** The publish confirmation dialog: open while the caller has not confirmed or cancelled. */
			publishConfirm: false,
			/** The optional commit message typed in that dialog; '' means "the server's default". */
			publishMessage: '',
			/**
			 * The three-way delete dialog: `{name}` while it is open, null otherwise. A file the site
			 * serves can be deleted with a take-off request, with an immediate hand-over to the orphan
			 * owner directory, or not at all - a `window.confirm` cannot say that.
			 */
			deleteChoice: null,
			/** The promise resolver of the open delete dialog; the first answer wins. */
			deleteResolve: null,
			loadingTree: false,
			status: null,
			previewVisible: true,
			previewPath: 'index.html',
			previewSrc: 'about:blank',
			previewToken: '',
			previewBase: '',
			previewExp: 0,
			/** Renews the preview token before it expires; armed by every successful fetch */
			previewTimer: null,
			statusTimer: null,
			editor: null,
			/** Editor pane share of the split, 0.25–0.75; persisted so the next visit keeps it. */
			splitRatio: 0.5,
			/** True while a pointer drag is in progress (adds the resizing class). */
			splitResizing: false,

			// --- the navigation column: the caller's own file tree ---
			/** A failed load of that tree, shown in the column instead of an empty one */
			authorError: '',
			/** `/<author_root>/<uid>`: what a node's target starts with, so a target can be read back */
			targetPrefix: '',

			// --- article views (P3) ---
			/**
			 * Which view the content area shows: 'site' | 'requests' | 'manage' | 'read'. 'site' is
			 * the editor pane (an author's own file), which every caller starts in.
			 */
			view: 'site',
			/**
			 * Whether the request panel may be shown at all. The review grant decides, and a 403 from
			 * the list (a grant taken away while the page is open) turns it off for good.
			 */
			requestsAllowed: true,
			/** The open editor holds a structured article, opened through the article endpoint */
			authorOpen: false,
			/** The path the editor was opened on, so a save can tell whether the list needs a reload */
			openedFrom: '',

			// --- structured article editor ---
			/**
			 * The fields of the open article, as edited: {title, date, tagsText, categoriesText,
			 * excerpt, extra}. `extra` holds the additional metadata (the keys the panel does not own),
			 * so a dialog edit is a change of this panel like any other field and the save sends only
			 * the delta.
			 */
			fields: { title: '', date: '', tagsText: '', categoriesText: '', excerpt: '', extra: {} },
			/** The fields exactly as the server handed them over, for "what changed" on save */
			fieldsBaseline: { title: '', date: '', tagsText: '', categoriesText: '', excerpt: '', extra: {} },
			/** `meta` of the structured load: author, modified_date, permalink, keys, state */
			articleMeta: {},
			/** The body as it was loaded, so an unchanged body is not a change */
			bodyBaseline: '',
			/**
			 * A new file name for the open article. Only a `fileName` update of the fields panel ever
			 * carried one, and that field is gone (the tree renames); `applyRename()` still consumes it,
			 * so the save path keeps working if such an update arrives.
			 */
			newFileName: '',
			/** Set when a save was refused with 409: the text stays, a reload is offered */
			conflict: false,
			/**
			 * Targets whose rejection note this session already dismissed. Browser state on purpose:
			 * the note stays in the manifest, and a reload shows it again - the author may need the
			 * reminder, but a banner they have read must not follow them through every open.
			 */
			dismissedRejectNotes: [],

			// --- a resource of the author's tree (an image, a stylesheet, …) ---
			/** The tree node of the open resource, so the pane can show its size and kind */
			resourceNode: null,
			/** The file input of the replace action; set while a replacement is being written */
			replacing: false,

			// --- tree action menu ---
			/** Node path → NcAppNavigationItem instance (needed to close its NcActions menu) */
			nodeRefs: {},

			// --- new file / new folder dialog ---
			createOpen: false,
			/** Bumped on every open and used as NcDialog's `:key`, so each dialog is a fresh instance. */
			createDialogKey: 0,
			/** 'file' | 'folder' */
			createKind: 'file',
			/** Target folder, as a site target of the caller's own area ('' = the area root) */
			createDir: '',
			/** The dialog asks for a name only; this is it */
			createName: '',
			creating: false,
			deleting: false,
		}
	},

	computed: {
		/** How many entries each of the two column buttons would touch, for its count in the label. */
		internalCount() {
			return this.fileEntries.filter((entry) => this.batchable(entry, 'online')).length
		},

		externalCount() {
			return this.fileEntries.filter((entry) => this.batchable(entry, 'offline')).length
		},

		/**
		 * What the publish confirmation says will happen: the two effects every publish has, in the
		 * words the reviewer needs before committing to them. A computed, not a method - the dialog
		 * reads it as a value.
		 */
		publishHint() {
			const base = t('pages', 'The pages marked external go out with this commit; the pages taken down disappear from the site with it. Nothing else leaves Nextcloud.')
			// `remote_configured` comes from the bridge's status: the trimmed site object a
			// non-administrator gets carries no repository, and the dialog must still be honest
			const remote = this.status && typeof this.status.remote_configured === 'boolean'
				? this.status.remote_configured
				: null
			if (remote === false) {
				return base + '\n\n' + t('pages', 'This site has no git remote: a publish commits to the local repository only.')
			}
			if (remote === true) {
				return base + '\n\n' + t('pages', 'The commit is pushed to the configured remote.')
			}

			return base
		},

		/** The confirmation dialog's two buttons; `publish()` is the one place a publish is started. */
		publishButtons() {
			return [
				{
					label: t('pages', 'Cancel'),
					callback: () => { this.publishConfirm = false },
				},
				{
					label: t('pages', 'Publish'),
					variant: 'primary',
					callback: () => { this.runPublish() },
				},
			]
		},

		batchName() {
			if (!this.batch) {
				return ''
			}
			return this.batch.kind === 'online'
				? t('pages', 'Request online for %s page(s)?', [String(this.batch.paths.length)])
				: t('pages', 'Request offline for %s page(s)?', [String(this.batch.paths.length)])
		},

		batchHint() {
			return t('pages', 'Each entry is handled on its own; failures are reported at the end.')
		},

		batchButtons() {
			return [
				{
					label: t('pages', 'Cancel'),
					callback: () => { this.batch = null },
				},
				{
					label: t('pages', 'Confirm'),
					variant: 'primary',
					callback: () => { this.runBatch() },
				},
			]
		},

		/** The title of the three-way delete dialog: the entry's own name, never its stored target. */
		deleteDialogName() {
			return t('pages', 'Delete “%s”?', [String(this.deleteChoice?.name || '')])
		},

		/**
		 * The three answers of that dialog: the two ways to delete, and the way out.
		 *
		 * "Request take-off" is the primary one: it is the reversible half of the two (the page stays
		 * online until a reviewer decides), so the destructive one is not what the eye lands on.
		 */
		deleteDialogButtons() {
			return [
				{
					label: t('pages', 'Cancel'),
					callback: () => this.answerDeleteDialog(null),
				},
				{
					label: t('pages', 'Keep on the site'),
					callback: () => this.answerDeleteDialog('keep'),
				},
				{
					label: t('pages', 'Request take-off'),
					variant: 'primary',
					callback: () => this.answerDeleteDialog('request-offline'),
				},
			]
		},

		/**
		 * The visibility actions that are real for the open entry, as a list of names.
		 *
		 * A computed, not a method: the template reads it as a value (`currentActions.includes(...)`);
		 * a method would be a function there and the call would throw during render. The header, the
		 * flat list and the tree all ask this one place — one rule, so they cannot drift apart.
		 */
		currentActions() {
			return this.actionsFor(this.currentEntry)
		},

		/** The tree node behind the open entry: its visibility marks, read without another request. */
		currentEntry() {
			if (!this.currentFile) {
				return null
			}
			return this.nodes.find((node) => this.nodeTarget(node) === this.currentFile) || null
		},


		/**
		 * The tree as rows for the shared article table: it wants a title, a date and the visibility
		 * state, while a tree node carries a name and an mtime. The node itself travels along as
		 * `entry`, so the row actions can act on exactly the thing that was clicked.
		 */
		articleRows() {
			return this.fileEntries.map((entry) => ({
				path: this.nodeTarget(entry),
				pathLabel: this.authorPath(this.nodeTarget(entry)),
				title: entry.name,
				state: entry.state,
				intent: entry.intent,
				owner: entry.owner,
				live: entry.live === true,
				// the folder model's own facts: whether the site serves a version (and since when),
				// whether a candidate waits for a decision (and since when), whether a take-off is
				// waiting, and whether that take-off says the author deleted the file. The badge and
				// the row actions read this same node, so they cannot disagree about the state.
				published: entry.published === true,
				publishedAt: entry.publishedAt || 0,
				candidate: entry.candidate === true,
				candidateAt: entry.candidateAt || 0,
				downRequest: entry.downRequest === true,
				sourceDeleted: entry.sourceDeleted === true,
				// the reason the last request about this entry was rejected; '' when there is none
				reviewNote: String(entry.reviewNote || ''),
				date: entry.mtime ? new Date(entry.mtime * 1000) : null,
				submittedAt: entry.submittedAt || 0,
				tags: [],
				// the "not pushed · retry" mark of this entry, so the table and the tree agree
				pushFailed: this.pushFailedFor(entry),
				entry,
			}))
		},

		/**
		 * The rejection reason of the open article, unless this session already dismissed it.
		 *
		 * It comes from the same row `articleRows` built, so the banner and the list cannot disagree
		 * about which entry has a note; while no file is open there is nothing to annotate.
		 */
		rejectionNote() {
			if (!this.currentFile) {
				return ''
			}
			if (this.dismissedRejectNotes.includes(this.currentFile)) {
				return ''
			}
			const row = this.articleRows.find((item) => item.path === this.currentFile)
			return String(row?.reviewNote || '').trim()
		},

		/**
		 * The counts the heading shows: the four labels of the list, with the pending candidate counted
		 * on its own. `state` alone cannot name it any more (a candidate is `internal` or `external`),
		 * so counting by state would file a waiting page under "Internal" and hide the review.
		 */
		articleCounts() {
			const by = { internal: 0, external: 0, requested: 0, candidate: 0 }
			for (const row of this.articleRows) {
				if (row.candidate === true) {
					by.candidate += 1
					continue
				}
				const key = String(row.state || 'internal')
				by[key] = (by[key] || 0) + 1
			}
			const parts = []
			if (by.candidate) {
				parts.push(t('pages', 'Waiting for review: %s', [String(by.candidate)]))
			}
			if (by.requested) {
				parts.push(t('pages', 'Take-off requested: %s', [String(by.requested)]))
			}
			if (by.external) {
				parts.push(t('pages', 'External: %s', [String(by.external)]))
			}
			if (by.internal) {
				parts.push(t('pages', 'Internal: %s', [String(by.internal)]))
			}
			return parts.join(' · ')
		},

		/**
		 * Where a new file will land in the author's own files, as `Pages/…`. The dialog used to show the
		 * site target (`tmp/<user>/…`), which is the mirror, not the place the author would look for it.
		 */
		createLocation() {
			const base = String(this.createDir || '')
			const parts = base.split('/').filter((part) => part !== '')
			// the target directory starts with the mirror root and the uid; both are the app's business
			const rest = parts.slice(2).join('/')
			return t('pages', 'Pages') + (rest === '' ? '' : '/' + rest)
		},

		/** Every file of the tree, flattened and sorted: what the pane lists while nothing is open. */
		fileEntries() {
			return this.nodes
				.filter((node) => node.type !== 'dir')
				.slice()
				.sort((a, b) => String(a.path).localeCompare(String(b.path)))
		},

		/** Is there a single site to talk about? Gates the buttons that would otherwise call nothing. */
		siteReady() {
			return Object.keys(this.site).length > 0
		},
		/**
		 * May this caller write in the column? The column is the caller's own author area, offered to
		 * whoever may author (an empty area is a normal starting point); the review-and-publish grant
		 * can author too, because publishing implies writing what gets published.
		 */
		canWrite() {
			return this.canAuthor || this.canEdit
		},
		/**
		 * The request panel needs the review grant. That is also what the list endpoint's decision
		 * path checks, but the panel is only mounted when the capability is actually held: a request
		 * that is guaranteed to answer 403 would only produce an error where there is nothing to
		 * decide. `canReview` falls back to `canPublish` for a shell rendered before the grant was
		 * split, so a stale cached page still shows the panel to the callers who used to hold it.
		 */
		showRequests() {
			return this.canReview && this.requestsAllowed
		},
		/**
		 * The manage view needs exactly the grant the request panel needs: taking a page off the site
		 * is a decision about the site, and the list it acts on is the same review surface. It shares
		 * `requestsAllowed`, so a grant taken away while the page is open hides both views at once.
		 */
		showManage() {
			return this.showRequests
		},
		/**
		 * Whether the open file is a structured article. The rule is narrow on purpose (an author
		 * target with a page extension, or a `_posts/…` target) and lives in articleGeometry.js, so a
		 * layout, a stylesheet or `_config.yml` can never be pulled into the fields panel: for those
		 * the pane is the resource pane, which replaces or deletes the bytes instead of editing them.
		 */
		isArticle() {
			return isArticlePath(this.currentFile)
		},
		/**
		 * An open file the app does not edit as a page: an image, a stylesheet, a download. The pane
		 * offers what such a file has - its facts, a replacement, the Files app and delete - instead
		 * of a text editor that would corrupt it.
		 */
		isResource() {
			return this.currentFile !== '' && !this.isArticle
		},
		/**
		 * Is there anything to save? The *content* is the truth: the body and the fields are compared
		 * against what was loaded, in one place (`hasChanges`), and nothing else.
		 *
		 * `dirty` is only the editor's own signal, not an answer: every `docChanged` sets it, and a
		 * programmatic `setDoc()` is a `docChanged` too, so it can be true with nothing to save. It
		 * stays readable for a display mark, but it must never decide whether a discard is confirmed
		 * or whether leaving the page is blocked - that is what made "Discard them?" appear after a
		 * successful save. Only the empty-file fallback still needs it: with no file there is no
		 * baseline to compare with.
		 *
		 * A conflict always asks: the local text may not be saved over the other version, so the user
		 * has to confirm losing it.
		 */
		editorDirty() {
			if (!this.currentFile) {
				return this.dirty
			}
			if (this.conflict) {
				return true
			}
			return hasChanges(
				this.bodyText,
				this.fields,
				this.fieldsBaseline,
				this.bodyBaseline,
			)
		},
		/** The summary the site would use while the field is empty, for the field's placeholder. */
		excerptHint() {
			return autoExcerpt(this.bodyText)
		},
		iconFile() {
			return 'icon-file'
		},
		iconFolder() {
			return 'icon-folder'
		},
		settingsUrl() {
			return generateUrl('/settings/admin/pages')
		},
		/** Flatten the tree according to the collapse state — the sidebar only renders visible rows */
		visibleNodes() {
			const out = []
			const hiddenPrefixes = []
			for (const node of this.nodes) {
				const hidden = hiddenPrefixes.some((prefix) => node.path.startsWith(prefix + '/'))
				if (hidden) {
					continue
				}
				out.push({ ...node, depth: node.path.split('/').length - 1 })
				if (node.type === 'dir' && this.collapsed.has(node.path)) {
					hiddenPrefixes.push(node.path)
				}
			}
			return out
		},
		/**
		 * The path the new-file dialog would create: the folder it was opened on plus the name. A name
		 * without an extension gets `.md`, which is what the column is for.
		 *
		 * Every file is addressed by its site target, the root of the area included: a bare name would
		 * be a *site* path for a caller who holds the review-and-publish grant (the storage user of the
		 * site, an administrator), and the server would create it outside the author area.
		 */
		authorCreatePath() {
			const name = String(this.createName || '').trim().replace(/^\/+/, '')
			if (name === '') {
				return ''
			}
			const file = this.createKind === 'folder'
				? name
				: (/\.[A-Za-z0-9]+$/.test(name) ? name : name + '.md')
			const dir = String(this.createDir || this.targetPrefix || '').replace(/\/+$/, '')

			return dir === '' ? file : dir + '/' + file
		},
		/** Must use the `buttons` prop: only that path keeps the dialog open when the callback returns false */
		createButtons() {
			const path = this.authorCreatePath.trim()
			const canCreate = !this.creating
				&& path !== ''
				&& !path.endsWith('/')
			return [
				{
					label: t('pages', 'Cancel'),
					callback: () => {
						this.closeCreate()
					},
				},
				{
					label: this.createKind === 'folder' ? t('pages', 'Create folder') : t('pages', 'Create'),
					variant: 'primary',
					disabled: !canCreate,
					// Returning false keeps the dialog open (e.g. the path already exists)
					callback: () => (this.createKind === 'folder'
						? this.createNewFolder()
						: this.createNewFile()),
				},
			]
		},
		/** Surface the preview state: a blank frame should tell "no token" from "token expired" */
		previewState() {
			if (this.previewSrc === 'about:blank') {
				return t('pages', 'Not loaded')
			}
			if (!this.previewExp) {
				return ''
			}
			const left = this.previewExp - Math.floor(Date.now() / 1000)
			return left > 0 ? `${Math.floor(left / 60)}min` : t('pages', 'Expired')
		},
		/**
		 * The address of the open article in the built site. The structured load reports it as
		 * `permalink`; `url` is accepted as well, because that is what the reading list calls it.
		 */
		articlePreviewUrl() {
			const meta = this.articleMeta && typeof this.articleMeta === 'object' ? this.articleMeta : {}

			return String(meta.permalink || meta.url || '').trim()
		},
		/** The article's own built page, through the same preview token the site preview uses. */
		articleFrameSrc() {
			const relative = this.articlePreviewUrl.replace(/^\/+/, '')
			if (relative === '' || this.previewToken === '') {
				return ''
			}

			return this.previewBase + relative
		},
		/**
		 * Is there an article to preview? Only then is the pane shown: an empty frame next to an
		 * empty editor would say nothing at all.
		 */
		canPreviewArticle() {
			return this.currentFile !== '' && this.isArticle
		},
		/**
		 * Does anything on screen need a preview token right now? The token is shared by every
		 * surface that shows the built site, and each one has its own condition:
		 *
		 *   - the article preview: only while an article is open in the editor;
		 *   - the **Read** view: always. Every article it shows is that article's own built page,
		 *     and the reader has no editor file open at all — `currentFile` is empty, so
		 *     `canPreviewArticle` is false and the pane toggle has nothing to do with them.
		 *
		 * Neither "is the preview pane visible" nor "is an article open in the editor" is therefore
		 * the question to ask: a reader goes straight to Read without ever touching either, and a
		 * fetch gated on them never happens, leaving `previewBase` empty and every article frame
		 * with no address.
		 */
		previewTokenWanted() {
			if (!this.siteReady) {
				return false
			}

			return this.view === 'read' || this.canPreviewArticle
		},
	},

	watch: {
		/*
		 * The leave guard follows the *content*, not `dirty`: see `editorDirty`. Watching `dirty`
		 * armed the browser's "unsaved changes" dialog after a save, because a programmatic
		 * `setDoc()` is a `docChanged` and sets that flag. Clearing the handler stays here, so
		 * nothing is left armed once there is nothing unsaved.
		 */
		editorDirty(value) {
			window.onbeforeunload = value ? () => true : null
		},
		/*
		 * The editor is created once, while the pane may still be hidden: an author without the edit
		 * grant gets an editor that only becomes visible when they pick one of their own articles, and
		 * CodeMirror created inside a `display: none` host measures a viewport of zero size - it stays
		 * blank when it is finally shown. Showing a file (or coming back to the site view) is the
		 * moment to ask for a fresh measurement, which is what makes the text appear.
		 */
		currentFile() {
			this.$nextTick(() => this.editor?.requestMeasure())
		},
		view() {
			this.$nextTick(() => this.editor?.requestMeasure())
			/*
			 * The reading view shows an article's built page in an iframe, which needs the preview
			 * token. This watcher is the one place that asks for it on a reader's behalf — Read.vue
			 * never fetches one itself, so entering the view cannot fetch twice.
			 *
			 * Deliberately not gated on "no token yet": `loadPreview()` sits still for a token that
			 * is still fresh and only re-asks when it is missing or within five minutes of expiry.
			 * That is what makes a return to Read after a while refresh the old token instead of
			 * handing it to the frames expired.
			 */
			if (this.view === 'read') {
				this.loadPreview()
			}
		},
	},

	created() {
		/*
		 * Which node owns the open action menu. Deliberately not reactive data: assigning it re-rendered
		 * the row, which rebuilt the popover, which then painted at the document origin for a frame — the
		 * menu flashing in the corner. Only `onNavScroll` reads it (to close the menu with
		 * returnFocus=false so the list does not jump), and nothing renders it.
		 */
		this.menuOpenPath = null
		/*
		 * The listener teardown of a drag that is still in progress. Not reactive data: nothing
		 * renders it, and `beforeUnmount` is the only other reader.
		 */
		this.splitCleanup = null
	},

	async mounted() {
		// The divider position this browser last used, read once before the split is first painted.
		this.splitRatio = readSplitRatio()

		/*
		 * The editor host is the one in the template below, created once and kept alive across the
		 * views (`v-show`), so switching views never builds a second CodeMirror instance.
		 *
		 * It is created even while the pane is hidden: a user without the edit grant gets an editor that
		 * only becomes visible once they pick one of their own articles.
		 */
		this.editor = createEditor({
			parent: this.$refs.editorHost,
			doc: '',
			onChange: () => {
				this.dirty = true
				// mirrored into reactive state: the save guard and the summary placeholder are computed
				// from it, and a value read straight out of CodeMirror never triggers a re-render
				this.bodyText = this.editor ? this.editor.getDoc() : ''
			},
			onSave: () => this.save(),
		})
		// The column is the caller's own file tree, for every author.
		await this.loadAuthorTree()

		// Only editors and publishers may read the status; for everybody else this request would be
		// a 403 and an error toast on every poll.
		if (this.canEdit || this.canPublish) {
			await this.refreshStatus()
		}

		// The preview is the one block every signed-in user gets.
		await this.loadPreview()

		// Scroll does not bubble, but it does pass ancestors in the capture phase — hence document + capture.
		document.addEventListener('scroll', this.onNavScroll, true)

		// Poll the status every 1.5s: reload the preview when a new build shows up
		if (this.canEdit || this.canPublish) {
			this.statusTimer = window.setInterval(() => this.refreshStatus(true), 1500)
		}
	},

	beforeUnmount() {
		document.removeEventListener('scroll', this.onNavScroll, true)
		// A drag that never saw its pointerup must not leave a listener on the window behind.
		this.stopSplitResize()
		if (this.statusTimer) {
			window.clearInterval(this.statusTimer)
		}
		window.clearTimeout(this.previewTimer)
		this.editor?.destroy()
		window.onbeforeunload = null
	},

	methods: {
		/**
		 * The label of one entry's state badge. A method, not a computed: the template calls it with a
		 * node, and the answer depends on the node's state.
		 */
		entryStateLabel(state, article) {
			return entryStateLabel(state, article)
		},

		/**
		 * A site target as the author sees it: `Pages/<path>`.
		 *
		 * A method, not a computed: the template and `articleRows` call it with an argument, and a computed
		 * would have been evaluated once instead. The mirror root and the user id are the app's own
		 * plumbing, so they never reach the screen.
		 */
		authorPath(target) {
			const raw = target && typeof target === 'object' ? (target.target || target.path || '') : target
			const value = typeof raw === 'string' ? raw : ''
			const prefix = String(this.targetPrefix || '')
			const rel = prefix !== '' && value.startsWith(prefix) ? value.slice(prefix.length) : value
			return t('pages', 'Pages') + (rel === '' ? '' : '/' + rel.replace(/^\/+/, ''))
		},

		/**
		 * Open the confirmation for a whole-tree batch. Nothing runs until the dialog is confirmed: a
		 * batch touches many entries, so it is never one click in the dark.
		 *
		 * The batch is one state, not "everything with an action": since an external entry also offers
		 * `requestOnline` (the update of its content), filtering by the action alone would sweep the
		 * whole served site into the "internal pages" batch.
		 *
		 * @param {'online'|'offline'} kind the intent every entry in the batch asks for
		 */
		startBatch(kind) {
			const paths = this.fileEntries
				.filter((entry) => this.batchable(entry, kind))
				.map((entry) => this.nodeTarget(entry))
				.filter((target) => target !== '')
			this.batch = paths.length > 0 ? { kind, paths } : null
		},

		/**
		 * Whether one entry belongs in a whole-tree batch. A batch asks for the same intent for every
		 * entry, and the server refuses a second request on an entry that already carries one: an
		 * entry with a `candidate` (a first publication or an update waits) or a `downRequest` (a
		 * take-off waits) is therefore counted in neither batch. The counts under the buttons use this
		 * same rule, so the number in the label is exactly the number the batch would send.
		 */
		batchable(entry, kind) {
			if (entry?.candidate === true || entry?.downRequest === true) {
				return false
			}
			const state = String(entry?.state || 'internal')
			return kind === 'online' ? state === 'internal' : state === 'external'
		},

		/**
		 * Run the confirmed batch one entry at a time and report the outcome per entry. Serial on
		 * purpose: the server sees ordinary single-entry calls, and one failure cannot hide the others.
		 */
		async runBatch() {
			const batch = this.batch
			this.batch = null
			if (!batch) {
				return
			}
			this.batchBusy = true
			const failures = []
			let done = 0
			try {
				for (const path of batch.paths) {
					done += 1
					this.batchProgress = t('pages', 'Working… %1$s/%2$s', [String(done), String(batch.paths.length)])
					try {
						const result = await api.requestVisibility(path, batch.kind)
						if (!result.ok) {
							failures.push(path + ': ' + (result.message || ''))
						}
					} catch (error) {
						failures.push(path + ': ' + reasonFor(error))
					}
				}
			} finally {
				this.batchBusy = false
				this.batchProgress = ''
				await this.refreshNav()
			}
			const ok = batch.paths.length - failures.length
			if (failures.length === 0) {
				this.showBanner('success', t('pages', 'Done: %s entries.', [String(ok)]))
				return
			}
			this.showBanner('error', t('pages', 'Done: %1$s succeeded, %2$s failed.', [String(ok), String(failures.length)])
				+ ' ' + failures.slice(0, 5).join(' · ')
				+ (failures.length > 5 ? ' …' : ''))
		},

		t,

		/**
		 * The navigation column: the caller's own file tree, straight from the tree endpoint's
		 * author scope.
		 *
		 * Those nodes are relative to the author's area and carry the site `target`, which is what
		 * every file and article endpoint takes - so a row is addressed by its target, and the client
		 * needs no knowledge of the mapping. Folders are part of the column now: they are where the
		 * author keeps their files, and creating one is an operation of its own.
		 *
		 * A failure is reported in the column: an empty column would read as "you have no files".
		 */
		async loadAuthorTree() {
			if (!this.canWrite) {
				this.nodes = []
				return
			}
			this.loadingTree = true
			this.authorError = ''
			// the author scope, asked for explicitly: the storage user of the site and an administrator
			// hold the review-and-publish grant, and without the scope the answer would be the site tree
			const result = await api.tree('author')
			this.loadingTree = false
			if (!result.ok) {
				this.nodes = []
				this.authorError = t('pages', 'Could not load your files:') + ' ' + reasonFor(result)
				return
			}
			// the prefix every target is built on: taken before the "no area yet" answer returns, so a
			// first file created in the area is addressed by its target and not by a bare name
			this.targetPrefix = String(result.data?.target_prefix || '')
			if (result.data?.exists === false) {
				this.nodes = []
				this.authorError = ''
				this.showBanner('warning', result.data.hint || t('pages', 'Your author area does not exist yet'))
				this.collapsed = new Set()
				return
			}
			this.nodes = Array.isArray(result.data?.nodes) ? result.data.nodes : []
			// folders always start collapsed, on every reload: the collapsed set is never restored
			this.collapsed = new Set(
				this.nodes.filter((node) => node.type === 'dir').map((node) => node.path),
			)
		},

		/** Reload the author column: what a save, a create, a rename or a delete just changed. */
		async refreshNav() {
			await this.loadAuthorTree()
		},

		/**
		 * The address of one node for the file and article endpoints.
		 *
		 * A node of the author scope carries a `target` (the folder's own included), which is what every
		 * endpoint takes; `path` is the fallback for a node built by hand (a delete of the open file).
		 * One function, so no call site has to remember which field is authoritative.
		 */
		nodeTarget(node) {
			if (!node) {
				return ''
			}

			return String(node.target || node.path || '')
		},

		/** The folder a node lives in, as the same kind of address `nodeTarget()` returns. */
		parentTargetOf(node) {
			const target = this.nodeTarget(node)
			const cut = target.lastIndexOf('/')

			return cut <= 0 ? '' : target.slice(0, cut)
		},

		/** The badge of one row: the visibility state the entry is in (内部 / 待审核 / 外部). */
		badgeOf(node) {
			if (!node || node.type === 'dir') {
				return ''
			}

			return node.state ? entryStateLabel(node.state, node) : ''
		},

		/** The badge's colour family: the state's own, with a pending candidate as the pending colour. */
		badgeKind(node) {
			return node?.state ? stateKind(node.state, node) : 'neutral'
		},

		/** Collect each node's component instance (functional ref inside v-for); Vue calls this again with null on unmount */
		setNodeRef(path, el) {
			if (el) {
				this.nodeRefs[path] = el
			} else {
				delete this.nodeRefs[path]
			}
		},

		/** Only tracks which node's menu is open — toggling itself is left to the component (see onNavScroll) */
		onMenuToggle(node, open) {
			if (open) {
				this.menuOpenPath = node.path
			} else if (this.menuOpenPath === node.path) {
				this.menuOpenPath = null
			}
		},

		/**
		 * Close the open action menu as soon as the navigation area scrolls.
		 * NcActions.closeMenu() must be called with returnFocus=false, otherwise the menu hands focus
		 * back to its trigger button and the browser scrolls the list back to that node.
		 */
		onNavScroll(event) {
			const path = this.menuOpenPath
			if (!path) {
				return
			}
			// Only scrolling inside the navigation counts; scrolling the editor must not close the menu
			const nav = this.$refs.nav?.$el
			const target = event?.target
			if (nav && target instanceof Node && !nav.contains(target)) {
				return
			}
			this.menuOpenPath = null
			this.nodeRefs[path]?.$refs?.actions?.closeMenu?.(false)
		},

		/** Start inline rename via NcAppNavigationItem's own handleEdit(); no `editable` prop, we place the menu entry ourselves */
		startRename(node) {
			this.nodeRefs[node.path]?.handleEdit?.()
		},

		/** Commit the rename: target path = the new name in the same folder */
		async onRename(node, newName) {
			const name = String(newName || '').trim()
			if (!name || name === node.name) {
				return
			}
			// Rename only — moving a node is a separate operation
			if (name.includes('/')) {
				this.showBanner('error', t('pages', 'Names cannot contain “/”.'))
				return
			}
			const parent = this.parentTargetOf(node)
			const target = parent ? `${parent}/${name}` : name
			const from = this.nodeTarget(node)

			// When a folder is renamed the open file path must follow, otherwise saving recreates the old path
			const movedCurrent = this.currentFile === from
				|| this.currentFile.startsWith(from + '/')
			const tail = this.currentFile.slice(from.length)

			const result = await api.move(from, target)
			if (result.status === 409) {
				this.showBanner('error', t('pages', 'That name is already taken, pick another one.'))
				return
			}
			if (!result.ok) {
				this.showBanner('error', result.message)
				return
			}

			if (movedCurrent) {
				this.currentFile = target + tail
				// Show the renamed file again, through the target it was opened with.
				await this.openFile(this.currentFile)
			}
			await this.refreshNav()
			const bridge = result.data.bridge || {}
			this.showBanner(
				bridge.notified ? 'success' : 'warning',
				bridge.notified
					? t('pages', 'Renamed, rebuilding…')
					: t('pages', 'Renamed, but notifying the bridge failed:') + (bridge.message || '?'),
			)
			if (bridge.notified) {
				window.setTimeout(() => this.refreshStatus(), 1500)
			}
		},

		/** Link that opens the node in the Files app (/f/<fileid>) */
		filesAppUrl(node) {
			if (!node?.id) {
				return generateUrl('/apps/files/')
			}
			return generateUrl(`/f/${node.id}`)
		},

		/**
		 * Both kinds of folder use the same icon: the navigation item already draws a chevron for a
		 * folder with children, and that chevron is what says open or closed. `icon-folder-open` is
		 * not one of the stylesheets' classes, so asking for it would render no icon at all.
		 *
		 * This is a method, not a computed: the template calls it with a node, and a computed would be
		 * evaluated once as a property — the template would then be calling a string.
		 */
		nodeIcon(node) {
			return node.type === 'dir' ? this.iconFolder : this.iconFile
		},

		onNodeClick(node) {
			// Row is being renamed inline: clicking it (including the input) must not select or expand
			if (this.nodeRefs[node.path]?.editingActive) {
				return
			}
			if (node.type === 'dir') {
				const next = new Set(this.collapsed)
				if (next.has(node.path)) {
					next.delete(node.path)
				} else {
					next.add(node.path)
				}
				this.collapsed = next
				return
			}
			this.openNode(node)
		},

		/**
		 * Open one node of the column: a page in the structured editor, anything else in the resource
		 * pane (which reads nothing - a binary file has no text to edit).
		 */
		async openNode(node) {
			const path = this.nodeTarget(node)
			if (!path) {
				return
			}
			if (!(await this.confirmDiscard(this.editorDirty))) {
				return
			}
			if (node.type !== 'dir' && node.kind !== 'page' && !isArticlePath(path)) {
				this.openResource(node)
				return
			}
			await this.loadPath(path)
		},

		/**
		 * Show a non-page file of the author's tree. Nothing is fetched or put into the editor: the
		 * tree already answered what the file is, and a text editor would corrupt its bytes.
		 */
		openResource(node) {
			this.currentFile = this.nodeTarget(node)
			this.currentEtag = String(node.etag || '')
			this.authorOpen = false
			this.openedFrom = 'tree'
			this.editor?.setDoc('')
			this.resetArticleState()
			// after the reset: that clears what belonged to the previous file, this one included
			this.resourceNode = node
			this.dirty = false
			this.conflict = false
			this.view = 'site'
		},

		/**
		 * Ask before throwing away unsaved edits. Used by every way of opening another file.
		 *
		 * The default is `editorDirty` (the content comparison), never `dirty` (the editor's own
		 * signal): a caller that passes nothing must still ask only when text would really be lost.
		 */
		async confirmDiscard(unsaved = this.editorDirty) {
			if (!unsaved) {
				return true
			}
			return window.confirm(t('pages', 'The current file has unsaved changes. Discard them?'))
		},

		/**
		 * Open one path of the author's own area: a structured article through
		 * `GET /api/article/content` (body without front matter, metadata as fields), anything else in
		 * the resource pane. Opening the file that is already open is a no-op, so switching views
		 * never reloads and never loses text.
		 *
		 * @param {string} path
		 * @param {'tree'|'open'} from where the open was triggered, for the refresh after a save
		 */
		async loadPath(path, from = 'tree') {
			if (!path) {
				return
			}
			if (this.currentFile === path && !this.dirty) {
				this.view = 'site'
				return
			}
			if (isArticlePath(path)) {
				await this.loadArticle(path, from)
				return
			}
			// a page of the author's tree that is not rendered as a document: the resource pane
			this.openResource({ path, target: path, type: 'file', kind: 'resource' })
		},

		/**
		 * The structured load. A failure is reported and nothing is opened: without the split the body
		 * cannot be edited on its own, and opening the raw file instead would put the front matter back
		 * into the editor — exactly what this mode exists to prevent.
		 */
		async loadArticle(path, from = 'site') {
			const result = await api.articleContent(path)
			if (!result.ok) {
				this.showBanner(
					result.status === 403 ? 'warning' : 'error',
					t('pages', 'Could not open the article fields:') + ' ' + (result.message || ''),
				)
				return
			}
			this.currentFile = result.data.path || path
			this.currentEtag = result.data.etag || ''
			this.authorOpen = true
			this.openedFrom = from
			this.applyArticle(result.data)
			this.view = 'site'
			// the pane shows this article's built page, which needs a token of its own on this page
			this.loadPreview()
		},

		/** Put one structured answer (load or save) into the panel, the editor and the baselines. */
		applyArticle(data) {
			const meta = data?.meta && typeof data.meta === 'object' ? data.meta : {}
			const current = {
				title: normalizeText(meta.title),
				// pre-filled from the date the page carries, or from its file name, which is what the
				// server would fall back to as well
				date: isoDate(meta.date) || dateFromFilename(data?.path || this.currentFile),
				tagsText: formatTags(meta.tags),
				categoriesText: formatTags(meta.categories),
				excerpt: normalizeText(meta.excerpt),
				// every key the panel does not own: the metadata dialog edits these
				extra: extrasOf(meta),
			}
			this.articleMeta = meta
			this.fields = { ...current }
			this.fieldsBaseline = { ...current }
			this.bodyBaseline = normalizeText(data?.body)
			this.setEditorDoc(this.bodyBaseline)
			this.dirty = false
			this.conflict = false
		},

		/** Assign the editor content without the assignment counting as a user edit. */
		setEditorDoc(value) {
			this.editor?.setPath(this.currentFile)
			this.editor?.setDoc(value)
			/*
			 * A programmatic assignment is not a user edit. `setDoc()` dispatches a change, which
			 * usually runs `onChange` and mirrors `bodyText` - but when the editor already holds the
			 * same text no `docChanged` fires, so `bodyText` would keep the previous file's value.
			 * Mirroring it here makes the reactive state match the editor in both cases; `dirty` is
			 * reset for the same reason, `onChange` may just have set it.
			 */
			this.bodyText = normalizeText(value)
			this.dirty = false
		},

		/** Clear everything that belonged to a structured article or a resource. */
		resetArticleState() {
			this.fields = { title: '', date: '', tagsText: '', categoriesText: '', excerpt: '', extra: {} }
			this.fieldsBaseline = { title: '', date: '', tagsText: '', categoriesText: '', excerpt: '', extra: {} }
			this.articleMeta = {}
			this.bodyBaseline = ''
			this.resourceNode = null
		},

		/**
		 * The metadata dialog saved its rows: they become this panel's `extra` state and the article
		 * is saved at once, which is what "Save" in the dialog means. A refused save leaves the rows
		 * in place (the toast says why) so the user can retry instead of retyping them.
		 */
		onMetaSave(rows) {
			this.fields = { ...this.fields, extra: rows && typeof rows === 'object' ? rows : {} }
			this.dirty = true
			this.save()
		},

		/** One field of the panel changed; the editor's own `onChange` cannot see this. */
		onFieldUpdate({ key, value }) {
			if (key === 'fileName') {
				/*
				 * The file name is not front matter: it is the address of the article, and changing it
				 * moves the file, so it is kept beside the fields and never becomes a meta key. The
				 * fields panel no longer offers such a field (the tree renames a page), which leaves
				 * this branch as the compatibility path for one.
				 */
				this.newFileName = value
				this.dirty = true
				return
			}
			this.fields = { ...this.fields, [key]: value }
			this.dirty = true
			// a newer edit is a newer edit: the conflict stays visible until it is resolved
		},

		/**
		 * The file name is the article's address, so renaming it has to rename the file. That goes
		 * through Nextcloud's own move (the same node under a new path), which keeps versions, shares
		 * and the trash working, and it is the one write an author without the edit grant may make
		 * besides the article itself.
		 *
		 * Only a `fileName` field update reaches it now; the panel no longer has that field, and the
		 * everyday rename is the tree's inline one.
		 *
		 * @returns {Promise<string|false>} the new target, '' when there was nothing to rename, false
		 *          when the rename failed (the caller must not save on top of a stale path)
		 */
		async applyRename() {
			const wanted = String(this.newFileName || '').trim()
			if (wanted === '' || !this.currentFile) {
				return ''
			}
			const target = renameArticleTarget(this.currentFile, wanted)
			if (target === '') {
				this.showBanner('error', t('pages', 'That name cannot be used as a file name.'))
				return false
			}
			if (target === this.currentFile) {
				this.newFileName = ''
				return ''
			}
			const result = await api.move(this.currentFile, target)
			if (!result.ok) {
				this.showBanner('error', result.status === 409
					? t('pages', 'That name is already taken in your author folder, pick another one.')
					: t('pages', 'Renaming failed:') + ' ' + (result.message || '?'))
				return false
			}
			this.currentFile = target
			this.newFileName = ''
			this.dirty = false
			// the node is the same but its ETag may have moved with the path: read it before writing
			const fresh = await api.articleContent(target)
			if (fresh.ok && fresh.data?.etag) {
				this.currentEtag = fresh.data.etag
			}
			await this.refreshNav()
			this.refreshPreview()
			this.showBanner('success', t('pages', 'Renamed to %s', [target.split('/').pop()]))

			return target
		},

		/** Reload the open file from the server, dropping local edits (offered after a 409). */
		async reloadCurrent() {
			if (!this.currentFile) {
				return
			}
			if (!(await this.confirmDiscard()) && !this.conflict) {
				return
			}
			this.dirty = false
			this.conflict = false
			await this.loadPath(this.currentFile, this.openedFrom || 'site')
		},

		/**
		 * A review surface lost the grant (the request panel or the manage view): hide it instead of
		 * showing a broken page. The two share one grant, so both go away together.
		 */
		onRequestsForbidden(reason) {
			this.requestsAllowed = false
			if (this.view === 'requests' || this.view === 'manage') {
				this.view = 'site'
			}
			this.showBanner('warning', reason)
		},

		/** After a decision the states moved; the tree's badges and the preview are stale. */
		onRequestDecided() {
			this.refreshPreview()
			this.refreshNav()
		},

		async openFile(path) {
			if (!this.canWrite) {
				return
			}
			if (!(await this.confirmDiscard())) {
				return
			}
			await this.loadPath(path)
		},

		async save() {
			if (!this.currentFile) {
				return
			}
			// An author without the edit capability writes into their own area through the same file
			// API, addressed by the target the backend maps to their own folder; this is the one write
			// such a user can make, and it is always a structured article.
			if (!this.canEdit && !this.authorOpen) {
				return
			}
			// Only an article has a save path here: anything else of the author area is a resource,
			// and the pane replaces or deletes it instead of writing text over its bytes.
			if (!this.isArticle) {
				return
			}
			/*
			 * The panel already says why (and the server refuses the same value), but the guard is
			 * here as well: a future date must not even start the rename request, and nothing is
			 * clamped behind the user's back.
			 */
			if (isFutureDay(this.fields.date)) {
				this.showBanner('error', t('pages', 'The date may not be in the future.'))
				return
			}
			this.saving = true
			let result = null
			try {
				// a new file name is a move, and it has to happen before the content is written
				const renamed = await this.applyRename()
				if (renamed === false) {
					return
				}
				result = await this.saveArticle()
			} catch (error) {
				/*
				 * A rejected request used to leave `saving` true forever, which turned the button into a
				 * spinner that could never be clicked again - the save looked impossible rather than
				 * failed. Whatever went wrong is said out loud, and the flag is cleared either way.
				 */
				this.showBanner('error', t('pages', 'Saving failed:') + ' ' + (error?.message || String(error)))
			} finally {
				this.saving = false
			}
			if (result === null || result === undefined) {
				return
			}

			this.currentEtag = result.etag
			this.dirty = false
			this.conflict = false

			const bridge = result.bridge || {}
			// The save answer carries the push outcome next to the new etag. A push that failed leaves the
			// entry marked ("not pushed · retry"), because the file is saved and the mirror does not have
			// it - a sentence in a toast would be gone with the next click. A push that went through
			// clears the mark of that entry: the same target is what both states describe.
			if (bridge.pushed === false) {
				this.markPushFailed(this.currentFile, bridge)
			} else {
				this.clearPushFailed(this.currentFile)
			}
			if (bridge.notified) {
				this.showBanner('success', t('pages', 'Saved, rebuilding…'))
				window.setTimeout(() => {
					this.refreshStatus()
					this.refreshPreview()
				}, 1500)
			} else {
				this.showBanner('warning', t('pages', 'Saved, but notifying the bridge failed:') + (bridge.message || '?'))
			}
			// The column lists what the files now say — a saved title moves a badge, a saved file a
			// name — so it is re-read rather than patched from the answer.
			await this.refreshNav()
		},

		/**
		 * Save an article: the body plus **only** the fields that differ from what was loaded, with the
		 * etag the content was read with. The answer carries the new etag and the new `modified_date`,
		 * and both become the baseline — so a second save sends the next delta, not the whole panel.
		 *
		 * @returns {Promise<{etag:string, bridge:object}|null>} null when nothing was written
		 */
		async saveArticle() {
			if (!this.editorDirty) {
				return null
			}
			const payload = articlePut(
				this.currentFile,
				this.editor.getDoc(),
				this.currentEtag,
				this.fields,
				this.fieldsBaseline,
			)
			const result = await api.saveArticleContent(payload)
			if (!result) {
				// the helper always answers an object; a missing one means the request never completed
				this.showBanner('error', t('pages', 'Saving failed:'))
				return null
			}
			if (result.status === 409) {
				// The text stays exactly as it is: the user chooses between reloading and copying it out
				this.conflict = true
				this.showBanner('error', t('pages', 'This article was changed elsewhere. Reload it to see the other version, or copy your text out first.'))
				return null
			}
			if (!result.ok) {
				this.showBanner('error', result.message)
				return null
			}
			// The server's answer is the new truth: it holds the etag, the body it stored and the new
			// `modified_date`, so the panel and the baselines are taken from it rather than patched.
			this.applyArticle(result.data)
			return { etag: result.data?.etag || this.currentEtag, bridge: result.data?.bridge || {} }
		},

		// ------------------------------------------------------------------ create / delete

		/**
		 * Folder a node's "new" action should land in: a folder → itself, a file → its parent. The
		 * answer is the same kind of address `nodeTarget()` returns, so the dialog creates the file
		 * where the menu was opened.
		 */
		targetDirFor(node) {
			if (!node) {
				return ''
			}
			if (node.type === 'dir') {
				return this.nodeTarget(node)
			}

			return this.parentTargetOf(node)
		},

		/** Expand every ancestor folder of a path so a newly created file is visible; session-only, a reload collapses everything again */
		expandAncestors(path) {
			// a target carries the area prefix, while the collapsed set is keyed by the area-relative path
			let rel = String(path || '')
			if (this.targetPrefix && rel.startsWith(this.targetPrefix + '/')) {
				rel = rel.slice(this.targetPrefix.length + 1)
			}
			const segments = rel.split('/')
			if (segments.length < 2) {
				return
			}
			const next = new Set(this.collapsed)
			for (let i = 1; i < segments.length; i += 1) {
				next.delete(segments.slice(0, i).join('/'))
			}
			this.collapsed = next
		},

		/** @param {'file'|'folder'} kind @param {string} dir target folder (empty string = the root) */
		openCreate(kind, dir) {
			if (!this.canWrite) {
				return
			}
			this.createKind = kind
			this.createDir = dir || ''
			// New key → brand new NcDialog instance (see createDialogKey)
			this.createDialogKey += 1
			this.createOpen = true
			this.createName = ''
			this.creating = false
		},

		closeCreate() {
			this.createOpen = false
			this.creating = false
		},

		/** Dialog is closing (close button, Esc or a button click). Never set createOpen=false here:
		 *  NcDialog renders the modal only while `open`, so unmounting it drops the delayed
		 *  handleClosed() emit and leaves the internal showModal flag stuck — the dialog then never
		 *  shows again. Let NcDialog finish closing itself via `update:open`. */
		onDialogClosing() {
			this.creating = false
		},

		/** The dialog asks for a name only; the path is derived from it. */
		onNameInput(value) {
			this.createName = value
		},

		/** @returns {Promise<boolean>} false keeps the dialog open (NcDialogButton convention) */
		async createNewFile() {
			const path = this.authorCreatePath.trim()
			if (!path || !this.siteReady) {
				return false
			}
			this.creating = true
			// a new file is a page of the site template: the title is the name, and the layout is the
			// site's - the same minimal block the panel would fill in
			const content = pageFrontMatter(String(this.createName || '').trim())
			const result = await api.createFile(path, content)
			this.creating = false

			if (result.status === 409) {
				this.showBanner('error', t('pages', 'A file already exists at this path, pick another name.'))
				return false
			}
			if (!result.ok) {
				this.showBanner('error', result.message)
				return false
			}

			this.closeCreate()
			await this.refreshNav()
			const created = result.data.path || path
			this.expandAncestors(created)
			await this.openFile(created)
			const bridge = result.data.bridge || {}
			this.showBanner(
				bridge.notified ? 'success' : 'warning',
				bridge.notified
					? t('pages', 'Created, rebuilding…')
					: t('pages', 'Created, but notifying the bridge failed:') + (bridge.message || '?'),
			)
			if (bridge.notified) {
				window.setTimeout(() => this.refreshStatus(), 1500)
			}
			return true
		},

		/** @returns {Promise<boolean>} */
		async createNewFolder() {
			const path = this.authorCreatePath.replace(/\/+$/, '')
			if (!path || !this.siteReady) {
				return false
			}
			this.creating = true
			const result = await api.createFolder(path)
			this.creating = false

			if (result.status === 409) {
				this.showBanner('error', t('pages', 'This folder already exists, pick another name.'))
				return false
			}
			if (!result.ok) {
				this.showBanner('error', result.message)
				return false
			}

			this.closeCreate()
			await this.refreshNav()
			this.expandAncestors((result.data.path || path) + '/')
			const bridge = result.data.bridge || {}
			this.showBanner(
				bridge.notified ? 'success' : 'warning',
				bridge.notified
					? t('pages', 'Folder created, rebuilding…')
					: t('pages', 'Folder created, but notifying the bridge failed:') + (bridge.message || '?'),
			)
			return true
		},

		/**
		 * Is the site serving this entry? The answer's own `published` flag when it carries one, and
		 * the `external` state as the fallback for a node that does not (an older tree answer, a node
		 * built by hand). One rule, so the tree, the article list and the editor cannot disagree about
		 * which deletion needs the three-way question.
		 */
		entryPublished(entry) {
			if (!entry) {
				return false
			}

			return typeof entry.published === 'boolean'
				? entry.published
				: String(entry.state || '') === 'external'
		},

		/** The name a delete question calls one entry by: its own name, else the last target segment. */
		entryName(entry, target = '') {
			const path = String(target || this.nodeTarget(entry || {}))
			const cut = path.lastIndexOf('/')

			return String(entry?.name || '').trim() || (cut === -1 ? path : path.slice(cut + 1))
		},

		/**
		 * Open the three-way dialog for one published entry and resolve with what the user chose.
		 *
		 * A promise, not a callback: the caller has to await the click before it may send anything,
		 * and closing the dialog is one more way of saying "nothing happens".
		 *
		 * @param {string} name the entry's name, for the title
		 * @returns {Promise<'request-offline'|'keep'|null>}
		 */
		askDeleteMode(name) {
			this.deleteChoice = { name }

			return new Promise((resolve) => {
				this.deleteResolve = resolve
			})
		},

		/** Answer the open delete dialog once; the `closing` event that follows is ignored. */
		answerDeleteDialog(mode) {
			if (!this.deleteChoice) {
				return
			}
			this.deleteChoice = null
			const resolve = this.deleteResolve
			this.deleteResolve = null
			if (resolve) {
				resolve(mode)
			}
		},

		/**
		 * The one question a delete asks, and the one place its answer is decided.
		 *
		 * A file the site serves has three possible outcomes, so it gets the dialog; a folder or an
		 * entry that is not online keeps the flat confirmation, where deleting is the whole story.
		 * Both entry points (the tree menu and the editor header) come through here, so the two can
		 * never ask about the same entry differently.
		 *
		 * @param {string} target the entry's target
		 * @param {object|null} [subject] its tree node, when the caller has one
		 * @returns {Promise<{mode: string}|null>} the mode the delete should send; null = cancelled
		 */
		async confirmDelete(target, subject = null) {
			if (!this.canWrite || !target) {
				return null
			}
			// The subject of the question is the raw entry: a row of `articleRows` carries the tree
			// node it was built from, so `published` is read from what the server actually said.
			const node = subject
				|| this.nodes.find((entry) => this.nodeTarget(entry) === target)
				|| this.articleRows.find((row) => row.path === target)?.entry
				|| null
			const isDir = node?.type === 'dir'
			if (!isDir && this.entryPublished(node)) {
				const mode = await this.askDeleteMode(this.entryName(node, target))
				return mode === null ? null : { mode }
			}

			const inside = isDir
				? this.nodes.filter((entry) => entry.path.startsWith(node.path + '/')).length
				: 0
			let message = isDir
				? t('pages', 'Delete this folder?')
				: t('pages', 'Delete this file?')
			if (isDir) {
				message += '\n' + t('pages', 'All files inside it are deleted too.')
					+ ' ' + t('pages', '(%s items)', [String(inside)])
			}
			message += '\n\n' + target
				+ '\n\n' + t('pages', 'It also disappears from the live site after the next publish.')

			return window.confirm(message) ? { mode: '' } : null
		},

		/**
		 * Delete a node of the column (the menu entry; deleting the open file goes through the same
		 * code, so neither the question nor the mode can drift).
		 *
		 * The file goes into Nextcloud's trash, and the server drops the entry from the visibility
		 * manifest. For a page the site serves, the answer says which workflow ran: it left with a
		 * take-off request waiting for a reviewer (`mode_applied`, `requestOffline`), or it was handed
		 * to the orphan owner directory at once (`transfer`). A folder or an unpublished entry falls
		 * back to the plain deletion and says so with `mode_applied: false`.
		 */
		async deleteNode(node) {
			if (!this.canWrite || !node) {
				return
			}
			const target = this.nodeTarget(node)
			if (!target) {
				return
			}
			const choice = await this.confirmDelete(target, node)
			if (!choice) {
				return
			}

			this.deleting = true
			const result = await api.deleteFile(target, choice.mode)
			this.deleting = false
			if (!result.ok) {
				this.showBanner('error', reasonFor(result))
				return
			}
			const data = result.data && typeof result.data === 'object' ? result.data : {}
			const bridge = data.bridge || {}
			// The open file (or its folder) was deleted → clear the editor, otherwise saving recreates it
			if (this.currentFile === target || this.currentFile.startsWith(target + '/')) {
				this.currentFile = ''
				this.currentEtag = ''
				this.editor?.setDoc('')
				this.resetArticleState()
				this.dirty = false
				this.conflict = false
			}
			await this.refreshNav()
			if (bridge.notified === false) {
				this.showBanner('warning', t('pages', 'Deleted, but notifying the bridge failed:') + (bridge.message || '?'))
				return
			}
			/*
			 * A folder is settled file by file: the server files a take-off request for every page
			 * inside it the site still serves and forgets the rest. The count it reports is the only
			 * thing that says so - a folder has no mode, and `mode_applied: false` alone would make
			 * the deletion look like the plain one.
			 */
			if (data.folder === true && Number(data.offline_requests) > 0) {
				this.showBanner('success', t('pages', 'Deleted. %s take-off request(s) wait for a reviewer.', [String(data.offline_requests)]))
				return
			}
			/*
			 * `mode_applied: false` is the server's own answer for "the mode did not apply": a folder,
			 * or a file the site is not serving. That is the ordinary deletion, and it is reported as
			 * one instead of pretending a take-off request or a hand-over happened.
			 */
			if (data.mode_applied !== true) {
				this.showBanner('success', t('pages', 'Deleted, rebuilding…'))
				return
			}
			if (data.mode === 'keep') {
				const transfer = data.transfer && typeof data.transfer === 'object' ? data.transfer : {}
				this.showBanner('success', String(transfer.message || '')
					|| t('pages', 'Transferred to %s.', [String(transfer.orphan || '')]))
				return
			}
			this.showBanner('success', t('pages', 'Deleted. The take-off request waits for a reviewer.'))
		},


		/**
		 * The visibility actions that are real for one entry, as a list of names. One rule for the
		 * header, the flat list and the tree: a button appears only where the server would accept it.
		 *
		 * The folders decide the state now, so the flags decide the action:
		 *
		 *   candidate       a first publication or an update waits: the author withdraws it (`cancel`),
		 *                   a reviewer approves or rejects it
		 *   downRequest     a take-off waits: the author withdraws it (`cancel`), a reviewer approves
		 *                   or rejects it
		 *   internal        nothing waits: the author asks for the site (`requestOnline`)
		 *   external        the site serves a version: the author can ask for an update
		 *                   (`requestOnline`, which freezes a new candidate) or for a take-off
		 *                   (`requestOffline`) - but not while something else already waits
		 *
		 * **Taking a page off the site is not one of them.** It is a decision about the site, not an
		 * action on one's own file, so it lives in the manage view alone (Manage.vue), where a
		 * reviewer sees every online entry of every author. What remains here is the author's side of
		 * the workflow plus the reviewer's answer to a pending request.
		 *
		 * A reviewer's actions are offered on any entry, the caller's own included: the server allows a
		 * reviewer to decide about an entry they own (an instance whose only reviewer is also the
		 * author would have no way to decide anything otherwise), and `decidedBy` records who answered.
		 */
		actionsFor(entry) {
			const out = []
			if (!entry || entry.type === 'dir') {
				return out
			}
			const state = String(entry.state || 'internal')
			const candidate = entry.candidate === true
			const downRequest = entry.downRequest === true
			if (candidate || downRequest) {
				// one entry never carries both requests, but reading both flags is what keeps a stale
				// state in an old answer from offering an action the server would refuse
				out.push('cancel')
			} else if (state === 'external') {
				// the update of an online page is an online request under the folder model: it takes
				// a new candidate snapshot and leaves the served version online until it is approved
				out.push('requestOffline', 'requestOnline')
			} else if (state === 'internal') {
				out.push('requestOnline')
			}
			/*
			 * Deciding is the reviewer's job, and it applies to their own entries too: the server
			 * allows it, and `decidedBy` records who answered. Both kinds of pending row are decided
			 * through the same endpoint, so both offer the same two answers: a candidate is published
			 * or discarded, a downRequest is carried out or declined. The direct take-down is not here
			 * any more: it is the manage view's one action, and the server would 409 a decision on an
			 * entry with nothing pending.
			 */
			if (this.canReview && (candidate || downRequest)) {
				out.push('approve', 'reject')
			}
			return out
		},

		/**
		 * The label of the `requestOnline` action for one entry: a first publication on an internal
		 * entry, an update of the served version on an external one. One method, so the tree, the
		 * list and the toast cannot call the same action by two different names.
		 */
		requestOnlineLabel(entry) {
			return String(entry?.state || '') === 'external'
				? t('pages', 'Request the update online')
				: t('pages', 'Request online')
		},

		/** Open a row of the flat list: the same call the tree makes for the same node. */
		openEntry(entry) {
			this.openNode(entry)
		},

		/**
		 * Hide the rejection note of the open article for the rest of this session. Not a decision:
		 * the manifest keeps the note, and a reload shows the banner again.
		 */
		dismissRejectNote() {
			if (this.currentFile && !this.dismissedRejectNotes.includes(this.currentFile)) {
				this.dismissedRejectNotes = [...this.dismissedRejectNotes, this.currentFile]
			}
		},

		/**
		 * One entry through one of the visibility actions. They are separate endpoints but one shape, so
		 * they share the busy flag, the toast and the refresh — the list and the tree call this too.
		 *
		 * The entry is read before the call: the toast has to describe what this action did to *that*
		 * state, and the refresh below already returns the new one.
		 *
		 * @param {'requestOnline'|'requestOffline'|'cancel'|'approve'|'reject'} kind
		 */
		async runAction(kind, path) {
			if (!path || this.acting) {
				return
			}
			const entry = this.nodes.find((node) => this.nodeTarget(node) === path) || null
			this.acting = true
			try {
				const result = await this.callAction(kind, path)
				if (!result.ok) {
					this.showBanner('error', reasonFor(result))
					return
				}
				this.showBanner('success', this.actionText(kind, path, entry))
				await this.refreshNav()
			} catch (error) {
				this.showBanner('error', reasonFor(error))
			} finally {
				this.acting = false
			}
		},

		/**
		 * The one request that performs an action, so the message and the call cannot drift apart.
		 *
		 * The direct take-down is not here: it is the manage view's own action (`api.takeDown`), so no
		 * `offline` kind reaches this method and a `reject` never has to mean "approve" depending on
		 * the entry.
		 */
		async callAction(kind, path) {
			switch (kind) {
			case 'requestOnline':
				return api.requestVisibility(path, 'online')
			case 'requestOffline':
				return api.requestVisibility(path, 'offline')
			case 'cancel':
				return api.cancelRequest(path)
			case 'approve':
				return api.decideRequest(path, 'approve')
			default:
				// `reject` from the header carries no note: the request panel is where a reason is typed
				return api.decideRequest(path, 'reject')
			}
		},

		/**
		 * What an action means, in one line.
		 *
		 * `requestOnline` means two things depending on the entry: a first publication on an internal
		 * entry, an update of the served version on an external one. A rejection has two outcomes and
		 * the sentence has to name the right one: refusing a take-offline request keeps the page on
		 * the site, refusing an online request keeps it internal. Anything else would tell a reviewer
		 * the page went away when it did not.
		 */
		actionText(kind, path = '', entry = null) {
			return {
				requestOnline: String(entry?.state || '') === 'external'
					? t('pages', 'Request the update online')
					: t('pages', 'Online requested'),
				requestOffline: t('pages', 'Offline requested'),
				cancel: t('pages', 'Request withdrawn'),
				approve: t('pages', 'Approved'),
				reject: intentOf(entry) === 'offline'
					? t('pages', 'Take-offline declined: %s stays online.', [path])
					: t('pages', 'Rejected'),
			}[kind] || t('pages', 'Done')
		},


		/** Close the open entry and return to the flat list, asking first when text would be lost. */
		async backToList() {
			if (!(await this.confirmDiscard(this.editorDirty))) {
				return
			}
			this.currentFile = ''
			this.currentEtag = ''
			this.conflict = false
			this.resetArticleState()
			/*
			 * The editor is cleared *before* `dirty` is reset: `setDoc('')` is a `docChanged`, so
			 * `onChange` sets `dirty` (and mirrors the now-empty body) while this runs. With no file
			 * open `editorDirty` falls back to `dirty`, so resetting it last is what keeps the list
			 * view - and a page refresh from it - from looking like unsaved work.
			 */
			this.editor?.setDoc('')
			this.dirty = false
		},

		async deleteCurrentFile() {
			if (!this.currentFile) {
				return
			}
			// Delete button in the editor header: the tree's own delete, with the tree node as its
			// subject - a published entry needs the node's `published` flag for the three-way question.
			const entry = this.currentEntry
			await this.deleteNode({
				...(entry || {}),
				path: this.currentFile,
				target: this.currentFile,
				name: this.entryName(entry, this.currentFile),
				type: 'file',
			})
		},

		/** The Files app link of the open resource. */
		openResourceInFiles() {
			if (this.resourceNode?.id) {
				window.open(generateUrl(`/f/${this.resourceNode.id}`), '_blank', 'noopener')
			}
		},

		/**
		 * Replace the open resource with the file the author picked. The bytes travel base64-encoded
		 * through the same whole-file endpoint, so the ownership rules, the etag lock and the bridge
		 * notification are the ones a text save has.
		 */
		async onReplaceChosen(event) {
			const input = event?.target
			const file = input?.files?.[0]
			if (!file || !this.currentFile) {
				return
			}
			this.replacing = true
			try {
				const bytes = await new Promise((resolve, reject) => {
					const reader = new FileReader()
					reader.onload = () => resolve(String(reader.result || ''))
					reader.onerror = () => reject(reader.error)
					reader.readAsDataURL(file)
				})
				const base64 = String(bytes).replace(/^data:[^,]*,/, '')
				const result = await api.writeFileBytes(this.currentFile, base64, this.currentEtag)
				if (!result.ok) {
					this.showBanner('error', result.message)
					return
				}
				this.currentEtag = result.data.etag || this.currentEtag
				this.dirty = false
				await this.refreshNav()
				this.showBanner('success', t('pages', 'File replaced, rebuilding…'))
			} catch (error) {
				this.showBanner('error', t('pages', 'The file could not be read:') + ' ' + (error?.message || String(error)))
			} finally {
				this.replacing = false
				if (input) {
					input.value = ''
				}
			}
		},

		/**
		 * The one way into a publish: open the confirmation. Every entry point calls this (the
		 * toolbar and the request panel), so a publish always says what it will do first, and
		 * `publish()` below is the only place that calls the endpoint.
		 */
		askPublish() {
			if (!this.canPublish || this.publishing) {
				return
			}
			this.publishMessage = ''
			this.publishConfirm = true
		},

		/** The confirmation's primary button: close the dialog and publish. */
		runPublish() {
			this.publishConfirm = false
			this.publish()
		},

		/** The commit message field of the dialog; kept in data so the dialog is controlled. */
		onPublishMessage(value) {
			this.publishMessage = typeof value === 'string' ? value : ''
		},

		/**
		 * Commit and push through the one publish endpoint.
		 *
		 * The answer is the outcome alone: `commit` ('' when there was nothing to commit), `pushed`,
		 * `remote_configured`. What the commit carried is a fact of the folders now, so the page does
		 * not report `paths`/`skipped`/`stale` any more - only whether a commit happened and whether
		 * the push went out. A failure is the server's message - the bridge's own error, not a generic
		 * one.
		 */
		async publish() {
			if (!this.canPublish || this.publishing) {
				return
			}
			this.publishing = true
			let result
			try {
				result = await api.publish(String(this.publishMessage || '').trim())
			} finally {
				this.publishing = false
			}
			const data = result?.data && typeof result.data === 'object' ? result.data : {}
			if (!result.ok) {
				if (data.error === 'WORK_DRIFT') {
					// The bridge refused before staging anything: nothing was committed, and the commit
					// tree has to be rebuilt with Apply source before a publish can go through. No
					// retry and no mirror from here — both would act on a tree the user has to repair.
					this.showBanner('error', this.workDriftMessage(data))
					return
				}
				// A failed push is the one publish failure with wording of its own, and the server
				// already phrased it (推送失败 …). Anything else keeps the server's message.
				const message = String(result.message || data.message || t('pages', 'Publishing failed'))
				this.showBanner('error', data.push_failed === true
					? t('pages', 'Publishing failed') + ': ' + message
					: message)
				// the commit may have happened even though the push failed: the status and the list
				// both changed, so they are re-read instead of being left at the pre-publish state
				this.refreshStatus()
				if (data.push_failed === true) {
					await this.refreshNav()
				}
				return
			}
			const commit = String(data.commit || '')
			if (commit === '') {
				// the endpoint answers an empty commit when the trees held nothing new; it is not a
				// failure, but saying "Published" would claim a commit that does not exist
				this.showBanner('warning', t('pages', 'Nothing to publish'))
			} else {
				// Two toasts, and no third: a commit is always published; a push only happened when
				// a remote is configured, and the answer says whether it went out
				const hash = commit.slice(0, 8)
				this.showBanner('success', data.pushed === true
					? t('pages', 'Published: %s · pushed', [hash])
					: t('pages', 'Published: %s', [hash]))
			}
			this.refreshStatus()
			await this.refreshNav()
		},

		/**
		 * What a `WORK_DRIFT` publish refusal says.
		 *
		 * The bridge refuses a publish whose commit tree is not the current `source/` + `online/`
		 * assembly, and it refuses before staging anything: nothing was committed, and publishing
		 * again would fail the same way. The counts and the first targets are the bridge's own
		 * measurement; Apply source is the operation that rebuilds the tree. A publish is not retried
		 * from here and no mirror is started, because both would act on a tree the user has to repair.
		 *
		 * @param {object} data the refused publish answer (`{error, message, details}`)
		 * @returns {string}
		 */
		workDriftMessage(data) {
			// `details` is where BridgeException leaves the bridge's extra fields; an answer that
			// carries them at the top level instead is read the same way.
			const details = data?.details && typeof data.details === 'object' && !Array.isArray(data.details)
				? data.details
				: (data || {})
			const count = (value) => {
				const number = Number(value)
				return Number.isFinite(number) ? String(number) : '?'
			}
			let message = t('pages', 'Nothing was committed or published: the commit tree does not match the site’s source and published files (missing %1$s, different %2$s, extra %3$s). Run “Apply source” first, then publish again.', [
				count(details.missing),
				count(details.different),
				count(details.extra),
			])
			const targets = details.targets && typeof details.targets === 'object' ? details.targets : {}
			const paths = []
			for (const kind of ['missing', 'different', 'extra']) {
				for (const path of Array.isArray(targets[kind]) ? targets[kind] : []) {
					if (paths.length < 3) {
						paths.push(String(path))
					}
				}
			}
			if (paths.length > 0) {
				message += ' ' + t('pages', 'First affected files: %s', [paths.join(', ')])
			}
			return message
		},

		/**
		 * Read the bridge's status (HEAD, build id, remote). `quiet` is the polling mode: a failing
		 * 1.5 s poll must not put a toast on a page somebody is working in, so only a foreground call
		 * reports its error.
		 */
		async refreshStatus(quiet = false) {
			if (!this.canEdit && !this.canPublish) {
				return
			}
			if (!this.siteReady) {
				return
			}
			const result = await api.status()
			if (!result.ok) {
				if (!quiet) {
					this.showBanner('error', result.message)
				}
				this.status = null
				return
			}
			const previousBuild = this.status?.last_build?.build_id
			this.status = result.data
			const currentBuild = result.data.last_build?.build_id
			if (previousBuild && currentBuild && currentBuild !== previousBuild) {
				this.refreshPreview()
			}
		},

		// ------------------------------------------------------------------ the split

		/**
		 * Clamp and persist the editor's share of the split.
		 *
		 * The clamp is the contract of the whole handle: a share outside 25–75% would leave one pane
		 * unusable at any window width, which no drag can be allowed to do. The write is best-effort —
		 * private mode refuses `localStorage` and the drag still has to work for this visit.
		 *
		 * @param {number|string} value the requested share, 0–1
		 */
		setSplitRatio(value) {
			const ratio = Math.min(0.75, Math.max(0.25, Number(value) || 0.5))
			this.splitRatio = ratio
			try {
				window.localStorage.setItem(SPLIT_RATIO_KEY, String(ratio))
			} catch (error) {
				// nothing to do: the divider works for this visit, it just is not remembered
			}
		},

		/**
		 * Follow the pointer while the divider is dragged.
		 *
		 * The rect is measured once, on pointerdown: the split's box does not move while the pointer
		 * travels, so measuring per move would only cost a layout read per event. The listeners live
		 * on the window (not the handle), which is what keeps the drag alive when the pointer leaves
		 * the 6px strip; `setPointerCapture` covers the case of leaving the window itself.
		 *
		 * @param {PointerEvent} event the pointerdown on the handle
		 */
		startSplitResize(event) {
			const split = this.$refs.splitEl
			if (!split) {
				return
			}
			const rect = split.getBoundingClientRect()
			if (rect.width <= 0) {
				return
			}
			// A second pointerdown without a pointerup would stack the listeners of the first drag.
			this.stopSplitResize()
			this.splitResizing = true
			const onMove = (moveEvent) => this.setSplitRatio((moveEvent.clientX - rect.left) / rect.width)
			const onUp = () => this.stopSplitResize()
			this.splitCleanup = () => {
				window.removeEventListener('pointermove', onMove)
				window.removeEventListener('pointerup', onUp)
				window.removeEventListener('pointercancel', onUp)
			}
			window.addEventListener('pointermove', onMove)
			window.addEventListener('pointerup', onUp)
			window.addEventListener('pointercancel', onUp)
			// The handle keeps receiving the pointer once it leaves the strip, so the drag stays smooth.
			event.target?.setPointerCapture?.(event.pointerId)
		},

		/** End the running drag: remove its listeners and drop the resizing class. Safe to call twice. */
		stopSplitResize() {
			if (typeof this.splitCleanup === 'function') {
				this.splitCleanup()
			}
			this.splitCleanup = null
			this.splitResizing = false
		},

		/** Back to the middle: what a double-click on the handle and `Home` do. */
		resetSplit() {
			this.setSplitRatio(0.5)
		},

		/**
		 * ←/→ move the divider by 5%; `Home` resets it. The handle is a separator with `tabindex="0"`,
		 * so this is the keyboard equivalent of the drag — and it keeps the same clamp and persistence.
		 *
		 * @param {KeyboardEvent} event the keydown on the handle
		 */
		onSplitKeydown(event) {
			const step = 0.05
			if (event.key === 'ArrowLeft') {
				event.preventDefault()
				this.setSplitRatio(this.splitRatio - step)
			} else if (event.key === 'ArrowRight') {
				event.preventDefault()
				this.setSplitRatio(this.splitRatio + step)
			} else if (event.key === 'Home') {
				event.preventDefault()
				this.resetSplit()
			}
		},

		// ------------------------------------------------------------------ preview

		/** Show or hide the article preview pane; showing it fetches a token when none is held yet. */
		togglePreview() {
			this.previewVisible = !this.previewVisible
			if (this.previewVisible && !this.previewToken) {
				this.loadPreview()
			}
		},

		/**
		 * Follow the preview where the reader goes. Clicking a link inside the frame navigates it, and
		 * the address this pane hands to "Open in new tab" has to say where that led: keeping the path
		 * the pane was opened with is a wrong answer to "what am I looking at". The frame is
		 * same-origin (the app serves it), so its location is readable; a frame we may not inspect is
		 * left alone rather than throwing on every load.
		 */
		onPreviewNavigated() {
			// The only frame the template renders now is the article preview; the path it reports
			// feeds `previewSrc`, which "Open in new tab" uses.
			const win = this.$refs.previewFrame?.contentWindow || this.$refs.articleFrame?.contentWindow
			if (!win) {
				return
			}
			try {
				// the mount is /preview/<token>/<path>: everything before the path is the app's own URL
				const path = win.location.pathname.replace(/^.*\/preview\/[^/]+\//, '')
				const next = (path || 'index.html') + (win.location.search || '')
				if (next !== this.previewPath) {
					this.previewPath = next
				}
			} catch (error) {
				// nothing to do: the field keeps the requested address, which is the honest fallback
			}
		},

		async loadPreview(force = false) {
			/*
			 * Who wants the token is `previewTokenWanted` — the article preview while its article is
			 * open, and the Read view whenever it is the view. The last case is why this is not decided
			 * by `canPreviewArticle` alone: a reader never opens an editor file, so that test is
			 * always false for them.
			 */
			if (!this.previewTokenWanted) {
				return
			}
			const now = Math.floor(Date.now() / 1000)
			// Refresh 5 minutes before expiry — a long-lived tab must not hit a blank frame
			if (!force && this.previewToken && this.previewExp - now > 300) {
				this.applyPreviewSrc()
				return
			}
			const result = await api.session()
			if (!result.ok) {
				// A blank frame must never be silent: a missing token has to be reported
				this.previewToken = ''
				this.previewSrc = 'about:blank'
				this.showBanner('error', t('pages', 'Could not get a preview token:') + result.message)
				return
			}
			this.previewToken = result.data.token || ''
			this.previewExp = result.data.exp || 0
			this.previewBase = result.data.base || ''
			if (!this.previewToken) {
				this.previewSrc = 'about:blank'
				this.showBanner('error', t('pages', 'The bridge did not return a preview token'))
				return
			}
			this.applyPreviewSrc()
			this.armPreviewRenewal()
		},

		/**
		 * Ask for a new token before the old one expires. The article preview can stay open for a
		 * long time — somebody writing a post — and a token that runs out turns the pane into an
		 * error page under their hands, which reads as "the site is broken" rather than
		 * "the preview expired".
		 */
		armPreviewRenewal() {
			window.clearTimeout(this.previewTimer)
			if (!this.previewExp) {
				return
			}
			// a minute before expiry, and never sooner than a minute from now
			const seconds = Math.max(60, this.previewExp - Math.floor(Date.now() / 1000) - 60)
			this.previewTimer = window.setTimeout(() => {
				this.previewTimer = null
				this.loadPreview(true)
			}, seconds * 1000)
		},

		applyPreviewSrc() {
			if (!this.previewToken) {
				return
			}
			const path = this.previewPath.replace(/^\/+/, '')
			this.previewSrc = this.previewBase + path
		},

		refreshPreview() {
			// The article preview shows the built site, so a finished build means a new page for it. The
			// `previewFrame` ref is no longer rendered; it is kept in the list so a frame of that name,
			// should one return, is reloaded too. A page that does not listen ignores the message.
			for (const ref of ['previewFrame', 'articleFrame']) {
				try {
					this.$refs[ref]?.contentWindow?.postMessage({ type: 'pages:reload' }, window.location.origin)
				} catch (error) {
					this.applyPreviewSrc()
				}
			}
		},

		/** Open the built site at the tracked preview path in a new tab; the token in that URL is the credential. */
		openPreviewInNewTab() {
			if (this.previewSrc && this.previewSrc !== 'about:blank') {
				window.open(this.previewSrc, '_blank', 'noopener')
			}
		},

		// ------------------------------------------------------------------ misc

		/**
		 * Show one piece of feedback as a Nextcloud toast. The four types the callers use map onto
		 * the four toast flavours; anything else is informational. The name stays `showBanner`: the
		 * Read view receives this method as its `showBanner` prop.
		 */
		showBanner(type, message) {
			const options = { timeout: 8000 }
			switch (String(type || '')) {
			case 'error':
				showError(message, options)
				break
			case 'success':
				showSuccess(message, options)
				break
			case 'warning':
				showWarning(message, options)
				break
			default:
				showInfo(message, options)
			}
		},

		// ------------------------------------------------------------------ push retry

		/**
		 * The push failure recorded for one entry, or null. A method and not a computed: the template and
		 * the article rows call it with a node, and a computed would be evaluated once as a property.
		 *
		 * @param {object} node a tree node
		 * @returns {object|null}
		 */
		pushFailedFor(node) {
			const target = node ? this.nodeTarget(node) : ''
			if (!target) {
				return null
			}
			return this.pushFailed[target] || null
		},

		/** The tooltip of a mark: why the push failed, and whether trying again can help. */
		pushFailureHint(node) {
			const failure = this.pushFailedFor(node)
			if (!failure) {
				return ''
			}
			const reason = failure.message || failure.code || ''
			return failure.retryable === false
				? t('pages', 'The bridge refused this push: %s', [reason])
				: t('pages', 'The bridge did not answer: %s', [reason])
		},

		/**
		 * Records the failure of one entry's push. The map is replaced rather than mutated so that Vue
		 * sees one change (and a key added later is tracked the same way an existing one is).
		 */
		markPushFailed(target, bridge) {
			if (!target) {
				return
			}
			this.pushFailed = {
				...this.pushFailed,
				[target]: {
					code: String(bridge?.error || 'ERROR'),
					message: String(bridge?.message || ''),
					retryable: bridge?.retryable !== false,
				},
			}
		},

		/** Forgets one entry's mark (the push that covers it went through). */
		clearPushFailed(target) {
			if (!target || !(target in this.pushFailed)) {
				return
			}
			const next = { ...this.pushFailed }
			delete next[target]
			this.pushFailed = next
		},

		/**
		 * Repeat the push a save could not deliver, for the author whose entry was clicked.
		 *
		 * One push covers every author's target list (that is what the save path sends), so a successful
		 * retry clears every mark instead of only the clicked one - the others are stale by then. A
		 * refused push keeps the mark and says why. Neither call can create a commit: this is the
		 * configuration push (`POST /api/site/push`), and commits only happen in `POST /api/site/publish`.
		 *
		 * @param {object} subject a tree node, or an article row (`{entry}`) from the table
		 */
		async retryPush(subject) {
			if (this.retryingPush) {
				return
			}
			const node = subject && subject.entry ? subject.entry : subject
			const target = node ? this.nodeTarget(node) : ''
			this.retryingPush = true
			try {
				const result = await api.pushAuthorSources()
				const bridge = result?.data?.bridge || {}
				if (bridge.pushed === true) {
					this.pushFailed = {}
					this.showBanner('success', t('pages', 'Pushed to the bridge.'))
					// the tree is re-read: the author area may have gained files the mirror now has too
					await this.refreshNav()
					return
				}
				if (!result?.ok) {
					this.showBanner('error', t('pages', 'The retry failed:') + ' ' + (result?.message || ''))
					return
				}
				// the endpoint answered 200 with `pushed: false` - exactly what the save answer said
				this.markPushFailed(target, bridge)
				this.showBanner('warning', t('pages', 'The entry is saved, but the bridge did not take the push:') + ' ' + (bridge.message || '?'))
			} finally {
				this.retryingPush = false
			}
		},
	},
}
</script>

<style scoped>
/*
 * App layout root: .app-content-wrapper is a plain block container, so this layer
 * provides the flex column and keeps min-height:0 at every level.
 */
.pages-root {
	display: flex;
	flex-direction: column;
	height: 100%;
	min-height: 0;
	overflow: hidden;
}

.pages-nav-new {
	padding: 6px 8px 2px;
	list-style: none;
}

/* A navigation column whose load failed: a line of its own, instead of an empty column. */
.pages-nav-error {
	padding: 8px 12px;
	list-style: none;
	font-size: 12px;
	line-height: 1.5;
	color: var(--color-error);
	overflow-wrap: anywhere;
}

/*
 * The review state of one node, in the column's counter slot. Small and quiet: it is a hint about
 * what the reviewer will do with the file, not the file's name.
 */
.pages-badge {
	display: inline-block;
	padding: 0 6px;
	border-radius: var(--border-radius-pill, 999px);
	font-size: 10px;
	line-height: 16px;
	white-space: nowrap;
	border: 1px solid var(--color-border);
	color: var(--color-text-maxcontrast, var(--color-main-text));
}

.pages-badge--info {
	border-color: var(--color-primary-element);
	color: var(--color-primary-element);
}

.pages-badge--success {
	border-color: var(--color-success);
	color: var(--color-success-text, var(--color-success));
}

.pages-badge--error {
	border-color: var(--color-error);
	color: var(--color-error-text, var(--color-error));
}

/*
 * A file of the author's tree that is not a page: it has facts and actions but no text, so the pane
 * does not pretend to be an editor.
 */
.pages-resource {
	display: flex;
	flex-direction: column;
	gap: 10px;
	padding: 12px;
	flex: 0 0 auto;
}

.pages-resource__hint {
	margin: 0;
	font-size: 13px;
	line-height: 1.5;
	opacity: 0.8;
}

.pages-resource__facts {
	display: flex;
	flex-wrap: wrap;
	gap: 4px 24px;
	margin: 0;
	font-size: 12px;
}

.pages-resource__facts dt {
	font-weight: 600;
	opacity: 0.7;
}

.pages-resource__facts dd {
	margin: 0;
	overflow-wrap: anywhere;
}

.pages-resource__actions {
	display: flex;
	align-items: center;
	gap: 10px;
	flex-wrap: wrap;
}

/* A file input dressed as a button: the browser's own control cannot be styled to match. */
.pages-resource__replace {
	position: relative;
	display: inline-block;
	padding: 6px 14px;
	border-radius: var(--border-radius);
	background-color: var(--color-primary-element);
	color: var(--color-primary-element-text, #fff);
	font-size: 13px;
	cursor: pointer;
}

.pages-resource__replace input {
	position: absolute;
	inset: 0;
	opacity: 0;
	cursor: pointer;
}

/*
 * The app-navigation toggle is not a child of this pane and not in its flow: NcAppNavigation keeps
 * it outside <nav> as `.app-navigation-toggle-wrapper`, absolutely positioned and pulled one
 * `--default-clickable-area` past the navigation's inline end (dist/assets/NcAppNavigation.css:
 * `inset-inline-end: calc(0px - var(--app-navigation-padding))` +
 * `margin-inline-end: calc(-1 * var(--default-clickable-area))`). In LTR that lands on the first
 * `--app-navigation-padding + --default-clickable-area` of the content pane, so a toolbar that
 * starts at the pane edge has its first control under it — the view switch is that control. There is
 * no toolbar slot in NcAppContent to hand the toggle to
 * (it only offers `list` and the default slot), so the toolbar row itself keeps clear of the
 * toggle's footprint: its own inset (3 baselines, the 12px it always had) starts after that
 * footprint instead of at the pane edge. Derived from the platform's variables so it follows the
 * theme (a compact 34px control, an accessibility-enlarged 44px one) and stays correct when the
 * column is collapsed — the toggle keeps the same offset from the pane edge as the navigation
 * slides away.
 */
.pages-toolbar {
	display: flex;
	align-items: center;
	gap: 8px;
	padding: 8px 12px;
	padding-inline-start: calc(
		var(--app-navigation-padding, calc(var(--default-grid-baseline, 4px) * 2))
		+ var(--default-clickable-area, 34px)
		+ var(--default-grid-baseline, 4px) * 3
	);
	border-bottom: 1px solid var(--color-border);
	flex: 0 0 auto;
	flex-wrap: wrap;
}

.pages-toolbar__spacer {
	flex: 1 1 auto;
}

/* The view switch is a segmented control: adjacent buttons, no gap between the segments. */
.pages-viewswitch {
	display: flex;
	align-items: center;
	gap: 0;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	overflow: hidden;
	flex: 0 0 auto;
}

.pages-viewswitch :deep(.button-vue) {
	border-radius: 0;
}

.pages-status {
	font-size: 12px;
	opacity: 0.7;
	white-space: nowrap;
}

.pages-status--error {
	color: var(--color-error);
	opacity: 1;
}

/* The way across to the other page: no such link is rendered now that there is one page only. */
.pages-pagelink {
	font-size: 13px;
	white-space: nowrap;
}

/*
 * The two panes share the width through `--pages-split-editor`: the drag handle writes it inline on
 * this element, so the editor's share is one value instead of two flex bases that can disagree.
 */
.pages-split {
	--pages-split-editor: 50%;
	display: flex;
	flex: 1 1 auto;
	min-height: 0;
	overflow: hidden;
}

.pages-pane {
	display: flex;
	flex-direction: column;
	min-width: 0;
	min-height: 0;
	overflow: hidden;
}

.pages-pane--editor {
	flex: 0 0 var(--pages-split-editor);
}

/* The divider is the handle between the panes, so the preview pane carries no border of its own. */
.pages-pane--preview {
	flex: 1 1 auto;
	border-inline-start: 0;
}

/*
 * The divider: a 6px strip that carries the line, so the seam is a pointer target instead of a
 * one-pixel accident. `touch-action: none` keeps a touch drag for the handle instead of the page.
 */
.pages-split__handle {
	flex: 0 0 6px;
	cursor: col-resize;
	background: transparent;
	border-inline-start: 1px solid var(--color-border);
	touch-action: none;
	position: relative;
}

.pages-split__handle:hover,
.pages-split__handle:focus-visible {
	background: var(--color-primary-element-light);
	outline: none;
}

/* While dragging, the whole page keeps the resize cursor and selects no text. */
.pages-split--resizing {
	cursor: col-resize;
	user-select: none;
}

.pages-split--resizing * {
	user-select: none !important;
}

/*
 * Without the preview there is no handle, so nothing divides the split: the editor takes the whole
 * width. The preview pane stays mounted (`v-show`) and is simply hidden on its own.
 */
.pages-split--solo .pages-pane--editor {
	flex: 1 1 100%;
}

.pages-split--solo .pages-pane--preview {
	flex: 1 1 100%;
	border-inline-start: 0;
}

/*
 * The editor pane is always in the DOM (CodeMirror mounts into it once), so it is taken out of the
 * layout instead of being unmounted. Nothing applies this class any more: the pane is hidden with
 * `v-show` and the split collapses through `.pages-split--solo`.
 */
.pages-pane--hidden {
	display: none;
}

.pages-pane__head {
	display: flex;
	align-items: center;
	gap: 8px;
	padding: 4px 8px;
	border-bottom: 1px solid var(--color-border);
	font-size: 12px;
	flex: 0 0 auto;
	min-height: 36px;
	/* Narrow panes wrap the controls instead of squeezing the title out of the row. */
	flex-wrap: wrap;
}

/*
 * The Back button is the first control of the pane header. It needs no inline inset of its own:
 * this header is a row below the toolbar, and the toolbar is the layer that keeps clear of the
 * navigation toggle's absolute footprint (see .pages-toolbar). It only has to keep its width, so
 * a long path cannot squeeze the label away.
 */
.pages-pane__back {
	flex: 0 0 auto;
}

.pages-pane__title {
	font-family: var(--font-face-monospace, monospace);
	opacity: 0.85;
	/* The one shrinking cell of the header: long paths shorten here, never in the buttons. */
	min-width: 0;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

.pages-pane__meta {
	opacity: 0.5;
	margin-inline-start: auto;
	white-space: nowrap;
}

/* The metadata of the pane header sits right next to the title, so it must not take the auto margin */
.pages-pane__meta--tight {
	margin-inline-start: 6px;
}

/* A refused save: the text is still in the editor, so the bar only offers the way forward. */
.pages-conflict {
	display: flex;
	align-items: center;
	gap: 8px;
	flex-wrap: wrap;
	padding: 6px 12px;
	font-size: 12px;
	color: var(--color-error);
	border-bottom: 1px solid var(--color-border);
	flex: 0 0 auto;
}

/*
 * The reason a request about the open article was rejected: a note above the fields, with the
 * dismiss control on its own line. The card's own spacing is replaced so it sits in the pane
 * instead of looking like a page-level notice.
 */
.pages-reject-note {
	margin: 8px 12px 0;
	flex: 0 0 auto;
}

.pages-reject-note__row {
	display: flex;
	align-items: flex-start;
	gap: 8px;
}

.pages-reject-note__row > span {
	flex: 1 1 auto;
	min-width: 0;
}

.pages-pane__spacer {
	flex: 1 1 auto;
}

/* ------------------------------------------------------------------ new file */

.pages-newfile {
	display: flex;
	flex-direction: column;
	gap: 12px;
}

.pages-newfile__presets {
	display: flex;
	flex-wrap: wrap;
	gap: 6px;
}

.pages-newfile__hint {
	margin: 0;
	font-size: 12px;
	opacity: 0.65;
	line-height: 1.5;
}

.pages-newfile__where {
	margin: 0;
	font-size: 12px;
	opacity: 0.75;
}

.pages-newfile__where code {
	font-family: var(--font-face-monospace, monospace);
	background: var(--color-background-dark);
	padding: 1px 5px;
	border-radius: var(--border-radius);
}

/*
 * The three-dot button must always be visible on touch devices; :deep() is required
 * because the button is rendered inside NcAppNavigationItem.
 */
@media (hover: none) {
	.pages-node:deep(.app-navigation-entry__utils .action-item.app-navigation-entry__actions) {
		position: relative !important;
		opacity: 1 !important;
		pointer-events: auto !important;
	}
}

/* the entry list: a page of its own, not a table glued to the pane edge */
.pages-entries {
	padding: 24px 32px;
	box-sizing: border-box;
	overflow: auto;
	height: 100%;
}

.pages-entries__head {
	display: flex;
	align-items: center;
	gap: 12px;
	margin-bottom: 16px;
	flex-wrap: wrap;
}

.pages-entries__headings {
	display: flex;
	align-items: baseline;
	gap: 10px;
	min-width: 0;
}

.pages-entries__title {
	margin: 0;
	font-size: 20px;
	font-weight: 600;
}

.pages-entries__count {
	font-size: 13px;
	opacity: 0.6;
}

.pages-entries__spacer {
	flex: 1 1 auto;
}

.pages-entries__table {
	width: 100%;
	border-collapse: collapse;
	font-size: 14px;
}

.pages-entries__table th {
	text-align: left;
	font-size: 12px;
	font-weight: 600;
	opacity: 0.65;
	padding: 0 12px 10px;
	border-bottom: 1px solid var(--color-border);
}

.pages-entries__table td {
	padding: 10px 12px;
	border-bottom: 1px solid var(--color-border);
}

.pages-entries__row:hover td {
	background-color: var(--color-background-hover);
}

/* the name reads as a link, not as a pressed button */
.pages-entries__open {
	background: none;
	border: 0;
	padding: 0;
	font: inherit;
	color: var(--color-main-text);
	font-weight: 500;
	cursor: pointer;
	text-align: left;
	max-width: 100%;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

.pages-entries__open:hover,
.pages-entries__open:focus-visible {
	color: var(--color-primary-element);
	text-decoration: underline;
}

.pages-entries__col--state,
.pages-entries__col--date,
.pages-entries__col--actions {
	width: 1%;
	white-space: nowrap;
	opacity: 0.95;
}

.pages-entries__tools {
	display: flex;
	justify-content: flex-end;
	gap: 2px;
}

.pages-entries__muted {
	opacity: 0.55;
}

.pages-entries__empty {
	margin-top: 48px;
	text-align: center;
}

.pages-entries__empty-title {
	margin: 0 0 4px;
	font-size: 16px;
	font-weight: 600;
}

/* the two marks of a tree row sit together and stay quiet next to the name */
.pages-node__marks {
	display: flex;
	align-items: center;
	gap: 4px;
}

/*
 * "not pushed · retry" on a tree row: a real button, because it is the one control that fixes the state
 * it describes. It is small and quiet so it never competes with the visibility badge next to it, and it
 * carries its own border so it reads as clickable inside a row that is itself clickable.
 */
.pages-node__push {
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-pill, 12px);
	background: transparent;
	color: var(--color-error-text, var(--color-error));
	font-size: 11px;
	line-height: 16px;
	padding: 0 6px;
	cursor: pointer;
	white-space: nowrap;
}

.pages-node__push:hover,
.pages-node__push:focus-visible {
	background-color: var(--color-background-hover);
}

.pages-node__push:disabled {
	opacity: 0.5;
	cursor: default;
}

.pages-badge--offline {
	opacity: 0.55;
}

.pages-articles-view {
	padding: 20px 24px;
	box-sizing: border-box;
	overflow: auto;
	height: 100%;
}

.pages-articles-view__head {
	display: flex;
	align-items: center;
	gap: 12px;
	margin-bottom: 16px;
	flex-wrap: wrap;
}

.pages-articles-view__title {
	margin: 0;
	font-size: 20px;
	font-weight: 600;
}

.pages-articles-view__counts {
	font-size: 13px;
	opacity: 0.65;
}

.pages-articles-view__spacer {
	flex: 1 1 auto;
}

.pages-articles-view__empty {
	margin-top: 40px;
	text-align: center;
}

.pages-articles-view__muted {
	opacity: 0.6;
}

.pages-editor-host {
	flex: 1 1 auto;
	min-height: 0;
	overflow: auto;
	position: relative;
}

.pages-editor-hint {
	padding: 24px;
	opacity: 0.5;
}

.pages-preview-path {
	flex: 1 1 auto;
	min-width: 0;
	font-family: var(--font-face-monospace, monospace);
	font-size: 12px;
}

.pages-preview-frame {
	flex: 1 1 auto;
	width: 100%;
	min-height: 0;
	border: 0;
	background: #fff;
}

/* The article preview has no address to show: a sentence where the frame would be. */
.pages-preview-hint {
	margin: 0;
	padding: 24px;
	opacity: 0.7;
	font-size: 13px;
}

.pages-diff {
	border-bottom: 1px solid var(--color-border);
	padding: 8px 12px;
	max-height: 40vh;
	overflow: auto;
	flex: 0 0 auto;
}

.pages-diff__head {
	display: flex;
	align-items: center;
	justify-content: space-between;
}

.pages-diff__stat,
.pages-diff__patch {
	margin: 6px 0 0;
	white-space: pre-wrap;
	word-break: break-all;
	font-size: 12px;
	opacity: 0.85;
	max-height: 24vh;
	overflow: auto;
}

.pages-diff__actions {
	display: flex;
	gap: 8px;
	margin-top: 8px;
}

.pages-build-errors {
	padding: 0 12px 12px;
	flex: 0 0 auto;
	max-height: 30vh;
	overflow: auto;
}
</style>

<style>
/*
 * No line numbers in the prose editor: the editor writes articles, not code, and the gutter took a
 * column of width from a pane that is already split with the preview. `basicSetup` cannot be
 * configured (it is an extension array), so the gutter is hidden here rather than left unused. The
 * active line stays highlighted, which is what matters when a build error names a line.
 */
.pages-app .cm-editor .cm-gutters {
	display: none;
}
</style>
