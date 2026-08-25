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
					<span class="journal-row__main">
						<span class="journal-row__title">
							<span v-if="journal.isCompleted" class="completed-badge" :title="t('journeys', 'Completed')">✓</span>
							{{ journal.title }}
						</span>
						<span v-if="journal.description" class="journal-row__desc">{{ journal.description }}</span>
						<span v-if="statsLine(journal.stats)" class="journal-row__stats">{{ statsLine(journal.stats) }}</span>
					</span>
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

			<textarea v-model="currentJournal.description" class="journal-description-input" rows="2"
				:placeholder="t('journeys', 'Add a description for this journal…')"
				@blur="saveDescription"></textarea>

			<div v-if="currentJournal.isOwner || statsLine(currentJournal.stats)" class="journal-stats">
				<div class="journal-stats__figures">
					<span v-if="currentJournal.isCompleted" class="completed-badge completed-badge--pill">
						✓ {{ t('journeys', 'Completed') }}
					</span>
					<span v-if="statsLine(currentJournal.stats)">{{ statsLine(currentJournal.stats) }}</span>
					<span v-if="visitedLine" class="journal-stats__places">{{ visitedLine }}</span>
					<span v-if="!statsLine(currentJournal.stats)" class="journal-stats__empty">
						{{ t('journeys', 'Add a day to start counting.') }}
					</span>
				</div>
				<NcButton v-if="currentJournal.isOwner" type="tertiary" @click="toggleCompleted">
					{{ currentJournal.isCompleted ? t('journeys', 'Reopen') : t('journeys', 'Mark as completed') }}
				</NcButton>
			</div>

			<div class="members">
				<button class="members__toggle" @click="membersOpen = !membersOpen">
					<span class="members__caret">{{ membersOpen ? '▾' : '▸' }}</span>
					{{ t('journeys', 'Collaborators') }}<span v-if="currentJournal.isOwner"> ({{ members.length }})</span>
				</button>
				<div v-show="membersOpen" class="members__body">
					<template v-if="currentJournal.isOwner">
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
					</template>

					<div class="consent" :class="{ 'consent--only': !currentJournal.isOwner }">
						<NcCheckboxRadioSwitch type="switch" :checked="!!currentJournal.myLibraryShared"
							@update:checked="setLibraryConsent">
							{{ t('journeys', 'Let the others pick from my photos') }}
						</NcCheckboxRadioSwitch>
						<p class="consent__hint">
							{{ consentHint }}
						</p>
						<p v-if="otherContributors.length" class="consent__sharers">
							{{ t('journeys', 'Sharing their photos:') }}
							<span v-for="c in otherContributors" :key="c.uid" class="member-chip">{{ c.label }}</span>
						</p>
					</div>
				</div>
			</div>

			<div class="add-day">
				<input v-model="newDay" type="date" class="add-day__date">
				<NcButton type="secondary" :disabled="!newDay" @click="addDay">
					{{ addDayLabel }}
				</NcButton>
				<span class="add-day__count">{{ selectedDayLabel }}</span>
			</div>

			<div class="daycal">
				<div class="daycal__head">
					<button class="daycal__nav" :title="t('journeys', 'Previous month')" @click="shiftMonth(-1)">‹</button>
					<span class="daycal__month">{{ monthLabel }}</span>
					<button class="daycal__nav" :title="t('journeys', 'Next month')" @click="shiftMonth(1)">›</button>
					<NcLoadingIcon v-if="calendar.loading" :size="16" />
					<span class="daycal__legend">{{ t('journeys', 'Highlighted days have photos') }}</span>
				</div>
				<div class="daycal__grid">
					<span v-for="(w, i) in weekdayLabels" :key="'w' + i" class="daycal__weekday">{{ w }}</span>
					<span v-for="i in calendarLeadingBlanks" :key="'b' + i" class="daycal__blank" />
					<button v-for="cell in calendarCells" :key="cell.date"
						class="daycal__day"
						:class="{
							'daycal__day--photos': cell.count > 0,
							'daycal__day--entry': cell.hasEntry,
							'daycal__day--selected': cell.date === newDay,
							'daycal__day--today': cell.isToday,
						}"
						:title="cell.title"
						@click="newDay = cell.date">
						<span class="daycal__num">{{ cell.day }}</span>
						<span v-if="cell.count" class="daycal__count">{{ cell.count }}</span>
					</button>
				</div>
			</div>

			<NcEmptyContent v-if="currentJournal.entries.length === 0"
				:name="t('journeys', 'No entries yet')"
				:description="t('journeys', 'Pick a day to add a journal entry.')" />

			<div v-for="entry in sortedEntries" :key="entry.id" class="entry-card">
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
					<div v-for="(photo, idx) in entry.photos" :key="photo.id" class="photo-thumb"
						:title="t('journeys', 'Click to add a caption')" @click="openCaption(entry, photo)">
						<img :src="entryPhotoUrl(photo.fileid)" alt="" loading="lazy">
						<span v-if="photo.caption" class="photo-thumb__caption" :title="photo.caption">{{ photo.caption }}</span>
						<button class="photo-thumb__cover" :class="{ active: currentJournal.coverFileid === photo.fileid }"
							title="Set as cover" @click.stop="setCover(photo.fileid)">★</button>
						<!-- No manual reordering: photos are kept in capture-time order by
						     the server so every contributor's shots interleave by time. -->
						<div class="photo-thumb__actions" @click.stop>
							<button title="remove" @click="removePhoto(entry, idx)">✕</button>
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
						<img :src="pickerUrl(p)" alt="" loading="lazy">
						<span v-if="!p.isMine" class="picker__owner">{{ p.ownerLabel }}</span>
						<span v-if="picker.selected[p.fileid]" class="picker__check">✓</span>
					</div>
				</div>
				<div class="picker__footer">
					<NcButton type="primary" @click="savePicker">{{ t('journeys', 'Save selection') }}</NcButton>
				</div>
			</div>
		</NcModal>

		<!-- ===================== PHOTO CAPTION MODAL ===================== -->
		<NcModal v-if="caption.open" :title="t('journeys', 'Photo caption')" @close="closeCaption">
			<div class="caption-edit">
				<img v-if="caption.photo" :src="entryPhotoUrl(caption.photo.fileid)" alt="">
				<textarea v-model="caption.text" class="caption-edit__text"
					:placeholder="t('journeys', 'Write a short caption for this photo…')"></textarea>
				<div class="caption-edit__footer">
					<NcButton type="primary" :disabled="caption.saving" @click="saveCaption">
						{{ caption.saving ? t('journeys', 'Saving…') : t('journeys', 'Save caption') }}
					</NcButton>
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
import NcCheckboxRadioSwitch from '@nextcloud/vue/dist/Components/NcCheckboxRadioSwitch.js'
import NcModal from '@nextcloud/vue/dist/Components/NcModal.js'
import NcLoadingIcon from '@nextcloud/vue/dist/Components/NcLoadingIcon.js'
import NcEmptyContent from '@nextcloud/vue/dist/Components/NcEmptyContent.js'

const API = generateUrl('/apps/journeys/diary')

// Local (not UTC) YYYY-MM-DD — what a <input type="date"> expects.
function todayStr() {
	const d = new Date()
	const p = n => String(n).padStart(2, '0')
	return `${d.getFullYear()}-${p(d.getMonth() + 1)}-${p(d.getDate())}`
}

export default {
	name: 'DiaryApp',
	components: { NcButton, NcCheckboxRadioSwitch, NcModal, NcLoadingIcon, NcEmptyContent },
	data() {
		return {
			loading: true,
			journals: [],
			currentJournal: null,
			newDay: todayStr(),
			calendar: { month: todayStr().slice(0, 7), counts: {}, loading: false },
			picker: { open: false, loading: false, entry: null, photos: [], selected: {} },
			caption: { open: false, saving: false, entry: null, photo: null, text: '' },
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
	watch: {
		// Typing a date from another month into the native input moves the grid.
		newDay(date) {
			if (date && date.slice(0, 7) !== this.calendar.month) {
				this.calendar.month = date.slice(0, 7)
				this.loadPhotoDays()
			}
		},
	},
	computed: {
		sortedEntries() {
			// Always newest day first; copy so we don't mutate the loaded array.
			// Same-day entries fall back to sortOrder then id, also descending, so
			// the most recently added one reads first (matches the public page).
			return [...(this.currentJournal?.entries ?? [])]
				.sort((a, b) => {
					if (a.date !== b.date) return a.date < b.date ? 1 : -1
					if (a.sortOrder !== b.sortOrder) return b.sortOrder - a.sortOrder
					return b.id - a.id
				})
		},
		addDayLabel() {
			return this.newDay === todayStr()
				? t('journeys', 'Add today')
				: t('journeys', 'Add day')
		},
		entryDates() {
			return new Set((this.currentJournal?.entries ?? []).map(e => e.date))
		},
		monthLabel() {
			const [y, m] = this.calendar.month.split('-').map(Number)
			return new Date(y, m - 1, 1).toLocaleDateString(undefined, { month: 'long', year: 'numeric' })
		},
		weekdayLabels() {
			// 2024-01-01 was a Monday, which is where the grid starts.
			return Array.from({ length: 7 }, (_, i) =>
				new Date(2024, 0, 1 + i).toLocaleDateString(undefined, { weekday: 'short' }))
		},
		calendarLeadingBlanks() {
			const [y, m] = this.calendar.month.split('-').map(Number)
			return (new Date(y, m - 1, 1).getDay() + 6) % 7
		},
		calendarCells() {
			const [y, m] = this.calendar.month.split('-').map(Number)
			const today = todayStr()
			const pad = n => String(n).padStart(2, '0')
			return Array.from({ length: new Date(y, m, 0).getDate() }, (_, i) => {
				const day = i + 1
				const date = `${y}-${pad(m)}-${pad(day)}`
				const count = this.calendar.counts[date] ?? 0
				const hasEntry = this.entryDates.has(date)
				const parts = [count
					? this.t('journeys', '{count} photos', { count })
					: this.t('journeys', 'No photos')]
				if (hasEntry) parts.push(this.t('journeys', 'already has an entry'))
				return { date, day, count, hasEntry, isToday: date === today, title: `${date} — ${parts.join(', ')}` }
			})
		},
		selectedDayLabel() {
			const count = this.calendar.counts[this.newDay] ?? 0
			if (!this.newDay || this.calendar.month !== this.newDay.slice(0, 7)) return ''
			return count
				? this.t('journeys', '{count} photos on this day', { count })
				: this.t('journeys', 'No photos on this day')
		},
		visitedLine() {
			const s = this.currentJournal?.stats
			if (!s || !s.countries?.length) return ''
			return s.countries.join(' · ')
		},
		otherContributors() {
			return (this.currentJournal?.libraryContributors ?? []).filter(c => !c.isMe)
		},
		consentHint() {
			const j = this.currentJournal
			return j?.startDate && j?.endDate
				? t('journeys', 'They can browse your photos taken between {from} and {to}, and add them to this journal.')
					.replace('{from}', j.startDate).replace('{to}', j.endDate)
				: t('journeys', 'They can browse your photos from the journal’s days once it has entries.')
		},
	},
	methods: {
		previewUrl(fileid) {
			return generateUrl('/core/preview') + '?fileId=' + fileid + '&x=256&y=256&a=1'
		},
		pickerUrl(photo) {
			// /core/preview only serves your own files, so a collaborator's photo
			// goes through the journal endpoint that resolves under its owner.
			return photo.isMine
				? this.previewUrl(photo.fileid)
				: generateUrl('/apps/journeys/diary/journals/' + this.currentJournal.id + '/library-photo/' + photo.fileid)
		},
		statsLine(stats) {
			if (!stats) return ''
			const parts = []
			if (stats.days) parts.push(n('journeys', '%n day', '%n days', stats.days))
			if (stats.countries?.length) parts.push(n('journeys', '%n country', '%n countries', stats.countries.length))
			if (stats.distanceKm) parts.push(this.t('journeys', '{km} km').replace('{km}', stats.distanceKm.toLocaleString()))
			if (stats.photoCount) parts.push(n('journeys', '%n photo', '%n photos', stats.photoCount))
			return parts.join(' · ')
		},
		async toggleCompleted() {
			const completed = !this.currentJournal.isCompleted
			try {
				const { data } = await axios.post(API + '/journals/' + this.currentJournal.id + '/completed', { completed })
				this.currentJournal = data.journal
			} catch (e) {
				showError(this.t('journeys', 'Could not update the journal'))
			}
		},
		async setLibraryConsent(shared) {
			this.$set(this.currentJournal, 'myLibraryShared', shared)
			try {
				const { data } = await axios.post(API + '/journals/' + this.currentJournal.id + '/library-consent', { shared })
				this.$set(this.currentJournal, 'myLibraryShared', data.myLibraryShared)
				this.$set(this.currentJournal, 'libraryContributors', data.libraryContributors)
			} catch (e) {
				this.$set(this.currentJournal, 'myLibraryShared', !shared)
				showError(this.t('journeys', 'Could not change photo sharing'))
			}
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
			this.calendar.month = this.newDay.slice(0, 7)
			this.calendar.counts = {}
			this.loadPhotoDays()
		},
		async loadPhotoDays() {
			const month = this.calendar.month
			const [y, m] = month.split('-').map(Number)
			const last = String(new Date(y, m, 0).getDate()).padStart(2, '0')
			this.calendar.loading = true
			try {
				const { data } = await axios.get(API + '/journals/' + this.currentJournal.id + '/photo-days',
					{ params: { from: `${month}-01`, to: `${month}-${last}` } })
				// A slower earlier month must not overwrite the month now on screen.
				if (this.calendar.month === month) this.calendar.counts = data.days ?? {}
			} catch (e) {
				// A missing photo index is not worth an error toast — the grid just
				// shows no highlights and the picker still works.
			} finally {
				this.calendar.loading = false
			}
		},
		shiftMonth(delta) {
			const [y, m] = this.calendar.month.split('-').map(Number)
			const d = new Date(y, m - 1 + delta, 1)
			this.calendar.month = `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}`
			this.loadPhotoDays()
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
		async saveDescription() {
			try {
				await axios.put(API + '/journals/' + this.currentJournal.id, { description: this.currentJournal.description })
			} catch (e) { showError(this.t('journeys', 'Could not save the description')) }
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
			this.newDay = todayStr()
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
			const photos = entry.photos.map(p => ({ fileid: p.fileid, caption: p.caption || null }))
			const { data } = await axios.put(API + '/entries/' + entry.id + '/photos', { photos })
			entry.photos = data.photos
		},
		openCaption(entry, photo) {
			this.caption = { open: true, saving: false, entry, photo, text: photo.caption || '' }
		},
		closeCaption() { this.caption.open = false },
		async saveCaption() {
			const { entry, photo } = this.caption
			this.caption.saving = true
			photo.caption = this.caption.text.trim() || null
			try {
				await this.persistPhotos(entry)
				this.caption.open = false
			} catch (e) {
				showError(this.t('journeys', 'Could not save caption'))
			}
			this.caption.saving = false
		},
		removePhoto(entry, idx) {
			entry.photos.splice(idx, 1)
			this.persistPhotos(entry)
		},
		async openPicker(entry) {
			// Offers the current user's own photos for that day plus those of any
			// member who shared their library. Photos already on the entry from
			// other days are preserved on save — they're just not shown here.
			this.picker = { open: true, loading: true, entry, photos: [], selected: {} }
			try {
				const { data } = await axios.get(API + '/journals/' + this.currentJournal.id + '/day-photos', { params: { date: entry.date } })
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
			// keep existing captions for photos that survive this save
			const capByFile = {}
			entry.photos.forEach(p => { if (p.caption) capByFile[p.fileid] = p.caption })
			// Submission order is irrelevant — the server merge sorts the whole
			// selection by capture time before storing it.
			const photos = [...keep, ...selected].map(fileid => ({ fileid, caption: capByFile[fileid] || null }))
			const { data } = await axios.put(API + '/entries/' + entry.id + '/photos', { photos })
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
.diary-header { display: flex; align-items: center; flex-wrap: wrap; gap: 12px; margin-bottom: 20px;
	h2 { flex: 1; margin: 0; } }
.diary-loading { margin: 40px auto; }
.journal-list { list-style: none; padding: 0; }
.journal-row { display: flex; justify-content: space-between; align-items: center;
	padding: 14px 16px; border: 1px solid var(--color-border); border-radius: 8px;
	margin-bottom: 8px; cursor: pointer;
	&:hover { background: var(--color-background-hover); }
	&__main { display: flex; flex-direction: column; gap: 2px; min-width: 0; }
	&__title { font-weight: 600; }
	&__desc { color: var(--color-text-maxcontrast); font-size: 0.9em;
		overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
	&__stats { color: var(--color-text-maxcontrast); font-size: 0.85em; }
	&__dates { color: var(--color-text-maxcontrast); font-size: 0.9em; white-space: nowrap; } }
.completed-badge { color: var(--color-success-text, #2d7d46); font-weight: 700;
	&--pill { background: var(--color-success, #d8f3da); color: var(--color-success-text, #2d7d46);
		border-radius: 12px; padding: 2px 10px; font-size: .85em; } }
/* Its own surface: the app background is a colored gradient, on which
   --color-text-maxcontrast is barely legible. */
.journal-stats { display: flex; align-items: center; justify-content: space-between; gap: 12px;
	background: var(--color-main-background); border: 1px solid var(--color-border);
	border-radius: 8px; padding: 8px 12px; margin: 0 0 16px; font-size: .95em;
	&__figures { display: flex; flex-wrap: wrap; align-items: center; gap: 10px; min-width: 0; }
	&__places { color: var(--color-text-maxcontrast); font-style: italic; }
	&__empty { color: var(--color-text-maxcontrast); } }
.journal-title-input { flex: 1; font-size: 1.3em; font-weight: 600; border: none;
	border-bottom: 2px solid transparent; background: transparent;
	&:focus { border-bottom-color: var(--color-primary-element); outline: none; } }
.journal-description-input { width: 100%; box-sizing: border-box; margin: 0 0 12px; padding: 8px 12px;
	font-size: .95em; line-height: 1.4; resize: vertical; border-radius: 8px;
	border: 1px solid var(--color-border); background: var(--color-main-background);
	color: var(--color-main-text);
	&:focus { border-color: var(--color-primary-element); outline: none; } }
.members { margin-bottom: 16px; border: 1px solid var(--color-border); border-radius: 8px;
	background: var(--color-main-background); }
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
.consent { margin-top: 10px; padding-top: 10px; border-top: 1px solid var(--color-border);
	&--only { margin-top: 0; padding-top: 0; border-top: none; } }
.consent__hint { margin: 2px 0 0 4px; color: var(--color-text-maxcontrast); font-size: .85em; }
.consent__sharers { margin: 8px 0 0 4px; font-size: .85em; display: flex; flex-wrap: wrap; gap: 6px; align-items: center;
	color: var(--color-text-maxcontrast); }
.add-day { display: flex; gap: 8px; align-items: center; margin: 16px 0 8px; }
.add-day__count { color: var(--color-text-maxcontrast); font-size: .9em; }
.daycal { background: var(--color-main-background); border: 1px solid var(--color-border);
	border-radius: 8px; padding: 8px 12px 12px; margin-bottom: 24px; max-width: 420px;
	&__head { display: flex; align-items: center; gap: 8px; margin-bottom: 6px; }
	&__month { font-weight: 600; min-width: 9em; text-align: center; }
	&__nav { background: none; border: none; cursor: pointer; font-size: 1.2em; line-height: 1;
		padding: 2px 8px; border-radius: 4px; color: var(--color-main-text);
		&:hover { background: var(--color-background-hover); } }
	&__legend { margin-left: auto; color: var(--color-text-maxcontrast); font-size: .8em; }
	&__grid { display: grid; grid-template-columns: repeat(7, 1fr); gap: 2px; }
	&__weekday { text-align: center; font-size: .75em; color: var(--color-text-maxcontrast); padding-bottom: 2px; }
	&__blank { }
	&__day { position: relative; aspect-ratio: 1; border: 2px solid transparent; border-radius: 6px;
		background: none; cursor: pointer; color: var(--color-main-text); font-size: .9em;
		display: flex; flex-direction: column; align-items: center; justify-content: center; line-height: 1.1;
		&:hover { background: var(--color-background-hover); }
		&--photos { background: var(--color-primary-element-light, var(--color-background-dark)); font-weight: 600; }
		&--today { text-decoration: underline; }
		&--selected { border-color: var(--color-primary-element); }
		&--entry::after { content: ''; position: absolute; top: 4px; right: 4px; width: 6px; height: 6px;
			border-radius: 50%; background: var(--color-success-text, #2d7d46); } }
	&__count { font-size: .65em; color: var(--color-text-maxcontrast); } }
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
.photo-thumb { position: relative; width: 110px; height: 110px; border-radius: 6px; overflow: hidden; cursor: pointer;
	img { width: 100%; height: 100%; object-fit: cover; display: block; background: var(--color-background-dark); }
	&__caption { position: absolute; left: 0; right: 0; bottom: 18px; padding: 2px 6px;
		background: rgba(0,0,0,.55); color: #fff; font-size: .72em; line-height: 1.25;
		display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; }
	&__cover { position: absolute; top: 2px; right: 2px; background: rgba(0,0,0,.45); border: none;
		color: #fff; cursor: pointer; font-size: 15px; border-radius: 4px; padding: 1px 4px;
		&.active { color: #ffce3d; } }
	&__actions { position: absolute; bottom: 0; left: 0; right: 0; display: flex;
		align-items: center; justify-content: flex-end; background: rgba(0,0,0,.45);
		button { background: none; border: none; color: #fff; cursor: pointer; font-size: 16px; padding: 2px 6px;
			&:disabled { opacity: .3; cursor: default; } } } }
.picker { padding: 12px; }
.picker__grid { display: flex; flex-wrap: wrap; gap: 8px; max-height: 55vh; overflow-y: auto; }
.picker__item { position: relative; width: 120px; height: 120px; border-radius: 6px; overflow: hidden;
	cursor: pointer; outline: 3px solid transparent;
	img { width: 100%; height: 100%; object-fit: cover; display: block; background: var(--color-background-dark); }
	&.selected { outline-color: var(--color-primary-element); }
	.picker__owner { position: absolute; left: 0; right: 0; bottom: 0; padding: 2px 6px;
		background: rgba(0,0,0,.55); color: #fff; font-size: .72em; white-space: nowrap;
		overflow: hidden; text-overflow: ellipsis; }
	.picker__check { position: absolute; top: 4px; right: 4px; background: var(--color-primary-element);
		color: var(--color-primary-element-text); border-radius: 50%; width: 22px; height: 22px;
		display: flex; align-items: center; justify-content: center; font-size: 14px; } }
.picker__footer { margin-top: 16px; text-align: right; }
.caption-edit { padding: 16px; display: flex; flex-direction: column; gap: 12px;
	img { max-width: 100%; max-height: 50vh; object-fit: contain; border-radius: 8px; align-self: center; background: var(--color-background-dark); }
	&__text { width: 100%; min-height: 80px; resize: vertical; box-sizing: border-box;
		border: 1px solid var(--color-border); border-radius: 6px; padding: 8px;
		background: var(--color-main-background); color: var(--color-main-text); }
	&__footer { text-align: right; } }

/* Mobile: the header packs back/title/refresh/share/delete into one row — on a
   narrow sidebar that overflows and the buttons become untappable. Give the
   title its own full-width row so the action buttons wrap below at full size. */
@media (max-width: 700px) {
	.diary-view { padding: 16px 12px 80px; }
	.diary-header { gap: 8px; }
	.journal-title-input { flex: 1 1 100%; font-size: 1.2em; }
	.add-day { flex-wrap: wrap; }
	.add-day__date { flex: 1 1 auto; }
	.entry-card { padding: 12px; }
	.entry-card__head { flex-wrap: wrap; }
}
</style>
