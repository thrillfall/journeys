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
									:checked="boostFaces"
									type="switch"
									@update:checked="value => onToggleSetting('boostFaces', value)">
									{{ t('journeys', 'Prefer photos with people when building videos') }}
								</NcCheckboxRadioSwitch>
						</div>
						<div class="orientation-select">
							<label :for="'videoOrientation'">{{ t('journeys', 'Video orientation') }}</label>
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

			<div v-if="clusters.length" class="cluster-summary">
				<div class="summary-header">
					<h3>{{ t('journeys', 'Clusters created') }}</h3>
				</div>
				<div class="table-responsive">
					<table class="nc-table nc-table--hover nc-table--compact">
						<thead>
							<tr>
								<th>{{ t('journeys', 'ID') }}</th>
								<th>{{ t('journeys', 'Name') }}</th>
								<th>{{ t('journeys', 'Actions') }}</th>
							</tr>
						</thead>
						<tbody>
							<tr v-for="cluster in clusters" :key="cluster.id">
								<td>{{ cluster.id }}</td>
								<td>{{ cluster.name }}</td>
								<td class="actions-cell">
									<NcButton size="small"
											@click="renderCluster(cluster)"
											:disabled="isProcessing || renderingClusterId === cluster.id">
										{{ renderingClusterId === cluster.id ? t('journeys', 'Rendering...') : t('journeys', 'Render video') }}
									</NcButton>
									<NcButton size="small" :disabled="isProcessing || renderingLandscapeId === cluster.id"
											@click="renderClusterLandscape(cluster)" class="secondary">
										{{ renderingLandscapeId === cluster.id ? t('journeys', 'Rendering...') : t('journeys', 'Render landscape') }}
									</NcButton>
								</td>
							</tr>
						</tbody>
					</table>
				</div>
			</div>

			<div v-if="manualAlbums.length" class="cluster-summary">
				<div class="summary-header">
					<h3>{{ t('journeys', 'Manual Photos albums') }}</h3>
				</div>
				<div class="table-responsive">
					<table class="nc-table nc-table--hover nc-table--compact">
						<thead>
							<tr>
								<th>{{ t('journeys', 'ID') }}</th>
								<th>{{ t('journeys', 'Name') }}</th>
								<th>{{ t('journeys', 'Type') }}</th>
								<th>{{ t('journeys', 'Actions') }}</th>
							</tr>
						</thead>
						<tbody>
							<tr v-for="album in manualAlbums" :key="album.id">
								<td>{{ album.id }}</td>
								<td>{{ album.name || t('journeys', 'Untitled album') }}</td>
								<td>{{ formatAlbumType(album) }}</td>
								<td>
									<NcButton size="small" @click="useAlbumId(album.id)">
										{{ t('journeys', 'Use ID') }}
									</NcButton>
								</td>
							</tr>
						</tbody>
					</table>
				</div>
			</div>

			<div class="manual-album-render">
				<div class="summary-header">
					<h3>{{ t('journeys', 'Render manual albums') }}</h3>
				</div>
				<div class="manual-album-row">
					<label :for="'manualAlbumId'" class="manual-label">{{ t('journeys', 'Photos album ID') }}</label>
					<input id="manualAlbumId" type="number" min="1" v-model.number="manualAlbumId" />
				</div>
				<div class="manual-album-buttons">
					<NcButton
							@click="renderManualAlbum('portrait')"
							:disabled="isProcessing || manualRenderBusy || !isManualAlbumInputValid">
							{{ manualRenderBusy ? t('journeys', 'Rendering...') : t('journeys', 'Render video') }}
					</NcButton>
					<NcButton
							@click="renderManualAlbum('landscape')"
							:disabled="isProcessing || manualLandscapeBusy || !isManualAlbumInputValid"
							class="secondary">
							{{ manualLandscapeBusy ? t('journeys', 'Rendering...') : t('journeys', 'Render landscape') }}
					</NcButton>
				</div>
				<small class="manual-hint">
					{{ t('journeys', 'Use the album ID from the Photos app (hover an album to see its numeric ID in the URL).') }}
				</small>
			</div>
		</NcSettingsSection>
	</div>
</template>

<script>
import { NcSettingsSection, NcCheckboxRadioSwitch, NcTextField, NcButton, NcNoteCard } from '@nextcloud/vue'
import { generateUrl } from '@nextcloud/router'
import axios from '@nextcloud/axios'
import { showError, showSuccess } from '@nextcloud/dialogs'

export default {
	name: 'PersonalSettingsPage',
	components: { NcSettingsSection, NcCheckboxRadioSwitch, NcTextField, NcButton, NcNoteCard },
	data() {
		return {
			isProcessing: false,
			savingInline: false,
			lastRun: null,
			error: null,
			clusters: [],
			renderingClusterId: null,
			renderingLandscapeId: null,
			minClusterSize: 3, // default
			maxTimeGap: 86400, // default (24h)
			maxDistanceKm: 100.0, // default
			nearTimeGap: 21600, // default (6h)
			nearDistanceKm: 3.0, // default
			awayTimeGap: 129600, // default (36h)
			awayDistanceKm: 50.0, // default
			homeLat: null,
			homeLon: null,
			homeRadiusKm: 50,
			homeName: null,
			includeGroupFolders: false,
			includeSharedImages: false,
			autoGenerateVideos: false,
			includeMotionFromGCam: true,
			showVideoTitle: true,
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
				this.homeLat = settingsResp.data.homeLat
				this.homeLon = settingsResp.data.homeLon
				this.homeRadiusKm = settingsResp.data.homeRadiusKm
				this.homeName = settingsResp.data.homeName || null
				this.autoGenerateVideos = !!settingsResp.data.autoGenerateVideos
				this.includeMotionFromGCam = !!settingsResp.data.includeMotionFromGCam
				this.showVideoTitle = settingsResp.data.showVideoTitle !== undefined ? !!settingsResp.data.showVideoTitle : true
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
	},
	methods: {
		isPositiveAlbumId(value) {
			return typeof value === 'number' && Number.isFinite(value) && value > 0
		},
		useAlbumId(id) {
			this.manualAlbumId = id
		},
		formatAlbumType(album) {
			return album?.isCluster
				? this.t('journeys', 'Journey cluster')
				: this.t('journeys', 'Manual album')
		},
		async renderManualAlbum(orientation = 'portrait') {
			const albumId = this.manualAlbumId
			if (!this.isPositiveAlbumId(albumId)) {
				showError(this.t('journeys', 'Enter a valid album ID.'))
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
				const resp = await axios.post(url, {
					albumId,
				})
				if (resp.data && resp.data.success) {
					const name = resp.data.clusterName || `#${albumId}`
					const count = resp.data.imageCount || 0
					showSuccess(this.t('journeys', 'Video rendering started for {name} ({count} images)', {
						name,
						count,
					}))
				} else if (resp.data && resp.data.error) {
					showError(resp.data.error)
				} else {
					showError(this.t('journeys', 'Failed to render video.'))
				}
			} catch (e) {
				const message = e?.response?.data?.error || e?.message || this.t('journeys', 'Failed to render video.')
				showError(message)
			} finally {
				this[busyField] = false
			}
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
				homeLat: this.homeLat,
				homeLon: this.homeLon,
				homeRadiusKm: this.homeRadiusKm,
				autoGenerateVideos: this.autoGenerateVideos,
				includeMotionFromGCam: this.includeMotionFromGCam,
				showVideoTitle: this.showVideoTitle,
				boostFaces: this.boostFaces,
				videoOrientation: this.videoOrientation,
				nearTimeGap: this.nearTimeGap,
				nearDistanceKm: this.nearDistanceKm,
				awayTimeGap: this.awayTimeGap,
				awayDistanceKm: this.awayDistanceKm,
			}
		},
		persistSettingSilently() {
			return this.persistSettingsRequest({ silent: true, inline: true }).catch(() => {
				showError(this.t('journeys', 'Failed to save setting.'))
			})
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
		},
		async renderCluster(cluster) {
			if (!cluster || !cluster.id) {
				return
			}
			this.error = null
			this.renderingClusterId = cluster.id
			try {
				const resp = await axios.post(generateUrl('/apps/journeys/personal_settings/render_cluster_video'), {
					albumId: cluster.id,
				})
				if (resp.data && resp.data.success) {
					const path = resp.data.path
					const imageCount = resp.data.imageCount
					const clusterName = resp.data.clusterName || cluster.name
					showSuccess(this.t('journeys', 'Video rendering started for {name} ({count} images)', {
						name: clusterName,
						count: imageCount,
					}))
				} else if (resp.data && resp.data.error) {
					showError(resp.data.error)
				} else {
					showError(this.t('journeys', 'Failed to render video.'))
				}
			} catch (e) {
				const message = e?.response?.data?.error || e?.message || this.t('journeys', 'Failed to render video.')
				showError(message)
			} finally {
				this.renderingClusterId = null
			}
		},
		async renderClusterLandscape(cluster) {
			if (!cluster || !cluster.id) {
				return
			}
			this.error = null
			this.renderingLandscapeId = cluster.id
			try {
				const resp = await axios.post(generateUrl('/apps/journeys/personal_settings/render_cluster_video_landscape'), {
					albumId: cluster.id,
				})
				if (resp.data && resp.data.success) {
					const path = resp.data.path
					const imageCount = resp.data.imageCount
					const clusterName = resp.data.clusterName || cluster.name
					showSuccess(this.t('journeys', 'Video rendering started for {name} ({count} images)', {
						name: clusterName,
						count: imageCount,
					}))
				} else if (resp.data && resp.data.error) {
					showError(resp.data.error)
				} else {
					showError(this.t('journeys', 'Failed to render video.'))
				}
			} catch (e) {
				const message = e?.response?.data?.error || e?.message || this.t('journeys', 'Failed to render video.')
				showError(message)
			} finally {
				this.renderingLandscapeId = null
			}
		},
		formatDateRange(range) {
			if (!range || (!range.start && !range.end)) {
				return this.t('journeys', 'Unknown')
			}
			const start = range.start ? range.start : '—'
 			const end = range.end ? range.end : null
			return end ? `${start} → ${end}` : start
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

.cluster-summary {
  margin: 1.5rem 0;
  background: var(--color-main-background, var(--color-background-darker));
  border: 1px solid var(--color-border);
  border-radius: var(--border-radius, 8px);
  padding: 1rem;
}

.summary-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-bottom: 0.75rem;
}

.summary-header h3 {
  margin: 0;
}

.table-responsive {
  overflow-x: auto;
}

.actions-cell {
  display: flex;
  gap: 0.5rem;
  flex-wrap: wrap;
}

.manual-album-render {
  margin-bottom: 2rem;
  background: var(--color-main-background, var(--color-background-darker));
  border: 1px solid var(--color-border);
  border-radius: var(--border-radius, 8px);
  padding: 1rem;
}

.manual-album-row {
  display: flex;
  align-items: center;
  gap: 1rem;
  margin-bottom: 0.75rem;
}

.manual-label {
  font-weight: 600;
}

.manual-album-row input {
  width: 180px;
  border-radius: 6px;
  border: 1px solid var(--color-border);
  padding: 0.4rem 0.6rem;
}

.manual-album-buttons {
  display: flex;
  gap: 0.5rem;
  flex-wrap: wrap;
  margin-bottom: 0.5rem;
}

.manual-hint {
  color: var(--color-text-lighter);
}

@media (max-width: 640px) {
  .settings-actions {
    flex-direction: column;
    align-items: flex-start;
  }
  .settings-actions .last-run {
    margin-left: 0;
  }
}
</style>
