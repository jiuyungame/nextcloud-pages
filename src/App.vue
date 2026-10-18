<!--
	Pages main UI. One application, two pages (see pageState.js and appinfo/routes.php):

	  - the **content** page (`GET /`, route `pages.page.index`): the caller's own articles, the request
	    panel for a reviewer, reading, and the structured article editor. Every signed-in user gets it,
	    and its navigation column is the author's own work — never the site's skeleton.
	  - the **source** page (`GET /source`, route `pages.page.source`): the Jekyll tree, the whole-file
	    editor, build, diff/publish, preview, the status line and the build errors. Publish grant only;
	    the server redirects everybody else to the content page.

	The two halves are branched at the top level on purpose: the editor host below exists once, for the
	page that is showing, so the other page's CodeMirror instance is never created at all.

	NcContent > NcAppNavigation and NcAppContent: hand-rolled divs do not work here, the Nextcloud
	layout CSS depends on this component structure.
-->
<template>
	<NcContent app-name="pages">
		<!-- ============================================================== content page -->
		<template v-if="isContentPage">
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
							@update:menu-open="(open) => onMenuToggle(node, open)"
							@drop="onDrop(node, $event)">
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
									{{ t('pages', 'Request online') }}
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
								<NcActionButton v-if="actionsFor(node).includes('offline')" @click="runAction('offline', nodeTarget(node))">
									<template #icon><Close :size="20" /></template>
									{{ t('pages', 'Take off the site') }}
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
							Which of the three views the content area shows. The nav and the toolbar stay in
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
						</div>

						<NcButton v-if="view === 'site' && (canEdit || authorOpen)" variant="primary" :disabled="!currentFile || saving" @click="save">
							<template #icon>
								<NcLoadingIcon v-if="saving" :size="20" />
								<ContentSave v-else :size="20" />
							</template>
							{{ t('pages', 'Save') }}
						</NcButton>

						<!--
							Publishing from the page a reviewer actually works on. Same endpoint and same
							implementation as the source page's diff (`askPublish` → `publish`), so there is
							one code path and one confirmation. Hidden entirely without the
							review-and-publish grant, disabled while a publish is running.
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

						<!-- The same preview the source page carries: the article being written, as the
						     site serves it. Shown once an article is open, so the pane is never empty. -->
						<NcButton
							v-if="view === 'site' && canPreviewArticle"
							:variant="previewVisible ? 'secondary' : 'tertiary'"
							:pressed="previewVisible"
							@click="togglePreview">
							<template #icon><Monitor :size="20" /></template>
							{{ t('pages', 'Preview') }}
						</NcButton>

						<span class="pages-toolbar__spacer" />

						<!-- The site's own files are the other page's work; this is the way across. -->
						<a v-if="canEdit || canPublish" class="pages-pagelink" :href="sourceUrl">
							{{ t('pages', 'Site source') }}
						</a>
					</div>

					<NcNoteCard v-if="banner" :type="banner.type" class="pages-banner">
						{{ banner.message }}
					</NcNoteCard>

					<RequestsView
						v-if="view === 'requests' && requestsAllowed"
						:target-prefix="targetPrefix"
						@forbidden="onRequestsForbidden"
						@publish="askPublish"
						@decided="onRequestDecided" />

					<ReadView
						v-if="view === 'read'"
						:can-moderate="canReview"
						:preview-base="previewBase"
						:show-banner="showBanner" />

					<!-- `v-show`, not `v-if`: the editor host has to survive a trip through the other
					     views, otherwise the CodeMirror instance stays attached to a removed node -->
					<div v-show="view === 'site'" class="pages-split" :class="{ 'pages-split--solo': !canPreviewArticle || !previewVisible }">
						<div class="pages-pane pages-pane--editor">
							<div class="pages-pane__head">
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
								<!-- Which of the two editors is active. Unobtrusive, but never hidden: the two
								     modes treat the front matter very differently. -->
								<span v-if="currentFile && editorMode" class="pages-pane__mode" :class="{ 'pages-pane__mode--structured': isArticle }">
									{{ editorMode }}
								</span>
								<span v-if="currentFile" class="pages-pane__meta pages-pane__meta--tight">ETag {{ shortEtag }}</span>
								<span class="pages-pane__spacer" />
								<!--
									The visibility actions of the open entry, one set for every place that offers
									them: `currentActions` decides which buttons are real for this state, so the
									header, the list and the tree cannot drift apart.
								-->
								<NcButton
									v-if="currentActions.includes('requestOnline')"
									variant="secondary"
									:disabled="acting"
									@click="runAction('requestOnline', currentFile)">
									<template #icon><Upload :size="20" /></template>
									{{ t('pages', 'Request online') }}
								</NcButton>
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
									v-if="currentActions.includes('offline')"
									variant="secondary"
									:disabled="acting"
									@click="runAction('offline', currentFile)">
									<template #icon><Close :size="20" /></template>
									{{ t('pages', 'Take off the site') }}
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

								<ArticleRows :rows="articleRows" show-live @retry-push="retryPush">
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
											{{ t('pages', 'Request online') }}
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
		</template>

		<!-- =============================================================== source page -->
		<template v-else>
			<NcAppNavigation ref="nav" :aria-label="t('pages', 'Site and files')">
				<template #list>
					<!-- The tree is edit-only; the preview needs no capability, so a user without one still gets the panel below. -->
					<template v-if="canEdit">
						<NcAppNavigationCaption :name="t('pages', 'Files')" />

						<li class="pages-nav-new">
							<NcButton
								variant="secondary"
								:wide="true"
								:disabled="!siteReady || loadingTree"
								@click="openCreate('file', '')">
								<template #icon><Plus :size="20" /></template>
								{{ t('pages', 'New file in site root') }}
							</NcButton>
						</li>

						<!--
							Items must be NcActionButton/NcActionLink: NcActions decides whether to collapse
							them into the three-dot menu or lay them out inline by inspecting child types.
						-->
						<NcAppNavigationItem
							v-for="node in visibleNodes"
							:key="node.path"
							:ref="(el) => setNodeRef(node.path, el)"
							class="pages-node"
							:name="node.name"
							:active="node.path === currentFile"
							:icon="node.type === 'dir' ? iconFolder : iconFile"
							:edit-placeholder="node.name"
							:style="{ paddingInlineStart: (8 + node.depth * 12) + 'px' }"
							@click.prevent.stop="onNodeClick(node)"
							@update:name="(name) => onRename(node, name)"
							@update:menu-open="(open) => onMenuToggle(node, open)">
							<template #actions>
								<NcActionButton @click="openCreate('file', targetDirFor(node))">
									<template #icon><FilePlus :size="20" /></template>
									{{ t('pages', 'New file') }}
								</NcActionButton>
								<NcActionButton @click="openCreate('folder', targetDirFor(node))">
									<template #icon><FolderPlus :size="20" /></template>
									{{ t('pages', 'New folder') }}
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

						<NcAppNavigationItem
							v-if="!visibleNodes.length && !loadingTree"
							:name="t('pages', '(no editable files)')"
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
						<NcButton v-if="view === 'site' && (canEdit || authorOpen)" variant="primary" :disabled="!currentFile || saving" @click="save">
							<template #icon>
								<NcLoadingIcon v-if="saving" :size="20" />
								<ContentSave v-else :size="20" />
							</template>
							{{ t('pages', 'Save') }}
						</NcButton>

						<NcButton v-if="view === 'site' && canEdit" :disabled="!siteReady || building" @click="build">
							<template #icon>
								<NcLoadingIcon v-if="building" :size="20" />
								<Hammer v-else :size="20" />
							</template>
							{{ t('pages', 'Rebuild') }}
						</NcButton>

						<NcButton v-if="view === 'site' && canPublish" :disabled="!siteReady" @click="openDiff">
							<template #icon><SourceBranch :size="20" /></template>
							{{ t('pages', 'Review and publish') }}
						</NcButton>

						<NcButton
							v-if="view === 'site'"
							:variant="previewVisible ? 'secondary' : 'tertiary'"
							:pressed="previewVisible"
							@click="togglePreview">
							<template #icon><Monitor :size="20" /></template>
							{{ t('pages', 'Preview') }}
						</NcButton>

						<span class="pages-toolbar__spacer" />

						<!-- The status line and the build errors belong to whoever edits or publishes: both need them to see what the site is doing. -->
						<span v-if="canEdit || canPublish" class="pages-status" :class="{ 'pages-status--error': statusError }">
							{{ statusText }}
						</span>

						<!-- The other page: the caller's own articles, the request panel and reading. -->
						<a class="pages-pagelink" :href="contentUrl">{{ t('pages', 'Back to articles') }}</a>
					</div>

					<NcNoteCard v-if="banner" :type="banner.type" class="pages-banner">
						{{ banner.message }}
					</NcNoteCard>

					<div v-if="canPublish && diffOpen" class="pages-diff">
						<div class="pages-diff__head">
							<strong>{{ t('pages', 'Pending changes') }}</strong>
							<NcButton variant="tertiary" @click="diffOpen = false">
								{{ t('pages', 'Collapse') }}
							</NcButton>
						</div>
						<pre class="pages-diff__stat">{{ diffStat || t('pages', '(no changes)') }}</pre>
						<pre v-if="diffPatch" class="pages-diff__patch">{{ diffPatch }}</pre>
						<div class="pages-diff__actions">
							<NcButton variant="primary" :disabled="publishing" @click="askPublish">
								<template #icon>
									<NcLoadingIcon v-if="publishing" :size="20" />
									<Upload v-else :size="20" />
								</template>
								{{ t('pages', 'Commit and push') }}
							</NcButton>
							<NcButton variant="tertiary" @click="openDiff">{{ t('pages', 'Reload diff') }}</NcButton>
						</div>
					</div>

					<!-- `v-show`, not `v-if`: CodeMirror is mounted into the host once, when the page
					     starts, and a removed node would take the editor down with it -->
					<div v-show="view === 'site'" class="pages-split" :class="{ 'pages-split--solo': !showEditorPane }">
						<!--
							The editor pane belongs to whoever may write the site; a publisher without the
							edit grant gets the preview alone, so the pane is hidden instead of being left
							empty. It stays in the DOM either way: the editor is mounted into it once.
						-->
						<div class="pages-pane pages-pane--editor" :class="{ 'pages-pane--hidden': !showEditorPane }">
							<div class="pages-pane__head">
								<span class="pages-pane__title">{{ currentFile || t('pages', 'No file selected') }}</span>
								<!-- Which of the two editors is active. Unobtrusive, but never hidden: the two
								     modes treat the front matter very differently. -->
								<span v-if="currentFile" class="pages-pane__mode" :class="{ 'pages-pane__mode--structured': isArticle }">
									{{ editorMode }}
								</span>
								<span v-if="currentFile" class="pages-pane__meta pages-pane__meta--tight">ETag {{ shortEtag }}</span>
								<span class="pages-pane__spacer" />
								<NcButton
									v-if="currentFile && canEdit"
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
								The fields of an article. The editor below holds the body only, so this is the
								one place a title, the date, the tags or the summary can be changed; a raw file
								never shows it, which is what keeps its bytes untouched.
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
								<span>{{ t('pages', 'This article was changed elsewhere.') }}</span>
								<NcButton variant="primary" :disabled="saving" @click="reloadCurrent">
									{{ t('pages', 'Reload the article') }}
								</NcButton>
								<NcButton variant="tertiary" @click="conflict = false">
									{{ t('pages', 'Keep my text') }}
								</NcButton>
							</div>

							<div v-show="currentFile" ref="editorHost" class="pages-editor-host" />
							<div v-if="!currentFile" class="pages-editor-hint">
								{{ canEdit
									? t('pages', 'Select a file on the left to start editing')
									: t('pages', 'Open one of your articles to start editing') }}
							</div>
						</div>

						<div v-show="previewVisible" class="pages-pane pages-pane--preview">
							<div class="pages-pane__head">
								<span class="pages-pane__title">{{ t('pages', 'Preview') }}</span>
								<input
									v-model="previewPath"
									class="pages-preview-path"
									type="text"
									:aria-label="t('pages', 'Path in build output')"
									@keydown.enter="loadPreview(true)">
								<NcButton variant="tertiary" @click="openPreviewInNewTab">
									{{ t('pages', 'Open in new tab') }}
								</NcButton>
								<span class="pages-pane__meta">{{ previewState }}</span>
							</div>
							<iframe
								ref="previewFrame"
								class="pages-preview-frame"
								:src="previewSrc"
								sandbox="allow-scripts allow-same-origin allow-popups allow-forms allow-modals"
								referrerpolicy="no-referrer"
								@load="onPreviewNavigated" />
						</div>
					</div>

					<div v-if="view === 'site' && (canEdit || canPublish) && buildErrors.length" class="pages-build-errors">
						<NcNoteCard type="error">
							<strong>{{ t('pages', 'Build errors') }}</strong>
							<ul>
								<li v-for="(item, index) in buildErrors.slice(0, 8)" :key="index">
									<code>{{ item.file ? item.file + (item.line ? ':' + item.line : '') : '' }}</code>
									{{ item.text }}
								</li>
							</ul>
						</NcNoteCard>
					</div>
				</div>

				<!--
					New file / new folder share one dialog; templates come from the site config.
					Closing invariants: use v-model:open, never set createOpen synchronously in `closing`,
					and pass buttons via the `buttons` prop (a false callback keeps the dialog open).
				-->
				<NcDialog
					v-if="canEdit"
					:key="createDialogKey"
					v-model:open="createOpen"
					:name="createKind === 'folder' ? t('pages', 'New folder') : t('pages', 'New file')"
					:buttons="createButtons"
					size="small"
					@closing="onDialogClosing">
					<div class="pages-newfile">
						<p class="pages-newfile__where">
							{{ t('pages', 'Location:') }}
							<code>{{ createDir || t('pages', '(site root)') }}</code>
						</p>

						<div v-if="createKind === 'file'" class="pages-newfile__presets">
							<NcButton
								v-for="tpl in newFileTemplates"
								:key="tpl.id"
								:variant="tpl.id === createTemplateId ? 'primary' : 'tertiary'"
								@click="applyTemplate(tpl)">
								{{ t('pages', tpl.label) }}
							</NcButton>
						</div>

						<NcTextField
							v-if="createKind === 'file'"
							:model-value="createTitle"
							:label="t('pages', 'Title')"
							label-outside
							:placeholder="t('pages', 'e.g. My first blog post')"
							@update:model-value="onTitleInput" />

						<NcTextField
							:model-value="createPath"
							:label="createKind === 'folder' ? t('pages', 'Folder name') : t('pages', 'Path (relative to site root)')"
							label-outside
							:placeholder="createKind === 'folder' ? t('pages', 'e.g. images/2026') : t('pages', 'e.g. _posts/2026-01-01-hello.md')"
							@update:model-value="onPathInput" />

						<p class="pages-newfile__hint">
							{{ createKind === 'folder'
								? t('pages', 'Nested folders are created in one go; an existing folder is rejected. Note: empty folders are not synced to the work copy or the repository (git does not track empty folders) — a folder shows up once it holds its first file.')
								: t('pages', 'Parent folders are created automatically; if the target file already exists, creation is rejected instead of overwritten.') }}
						</p>
					</div>
				</NcDialog>
			</NcAppContent>
		</template>
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
			The confirmation of a publish, shared by every entry point: the content page's toolbar, the
			request panel and the source page's diff. One dialog and one implementation (`publish()`), so
			the buttons cannot drift apart and a publish always says what it will do before it does it.
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
	</NcContent>
</template>

<script>
import { t } from './i18n.js'
import { generateUrl } from '@nextcloud/router'
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
import { extrasOf, pageFrontMatter, reasonFor, renameArticleTarget, stateKind, stateLabel } from './articles.js'
import { createEditor } from './editor.js'
import {
	CONTENT_PAGE,
	SOURCE_PAGE,
	readShellState,
	resolvePage,
	reviewGrant,
} from './pageState.js'

// Folder collapsed state lives in memory only: every page load starts fully collapsed.

/**
 * A URL out of the shell state, or the route path as the fallback.
 *
 * The shell hands both page URLs over (`PageController::shell()`); the literal path keeps the two
 * links between the pages working when a shell does not carry them — a missing key must not turn
 * into a broken link.
 */
function urlOr(value, path) {
	return typeof value === 'string' && value !== '' ? value : generateUrl(path)
}

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
		// Which of the two pages this load is (pageState.js). It decides the branch the template
		// renders, and with it which editor host exists at all.
		const page = resolvePage(initial)
		return {
			/** 'content' | 'source' */
			page,
			/** The two pages link to each other; the shell hands both URLs over */
			contentUrl: urlOr(initial.contentUrl, '/apps/pages/'),
			sourceUrl: urlOr(initial.sourceUrl, '/apps/pages/source'),
			site,
			canEdit: initial.canEdit === true,
			canPublish: initial.canPublish === true,
			// the authoring grant: on the content page it decides whether the file tree is shown at all
			canAuthor: initial.canAuthor === true,
			// the review grant, with the publish grant as the fallback for a shell cached before the
			// review grant existed (see reviewGrant() in pageState.js)
			canReview: reviewGrant(initial),
			/** The signed-in user id: a reviewer may not decide about their own request. */
			uid: typeof initial.uid === 'string' ? initial.uid : '',
			isAdmin: initial.isAdmin === true,
			nodes: [],
			collapsed: new Set(),
			/** The row being dragged, and the folder it is hovering over: both are paths. */
			dragging: null,
			/** True while a review action is in flight, so its buttons cannot be double-fired. */
			acting: false,
			batch: null,
			batchBusy: false,
			batchProgress: '',
			dropTarget: null,
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
			building: false,
			/** True while a publish request is in flight: the button is disabled, not removed. */
			publishing: false,
			/** The publish confirmation dialog: open while the caller has not confirmed or cancelled. */
			publishConfirm: false,
			/** The optional commit message typed in that dialog; '' means "the server's default". */
			publishMessage: '',
			loadingTree: false,
			banner: null,
			status: null,
			buildErrors: [],
			diffOpen: false,
			diffStat: '',
			diffPatch: '',
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

			// --- the content page's navigation: the caller's own file tree ---
			/** A failed load of that tree, shown in the column instead of an empty one */
			authorError: '',
			/** `/<author_root>/<uid>`: what a node's target starts with, so a target can be read back */
			targetPrefix: '',
			/** The file name of the node whose action menu is open, for the scroll handling below */

			// --- article views (P3) ---
			/**
			 * Which view the content area shows: 'site' | 'requests' | 'read'. 'site' is the editor pane
			 * of the page that is showing — an author's file on the content page, a site file on the
			 * source page — and the source page has no other view, so both pages start there.
			 */
			view: 'site',
			/**
			 * Whether the request panel may be shown at all. The review grant decides, and a 403 from
			 * the list (a grant taken away while the page is open) turns it off for good.
			 */
			requestsAllowed: true,
			/** The open editor holds an article of the caller's own author area, not a site file */
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
			/** A new file name typed in the panel; '' means the article keeps its address */
			newFileName: '',
			/** Set when a save was refused with 409: the text stays, a reload is offered */
			conflict: false,

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
			/** Target folder: a site path on the source page, a target on the content page ('' = root) */
			createDir: '',
			createTemplateId: '',
			createTitle: '',
			createPath: '',
			/** The content page's dialog asks for a name only; this is it */
			createName: '',
			/** Once the user edits the path by hand, stop deriving it from the title */
			createPathTouched: false,
			creating: false,
			deleting: false,
		}
	},

	computed: {
		/** How many entries the two batch actions would touch, for the menu and its labels. */
		internalCount() {
			return this.fileEntries.filter((entry) => this.actionsFor(entry).includes('requestOnline')).length
		},

		externalCount() {
			return this.fileEntries.filter((entry) => this.actionsFor(entry).includes('requestOffline')).length
		},

		/**
		 * What the publish confirmation says will happen: the two effects every publish has, in the
		 * words the reviewer needs before committing to them. A computed, not a method - the dialog
		 * reads it as a value.
		 */
		publishHint() {
			return t('pages', 'The pages marked external go out with this commit; the pages taken down disappear from the site with it. Nothing else leaves Nextcloud.')
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
				date: entry.mtime ? new Date(entry.mtime * 1000) : null,
				submittedAt: entry.submittedAt || 0,
				tags: [],
				// the "not pushed · retry" mark of this entry, so the table and the tree agree
				pushFailed: this.pushFailedFor(entry),
				entry,
			}))
		},

		/** The counts the heading shows: what waits for a decision, then the two settled states. */
		articleCounts() {
			const by = {}
			for (const row of this.articleRows) {
				const key = String(row.state || 'internal')
				by[key] = (by[key] || 0) + 1
			}
			const parts = []
			if (by.requested) {
				parts.push(t('pages', 'Waiting for review: %s', [String(by.requested)]))
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

		/** Which of the two pages this is. The template branches on it at the top level. */
		isContentPage() {
			return this.page === CONTENT_PAGE
		},
		/** Is there a single site to talk about? Gates the buttons that would otherwise call nothing. */
		siteReady() {
			return Object.keys(this.site).length > 0
		},
		/**
		 * May this caller write in the column on this page? The content page shows the caller's own
		 * file tree and is offered to whoever may author (an empty area is a normal starting point);
		 * the source page is the site's own tree, which is the review-and-publish grant.
		 */
		canWrite() {
			return this.isContentPage ? (this.canAuthor || this.canEdit) : this.canEdit
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
		 * The editor pane is offered to whoever may write something — an `edit` holder for the whole
		 * site, every other author for their own area — plus whoever has an article open right now.
		 * A reader without either still gets the preview, exactly as before.
		 */
		showEditorPane() {
			return this.canEdit || this.authorOpen
		},
		/**
		 * Whether the open file is a structured article. The rule is narrow on purpose (an author
		 * target with a page extension, or the site's own `_posts/…`) and lives in
		 * articleGeometry.js, so a layout, a stylesheet or `_config.yml` can never be pulled into the
		 * fields panel: for those the editor stays the raw whole-file editor, which is what keeps them
		 * byte-exact.
		 */
		isArticle() {
			return isArticlePath(this.currentFile)
		},
		/**
		 * An open file the content page does not edit as a page: an image, a stylesheet, a download.
		 * The pane offers what such a file has - its facts, a replacement, the Files app and delete -
		 * instead of a text editor that would corrupt it.
		 */
		isResource() {
			return this.isContentPage && this.currentFile !== '' && !this.isArticle
		},
		/** Which editor the pane is showing, as a label for the pane header. */
		editorMode() {
			if (this.isResource) {
				return ''
			}

			return this.isArticle
				? t('pages', 'Article: fields and body')
				: t('pages', 'Whole file')
		},
		/** Is there anything to save? Compares the body and the fields against what was loaded. */
		editorDirty() {
			if (!this.currentFile || this.conflict) {
				return this.dirty
			}
			return this.dirty || hasChanges(
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
		shortEtag() {
			return String(this.currentEtag || '').slice(0, 8)
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
		/** Available new-file templates (site config); the `when_present` gate is checked here because only the frontend has the tree. */
		newFileTemplates() {
			const templates = this.site?.new_file?.templates || []
			return templates.filter((tpl) => {
				const need = tpl.when_present
				if (!Array.isArray(need) || need.length === 0) {
					return true
				}
				return need.every((entry) => this.treeHas(String(entry)))
			})
		},
		currentTemplate() {
			return this.newFileTemplates.find((tpl) => tpl.id === this.createTemplateId)
				|| this.newFileTemplates[0]
				|| null
		},
		/**
		 * The path the content page's dialog would create: the folder the menu was opened on plus the
		 * name. A name without an extension gets `.md`, which is what the column is for.
		 *
		 * The content page addresses every file by its site target, the root of the area included: a
		 * bare name would be a *site* path for a caller who holds the review-and-publish grant (the
		 * storage user of the site, an administrator), and the server would create it in the site source.
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
			const path = (this.isContentPage ? this.authorCreatePath : this.createPath.trim())
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
		statusText() {			if (!this.status) {
				return ''
			}
			const git = this.status.git || {}
			const last = this.status.last_build || {}
			const drift = this.status.drift || {}
			const parts = []
			if (git.branch) {
				parts.push(`git:${git.branch}${git.dirty ? '*' : ''}`)
			}
			parts.push(drift.in_sync === false ? t('pages', '⚠ Unmirrored changes') : t('pages', '✓ In sync'))
			if (last.build_id) {
				parts.push(`build#${last.build_id}${last.ok ? '✓' : '✗'}`)
			}
			return parts.join(' · ')
		},
		statusError() {
			return this.status?.last_build?.ok === false || this.status?.drift?.in_sync === false
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
		 * Is there an article to preview? Only then does the content page show the pane: an empty
		 * frame next to an empty editor would say nothing at all.
		 */
		canPreviewArticle() {
			return this.isContentPage && this.currentFile !== '' && this.isArticle
		},
		/**
		 * Does anything on screen need a preview token right now? The token is shared by every
		 * surface that shows the built site, and each one has its own condition:
		 *
		 *   - the source page's preview frame: only while that pane is shown (`previewVisible`);
		 *   - the content page's article preview: only while an article is open in the editor;
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
			if (this.isContentPage && this.view === 'read') {
				return true
			}

			return this.isContentPage ? this.canPreviewArticle : this.previewVisible
		},
	},

	watch: {
		dirty(value) {
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
	},

	async mounted() {
		/*
		 * The editor is created for the page that is showing, and only for it: the content page needs it
		 * for the body of an article, the source page for a whole site file, and a second CodeMirror
		 * instance would fight the first one over the same state. The template branches at the top level,
		 * so exactly one host exists here.
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
		if (this.isContentPage) {
			// The content page's column is the caller's own file tree, for every author.
			await this.loadAuthorTree()
		} else if (this.canEdit) {
			await this.loadTree()
		}

		// Only editors and publishers may read the status; for everybody else this request would be
		// a 403 and a banner on every poll.
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
		if (this.statusTimer) {
			window.clearInterval(this.statusTimer)
		}
		window.clearTimeout(this.previewTimer)
		this.editor?.destroy()
		window.onbeforeunload = null
	},

	methods: {
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
		 * @param {'online'|'offline'} kind the intent every entry in the batch asks for
		 */
		startBatch(kind) {
			const action = kind === 'online' ? 'requestOnline' : 'requestOffline'
			const paths = this.fileEntries
				.filter((entry) => this.actionsFor(entry).includes(action))
				.map((entry) => this.nodeTarget(entry))
				.filter((target) => target !== '')
			this.batch = paths.length > 0 ? { kind, paths } : null
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

		async loadTree() {
			if (!this.canEdit) {
				this.nodes = []
				return
			}
			this.loadingTree = true
			// the site scope, asked for explicitly: a publisher's (or an administrator's, or the
			// storage user's) default would be the same tree, but the source page must never be
			// decided by a fallback - this page is the one place the Jekyll tree belongs
			const result = await api.tree('site')
			this.loadingTree = false
			if (!result.ok) {
				this.nodes = []
				this.showBanner('error', result.message)
				return
			}
			if (result.data.exists === false) {
				this.nodes = []
				this.showBanner('warning', result.data.hint || t('pages', 'The site directory does not exist yet'))
				return
			}
			this.nodes = result.data.nodes || []
			// Folders always start collapsed, on every reload: the collapsed set is never restored
			// from a previous session.
			this.collapsed = new Set(
				this.nodes.filter((node) => node.type === 'dir').map((node) => node.path),
			)
		},

		/**
		 * The content page's column: the caller's own file tree, straight from the tree endpoint's
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

		/** Reload the navigation of whichever page is showing. */
		async refreshNav() {
			if (this.isContentPage) {
				await this.loadAuthorTree()
				return
			}
			await this.loadTree()
		},

		/**
		 * The address of one node for the file and article endpoints.
		 *
		 * The content page's nodes carry a `target` (the folder's own included), which is what every
		 * endpoint takes; the source page's nodes carry a site-relative `path`, which is what its
		 * endpoints take. One function, so no call site has to remember which page it is on.
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

			return node.state ? stateLabel(node.state) : ''
		},

		/** The badge's colour family: the state's own. */
		badgeKind(node) {
			return node?.state ? stateKind(node.state) : 'neutral'
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
				// Show the renamed file again: the source page reads it through the site tree, the
				// content page through the target it was opened with.
				if (this.isContentPage) {
					await this.loadPath(this.currentFile, 'tree')
				} else {
					await this.openFile(this.currentFile)
				}
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
		 * Drag and drop moves a page or a folder into another folder — the gesture the Files and Notes
		 * apps teach, and the reason the tree is worth more than a list. Only folders accept a drop;
		 * a folder cannot be dropped into itself or into one of its own children.
		 */
		onDragStart(node, event) {
			// The row is draggable, so its children are too. A press on the actions trigger, a button or
			// a link must never become a drag: the popover would anchor to a moving element and paint at
			// the page origin for a frame — the "flicker in the corner" this guard removes.
			const target = event?.target
			if (target && typeof target.closest === 'function' && target.closest('button, a, .action-item__menutoggle, .action-item')) {
				event.preventDefault()
				return
			}
			this.dragging = node.path
			// some browsers require data to be set for the drag to start at all
			event?.dataTransfer?.setData('text/plain', node.path)
			if (event?.dataTransfer) {
				event.dataTransfer.effectAllowed = 'move'
			}
		},

		onDragEnd() {
			this.dragging = null
			this.dropTarget = null
		},

		onDragOver(node, event) {
			if (node.type !== 'dir' || this.dragging === null || !this.canDropOn(node)) {
				return
			}
			// the default is "no drop": preventing it is what makes the folder a target
			event?.preventDefault()
			if (event?.dataTransfer) {
				event.dataTransfer.dropEffect = 'move'
			}
			this.dropTarget = node.path
		},

		onDragLeave(node) {
			if (this.dropTarget === node.path) {
				this.dropTarget = null
			}
		},

		onDrop(node, event) {
			if (node.type !== 'dir' || this.dragging === null || !this.canDropOn(node)) {
				return
			}
			event?.preventDefault()
			const from = this.dragging
			const name = from.split('/').pop()
			const to = node.path === '' ? name : node.path + '/' + name
			this.dragging = null
			this.dropTarget = null
			if (from === to) {
				return
			}
			this.moveNodeTarget(from, to)
		},

		/** A folder may not be dropped into itself or below itself: that would detach the subtree. */
		canDropOn(target) {
			return this.dragging !== null && !(target.path === this.dragging || target.path.startsWith(this.dragging + '/'))
		},

		/**
		 * Move a node through Nextcloud's own move and reload the column. The paths are the tree's own
		 * area-relative paths, which is what the move endpoint takes for an author area.
		 */
		async moveNodeTarget(from, to) {
			try {
				await api.move(from, to)
				this.showBanner('success', t('pages', 'Moved to %s', [to || '/']))
				await this.loadAuthorTree()
			} catch (error) {
				this.showBanner('error', t('pages', 'Could not move it:') + ' ' + reasonFor(error))
			}
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
			if (this.isContentPage && node.type !== 'dir' && node.kind !== 'page' && !isArticlePath(path)) {
				this.openResource(node)
				return
			}
			await this.loadPath(path, this.isContentPage ? 'tree' : 'site')
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
			this.banner = null
			this.view = 'site'
		},

		/** Ask before throwing away unsaved edits. Used by every way of opening another file. */
		async confirmDiscard(unsaved = this.dirty) {
			if (!unsaved) {
				return true
			}
			return window.confirm(t('pages', 'The current file has unsaved changes. Discard them?'))
		},

		/**
		 * Open one path in the editor, in whichever mode it belongs to: a structured article through
		 * `GET /api/article/content` (body without front matter, metadata as fields), everything else
		 * as the raw whole file — or, on the content page, in the resource pane. Opening the file that
		 * is already open is a no-op, so switching views never reloads and never loses text.
		 *
		 * @param {string} path
		 * @param {'site'|'tree'} from where the open was triggered, for the refresh after a save
		 */
		async loadPath(path, from = 'site') {
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
			if (this.isContentPage) {
				// a page of the author's tree that is not rendered as a document: the resource pane
				this.openResource({ path, target: path, type: 'file', kind: 'resource' })
				return
			}
			const result = await api.readFile(path)
			if (!result.ok) {
				this.showBanner(result.status === 403 ? 'warning' : 'error', result.message)
				return
			}
			this.currentFile = result.data.path || path
			this.currentEtag = result.data.etag
			this.authorOpen = false
			this.openedFrom = from
			// the whole file, front matter included: this mode has no fields panel to hold it
			this.setEditorDoc(result.data.content)
			this.resetArticleState()
			this.dirty = false
			this.conflict = false
			this.banner = null
			this.view = 'site'
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
				// every key the panel does not own: the read-only table shows these, the dialog edits them
				extra: extrasOf(meta),
			}
			this.articleMeta = meta
			this.fields = { ...current }
			this.fieldsBaseline = { ...current }
			this.bodyBaseline = normalizeText(data?.body)
			this.setEditorDoc(this.bodyBaseline)
			this.dirty = false
			this.conflict = false
			this.banner = null
		},

		/** Assign the editor content without the assignment counting as a user edit. */
		setEditorDoc(value) {
			this.editor?.setPath(this.currentFile)
			this.editor?.setDoc(value)
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
		 * in place (the banner says why) so the user can retry instead of retyping them.
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
				 * moves the file. It is kept beside the fields so the save payload never carries a meta
				 * key the server does not know.
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

		/** The request panel lost the grant: hide the section instead of showing a broken page. */
		onRequestsForbidden(reason) {
			this.requestsAllowed = false
			if (this.view === 'requests') {
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
			if (!this.canEdit && !this.isContentPage) {
				return
			}
			if (!(await this.confirmDiscard())) {
				return
			}
			await this.loadPath(path, this.isContentPage ? 'tree' : 'site')
		},

		async save() {
			if (!this.currentFile) {
				return
			}
			// An author without the edit capability writes into their own area through the same file
			// API, addressed by the target the backend maps to their own folder; the site tree stays
			// edit-only, so this is the one write such a user can make.
			const ownedByAuthor = !this.canEdit && (this.authorOpen || this.isContentPage)
			if (!this.canEdit && !ownedByAuthor) {
				return
			}
			/*
			 * The panel already says why (and the server refuses the same value), but the guard is
			 * here as well: a future date must not even start the rename request, and nothing is
			 * clamped behind the user's back.
			 */
			if (this.isArticle && isFutureDay(this.fields.date)) {
				this.showBanner('error', t('pages', 'The date may not be in the future.'))
				return
			}
			this.saving = true
			let result = null
			try {
				if (this.isArticle) {
					// a new file name is a move, and it has to happen before the content is written
					const renamed = await this.applyRename()
					if (renamed === false) {
						return
					}
				}
				result = this.isArticle
					? await this.saveArticle()
					: await this.saveRawFile()
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
			// it - a sentence in a banner would be gone with the next click. A push that went through
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
			if (this.isContentPage) {
				await this.refreshNav()
			}
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

		/**
		 * Save a non-article file the way it always worked: the whole file, byte for byte, through the
		 * raw endpoint. Nothing here splits or rewrites anything — a layout or a `_config.yml` has to
		 * survive a save unchanged.
		 *
		 * @returns {Promise<{etag:string, bridge:object}|null>}
		 */
		async saveRawFile() {
			const result = await api.writeFile(
				this.currentFile,
				this.editor.getDoc(),
				this.currentEtag,
			)
			if (result.status === 409) {
				this.showBanner('error', t('pages', 'The file was modified elsewhere, reopen it before saving'))
				return null
			}
			if (!result.ok) {
				this.showBanner('error', result.message)
				return null
			}
			return { etag: result.data.etag, bridge: result.data.bridge || {} }
		},

		async build() {
			if (!this.canEdit) {
				return
			}
			this.building = true
			const result = await api.build()
			this.building = false
			if (!result.ok) {
				this.showBanner('error', result.message)
				return
			}
			const build = result.data.build || {}
			this.buildErrors = build.errors || []
			if (build.ok) {
				this.showBanner('success', t('pages', 'Build succeeded') + ` · ${build.ms}ms · ${build.output_files} ` + t('pages', 'output files'))
			} else {
				this.showBanner('error', t('pages', 'Build failed, see the errors below'))
			}
			this.status = { ...(this.status || {}), last_build: build }
			this.refreshPreview()
		},

		// ------------------------------------------------------------------ create / delete

		/** Does this path exist in the tree? (Folders count: any node starting with `p/`) */
		treeHas(path) {
			if (!path) {
				return false
			}
			return this.nodes.some((node) => node.path === path || node.path.startsWith(path + '/'))
		},

		/** Templates only use these placeholders; replacing them in one place keeps two regexes from drifting apart */
		renderTemplate(text, slug) {
			const now = new Date()
			const pad = (n) => String(n).padStart(2, '0')
			const values = {
				slug,
				title: this.createTitle.trim(),
				date: `${now.getFullYear()}-${pad(now.getMonth() + 1)}-${pad(now.getDate())}`,
				time: `${pad(now.getHours())}:${pad(now.getMinutes())}:00`,
				year: String(now.getFullYear()),
				month: pad(now.getMonth() + 1),
				day: pad(now.getDate()),
			}
			return String(text || '').replace(
				/\{(slug|title|date|time|year|month|day)\}/g,
				(match, key) => values[key] ?? '',
			)
		},

		/** Keep non-ASCII letters in the slug (Jekyll escapes them in permalinks); only strip path-hostile and whitespace characters */
		slugify(title) {
			return String(title || '')
				.trim()
				.toLowerCase()
				.replace(/\s+/g, '-')
				.replace(/[/\\:*?"<>|#%]+/g, '')
				.replace(/^[.-]+|[.-]+$/g, '')
				.slice(0, 80)
		},

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

		/** Resolve a template path into the target folder, using only its last segment */
		pathInDir(templatePath, dir) {
			const name = String(templatePath || '').split('/').pop() || ''
			return dir ? `${dir}/${name}` : name
		},

		/** Expand every ancestor folder of a path so a newly created file is visible; session-only, a reload collapses everything again */
		expandAncestors(path) {
			// the content page addresses files by target, the tree by the area-relative path
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
			this.createTitle = ''
			this.createName = ''
			this.createPathTouched = false
			this.creating = false
			this.createTemplateId = this.newFileTemplates[0]?.id || ''
			this.applyTemplate(this.currentTemplate)
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

		applyTemplate(tpl) {
			if (!tpl) {
				return
			}
			this.createTemplateId = tpl.id
			this.createPathTouched = false
			// Templates are irrelevant in folder mode: the path is "folder + empty name" until the user fills it in
			this.createPath = this.createKind === 'folder'
				? (this.createDir ? this.createDir + '/' : '')
				: this.pathInDir(this.renderTemplate(tpl.path, this.slugify(this.createTitle)), this.createDir)
		},

		onTitleInput(value) {
			this.createTitle = value
			if (!this.createPathTouched) {
				this.createPath = this.pathInDir(
					this.renderTemplate(this.currentTemplate?.path || '', this.slugify(value)),
					this.createDir,
				)
			}
		},

		onPathInput(value) {
			this.createPathTouched = true
			this.createPath = value
		},

		/** The content page's dialog asks for a name only; the path is derived from it. */
		onNameInput(value) {
			this.createName = value
		},

		/** @returns {Promise<boolean>} false keeps the dialog open (NcDialogButton convention) */
		async createNewFile() {
			const authorPage = this.isContentPage
			const path = authorPage ? this.authorCreatePath : this.createPath.trim()
			if (!path || !this.siteReady) {
				return false
			}
			this.creating = true
			// an author's new file is a page of the site template: the title is the name, and the
			// layout is the site's - the same minimal block the panel would fill in
			const content = authorPage
				? pageFrontMatter(String(this.createName || '').trim())
				: this.renderTemplate(this.currentTemplate?.content || '', this.slugify(this.createTitle))
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
			const path = (this.isContentPage ? this.authorCreatePath : this.createPath.trim()).replace(/\/+$/, '')
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
		 * Delete a node of the column (the menu entry; deleting the open file goes through the same
		 * code, so the confirmation text cannot drift).
		 *
		 * Deleting is direct: the file goes into Nextcloud's trash, and the server drops the entry from
		 * the visibility manifest. An entry that was external leaves the published set with it, so the
		 * next publish takes the page off the site - the confirmation says so before it happens.
		 */
		async deleteNode(node) {
			if (!this.canWrite || !node) {
				return
			}
			const isDir = node.type === 'dir'
			const target = this.nodeTarget(node)
			const inside = isDir
				? this.nodes.filter((n) => n.path.startsWith(node.path + '/')).length
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
			if (!window.confirm(message)) {
				return
			}

			this.deleting = true
			const result = await api.deleteFile(target)
			this.deleting = false
			if (!result.ok) {
				this.showBanner('error', reasonFor(result))
				return
			}
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
			const bridge = result.data.bridge || {}
			this.showBanner(
				bridge.notified === false ? 'warning' : 'success',
				bridge.notified === false
					? t('pages', 'Deleted, but notifying the bridge failed:') + (bridge.message || '?')
					: t('pages', 'Deleted, rebuilding…'),
			)
		},


		/**
		 * The visibility actions that are real for one entry, as a list of names. One rule for the
		 * header, the flat list and the tree: a button appears only where the server would accept it.
		 *
		 *   internal   the author asks for the site: requestOnline
		 *   requested  the author withdraws it (cancel); a reviewer approves or rejects it
		 *   external   the author asks to come off (requestOffline); a reviewer takes it down directly
		 *
		 * A reviewer's actions are offered on any entry, and the server refuses a decision about one's
		 * own request - which is why the header disables them for the caller's own entry.
		 */
		actionsFor(entry) {
			const out = []
			if (!entry || entry.type === 'dir') {
				return out
			}
			const state = String(entry.state || 'internal')
			const intent = String(entry.intent || '')
			if (state === 'internal') {
				out.push('requestOnline')
			}
			if (state === 'external') {
				out.push('requestOffline')
			}
			if (state === 'requested') {
				out.push('cancel')
			}
			// deciding is the reviewer's job, and never about one's own request: the server refuses
			// that with 403, so the action is not offered at all
			const ownEntry = String(entry.owner || '') === '' || String(entry.owner || '') === this.uid
			if (this.canReview && !ownEntry) {
				if (state === 'requested') {
					out.push('approve', 'reject')
				}
				if (state === 'external' || (state === 'requested' && intent === 'offline')) {
					// the direct take-down: it applies to anything the site may serve and waits for
					// no second request
					out.push('offline')
				}
			}
			return out
		},

		/** Open a row of the flat list: the same call the tree makes for the same node. */
		openEntry(entry) {
			this.openNode(entry)
		},

		/**
		 * One entry through one of the visibility actions. They are separate endpoints but one shape, so
		 * they share the busy flag, the banner and the refresh — the list and the tree call this too.
		 *
		 * @param {'requestOnline'|'requestOffline'|'cancel'|'approve'|'reject'|'offline'} kind
		 */
		async runAction(kind, path) {
			if (!path || this.acting) {
				return
			}
			this.acting = true
			try {
				const result = await this.callAction(kind, path)
				if (!result.ok) {
					this.showBanner('error', reasonFor(result))
					return
				}
				this.showBanner('success', this.actionText(kind))
				await this.refreshNav()
			} catch (error) {
				this.showBanner('error', reasonFor(error))
			} finally {
				this.acting = false
			}
		},

		/** The one request that performs an action, so the message and the call cannot drift apart. */
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
			case 'offline':
				return api.decideRequest(path, 'reject')
			default:
				// `reject` from the header carries no note: the request panel is where a reason is typed
				return api.decideRequest(path, 'reject')
			}
		},

		/** What an action means, in one line. */
		actionText(kind) {
			return {
				requestOnline: t('pages', 'Online requested'),
				requestOffline: t('pages', 'Offline requested'),
				cancel: t('pages', 'Request withdrawn'),
				approve: t('pages', 'Approved'),
				reject: t('pages', 'Rejected'),
				offline: t('pages', 'Taken off the site. The next publish drops it.'),
			}[kind] || t('pages', 'Done')
		},


		/** Close the open entry and return to the flat list, asking first when text would be lost. */
		backToList() {
			if (this.editorDirty && !this.confirmDiscard(this.editorDirty)) {
				return
			}
			this.currentFile = ''
			this.currentEtag = ''
			this.dirty = false
			this.conflict = false
			this.resetArticleState()
			this.editor?.setDoc('')
		},

		async deleteCurrentFile() {
			if (!this.currentFile) {
				return
			}
			// Delete button in the editor header: same code path, so the confirmation text cannot drift
			await this.deleteNode({
				path: this.currentFile,
				target: this.currentFile,
				type: 'file',
				hidden: false,
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

		async openDiff() {
			if (!this.canPublish) {
				return
			}
			const result = await api.diff()
			if (!result.ok) {
				this.showBanner('error', result.message)
				return
			}
			this.diffStat = result.data.stat || ''
			const patch = result.data.patch || ''
			this.diffPatch = patch.length > 8000 ? patch.slice(0, 8000) + '\n…' + t('pages', '(truncated)') : patch
			this.diffOpen = true
		},

		/**
		 * The one way into a publish: open the confirmation. Every entry point calls this (the
		 * content page's toolbar, the request panel, the source page's diff), so a publish always
		 * says what it will do first, and `publish()` below is the only place that calls the endpoint.
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
		 * Commit and push, through the same endpoint the source page uses. The answer carries the
		 * outcome under `result` (`commit`, `pushed`, `nothing_to_commit`); the top level carries
		 * what was staged (`paths`, `skipped`, `stale`) and the bookkeeping counts. A failure is the
		 * server's message - the bridge's own error, not a generic one.
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
			if (!result.ok) {
				this.showBanner('error', result.message || t('pages', 'Publishing failed'))
				return
			}
			const data = result.data && typeof result.data === 'object' ? result.data : {}
			const outcome = data.result && typeof data.result === 'object' ? data.result : {}
			this.diffOpen = false
			if (outcome.nothing_to_commit === true) {
				this.showBanner('warning', t('pages', 'Nothing to publish'))
			} else {
				const hash = String(outcome.commit || '').slice(0, 8)
				this.showBanner('success', hash === ''
					? t('pages', 'Published')
					: t('pages', 'Published: %s', [hash]))
			}
			this.refreshStatus()
		},

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
				this.buildErrors = result.data.last_build?.errors || []
				this.refreshPreview()
			}
		},

		// ------------------------------------------------------------------ preview

		togglePreview() {
			this.previewVisible = !this.previewVisible
			if (this.previewVisible && !this.previewToken) {
				this.loadPreview()
			}
		},

		/**
		 * Follow the preview where the reader goes. Clicking a link inside the frame navigates it, and
		 * the address field above has to say where that led: keeping the address the pane was opened
		 * with is a wrong answer to "what am I looking at". The frame is same-origin (the app serves
		 * it), so its location is readable; a frame we may not inspect is left alone rather than
		 * throwing on every load.
		 */
		onPreviewNavigated() {
			// Either pane can be the one that navigated: the source page's frame, or the article
			// preview the content page shows. Only one of the two refs exists per page load, and the
			// address field belongs to the source page's frame, so that one is read first.
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
			 * Who wants the token is `previewTokenWanted` — the source page's preview frame while
			 * its pane is shown, the content page's article preview while an article is open, and
			 * the Read view whenever it is the view. The last case is why this is not decided by
			 * `canPreviewArticle` alone: a reader never opens an editor file, so that test is
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
		 * Ask for a new token before the old one expires. The article preview on the content page can
		 * stay open for a long time — somebody writing a post — and a token that runs out turns the
		 * pane into an error page under their hands, which reads as "the site is broken" rather than
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
			// Both frames show the same built site: the source page's preview and the article preview.
			// A frame that is not there is skipped, and the reload message is ignored by a page that
			// does not listen for it.
			for (const ref of ['previewFrame', 'articleFrame']) {
				try {
					this.$refs[ref]?.contentWindow?.postMessage({ type: 'pages:reload' }, window.location.origin)
				} catch (error) {
					this.applyPreviewSrc()
				}
			}
		},

		openPreviewInNewTab() {
			if (this.previewSrc && this.previewSrc !== 'about:blank') {
				window.open(this.previewSrc, '_blank', 'noopener')
			}
		},

		// ------------------------------------------------------------------ misc

		showBanner(type, message) {
			this.banner = { type, message }
			window.clearTimeout(this._bannerTimer)
			this._bannerTimer = window.setTimeout(() => {
				this.banner = null
			}, 8000)
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
					if (this.isContentPage) {
						await this.refreshNav()
					}
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
 * starts at the pane edge has its first control under it — "My articles" on the content page,
 * "Save" on the source page. There is no toolbar slot in NcAppContent to hand the toggle to
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

/* The way across to the other page: a plain link, so it never reads as an action of this page. */
.pages-pagelink {
	font-size: 13px;
	white-space: nowrap;
}

.pages-banner {
	margin: 8px 12px 0;
	flex: 0 0 auto;
}

.pages-split {
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
	flex: 1 1 50%;
}

.pages-pane--preview {
	flex: 1 1 50%;
	border-inline-start: 1px solid var(--color-border);
}

/* Without the editor pane the preview is the only pane, so it takes the whole width. */
.pages-split--solo .pages-pane--preview {
	flex: 1 1 100%;
	border-inline-start: 0;
}

/*
 * The editor pane is always in the DOM (CodeMirror mounts into it once), so it is taken out of the
 * layout instead of being unmounted: an author who may not edit the site still gets an editor.
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
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

.pages-pane__meta {
	opacity: 0.5;
	margin-inline-start: auto;
	white-space: nowrap;
}

/* The ETag in the editor header sits right next to the title, so it must not take the auto margin */
.pages-pane__meta--tight {
	margin-inline-start: 6px;
}

/* Which editor is active: the raw whole-file editor, or the structured article editor. */
.pages-pane__mode {
	font-size: 11px;
	opacity: 0.6;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-pill, 999px);
	padding: 0 8px;
	white-space: nowrap;
}

.pages-pane__mode--structured {
	border-color: var(--color-primary-element);
	color: var(--color-primary-element);
	opacity: 1;
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

/* the entry list in the article-table style the panel used before */
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
