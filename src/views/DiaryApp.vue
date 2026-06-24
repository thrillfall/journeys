<template>
	<div class="journeys-diary">
		<!-- ===================== JOURNAL LIST ===================== -->
		<div v-if="!currentJournal" class="diary-view">
			<header class="diary-header">
				<h2>{{ t('journeys', 'Travel diary') }}</h2>
				<NcButton type="primary" @click="createJournal">
					{{ t('journeys', 'New journal') }}
				</NcButton>
			</header>

			<NcLoadingIcon v-if="loading" :size="32" class="diary-loading" />

			<NcEmptyContent v-else-if="journals.length === 0"
				:name="t('journeys', 'No journals yet')"
				:description="t('journeys', 'Create a journal to start your travel diary.')" />

			<ul v-else class="journal-list">
				<li v-for="journal in journals" :key="journal.id"
					class="journal-row" @click="openJournal(journal.id)">
					<span class="journal-row__title">{{ journal.title }}</span>
					<span v-if="journal.startDate" class="journal-row__dates">{{ journal.startDate }}<span v-if="journal.endDate"> – {{ journal.endDate }}</span></span>
				</li>
			</ul>
		</div>

		<!-- ===================== JOURNAL EDITOR ===================== -->
		<div v-else class="diary-view">
			<header class="diary-header">
				<NcButton type="tertiary" @click="closeJournal">← {{ t('journeys', 'All journals') }}</NcButton>
				<input v-model="currentJournal.title" class="journal-title-input"
					@blur="renameJournal" @keyup.enter="renameJournal">
				<NcButton type="tertiary" :title="t('journeys', 'Reload to see collaborators’ changes')" @click="reloadCurrent">
					↻ {{ t('journeys', 'Refresh') }}
				</NcButton>
				<template v-if="currentJournal.isOwner">
					<NcButton v-if="!currentJournal.isPublic" type="secondary" @click="shareJournal">{{ t('journeys', 'Share') }}</NcButton>
					<template v-else>
						<NcButton type="secondary" @click="copyShareLink">{{ t('journeys', 'Copy share link') }}</NcButton>
						<NcButton type="tertiary" @click="unshareJournal">{{ t('journeys', 'Unshare') }}</NcButton>
					</template>
					<NcButton type="error" @click="deleteJournal">{{ t('journeys', 'Delete journal') }}</NcButton>
				</template>
			</header>

			<div v-if="currentJournal.isOwner" class="members">
				<button class="members__toggle" @click="membersOpen = !membersOpen">
					<span class="members__caret">{{ membersOpen ? '▾' : '▸' }}</span>
					{{ t('journeys', 'Collaborators') }} ({{ members.length }})
				</button>
				<div v-show="membersOpen" class="members__body">
					<div class="members__list">
						<span v-for="m in members" :key="m.type + m.id" class="member-chip">
							{{ m.id }}<small class="member-chip__type">{{ m.type }}</small>
							<button title="remove" @click="removeMember(m)">✕</button>
						</span>
						<span v-if="members.length === 0" class="members__empty">{{ t('journeys', 'Only you. Add people or groups to collaborate.') }}</span>
					</div>
					<div class="members__add">
						<input v-model="shareeQuery" class="members__input"
							:placeholder="t('journeys', 'Add a user or group…')" @input="searchSharees">
						<ul v-if="shareeResults.length" class="sharee-results">
							<li v-for="s in shareeResults" :key="s.type + s.id" @click="addMember(s)">
								{{ s.label }} <em>{{ s.type }}</em>
							</li>
						</ul>
					</div>
				</div>
			</div>

			<div class="add-day">
				<input v-model="newDay" type="date" class="add-day__date">
				<NcButton type="secondary" :disabled="!newDay" @click="addDay">
					{{ t('journeys', 'Add day') }}
				</NcButton>
			</div>

			<NcEmptyContent v-if="currentJournal.entries.length === 0"
				:name="t('journeys', 'No entries yet')"
				:description="t('journeys', 'Pick a day to add a journal entry.')" />

			<div v-for="entry in currentJournal.entries" :key="entry.id" class="entry-card">
				<div class="entry-card__head">
					<span class="entry-card__date">{{ entry.date }}</span>
					<span v-if="entry.location && entry.location.placeLabel" class="entry-card__place">
						📍 {{ entry.location.placeLabel }}<span v-if="entry.location.country">, {{ entry.location.country }}</span>
					</span>
					<NcButton type="tertiary" @click="deleteEntry(entry)">✕</NcButton>
				</div>

				<input v-model="entry.title" class="entry-card__title"
					:placeholder="t('journeys', 'Title (optional)')"
					@input="markDirty(entry)" @blur="saveEntry(entry)">
				<textarea v-model="entry.body" class="entry-card__body"
					:placeholder="t('journeys', 'Write about this day…')"
					@input="markDirty(entry)" @blur="saveEntry(entry)"></textarea>
				<div class="entry-card__save">
					<span v-if="flash[entry.id] === 'saved'" class="entry-card__saved">{{ t('journeys', 'Saved') }} ✓</span>
					<NcButton type="secondary" :disabled="flash[entry.id] === 'saving'"
						@click="saveEntry(entry, true)">
						{{ flash[entry.id] === 'saving' ? t('journeys', 'Saving…') : t('journeys', 'Save entry') }}
					</NcButton>
				</div>

				<div class="photo-grid">
					<div v-for="(photo, idx) in entry.photos" :key="photo.id" class="photo-thumb">
						<img :src="entryPhotoUrl(photo.fileid)" alt="" loading="lazy">
						<button class="photo-thumb__cover" :class="{ active: currentJournal.coverFileid === photo.fileid }"
							title="Set as cover" @click="setCover(photo.fileid)">★</button>
						<div class="photo-thumb__actions">
							<button :disabled="idx === 0" title="left" @click="movePhoto(entry, idx, -1)">‹</button>
							<button title="remove" @click="removePhoto(entry, idx)">✕</button>
							<button :disabled="idx === entry.photos.length - 1" title="right" @click="movePhoto(entry, idx, 1)">›</button>
						</div>
					</div>
				</div>

				<NcButton type="tertiary" @click="openPicker(entry)">
					{{ t('journeys', 'Add / choose photos') }}
				</NcButton>
			</div>
		</div>

		<!-- ===================== PHOTO PICKER MODAL ===================== -->
		<NcModal v-if="picker.open" :title="t('journeys', 'Choose photos')" size="large" @close="closePicker">
			<div class="picker">
				<h3>{{ t('journeys', 'Photos from') }} {{ picker.entry && picker.entry.date }}</h3>
				<NcLoadingIcon v-if="picker.loading" :size="32" />
				<NcEmptyContent v-else-if="picker.photos.length === 0"
					:name="t('journeys', 'No photos for this day')" />
				<div v-else class="picker__grid">
					<div v-for="p in picker.photos" :key="p.fileid"
						class="picker__item" :class="{ selected: picker.selected[p.fileid] }"
						@click="togglePick(p.fileid)">
						<img :src="previewUrl(p.fileid)" alt="" loading="lazy">
						<span v-if="picker.selected[p.fileid]" class="picker__check">✓</span>
					</div>
				</div>
				<div class="picker__footer">
					<NcButton type="primary" @click="savePicker">{{ t('journeys', 'Save selection') }}</NcButton>
				</div>
			</div>
		</NcModal>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { showError, showSuccess } from '@nextcloud/dialogs'

import NcButton from '@nextcloud/vue/dist/Components/NcButton.js'
import NcModal from '@nextcloud/vue/dist/Components/NcModal.js'
import NcLoadingIcon from '@nextcloud/vue/dist/Components/NcLoadingIcon.js'
import NcEmptyContent from '@nextcloud/vue/dist/Components/NcEmptyContent.js'

const API = generateUrl('/apps/journeys/diary')

export default {
	name: 'DiaryApp',
	components: { NcButton, NcModal, NcLoadingIcon, NcEmptyContent },
	data() {
		return {
			loading: true,
			journals: [],
			currentJournal: null,
			newDay: '',
			picker: { open: false, loading: false, entry: null, photos: [], selected: {} },
			members: [],
			shareeQuery: '',
			shareeResults: [],
			shareeTimer: null,
			flash: {},
			membersOpen: false,
		}
	},
	async mounted() {
		await this.loadJournals()
	},
	methods: {
		previewUrl(fileid) {
			// Used by the day picker — shows the viewer's OWN library photos.
			return generateUrl('/core/preview') + '?fileId=' + fileid + '&x=256&y=256&a=1'
		},
		entryPhotoUrl(fileid) {
			// Attached entry photos may belong to other collaborators; serve via
			// the journal endpoint which resolves under the photo's owner_uid.
			return generateUrl('/apps/journeys/diary/journals/' + this.currentJournal.id + '/photo/' + fileid) + '?size=thumb'
		},
		async loadJournals() {
			this.loading = true
			try {
				const { data } = await axios.get(API + '/journals')
				this.journals = data.journals
			} catch (e) { showError(this.t('journeys', 'Could not load journals')) }
			this.loading = false
		},
		async createJournal() {
			// Create with a default name; the editor opens with an inline-editable title.
			const { data } = await axios.post(API + '/journals', { title: this.t('journeys', 'New journal') })
			await this.loadJournals()
			this.openJournal(data.journal.id)
		},
		async openJournal(id) {
			const { data } = await axios.get(API + '/journals/' + id)
			this.currentJournal = data.journal
			this.members = []
			this.shareeQuery = ''
			this.shareeResults = []
			this.membersOpen = false
			if (data.journal.isOwner) this.loadMembers()
		},
		closeJournal() { this.currentJournal = null; this.loadJournals() },
		async loadMembers() {
			const { data } = await axios.get(API + '/journals/' + this.currentJournal.id + '/members')
			this.members = data.members
		},
		searchSharees() {
			clearTimeout(this.shareeTimer)
			const q = this.shareeQuery.trim()
			if (q.length < 2) { this.shareeResults = []; return }
			this.shareeTimer = setTimeout(async () => {
				const { data } = await axios.get(API + '/sharees', { params: { search: q } })
				this.shareeResults = data.sharees
			}, 300)
		},
		async addMember(s) {
			try {
				const { data } = await axios.post(API + '/journals/' + this.currentJournal.id + '/members', { type: s.type, principal: s.id })
				this.members = data.members
				this.shareeQuery = ''
				this.shareeResults = []
			} catch (e) { showError(this.t('journeys', 'Could not add collaborator')) }
		},
		async removeMember(m) {
			try {
				const { data } = await axios.delete(API + '/journals/' + this.currentJournal.id + '/members/' + m.type + '/' + encodeURIComponent(m.id))
				this.members = data.members
			} catch (e) { showError(this.t('journeys', 'Could not remove collaborator')) }
		},
		async reloadCurrent() {
			const { data } = await axios.get(API + '/journals/' + this.currentJournal.id)
			this.currentJournal = data.journal
		},
		async renameJournal() {
			try {
				await axios.put(API + '/journals/' + this.currentJournal.id, { title: this.currentJournal.title })
			} catch (e) { showError(this.t('journeys', 'Could not rename journal')) }
		},
		async deleteJournal() {
			if (!window.confirm(this.t('journeys', 'Delete this journal and all its entries?'))) return
			await axios.delete(API + '/journals/' + this.currentJournal.id)
			this.closeJournal()
		},
		async shareJournal() {
			const { data } = await axios.post(API + '/journals/' + this.currentJournal.id + '/share')
			this.$set(this.currentJournal, 'isPublic', true)
			this.$set(this.currentJournal, 'shareUrl', data.url)
		},
		async unshareJournal() {
			await axios.post(API + '/journals/' + this.currentJournal.id + '/unshare')
			this.$set(this.currentJournal, 'isPublic', false)
			this.$set(this.currentJournal, 'shareUrl', null)
		},
		async copyShareLink() {
			const url = this.currentJournal.shareUrl
			try {
				await navigator.clipboard.writeText(url)
				showSuccess(this.t('journeys', 'Share link copied to clipboard'))
			} catch (e) {
				window.prompt(this.t('journeys', 'Copy this share link'), url)
			}
		},
		async setCover(fileid) {
			try {
				await axios.put(API + '/journals/' + this.currentJournal.id, { coverFileid: fileid })
				this.$set(this.currentJournal, 'coverFileid', fileid)
			} catch (e) { showError(this.t('journeys', 'Could not set cover photo')) }
		},
		async addDay() {
			if (!this.newDay) return
			await axios.post(API + '/journals/' + this.currentJournal.id + '/entries', { date: this.newDay })
			this.newDay = ''
			await this.reloadCurrent()
		},
		markDirty(entry) {
			if (this.flash[entry.id]) this.$set(this.flash, entry.id, null)
		},
		async saveEntry(entry, manual = false) {
			if (manual) this.$set(this.flash, entry.id, 'saving')
			try {
				await axios.put(API + '/entries/' + entry.id, { title: entry.title, body: entry.body })
				if (manual) {
					this.$set(this.flash, entry.id, 'saved')
					setTimeout(() => { if (this.flash[entry.id] === 'saved') this.$set(this.flash, entry.id, null) }, 2500)
				}
			} catch (e) {
				if (manual) this.$set(this.flash, entry.id, null)
				showError(this.t('journeys', 'Could not save entry'))
			}
		},
		async deleteEntry(entry) {
			if (!window.confirm(this.t('journeys', 'Delete this entry?'))) return
			await axios.delete(API + '/entries/' + entry.id)
			await this.reloadCurrent()
		},
		async persistPhotos(entry) {
			const fileids = entry.photos.map(p => p.fileid)
			const { data } = await axios.put(API + '/entries/' + entry.id + '/photos', { photos: fileids })
			entry.photos = data.photos
		},
		movePhoto(entry, idx, dir) {
			const j = idx + dir
			if (j < 0 || j >= entry.photos.length) return
			const arr = entry.photos
			const tmp = arr[idx]; this.$set(arr, idx, arr[j]); this.$set(arr, j, tmp)
			this.persistPhotos(entry)
		},
		removePhoto(entry, idx) {
			entry.photos.splice(idx, 1)
			this.persistPhotos(entry)
		},
		async openPicker(entry) {
			// The picker only offers the CURRENT user's own photos for that day.
			// Other users' (and other days') photos already on the entry are
			// preserved on save — they're just not shown here.
			this.picker = { open: true, loading: true, entry, photos: [], selected: {} }
			try {
				const { data } = await axios.get(API + '/day-photos', { params: { date: entry.date } })
				this.picker.photos = data.photos
			} catch (e) { showError(this.t('journeys', 'Could not load photos')) }
			const attached = new Set(entry.photos.map(p => p.fileid))
			const sel = {}
			this.picker.photos.forEach(p => { if (attached.has(p.fileid)) sel[p.fileid] = true })
			this.picker.selected = sel
			this.picker.loading = false
		},
		togglePick(fileid) {
			this.$set(this.picker.selected, fileid, !this.picker.selected[fileid])
		},
		closePicker() { this.picker.open = false },
		async savePicker() {
			const entry = this.picker.entry
			const candidates = new Set(this.picker.photos.map(p => p.fileid))
			const selected = this.picker.photos.map(p => p.fileid).filter(f => this.picker.selected[f])
			// preserve photos not offered in this picker (other users' / other days')
			const keep = entry.photos.map(p => p.fileid).filter(f => !candidates.has(f))
			const { data } = await axios.put(API + '/entries/' + entry.id + '/photos', { photos: [...keep, ...selected] })
			entry.photos = data.photos
			if (data.location) entry.location = data.location
			this.picker.open = false
		},
	},
}
</script>

<style scoped lang="scss">
/* NC's #content is fixed-height with overflow clipped, so the app must own its
   vertical scroll — otherwise tall journals get cut off with no scrollbar. */
.journeys-diary { height: 100%; overflow-y: auto; box-sizing: border-box; }
.diary-view { max-width: 900px; margin: 0 auto; padding: 24px 16px 80px; }
.diary-header { display: flex; align-items: center; gap: 12px; margin-bottom: 20px;
	h2 { flex: 1; margin: 0; } }
.diary-loading { margin: 40px auto; }
.journal-list { list-style: none; padding: 0; }
.journal-row { display: flex; justify-content: space-between; align-items: center;
	padding: 14px 16px; border: 1px solid var(--color-border); border-radius: 8px;
	margin-bottom: 8px; cursor: pointer;
	&:hover { background: var(--color-background-hover); }
	&__title { font-weight: 600; }
	&__dates { color: var(--color-text-maxcontrast); font-size: 0.9em; } }
.journal-title-input { flex: 1; font-size: 1.3em; font-weight: 600; border: none;
	border-bottom: 2px solid transparent; background: transparent;
	&:focus { border-bottom-color: var(--color-primary-element); outline: none; } }
.members { margin-bottom: 16px; border: 1px solid var(--color-border); border-radius: 8px; }
.members__toggle { width: 100%; text-align: left; background: none; border: none; cursor: pointer;
	padding: 10px 12px; font-weight: 600; font-size: 1em; color: var(--color-main-text); }
.members__caret { display: inline-block; width: 1em; color: var(--color-text-maxcontrast); }
.members__body { padding: 0 12px 12px; }
.members__list { display: flex; flex-wrap: wrap; gap: 6px; margin-bottom: 8px; }
.members__empty { color: var(--color-text-maxcontrast); font-size: .9em; }
.member-chip { background: var(--color-background-dark); border-radius: 14px; padding: 2px 6px 2px 10px; font-size: .9em; display: inline-flex; align-items: center; gap: 4px;
	&__type { color: var(--color-text-maxcontrast); }
	button { background: none; border: none; cursor: pointer; color: var(--color-text-maxcontrast); } }
.members__add { position: relative; max-width: 320px; }
.members__input { width: 100%; }
.sharee-results { position: absolute; z-index: 5; left: 0; right: 0; background: var(--color-main-background); border: 1px solid var(--color-border); border-radius: 6px; list-style: none; margin: 2px 0 0; padding: 4px; max-height: 220px; overflow-y: auto;
	li { padding: 6px 8px; cursor: pointer; border-radius: 4px; em { color: var(--color-text-maxcontrast); font-style: normal; font-size: .85em; } &:hover { background: var(--color-background-hover); } } }
.add-day { display: flex; gap: 8px; align-items: center; margin: 16px 0 24px; }
.entry-card { border: 1px solid var(--color-border); border-radius: 10px;
	padding: 16px; margin-bottom: 18px; background: var(--color-main-background); }
.entry-card__head { display: flex; align-items: center; gap: 10px; margin-bottom: 8px;
	.entry-card__date { font-weight: 700; }
	.entry-card__place { color: var(--color-text-maxcontrast); font-size: 0.9em; flex: 1; } }
.entry-card__title { width: 100%; font-size: 1.1em; border: none; margin-bottom: 6px;
	background: transparent; &:focus { outline: none; } }
.entry-card__body { width: 100%; min-height: 70px; resize: vertical;
	border: 1px solid var(--color-border); border-radius: 6px; padding: 8px;
	background: var(--color-main-background); color: var(--color-main-text); }
.entry-card__save { display: flex; align-items: center; justify-content: flex-end; gap: 10px; margin-top: 8px; }
.entry-card__saved { color: var(--color-success, #2d7d46); font-size: .9em; }
.photo-grid { display: flex; flex-wrap: wrap; gap: 8px; margin: 12px 0; }
.photo-thumb { position: relative; width: 110px; height: 110px; border-radius: 6px; overflow: hidden;
	img { width: 100%; height: 100%; object-fit: cover; display: block; background: var(--color-background-dark); }
	&__cover { position: absolute; top: 2px; right: 2px; background: rgba(0,0,0,.45); border: none;
		color: #fff; cursor: pointer; font-size: 15px; border-radius: 4px; padding: 1px 4px;
		&.active { color: #ffce3d; } }
	&__actions { position: absolute; bottom: 0; left: 0; right: 0; display: flex;
		justify-content: space-between; background: rgba(0,0,0,.45);
		button { background: none; border: none; color: #fff; cursor: pointer; font-size: 16px; padding: 2px 6px;
			&:disabled { opacity: .3; cursor: default; } } } }
.picker { padding: 12px; }
.picker__grid { display: flex; flex-wrap: wrap; gap: 8px; max-height: 55vh; overflow-y: auto; }
.picker__item { position: relative; width: 120px; height: 120px; border-radius: 6px; overflow: hidden;
	cursor: pointer; outline: 3px solid transparent;
	img { width: 100%; height: 100%; object-fit: cover; display: block; background: var(--color-background-dark); }
	&.selected { outline-color: var(--color-primary-element); }
	.picker__check { position: absolute; top: 4px; right: 4px; background: var(--color-primary-element);
		color: var(--color-primary-element-text); border-radius: 50%; width: 22px; height: 22px;
		display: flex; align-items: center; justify-content: center; font-size: 14px; } }
.picker__footer { margin-top: 16px; text-align: right; }
</style>
