<template>
	<div class="journeys_settings">
		<NcSettingsSection :title="t('journeys', 'Journeys Album Clustering')"
			:description="t('journeys', 'Configure and start clustering your photos into journeys (vacations/trips).')">
			<div class="settings-panels">
				<section class="settings-card">
					<header>
						<h3>{{ t('journeys', 'Clustering basics') }}</h3>
						<p>{{ t('journeys', 'Control how photos get grouped into journeys.') }}</p>
					</header>
					<div class="card-body">
						<div class="card-grid">
							<NcTextField
									:label="t('journeys', 'Minimum cluster size')"
									:label-visible="true"
									type="number"
									min="1"
									:value="minClusterSize"
									@update:value="value => updateNumber('minClusterSize', value, { min: 1, integer: true })" />
							<NcTextField
									:label="t('journeys', 'Max time gap (hours)')"
									:label-visible="true"
									type="number"
									min="0"
									step="0.1"
									:value="maxTimeGap"
									@update:value="value => updateNumber('maxTimeGap', value, { min: 0, decimals: true })" />
							<NcTextField
									:label="t('journeys', 'Max distance (km)')"
									:label-visible="true"
									type="number"
									min="0.1"
									step="0.1"
									:value="maxDistanceKm"
									@update:value="value => updateNumber('maxDistanceKm', value, { min: 0.1, decimals: true })" />
						</div>
						<div class="card-grid">
							<NcTextField
									:label="t('journeys', 'Only cluster from (optional)')"
									:label-visible="true"
									:placeholder="'YYYY-MM-DD'"
									:value="rangeFrom"
									@update:value="value => (rangeFrom = value)" />
							<NcTextField
									:label="t('journeys', 'Only cluster to (optional)')"
									:label-visible="true"
									:placeholder="'YYYY-MM-DD'"
									:value="rangeTo"
									@update:value="value => (rangeTo = value)" />
						</div>
						<div class="toggle-list">
							<NcCheckboxRadioSwitch
									:checked="includeGroupFolders"
									type="switch"
									@update:checked="value => onToggleSetting('includeGroupFolders', value)">
									{{ t('journeys', 'Include group folders') }}
								</NcCheckboxRadioSwitch>
							<NcCheckboxRadioSwitch
									:checked="includeSharedImages"
									type="switch"
									@update:checked="value => onToggleSetting('includeSharedImages', value)">
									{{ t('journeys', 'Include shared images') }}
								</NcCheckboxRadioSwitch>
							<NcCheckboxRadioSwitch
									:checked="mergeAdjacent"
									type="switch"
									@update:checked="value => onToggleSetting('mergeAdjacent', value)">
									{{ t('journeys', 'Merge adjacent journeys in the same country (within one week)') }}
								</NcCheckboxRadioSwitch>
						</div>
					</div>
				</section>

				<section class="settings-card">
					<header>
						<h3>{{ t('journeys', 'Near-home thresholds') }}</h3>
						<p>{{ t('journeys', 'Manage day-to-day movement close to home.') }}</p>
					</header>
					<div class="card-body card-grid">
						<NcTextField
								:label="t('journeys', 'Max time gap (hours)')"
								:label-visible="true"
								type="number"
								min="0"
								step="0.1"
								:value="nearTimeGap"
								@update:value="value => updateNumber('nearTimeGap', value, { min: 0, decimals: true })" />
						<NcTextField
								:label="t('journeys', 'Max distance (km)')"
								:label-visible="true"
								type="number"
								min="0.1"
								step="0.1"
								:value="nearDistanceKm"
								@update:value="value => updateNumber('nearDistanceKm', value, { min: 0.1, decimals: true })" />
					</div>
				</section>

				<section class="settings-card">
					<header>
						<h3>{{ t('journeys', 'Away-from-home thresholds') }}</h3>
						<p>{{ t('journeys', 'Tune how longer trips get clustered.') }}</p>
					</header>
					<div class="card-body card-grid">
						<NcTextField
								:label="t('journeys', 'Max time gap (hours)')"
								:label-visible="true"
								type="number"
								min="0"
								step="0.1"
								:value="awayTimeGap"
								@update:value="value => updateNumber('awayTimeGap', value, { min: 0, decimals: true })" />
						<NcTextField
								:label="t('journeys', 'Max distance (km)')"
								:label-visible="true"
								type="number"
								min="0.1"
								step="0.1"
								:value="awayDistanceKm"
								@update:value="value => updateNumber('awayDistanceKm', value, { min: 0.1, decimals: true })" />
					</div>
				</section>

				<section class="settings-card">
					<header>
						<h3>{{ t('journeys', 'Video & home') }}</h3>
						<p>{{ t('journeys', 'Automations, video output and home detection.') }}</p>
					</header>
					<div class="card-body">
						<div class="toggle-list">
							<NcCheckboxRadioSwitch
									:checked="autoGenerateVideos"
									type="switch"
									@update:checked="value => onToggleSetting('autoGenerateVideos', value)">
									{{ t('journeys', 'Auto-generate videos for away clusters') }}
								</NcCheckboxRadioSwitch>
							<NcCheckboxRadioSwitch
									:checked="includeMotionFromGCam"
									type="switch"
									@update:checked="value => onToggleSetting('includeMotionFromGCam', value)">
									{{ t('journeys', 'Include motion from GCam photos (Live)') }}
								</NcCheckboxRadioSwitch>
							<NcCheckboxRadioSwitch
									:checked="showVideoTitle"
									type="switch"
									@update:checked="value => onToggleSetting('showVideoTitle', value)">
									{{ t('journeys', 'Show cluster name title on videos') }}
								</NcCheckboxRadioSwitch>
							<NcCheckboxRadioSwitch
									:checked="showLocationSubtitles"
									type="switch"
									@update:checked="value => onToggleSetting('showLocationSubtitles', value)">
									{{ t('journeys', 'Show per-location subtitles on videos') }}
								</NcCheckboxRadioSwitch>
							<NcCheckboxRadioSwitch
									:checked="boostFaces"
									type="switch"
									@update:checked="value => onToggleSetting('boostFaces', value)">
									{{ t('journeys', 'Prefer photos with people when building videos') }}
								</NcCheckboxRadioSwitch>
						</div>
						<div class="orientation-select">
							<label :for="'videoOrientation'">{{ t('journeys', 'Default video orientation') }}</label>
							<select id="videoOrientation" v-model="videoOrientation">
								<option value="portrait">{{ t('journeys', 'Portrait') }}</option>
								<option value="landscape">{{ t('journeys', 'Landscape') }}</option>
							</select>
						</div>
						<div class="card-grid home-grid">
							<NcTextField
									:label="t('journeys', 'Home latitude')"
									:label-visible="true"
									type="number"
									step="0.000001"
									:value="homeLat"
									@update:value="value => updateNumber('homeLat', value, { decimals: true })" />
							<NcTextField
									:label="t('journeys', 'Home longitude')"
									:label-visible="true"
									type="number"
									step="0.000001"
									:value="homeLon"
									@update:value="value => updateNumber('homeLon', value, { decimals: true })" />
							<NcTextField
									:label="t('journeys', 'Home radius (km)')"
									:label-visible="true"
									type="number"
									min="1"
									:value="homeRadiusKm"
									@update:value="value => updateNumber('homeRadiusKm', value, { min: 1, decimals: true })" />
						</div>
						<div class="home-name" v-if="homeName">
							<NcNoteCard type="info">
								{{ t('journeys', 'Home location') }}: {{ homeName }}
							</NcNoteCard>
						</div>
					</div>
				</section>
			</div>

			<div class="settings-actions">
				<NcButton type="primary" :disabled="isProcessing" @click="persistSettings()">
					{{ t('journeys', 'Save settings') }}
				</NcButton>
				<NcButton :disabled="isProcessing" @click="startClustering" class="secondary">
					{{ isProcessing ? t('journeys', 'Clustering...') : t('journeys', 'Start clustering') }}
				</NcButton>
				<NcNoteCard v-if="lastRun" class="last-run" type="info">
					{{ t('journeys', 'Last run:') }} {{ lastRun }}
				</NcNoteCard>
			</div>
			<NcNoteCard v-if="error" type="error">{{ error }}</NcNoteCard>

			<section v-if="clusters.length" class="journeys-section">
				<header class="section-header">
					<h3>{{ t('journeys', 'Your journeys') }}</h3>
					<p>{{ t('journeys', 'Tap a button to render a video. It runs in the background and lands in Documents / Journeys Movies.') }}</p>
				</header>
				<div class="journey-filters">
					<div class="manual-field">
						<label for="filterYear" class="manual-label">{{ t('journeys', 'Year') }}</label>
						<select id="filterYear" v-model="filterYear">
							<option value="">{{ t('journeys', 'All years') }}</option>
							<option v-for="year in availableYears" :key="year" :value="year">{{ year }}</option>
						</select>
					</div>
					<div class="manual-field">
						<label for="filterMonth" class="manual-label">{{ t('journeys', 'Month') }}</label>
						<select id="filterMonth" v-model="filterMonth">
							<option value="">{{ t('journeys', 'All months') }}</option>
							<option v-for="m in 12" :key="m" :value="m">{{ monthLabel(m) }}</option>
						</select>
					</div>
					<div class="manual-field manual-field--grow">
						<label for="filterLocation" class="manual-label">{{ t('journeys', 'Location or name') }}</label>
						<input id="filterLocation"
								type="search"
								v-model="filterLocation"
								:placeholder="t('journeys', 'Search…')" />
					</div>
					<button v-if="hasActiveFilter"
							type="button"
							class="filter-reset"
							@click="resetFilters">
						{{ t('journeys', 'Clear') }}
					</button>
				</div>
				<p class="filter-summary">
					<span v-if="hasActiveFilter">
						{{ t('journeys', 'Showing {shown} of {total} journeys', { shown: filteredClusters.length, total: clusters.length }) }}
					</span>
					<span v-else>
						{{ t('journeys', '{total} journeys', { total: clusters.length }) }}
					</span>
				</p>
				<div v-if="!filteredClusters.length" class="filter-empty">
					{{ t('journeys', 'No journeys match the current filter.') }}
				</div>
				<div v-else class="journey-grid">
					<article v-for="cluster in filteredClusters" :key="cluster.id" class="journey-card">
						<div class="journey-card__title">
							<h4 :title="cluster.customName || cluster.name">{{ cluster.customName || cluster.name || t('journeys', 'Untitled journey') }}</h4>
							<button type="button" class="journey-card__rename-btn"
									:title="t('journeys', 'Edit name')"
									@click="startEditName(cluster)">✎</button>
							<button v-if="cluster.customName" type="button" class="journey-card__rename-btn"
									:title="t('journeys', 'Reset to auto-derived name')"
									@click="clearCustomName(cluster)">⟲</button>
							<a v-if="cluster.hasVideo && cluster.videoFileId"
									class="rendered-badge rendered-badge--link"
									:href="openInFilesUrl({ fileId: cluster.videoFileId })"
									target="_blank"
									rel="noopener"
									:title="cluster.videoName ? t('journeys', 'Open “{name}” in Files', { name: cluster.videoName }) : t('journeys', 'Open the rendered video in Files')">
								{{ t('journeys', 'Watch') }}
							</a>
							<span v-else-if="cluster.hasVideo" class="rendered-badge" :title="t('journeys', 'A video has been rendered for this journey')">
								{{ t('journeys', 'Rendered') }}
							</span>
						</div>
						<p v-if="cluster.customName"
								class="journey-card__autoname"
								:title="cluster.name">
							{{ cluster.name }}
						</p>
						<ul class="journey-card__meta">
							<li v-if="compactDateRange(cluster.dateRange)" :title="formatDateRange(cluster.dateRange)">
								{{ compactDateRange(cluster.dateRange) }}
							</li>
							<li v-if="cluster.imageCount">{{ t('journeys', '{n} photos', { n: cluster.imageCount }) }}</li>
							<li v-if="cluster.location" class="journey-card__location" :title="cluster.location">{{ cluster.location }}</li>
						</ul>
						<div class="journey-card__actions">
							<NcButton class="render-btn"
									:type="videoOrientation === 'portrait' ? 'primary' : 'secondary'"
									:disabled="isQueued(cluster.id, 'portrait')"
									:title="renderButtonTitle(cluster, 'portrait')"
									@click="renderCluster(cluster, 'portrait')">
								{{ renderButtonLabel(cluster, 'portrait') }}
							</NcButton>
							<NcButton class="render-btn"
									:type="videoOrientation === 'landscape' ? 'primary' : 'secondary'"
									:disabled="isQueued(cluster.id, 'landscape')"
									:title="renderButtonTitle(cluster, 'landscape')"
									@click="renderCluster(cluster, 'landscape')">
								{{ renderButtonLabel(cluster, 'landscape') }}
							</NcButton>
						</div>
						<p v-if="isQueued(cluster.id, 'portrait') || isQueued(cluster.id, 'landscape')" class="journey-card__hint">
							{{ t('journeys', 'Queued — appears in Documents / Journeys Movies shortly.') }}
						</p>
					</article>
				</div>
			</section>

			<section class="journeys-section manual-album-section">
				<header class="section-header">
					<h3>{{ t('journeys', 'Render any Photos album') }}</h3>
					<p>{{ t('journeys', 'Pick one of your manual Photos albums (or paste an album ID) to render a video for it.') }}</p>
				</header>
				<div class="manual-album-grid">
					<div class="manual-field">
						<label for="manualAlbumPicker" class="manual-label">{{ t('journeys', 'Manual albums') }}</label>
						<select id="manualAlbumPicker" v-if="manualAlbums.length"
								:value="manualAlbumId || ''"
								@change="event => manualAlbumId = event.target.value ? Number(event.target.value) : null">
							<option value="">{{ t('journeys', '— pick an album —') }}</option>
							<option v-for="album in manualAlbums" :key="album.id" :value="album.id">
								{{ album.name || t('journeys', 'Untitled album') }} (#{{ album.id }})
							</option>
						</select>
						<p v-else class="manual-hint">
							{{ t('journeys', 'No manual albums detected. Enter an album ID below if you know it.') }}
						</p>
					</div>
					<div class="manual-field">
						<label for="manualAlbumId" class="manual-label">{{ t('journeys', 'Album ID') }}</label>
						<input id="manualAlbumId"
								type="number"
								min="1"
								inputmode="numeric"
								v-model.number="manualAlbumId"
								:placeholder="t('journeys', 'e.g. 142')" />
					</div>
				</div>
				<div class="manual-buttons">
					<NcButton class="render-btn"
							:type="videoOrientation === 'portrait' ? 'primary' : 'secondary'"
							:disabled="!isManualAlbumInputValid || manualRenderBusy"
							@click="renderManualAlbum('portrait')">
						{{ manualRenderBusy ? t('journeys', 'Queuing...') : t('journeys', 'Render portrait') }}
					</NcButton>
					<NcButton class="render-btn"
							:type="videoOrientation === 'landscape' ? 'primary' : 'secondary'"
							:disabled="!isManualAlbumInputValid || manualLandscapeBusy"
							@click="renderManualAlbum('landscape')">
						{{ manualLandscapeBusy ? t('journeys', 'Queuing...') : t('journeys', 'Render landscape') }}
					</NcButton>
				</div>
				<small class="manual-hint">
					{{ t('journeys', 'Rendering happens in the background. Look in Documents / Journeys Movies once the next cron tick has run.') }}
				</small>
			</section>

			<section v-if="renderedVideos.length" class="journeys-section">
				<header class="section-header">
					<h3>{{ t('journeys', 'Rendered videos') }}</h3>
					<p>{{ t('journeys', 'Files in Documents / Journeys Movies, newest first.') }}</p>
				</header>
				<ul class="rendered-list">
					<li v-for="video in renderedVideos" :key="video.fileId" class="rendered-item">
						<div class="rendered-item__main">
							<span class="rendered-item__name">{{ video.name }}</span>
							<span class="rendered-item__meta">
								{{ formatTimestamp(video.mtime) }} · {{ formatSize(video.size) }}
							</span>
						</div>
						<a class="rendered-item__action" :href="openInFilesUrl(video)" target="_blank" rel="noopener">
							{{ t('journeys', 'Open in Files') }}
						</a>
					</li>
				</ul>
			</section>
		</NcSettingsSection>

		<NcModal v-if="editingClusterId !== null"
				:show="true"
				size="normal"
				@close="cancelEditName">
			<div class="rename-modal">
				<h3 class="rename-modal__title">{{ t('journeys', 'Rename journey') }}</h3>
				<p class="rename-modal__hint">
					{{ t('journeys', 'Give this journey a custom name. Use it for events that aren’t a place — e.g. Christmas 2024, Family reunion, Sabbatical.') }}
				</p>
				<label for="journeyRenameInput" class="rename-modal__label">{{ t('journeys', 'Name') }}</label>
				<input id="journeyRenameInput"
						ref="renameInput"
						class="rename-modal__input"
						type="text"
						v-model="editingValue"
						:disabled="editSaving"
						:placeholder="t('journeys', 'Custom name')"
						@keyup.enter="saveCustomName(editingCluster)"
						@keyup.esc="cancelEditName" />
				<p class="rename-modal__autoname">
					{{ t('journeys', 'Auto-derived name:') }} <span>{{ editingCluster ? editingCluster.name : '' }}</span>
				</p>
				<div class="rename-modal__actions">
					<NcButton :disabled="editSaving" @click="cancelEditName">
						{{ t('journeys', 'Cancel') }}
					</NcButton>
					<NcButton v-if="editingCluster && editingCluster.customName"
							:disabled="editSaving"
							@click="clearFromModal">
						{{ t('journeys', 'Reset to auto') }}
					</NcButton>
					<NcButton type="primary" :disabled="editSaving" @click="saveCustomName(editingCluster)">
						{{ editSaving ? t('journeys', 'Saving…') : t('journeys', 'Save') }}
					</NcButton>
				</div>
			</div>
		</NcModal>
	</div>
</template>

<script>
import { NcSettingsSection, NcCheckboxRadioSwitch, NcTextField, NcButton, NcNoteCard, NcModal } from '@nextcloud/vue'
import { generateUrl } from '@nextcloud/router'
import axios from '@nextcloud/axios'
import { showError, showSuccess } from '@nextcloud/dialogs'

export default {
	name: 'PersonalSettingsPage',
	components: { NcSettingsSection, NcCheckboxRadioSwitch, NcTextField, NcButton, NcNoteCard, NcModal },
	data() {
		return {
			isProcessing: false,
			savingInline: false,
			lastRun: null,
			error: null,
			clusters: [],
			renderedVideos: [],
			queuedRenders: {},
			editingClusterId: null,
			editingValue: '',
			editSaving: false,
			filterYear: '',
			filterMonth: '',
			filterLocation: '',
			minClusterSize: 3,
			maxTimeGap: 86400,
			maxDistanceKm: 100.0,
			nearTimeGap: 21600,
			nearDistanceKm: 3.0,
			awayTimeGap: 129600,
			awayDistanceKm: 50.0,
			homeLat: null,
			homeLon: null,
			homeRadiusKm: 50,
			homeName: null,
			includeGroupFolders: false,
			includeSharedImages: false,
			mergeAdjacent: true,
			rangeFrom: null,
			rangeTo: null,
			autoGenerateVideos: false,
			includeMotionFromGCam: true,
			showVideoTitle: true,
			showLocationSubtitles: true,
			boostFaces: true,
			videoOrientation: 'portrait',
			albums: [],
			manualAlbumId: null,
			manualRenderBusy: false,
			manualLandscapeBusy: false,
		}
	},
	computed: {
		isManualAlbumInputValid() {
			return this.isPositiveAlbumId(this.manualAlbumId)
		},
		manualAlbums() {
			return (this.albums || []).filter(album => !album?.isCluster)
		},
		availableYears() {
			const years = new Set()
			for (const cluster of this.clusters) {
				const year = this.clusterYear(cluster)
				if (year) years.add(year)
			}
			return Array.from(years).sort((a, b) => b - a)
		},
		hasActiveFilter() {
			return this.filterYear !== '' || this.filterMonth !== '' || (this.filterLocation || '').trim() !== ''
		},
		editingCluster() {
			if (this.editingClusterId === null) return null
			return this.clusters.find(c => c.id === this.editingClusterId) || null
		},
		filteredClusters() {
			const year = this.filterYear ? Number(this.filterYear) : null
			const month = this.filterMonth ? Number(this.filterMonth) : null
			const needle = (this.filterLocation || '').trim().toLowerCase()

			return this.clusters.filter(cluster => {
				if (year !== null) {
					if (this.clusterYear(cluster) !== year) return false
				}
				if (month !== null) {
					if (!this.clusterCoversMonth(cluster, month, year)) return false
				}
				if (needle !== '') {
					const haystack = `${cluster.customName || ''} ${cluster.name || ''} ${cluster.location || ''}`.toLowerCase()
					if (!haystack.includes(needle)) return false
				}
				return true
			})
		},
	},
	async mounted() {
		try {
			const settingsResp = await axios.get(generateUrl('/apps/journeys/personal_settings/get_clustering_settings'))
			if (settingsResp.data) {
				this.minClusterSize = settingsResp.data.minClusterSize
				this.maxTimeGap = settingsResp.data.maxTimeGap
				this.maxDistanceKm = settingsResp.data.maxDistanceKm
				this.includeGroupFolders = !!settingsResp.data.includeGroupFolders
				this.includeSharedImages = !!settingsResp.data.includeSharedImages
				this.mergeAdjacent = settingsResp.data.mergeAdjacent !== false
				this.rangeFrom = settingsResp.data.rangeFrom || null
				this.rangeTo = settingsResp.data.rangeTo || null
				this.homeLat = settingsResp.data.homeLat
				this.homeLon = settingsResp.data.homeLon
				this.homeRadiusKm = settingsResp.data.homeRadiusKm
				this.homeName = settingsResp.data.homeName || null
				this.autoGenerateVideos = !!settingsResp.data.autoGenerateVideos
				this.includeMotionFromGCam = !!settingsResp.data.includeMotionFromGCam
				this.showVideoTitle = settingsResp.data.showVideoTitle !== undefined ? !!settingsResp.data.showVideoTitle : true
				this.showLocationSubtitles = settingsResp.data.showLocationSubtitles !== undefined ? !!settingsResp.data.showLocationSubtitles : true
				this.boostFaces = settingsResp.data.boostFaces !== undefined ? !!settingsResp.data.boostFaces : true
				this.videoOrientation = settingsResp.data.videoOrientation || 'portrait'
				if (typeof settingsResp.data.nearTimeGap !== 'undefined') this.nearTimeGap = settingsResp.data.nearTimeGap
				if (typeof settingsResp.data.nearDistanceKm !== 'undefined') this.nearDistanceKm = settingsResp.data.nearDistanceKm
				if (typeof settingsResp.data.awayTimeGap !== 'undefined') this.awayTimeGap = settingsResp.data.awayTimeGap
				if (typeof settingsResp.data.awayDistanceKm !== 'undefined') this.awayDistanceKm = settingsResp.data.awayDistanceKm
			}
		} catch (e) {
			// ignore if not available
		}
		try {
			const resp = await axios.get(generateUrl('/apps/journeys/personal_settings/last_run'))
			this.lastRun = resp.data.lastRun
		} catch (e) {
			// ignore if not available
		}
		await this.fetchClusters()
		await this.fetchRenderedVideos()
	},
	methods: {
		isPositiveAlbumId(value) {
			return typeof value === 'number' && Number.isFinite(value) && value > 0
		},
		isQueued(clusterId, orientation) {
			return !!this.queuedRenders[`${clusterId}-${orientation}`]
		},
		parseClusterDate(value) {
			if (!value) return null
			const date = new Date(String(value).replace(' ', 'T'))
			return isNaN(date.getTime()) ? null : date
		},
		clusterYear(cluster) {
			const start = this.parseClusterDate(cluster?.dateRange?.start)
			if (start) return start.getFullYear()
			const end = this.parseClusterDate(cluster?.dateRange?.end)
			return end ? end.getFullYear() : null
		},
		clusterCoversMonth(cluster, month, year) {
			const start = this.parseClusterDate(cluster?.dateRange?.start)
			const end = this.parseClusterDate(cluster?.dateRange?.end) || start
			if (!start) return false
			// Iterate calendar months between start and end (inclusive); cap at 18 months.
			const cursor = new Date(start.getFullYear(), start.getMonth(), 1)
			const stop = new Date(end.getFullYear(), end.getMonth(), 1)
			for (let i = 0; i < 18 && cursor <= stop; i++) {
				if (cursor.getMonth() + 1 === month && (year === null || cursor.getFullYear() === year)) {
					return true
				}
				cursor.setMonth(cursor.getMonth() + 1)
			}
			return false
		},
		monthLabel(monthNumber) {
			const date = new Date(2000, monthNumber - 1, 1)
			return date.toLocaleString(undefined, { month: 'long' })
		},
		resetFilters() {
			this.filterYear = ''
			this.filterMonth = ''
			this.filterLocation = ''
		},
		renderButtonLabel(cluster, orientation) {
			if (this.isQueued(cluster.id, orientation)) {
				return this.t('journeys', 'Queued')
			}
			return orientation === 'landscape'
				? this.t('journeys', 'Landscape')
				: this.t('journeys', 'Portrait')
		},
		renderButtonTitle(cluster, orientation) {
			if (this.isQueued(cluster.id, orientation)) {
				return this.t('journeys', 'Render queued')
			}
			const action = cluster.hasVideo
				? (orientation === 'landscape' ? this.t('journeys', 'Re-render landscape video') : this.t('journeys', 'Re-render portrait video'))
				: (orientation === 'landscape' ? this.t('journeys', 'Render landscape video') : this.t('journeys', 'Render portrait video'))
			return action
		},
		formatDateRange(range) {
			if (!range || (!range.start && !range.end)) {
				return '—'
			}
			const start = range.start ? this.formatDate(range.start) : '—'
			const end = range.end ? this.formatDate(range.end) : null
			if (!end || end === start) {
				return start
			}
			return `${start} → ${end}`
		},
		formatDate(value) {
			if (!value) return ''
			const date = new Date(value.replace(' ', 'T'))
			if (isNaN(date.getTime())) return String(value).slice(0, 10)
			return date.toISOString().slice(0, 10)
		},
		compactDateRange(range) {
			const start = this.parseClusterDate(range?.start)
			const end = this.parseClusterDate(range?.end) || start
			if (!start) return ''
			const monthShort = (d) => d.toLocaleString(undefined, { month: 'short' })
			const startStr = `${monthShort(start)} ${start.getDate()}`
			if (!end || +start === +end) {
				return `${startStr}, ${start.getFullYear()}`
			}
			if (start.getFullYear() === end.getFullYear()) {
				if (start.getMonth() === end.getMonth()) {
					return `${startStr}–${end.getDate()}, ${start.getFullYear()}`
				}
				return `${startStr} – ${monthShort(end)} ${end.getDate()}, ${start.getFullYear()}`
			}
			return `${startStr}, ${start.getFullYear()} – ${monthShort(end)} ${end.getDate()}, ${end.getFullYear()}`
		},
		formatTimestamp(unixSeconds) {
			if (!unixSeconds) return ''
			const date = new Date(unixSeconds * 1000)
			if (isNaN(date.getTime())) return ''
			return date.toLocaleString()
		},
		formatSize(bytes) {
			if (!bytes && bytes !== 0) return ''
			const units = ['B', 'KB', 'MB', 'GB']
			let value = Number(bytes)
			let unitIndex = 0
			while (value >= 1024 && unitIndex < units.length - 1) {
				value /= 1024
				unitIndex++
			}
			return `${value.toFixed(value >= 10 || unitIndex === 0 ? 0 : 1)} ${units[unitIndex]}`
		},
		openInFilesUrl(video) {
			const dir = '/Documents/Journeys Movies'
			const params = new URLSearchParams({ dir, openfile: String(video.fileId) })
			return generateUrl('/apps/files/?' + params.toString())
		},
		async persistSettingsRequest(options = { silent: false, inline: false }) {
			const { silent = false, inline = false } = options || {}
			if (inline) {
				this.savingInline = true
			} else {
				this.isProcessing = true
			}
			try {
				await axios.post(generateUrl('/apps/journeys/personal_settings/save_clustering_settings'), this.getSettingsPayload())
				if (!silent) {
					showSuccess(this.t('journeys', 'Settings saved.'))
				}
			} catch (e) {
				if (!silent) {
					showError(this.t('journeys', 'Failed to save settings.'))
				}
				throw e
			} finally {
				if (inline) {
					this.savingInline = false
				} else {
					this.isProcessing = false
				}
			}
		},
		getSettingsPayload() {
			return {
				minClusterSize: this.minClusterSize,
				maxTimeGap: this.maxTimeGap,
				maxDistanceKm: this.maxDistanceKm,
				includeGroupFolders: this.includeGroupFolders,
				includeSharedImages: this.includeSharedImages,
				mergeAdjacent: this.mergeAdjacent,
				rangeFrom: this.rangeFrom,
				rangeTo: this.rangeTo,
				homeLat: this.homeLat,
				homeLon: this.homeLon,
				homeRadiusKm: this.homeRadiusKm,
				autoGenerateVideos: this.autoGenerateVideos,
				includeMotionFromGCam: this.includeMotionFromGCam,
				showVideoTitle: this.showVideoTitle,
				showLocationSubtitles: this.showLocationSubtitles,
				boostFaces: this.boostFaces,
				videoOrientation: this.videoOrientation,
				nearTimeGap: this.nearTimeGap,
				nearDistanceKm: this.nearDistanceKm,
				awayTimeGap: this.awayTimeGap,
				awayDistanceKm: this.awayDistanceKm,
			}
		},
		async onToggleSetting(field, value) {
			const previous = this[field]
			this[field] = value
			try {
				await this.persistSettingsRequest({ silent: true, inline: true })
			} catch (e) {
				this[field] = previous
			}
		},
		updateNumber(field, value, options = {}) {
			const { min = null, max = null, integer = false, decimals = false } = options
			let nextValue = value === '' || value === null ? null : Number(value)
			if (!Number.isFinite(nextValue)) {
				return
			}
			if (integer) {
				nextValue = Math.round(nextValue)
			}
			if (decimals) {
				nextValue = parseFloat(nextValue.toFixed(6))
			}
			if (min !== null && nextValue < min) {
				nextValue = min
			}
			if (max !== null && nextValue > max) {
				nextValue = max
			}
			this[field] = nextValue
		},
		async persistSettings() {
			return this.persistSettingsRequest({ silent: false, inline: false })
		},
		async startClustering() {
			this.isProcessing = true
			this.error = null
			try {
				const resp = await axios.post(generateUrl('/apps/journeys/personal_settings/start_clustering'), this.getSettingsPayload())
				showSuccess(this.t('journeys', 'Clustering started successfully.'))
				this.lastRun = resp.data.lastRun || new Date().toISOString()
				await this.fetchClusters()
				await this.fetchRenderedVideos()
			} catch (e) {
				this.error = this.t('journeys', 'Failed to start clustering.')
				showError(this.error)
			} finally {
				this.isProcessing = false
			}
		},
		async fetchClusters() {
			try {
				const resp = await axios.get(generateUrl('/apps/journeys/personal_settings/clusters'))
				this.clusters = Array.isArray(resp.data?.clusters) ? resp.data.clusters : []
				this.albums = Array.isArray(resp.data?.albums) ? resp.data.albums : []
			} catch (e) {
				this.clusters = []
				this.albums = []
			}
			this.queuedRenders = {}
		},
		async fetchRenderedVideos() {
			try {
				const resp = await axios.get(generateUrl('/apps/journeys/personal_settings/rendered_videos'))
				this.renderedVideos = Array.isArray(resp.data?.videos) ? resp.data.videos : []
			} catch (e) {
				this.renderedVideos = []
			}
		},
		markQueued(albumId, orientation) {
			this.$set(this.queuedRenders, `${albumId}-${orientation}`, true)
		},
		startEditName(cluster) {
			this.editingClusterId = cluster.id
			// Seed the input with whatever name the user currently sees, so they can
			// tweak it instead of starting from an empty field.
			this.editingValue = cluster.customName || cluster.name || ''
			this.editSaving = false
			this.$nextTick(() => {
				const el = this.$refs.renameInput
				if (el) {
					el.focus()
					el.select()
				}
			})
		},
		cancelEditName() {
			this.editingClusterId = null
			this.editingValue = ''
			this.editSaving = false
		},
		async clearFromModal() {
			const cluster = this.editingCluster
			if (!cluster) return
			this.editSaving = true
			try {
				await this.clearCustomName(cluster)
				this.cancelEditName()
			} finally {
				this.editSaving = false
			}
		},
		async saveCustomName(cluster) {
			if (!cluster || !cluster.id) {
				return
			}
			const trimmed = (this.editingValue || '').trim()
			// Saving the auto-derived name unchanged is equivalent to clearing the custom name.
			const payload = (trimmed === '' || trimmed === (cluster.name || '').trim()) ? '' : trimmed
			if (payload === (cluster.customName || '')) {
				this.cancelEditName()
				return
			}
			this.editSaving = true
			try {
				const resp = await axios.post(generateUrl('/apps/journeys/personal_settings/update_cluster_name'), {
					albumId: cluster.id,
					customName: payload,
				})
				const updated = resp.data || {}
				const idx = this.clusters.findIndex(c => c.id === cluster.id)
				if (idx !== -1) {
					this.$set(this.clusters, idx, {
						...this.clusters[idx],
						customName: updated.customName ?? null,
					})
				}
				showSuccess(this.t('journeys', 'Journey name updated.'))
				this.cancelEditName()
			} catch (e) {
				const message = e?.response?.data?.error || e?.message || this.t('journeys', 'Failed to update name.')
				showError(message)
				this.editSaving = false
			}
		},
		async clearCustomName(cluster) {
			if (!cluster || !cluster.id) {
				return
			}
			try {
				const resp = await axios.post(generateUrl('/apps/journeys/personal_settings/update_cluster_name'), {
					albumId: cluster.id,
					customName: '',
				})
				const updated = resp.data || {}
				const idx = this.clusters.findIndex(c => c.id === cluster.id)
				if (idx !== -1) {
					this.$set(this.clusters, idx, {
						...this.clusters[idx],
						customName: updated.customName ?? null,
					})
				}
				showSuccess(this.t('journeys', 'Reverted to auto-derived name.'))
			} catch (e) {
				const message = e?.response?.data?.error || e?.message || this.t('journeys', 'Failed to update name.')
				showError(message)
			}
		},
		async renderCluster(cluster, orientation) {
			if (!cluster || !cluster.id) {
				return
			}
			this.error = null
			const url = orientation === 'landscape'
				? '/apps/journeys/personal_settings/render_cluster_video_landscape'
				: '/apps/journeys/personal_settings/render_cluster_video'
			try {
				const resp = await axios.post(generateUrl(url), { albumId: cluster.id })
				if (resp.data && resp.data.success) {
					this.markQueued(cluster.id, orientation)
					showSuccess(this.t('journeys', 'Queued “{name}” — your video will appear in Documents / Journeys Movies in a few minutes.', {
						name: cluster.name || `#${cluster.id}`,
					}))
				} else {
					showError(resp.data?.error || this.t('journeys', 'Failed to queue video.'))
				}
			} catch (e) {
				const message = e?.response?.data?.error || e?.message || this.t('journeys', 'Failed to queue video.')
				showError(message)
			}
		},
		async renderManualAlbum(orientation = 'portrait') {
			const albumId = this.manualAlbumId
			if (!this.isPositiveAlbumId(albumId)) {
				showError(this.t('journeys', 'Pick or enter a valid album ID.'))
				return
			}
			const isLandscape = orientation === 'landscape'
			const busyField = isLandscape ? 'manualLandscapeBusy' : 'manualRenderBusy'
			if (this[busyField]) {
				return
			}
			this[busyField] = true
			try {
				const url = generateUrl(isLandscape
					? '/apps/journeys/personal_settings/render_cluster_video_landscape'
					: '/apps/journeys/personal_settings/render_cluster_video')
				const resp = await axios.post(url, { albumId })
				if (resp.data && resp.data.success) {
					showSuccess(this.t('journeys', 'Queued album #{id} — your video will appear in Documents / Journeys Movies in a few minutes.', {
						id: albumId,
					}))
				} else {
					showError(resp.data?.error || this.t('journeys', 'Failed to queue video.'))
				}
			} catch (e) {
				const message = e?.response?.data?.error || e?.message || this.t('journeys', 'Failed to queue video.')
				showError(message)
			} finally {
				this[busyField] = false
			}
		},
	},
}
</script>

<style lang="scss" scoped>
.journeys_settings {
  max-width: 960px;
  margin: 2em auto;
}

.settings-panels {
  display: grid;
  gap: 1.5rem;
  grid-template-columns: repeat(auto-fit, minmax(260px, 1fr));
}

.settings-card {
  background: var(--color-main-background, var(--color-background-darker));
  border: 1px solid var(--color-border);
  border-radius: var(--border-radius, 8px);
  box-shadow: var(--box-shadow, 0 2px 6px rgba(0, 0, 0, 0.05));
  padding: 1rem 1.25rem;
  display: flex;
  flex-direction: column;
}

.settings-card header {
  margin-bottom: 1rem;
}

.settings-card h3 {
  margin: 0;
  font-size: 1.1rem;
  font-weight: 600;
}

.settings-card p {
  margin: 0.25rem 0 0;
  color: var(--color-text-lighter);
  font-size: 0.92rem;
}

.card-body {
  display: flex;
  flex-direction: column;
  gap: 1rem;
}

.card-grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
  gap: 0.75rem 1rem;
}

.home-grid {
  grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
}

.toggle-list {
  display: flex;
  flex-direction: column;
  gap: 0.5rem;
}

.orientation-select {
  display: flex;
  flex-direction: column;
  gap: 0.25rem;
}

.orientation-select select {
  border-radius: 6px;
  border: 1px solid var(--color-border);
  padding: 0.4rem 0.6rem;
  background: var(--color-background-input);
}

.home-name {
  margin-top: 0.5rem;
}

.settings-actions {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 0.75rem;
  margin: 2rem 0 1rem;
}

.settings-actions .last-run {
  margin-left: auto;
}

.journeys-section {
  margin: 1.75rem 0;
}

.section-header {
  margin-bottom: 0.75rem;
}

.section-header h3 {
  margin: 0;
  font-size: 1.1rem;
  font-weight: 600;
}

.section-header p {
  margin: 0.25rem 0 0;
  color: var(--color-text-lighter);
  font-size: 0.92rem;
}

.journey-filters {
  display: grid;
  grid-template-columns: minmax(120px, 1fr) minmax(120px, 1fr) minmax(160px, 2fr) auto;
  gap: 0.5rem 0.75rem;
  align-items: end;
  margin-bottom: 0.5rem;
}

.journey-filters .manual-field {
  margin: 0;
}

.journey-filters select,
.journey-filters input {
  width: 100%;
  border-radius: 6px;
  border: 1px solid var(--color-border);
  padding: 0.45rem 0.6rem;
  background: var(--color-background-input);
  font-size: 0.95rem;
}

.manual-field--grow {
  grid-column: span 1;
}

.filter-reset {
  height: calc(0.45rem * 2 + 1.4rem);
  padding: 0 0.85rem;
  border-radius: 6px;
  border: 1px solid var(--color-border);
  background: var(--color-background-hover, transparent);
  cursor: pointer;
  white-space: nowrap;
}

.filter-reset:hover {
  background: var(--color-background-darker);
}

.filter-summary {
  margin: 0 0 0.75rem;
  color: var(--color-text-lighter);
  font-size: 0.88rem;
}

.filter-empty {
  padding: 1rem;
  border: 1px dashed var(--color-border);
  border-radius: var(--border-radius, 8px);
  color: var(--color-text-lighter);
  text-align: center;
}

.journey-grid {
  display: grid;
  gap: 0.6rem;
  grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
}

.journey-card {
  display: flex;
  flex-direction: column;
  gap: 0.4rem;
  background: var(--color-main-background, var(--color-background-darker));
  border: 1px solid var(--color-border);
  border-radius: var(--border-radius-large, 10px);
  padding: 0.55rem 0.7rem;
  box-shadow: var(--box-shadow, 0 1px 3px rgba(0, 0, 0, 0.05));
}

.journey-card__title {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 0.4rem;
}

.journey-card__title h4 {
  margin: 0;
  font-size: 0.95rem;
  font-weight: 600;
  line-height: 1.25;
  flex: 1;
  min-width: 0;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.journey-card__rename-btn {
  background: transparent;
  border: 1px solid transparent;
  border-radius: var(--border-radius, 4px);
  cursor: pointer;
  color: var(--color-text-lighter);
  font-size: 0.95rem;
  line-height: 1;
  padding: 0.15rem 0.35rem;
  flex-shrink: 0;
}

.journey-card__rename-btn:hover:not(:disabled),
.journey-card__rename-btn:focus:not(:disabled) {
  background: var(--color-background-hover, rgba(0, 0, 0, 0.04));
  color: var(--color-main-text);
}

.journey-card__rename-btn:disabled {
  opacity: 0.4;
  cursor: default;
}

.journey-card__autoname {
  margin: 0;
  font-size: 0.78rem;
  color: var(--color-text-maxcontrast, var(--color-text-lighter));
  line-height: 1.25;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.rename-modal {
  display: flex;
  flex-direction: column;
  gap: 0.6rem;
  padding: 1.2rem 1.4rem 1.1rem;
  min-width: min(480px, 100%);
  max-width: 560px;
}

.rename-modal__title {
  margin: 0;
  font-size: 1.05rem;
  font-weight: 600;
}

.rename-modal__hint {
  margin: 0;
  font-size: 0.85rem;
  color: var(--color-text-lighter);
  line-height: 1.35;
}

.rename-modal__label {
  font-size: 0.78rem;
  font-weight: 600;
  text-transform: uppercase;
  letter-spacing: 0.02em;
  color: var(--color-text-lighter);
  margin-top: 0.3rem;
}

.rename-modal__input {
  width: 100%;
  font-size: 1rem;
  font-weight: 500;
  padding: 0.55rem 0.75rem;
  border: 1px solid var(--color-border-dark, var(--color-border));
  border-radius: var(--border-radius, 4px);
  background: var(--color-main-background);
  color: var(--color-main-text);
  box-sizing: border-box;
}

.rename-modal__input:focus {
  outline: none;
  border-color: var(--color-primary-element, var(--color-primary));
}

.rename-modal__autoname {
  margin: 0;
  font-size: 0.82rem;
  color: var(--color-text-lighter);
}

.rename-modal__autoname span {
  color: var(--color-main-text);
  font-weight: 500;
}

.rename-modal__actions {
  display: flex;
  justify-content: flex-end;
  gap: 0.5rem;
  margin-top: 0.4rem;
  flex-wrap: wrap;
}

.rendered-badge {
  display: inline-flex;
  align-items: center;
  gap: 0.2rem;
  padding: 0.08rem 0.5rem;
  border-radius: 999px;
  background: var(--color-success, #46ba61);
  color: var(--color-primary-text, #fff);
  font-size: 0.72rem;
  font-weight: 600;
  white-space: nowrap;
  flex-shrink: 0;
}

.rendered-badge--link {
  text-decoration: none;
  cursor: pointer;
  transition: filter 0.1s ease, transform 0.1s ease;
}

.rendered-badge--link::before {
  content: '▶';
  font-size: 0.7em;
}

.rendered-badge--link:hover,
.rendered-badge--link:focus {
  filter: brightness(1.08);
  transform: translateY(-1px);
  text-decoration: none;
}

.journey-card__meta {
  list-style: none;
  margin: 0;
  padding: 0;
  display: flex;
  flex-wrap: wrap;
  gap: 0.15rem 0.5rem;
  font-size: 0.82rem;
  color: var(--color-text-lighter);
  line-height: 1.3;
}

.journey-card__meta li {
  display: inline-flex;
  align-items: center;
  min-width: 0;
}

.journey-card__meta li + li::before {
  content: '·';
  margin-right: 0.5rem;
  opacity: 0.7;
}

.journey-card__location {
  max-width: 100%;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.journey-card__actions {
  display: flex;
  gap: 0.4rem;
  margin-top: 0.1rem;
}

.journey-card__actions .render-btn {
  flex: 1 1 0;
  min-width: 0;
}

.journey-card__actions .render-btn :deep(.button-vue) {
  min-height: 32px !important;
  font-size: 0.85rem;
}

.journey-card__hint {
  margin: 0;
  color: var(--color-text-lighter);
  font-size: 0.78rem;
}

.manual-album-section {
  background: var(--color-main-background, var(--color-background-darker));
  border: 1px solid var(--color-border);
  border-radius: var(--border-radius-large, 12px);
  padding: 1rem 1.1rem;
}

.manual-album-grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
  gap: 0.75rem 1rem;
  margin-bottom: 0.75rem;
}

.manual-field {
  display: flex;
  flex-direction: column;
  gap: 0.25rem;
}

.manual-field select,
.manual-field input {
  border-radius: 6px;
  border: 1px solid var(--color-border);
  padding: 0.5rem 0.6rem;
  background: var(--color-background-input);
  font-size: 1rem;
  width: 100%;
}

.manual-label {
  font-weight: 600;
  font-size: 0.92rem;
}

.manual-buttons {
  display: flex;
  flex-wrap: wrap;
  gap: 0.5rem;
  margin-bottom: 0.5rem;
}

.manual-buttons .render-btn {
  flex: 1 1 160px;
}

.manual-hint {
  color: var(--color-text-lighter);
}

.rendered-list {
  list-style: none;
  margin: 0;
  padding: 0;
  display: flex;
  flex-direction: column;
  gap: 0.4rem;
}

.rendered-item {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 0.75rem;
  padding: 0.6rem 0.85rem;
  border: 1px solid var(--color-border);
  border-radius: var(--border-radius, 8px);
  background: var(--color-main-background, var(--color-background-darker));
}

.rendered-item__main {
  display: flex;
  flex-direction: column;
  min-width: 0;
}

.rendered-item__name {
  font-weight: 500;
  word-break: break-word;
}

.rendered-item__meta {
  font-size: 0.82rem;
  color: var(--color-text-lighter);
}

.rendered-item__action {
  flex: 0 0 auto;
  color: var(--color-primary-element);
  font-weight: 500;
  text-decoration: none;
}

.rendered-item__action:hover {
  text-decoration: underline;
}

@media (max-width: 640px) {
  .settings-actions {
    flex-direction: column;
    align-items: flex-start;
  }
  .settings-actions .last-run {
    margin-left: 0;
  }

  .journey-grid {
    grid-template-columns: 1fr;
  }

  .journey-filters {
    grid-template-columns: 1fr 1fr;
  }

  .journey-filters .manual-field--grow,
  .journey-filters .filter-reset {
    grid-column: 1 / -1;
  }

  .manual-buttons {
    flex-direction: column;
  }

  .manual-buttons .render-btn {
    flex: 1 1 auto;
    width: 100%;
  }

  .rendered-item {
    flex-direction: column;
    align-items: flex-start;
    gap: 0.4rem;
  }

  .rendered-item__action {
    align-self: flex-start;
  }
}
</style>
