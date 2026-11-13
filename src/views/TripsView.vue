<template>
	<div class="journeys-page">
		<header class="page-header">
			<div class="page-header__text">
				<h2>{{ t('journeys', 'Trips') }}</h2>
				<p>{{ t('journeys', 'Away from home with at least one night') }}</p>
			</div>
			<NcCheckboxRadioSwitch type="switch"
				:checked.sync="showExcursions"
				class="page-header__switch">
				{{ t('journeys', 'Also show excursions ({n})', { n: excursionCount }) }}
			</NcCheckboxRadioSwitch>
		</header>

		<p v-if="media.loaded && !media.homeKnown" class="hint-box">
			{{ t('journeys', 'Your home is not known yet, so trips are recognised by the overnight stay alone. Set it in the settings to separate trips from days around home.') }}
		</p>

		<div v-if="visible.length || hasFilter" class="filters">
			<label class="filters__field">
				<span class="hidden-visually">{{ t('journeys', 'Year') }}</span>
				<select v-model="filterYear">
					<option value="">{{ t('journeys', 'All years') }}</option>
					<option v-for="year in years" :key="year" :value="String(year)">{{ year }}</option>
				</select>
			</label>
			<label class="filters__field filters__field--grow">
				<span class="hidden-visually">{{ t('journeys', 'Search by name or place') }}</span>
				<input v-model="filterText" type="search" :placeholder="t('journeys', 'Search by name or place')">
			</label>
		</div>

		<NcLoadingIcon v-if="!media.loaded" :size="32" class="page-loading" />

		<NcEmptyContent v-else-if="!filtered.length"
			:name="hasFilter ? t('journeys', 'No trips match the filter') : t('journeys', 'No trips yet')"
			:description="hasFilter ? '' : t('journeys', 'Trips appear here after the next clustering run.')">
			<template #icon>
				<MapMarkerPath />
			</template>
		</NcEmptyContent>

		<ul v-else class="card-grid">
			<li v-for="journey in filtered" :key="journey.id" class="trip-card">
				<div class="trip-card__cover">
					<img v-if="journey.coverFileId && !brokenCovers[journey.id]"
						:src="previewUrl(journey.coverFileId)"
						alt=""
						loading="lazy"
						@error="$set(brokenCovers, journey.id, true)">
					<ImageOutline v-else :size="48" class="trip-card__placeholder" />
					<span v-if="journey.kind !== 'trip'" class="trip-card__badge">{{ kindLabel(journey.kind) }}</span>
					<button v-if="journey.videos.length"
						type="button"
						class="play-button"
						:aria-label="t('journeys', 'Play video of {name}', { name: displayName(journey) })"
						@click="play(journey.videos[0])">
						<Play :size="24" />
					</button>
				</div>
				<div class="trip-card__body">
					<div class="trip-card__title-row">
						<h3 :title="displayName(journey)">{{ displayName(journey) }}</h3>
						<NcActions :aria-label="t('journeys', 'Actions for {name}', { name: displayName(journey) })">
							<NcActionButton :close-after-click="true" @click="startRename(journey)">
								<template #icon>
									<Pencil :size="20" />
								</template>
								{{ t('journeys', 'Rename') }}
							</NcActionButton>
							<NcActionButton :close-after-click="true"
								:disabled="journey.pending.includes('portrait')"
								@click="queue(journey, 'portrait')">
								<template #icon>
									<VideoOutline :size="20" />
								</template>
								{{ t('journeys', 'Create portrait video') }}
							</NcActionButton>
							<NcActionButton :close-after-click="true"
								:disabled="journey.pending.includes('landscape')"
								@click="queue(journey, 'landscape')">
								<template #icon>
									<VideoOutline :size="20" />
								</template>
								{{ t('journeys', 'Create landscape video') }}
							</NcActionButton>
							<NcActionLink :href="photosUrl(journey)">
								<template #icon>
									<ImageMultiple :size="20" />
								</template>
								{{ t('journeys', 'Open in Photos') }}
							</NcActionLink>
						</NcActions>
					</div>
					<p class="trip-card__meta">
						{{ dateRange(journey.start, journey.end) }}
						<span v-if="journey.imageCount"> · {{ n('journeys', '%n photo', '%n photos', journey.imageCount) }}</span>
					</p>
					<p v-if="journey.location" class="trip-card__meta trip-card__location" :title="journey.location">
						{{ journey.location }}
					</p>
					<div class="trip-card__videos">
						<button v-for="video in latestPerOrientation(journey.videos)"
							:key="video.fileId"
							type="button"
							class="video-chip"
							@click="play(video)">
							<Play :size="16" />
							{{ orientationLabel(video.orientation) }}
						</button>
						<span v-if="journey.pending.length" class="pending-label">
							<NcLoadingIcon :size="16" />
							{{ t('journeys', 'Video is being created …') }}
						</span>
						<NcButton v-else-if="!journey.videos.length"
							type="secondary"
							@click="queue(journey, media.defaultOrientation)">
							<template #icon>
								<VideoOutline :size="20" />
							</template>
							{{ t('journeys', 'Create video') }}
						</NcButton>
					</div>
				</div>
			</li>
		</ul>

		<NcModal v-if="renaming" size="small" @close="renaming = null">
			<form class="rename-modal" @submit.prevent="saveRename">
				<h3>{{ t('journeys', 'Rename trip') }}</h3>
				<NcTextField ref="renameField"
					:value.sync="renameValue"
					:label="t('journeys', 'Name')"
					:disabled="renameSaving" />
				<p class="rename-modal__hint">
					{{ t('journeys', 'Automatic name: {name}', { name: renaming.name }) }}
				</p>
				<div class="rename-modal__actions">
					<NcButton v-if="renaming.customName" :disabled="renameSaving" @click="resetRename">
						{{ t('journeys', 'Use automatic name') }}
					</NcButton>
					<NcButton type="primary" native-type="submit" :disabled="renameSaving">
						{{ t('journeys', 'Save') }}
					</NcButton>
				</div>
			</form>
		</NcModal>
	</div>
</template>

<script>
import { generateUrl } from '@nextcloud/router'
import { showError, showSuccess } from '@nextcloud/dialogs'
import NcActions from '@nextcloud/vue/dist/Components/NcActions.js'
import NcActionButton from '@nextcloud/vue/dist/Components/NcActionButton.js'
import NcActionLink from '@nextcloud/vue/dist/Components/NcActionLink.js'
import NcButton from '@nextcloud/vue/dist/Components/NcButton.js'
import NcCheckboxRadioSwitch from '@nextcloud/vue/dist/Components/NcCheckboxRadioSwitch.js'
import NcEmptyContent from '@nextcloud/vue/dist/Components/NcEmptyContent.js'
import NcLoadingIcon from '@nextcloud/vue/dist/Components/NcLoadingIcon.js'
import NcModal from '@nextcloud/vue/dist/Components/NcModal.js'
import NcTextField from '@nextcloud/vue/dist/Components/NcTextField.js'
import ImageMultiple from 'vue-material-design-icons/ImageMultiple.vue'
import ImageOutline from 'vue-material-design-icons/ImageOutline.vue'
import MapMarkerPath from 'vue-material-design-icons/MapMarkerPath.vue'
import Pencil from 'vue-material-design-icons/Pencil.vue'
import Play from 'vue-material-design-icons/Play.vue'
import VideoOutline from 'vue-material-design-icons/VideoOutline.vue'

import { media, render, rename, displayName } from '../store/media.js'
import { dateRange, orientationLabel, parseDate, playVideo, previewUrl } from '../util/format.js'

const SHOW_EXCURSIONS_KEY = 'journeys.showExcursions'

function readFlag(key) {
	try {
		return window.localStorage.getItem(key) === '1'
	} catch (e) {
		return false
	}
}

export default {
	name: 'TripsView',
	components: {
		NcActions, NcActionButton, NcActionLink, NcButton, NcCheckboxRadioSwitch, NcEmptyContent,
		NcLoadingIcon, NcModal, NcTextField,
		ImageMultiple, ImageOutline, MapMarkerPath, Pencil, Play, VideoOutline,
	},
	data() {
		return {
			media,
			showExcursions: readFlag(SHOW_EXCURSIONS_KEY),
			filterYear: '',
			filterText: '',
			brokenCovers: {},
			renaming: null,
			renameValue: '',
			renameSaving: false,
		}
	},
	computed: {
		visible() {
			return media.journeys.filter(j => this.showExcursions || j.kind === 'trip')
		},
		excursionCount() {
			return media.journeys.filter(j => j.kind !== 'trip').length
		},
		years() {
			const years = new Set(this.visible.map(j => parseDate(j.start)?.getFullYear()).filter(Boolean))
			return [...years].sort((a, b) => b - a)
		},
		hasFilter() {
			return this.filterYear !== '' || this.filterText.trim() !== ''
		},
		filtered() {
			const needle = this.filterText.trim().toLowerCase()
			return this.visible.filter(j => {
				if (this.filterYear !== '' && String(parseDate(j.start)?.getFullYear()) !== this.filterYear) {
					return false
				}
				if (needle !== '') {
					const haystack = `${j.customName || ''} ${j.name || ''} ${j.location || ''}`.toLowerCase()
					return haystack.includes(needle)
				}
				return true
			})
		},
	},
	watch: {
		showExcursions(value) {
			try {
				window.localStorage.setItem(SHOW_EXCURSIONS_KEY, value ? '1' : '0')
			} catch (e) {
			}
		},
	},
	methods: {
		dateRange,
		displayName,
		orientationLabel,
		previewUrl,
		play: playVideo,
		kindLabel(kind) {
			return kind === 'nearby' ? t('journeys', 'Near home') : t('journeys', 'Day trip')
		},
		latestPerOrientation(videos) {
			const seen = new Set()
			return videos.filter(v => !seen.has(v.orientation) && seen.add(v.orientation))
		},
		photosUrl(journey) {
			return generateUrl('/apps/photos/albums/{name}', { name: displayName(journey) })
		},
		async queue(journey, orientation) {
			try {
				await render(journey.id, orientation)
				showSuccess(t('journeys', '“{name}” is queued. You will get a notification when the video is ready.', { name: displayName(journey) }))
			} catch (e) {
				showError(e?.response?.data?.error || t('journeys', 'Failed to queue video.'))
			}
		},
		startRename(journey) {
			this.renaming = journey
			this.renameValue = displayName(journey)
			this.renameSaving = false
		},
		async saveRename() {
			const journey = this.renaming
			const value = this.renameValue.trim()
			const customName = value === '' || value === journey.name ? '' : value
			if (customName === (journey.customName || '')) {
				this.renaming = null
				return
			}
			await this.submitRename(journey, customName)
		},
		async resetRename() {
			await this.submitRename(this.renaming, '')
		},
		async submitRename(journey, customName) {
			this.renameSaving = true
			try {
				await rename(journey.id, customName)
				this.renaming = null
			} catch (e) {
				showError(e?.response?.data?.error || t('journeys', 'Failed to update name.'))
			} finally {
				this.renameSaving = false
			}
		},
	},
}
</script>

<style scoped lang="scss">
@use '../styles/page';

.trip-card {
	display: flex;
	flex-direction: column;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large, 12px);
	background: var(--color-main-background);
	overflow: hidden;

	&__cover {
		position: relative;
		aspect-ratio: 16 / 10;
		overflow: hidden;
		background: var(--color-background-dark);
		display: flex;
		align-items: center;
		justify-content: center;

		img {
			position: absolute;
			inset: 0;
			width: 100%;
			height: 100%;
			object-fit: cover;
		}
	}

	&__placeholder {
		color: var(--color-text-maxcontrast);
	}

	&__badge {
		position: absolute;
		top: 8px;
		left: 8px;
		padding: 2px 10px;
		border-radius: var(--border-radius-pill, 16px);
		background: var(--color-main-background);
		color: var(--color-main-text);
		font-size: 0.85em;
		font-weight: 600;
	}

	.play-button {
		position: absolute;
		right: 10px;
		bottom: 10px;
	}

	&__body {
		display: flex;
		flex-direction: column;
		gap: 2px;
		padding: 8px 8px 12px 14px;
	}

	&__title-row {
		display: flex;
		align-items: flex-start;
		gap: 4px;

		h3 {
			flex: 1;
			min-width: 0;
			margin: 10px 0 0;
			font-size: 1.05em;
			font-weight: 700;
			line-height: 1.3;
			overflow-wrap: anywhere;
		}
	}

	&__meta {
		margin: 0;
		padding-right: 6px;
		color: var(--color-text-maxcontrast);
		font-size: 0.9em;
	}

	&__location {
		overflow: hidden;
		text-overflow: ellipsis;
		white-space: nowrap;
	}

	&__videos {
		display: flex;
		flex-wrap: wrap;
		align-items: center;
		gap: 6px;
		margin-top: 10px;
		min-height: 44px;
	}
}

.rename-modal {
	display: flex;
	flex-direction: column;
	gap: 12px;
	padding: 20px;

	h3 {
		margin: 0;
	}

	&__hint {
		margin: 0;
		color: var(--color-text-maxcontrast);
		font-size: 0.9em;
		overflow-wrap: anywhere;
	}

	&__actions {
		display: flex;
		flex-wrap: wrap;
		justify-content: flex-end;
		gap: 8px;
	}
}
</style>
