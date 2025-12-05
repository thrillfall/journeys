<template>
	<div class="journeys_settings">
		<NcSettingsSection :title="t('journeys', 'Journeys Album Clustering')"
			:description="t('journeys', 'Configure and start clustering your photos into journeys (vacations/trips).')">
			<div class="form-group">
				<div class="grid-two">
					<div class="settings-field">
						<label :for="'minClusterSize'">{{ t('journeys', 'Minimum Cluster Size') }}</label>
						<input id="minClusterSize" type="number" min="1" v-model.number="minClusterSize" />
					</div>
					<div class="settings-field">
						<label :for="'maxTimeGap'">{{ t('journeys', 'Max Time Gap (hours)') }}</label>
						<input id="maxTimeGap" type="number" min="0" step="0.1" v-model.number="maxTimeGap" />
					</div>
				</div>

				<div class="grid-two" style="margin-top: 0.75rem;">
					<div class="settings-field">
						<label :for="'maxDistanceKm'">{{ t('journeys', 'Max Distance (km)') }}</label>
						<input id="maxDistanceKm" type="number" min="0.1" step="0.1" v-model.number="maxDistanceKm" />
					</div>
					<div class="settings-field">
						<label>
							<input type="checkbox" v-model="includeGroupFolders" />
							{{ t('journeys', 'Include Group Folders') }}
						</label>
					</div>
				</div>
				<div class="settings-field">
					<label>
						<input type="checkbox" v-model="includeSharedImages" />
						{{ t('journeys', 'Include shared images') }}
					</label>
				</div>

				<div class="settings-group">
					<h4>{{ t('journeys', 'Near-home thresholds') }}</h4>
					<div class="grid-two">
						<div class="settings-field">
							<label :for="'nearTimeGap'">{{ t('journeys', 'Max Time Gap (hours)') }}</label>
							<input id="nearTimeGap" type="number" min="0" step="0.1" v-model.number="nearTimeGap" />
						</div>
						<div class="settings-field">
							<label :for="'nearDistanceKm'">{{ t('journeys', 'Max Distance (km)') }}</label>
							<input id="nearDistanceKm" type="number" min="0.1" step="0.1" v-model.number="nearDistanceKm" />
						</div>
					</div>
				</div>

				<div class="settings-group">
					<h4>{{ t('journeys', 'Away-from-home thresholds') }}</h4>
					<div class="grid-two">
						<div class="settings-field">
							<label :for="'awayTimeGap'">{{ t('journeys', 'Max Time Gap (hours)') }}</label>
							<input id="awayTimeGap" type="number" min="0" step="0.1" v-model.number="awayTimeGap" />
						</div>
						<div class="settings-field">
							<label :for="'awayDistanceKm'">{{ t('journeys', 'Max Distance (km)') }}</label>
							<input id="awayDistanceKm" type="number" min="0.1" step="0.1" v-model.number="awayDistanceKm" />
						</div>
					</div>
				</div>

				<div class="settings-group">
					<h4>{{ t('journeys', 'Video & home') }}</h4>
					<div class="grid-two">
						<div class="settings-field">
							<label>
								<input type="checkbox" v-model="autoGenerateVideos" />
								{{ t('journeys', 'Auto-generate videos for away clusters') }}
							</label>
						</div>
						<div class="settings-field">
							<label :for="'videoOrientation'">{{ t('journeys', 'Video orientation') }}</label>
							<select id="videoOrientation" v-model="videoOrientation">
								<option value="portrait">{{ t('journeys', 'Portrait') }}</option>
								<option value="landscape">{{ t('journeys', 'Landscape') }}</option>
							</select>
						</div>
					</div>
					<div class="settings-field" style="margin-top: 0.5rem;">
						<label>
							<input type="checkbox" v-model="includeMotionFromGCam" />
							{{ t('journeys', 'Include motion from GCam photos (Live)') }}
						</label>
					</div>
					<div class="settings-field" style="margin-top: 0.5rem;">
						<label>
							<input type="checkbox" v-model="showVideoTitle" />
							{{ t('journeys', 'Show cluster name title on videos') }}
						</label>
					</div>
					<div class="settings-field" style="margin-top: 0.5rem;">
						<label>
							<input type="checkbox" v-model="boostFaces" />
							{{ t('journeys', 'Prefer photos with people when building videos') }}
						</label>
					</div>
					<div class="grid-three" style="margin-top: 0.75rem;">
						<div class="settings-field">
							<label :for="'homeLat'">{{ t('journeys', 'Home latitude') }}</label>
							<input id="homeLat" type="number" step="0.000001" v-model.number="homeLat" />
						</div>
						<div class="settings-field">
							<label :for="'homeLon'">{{ t('journeys', 'Home longitude') }}</label>
							<input id="homeLon" type="number" step="0.000001" v-model.number="homeLon" />
						</div>
						<div class="settings-field">
							<label :for="'homeRadiusKm'">{{ t('journeys', 'Home radius (km)') }}</label>
							<input id="homeRadiusKm" type="number" min="1" step="1" v-model.number="homeRadiusKm" />
						</div>
					</div>
					<div class="settings-field" v-if="homeName" style="margin-top: 0.5rem;">
						<label>{{ t('journeys', 'Home location') }}</label>
						<div>{{ homeName }}</div>
					</div>
				</div>

				<div class="settings-buttons">
					<button @click="saveSettings" :disabled="isProcessing">
						{{ t('journeys', 'Save Settings') }}
					</button>
					<button @click="startClustering" :disabled="isProcessing" style="margin-left: 1em;">
						{{ isProcessing ? t('journeys', 'Clustering...') : t('journeys', 'Start Clustering') }}
					</button>
				</div>
				<span v-if="error" class="error">{{ error }}</span>
				<span v-if="lastRun">{{ t('journeys', 'Last run:') }} {{ lastRun }}</span>

				<div v-if="clusters.length" class="cluster-summary">
					<h3>{{ t('journeys', 'Clusters Created') }}</h3>
					<div class="table-responsive">
						<table class="nc-table nc-table--hover nc-table--compact">
							<thead>
								<tr>
									<th style="text-align:left; min-width: 60px;">{{ t('journeys', 'ID') }}</th>
									<th style="text-align:left; min-width: 240px;">{{ t('journeys', 'Name') }}</th>
									<th style="text-align:left; min-width: 160px;">{{ t('journeys', 'Actions') }}</th>
								</tr>
							</thead>
							<tbody>
								<tr v-for="cluster in clusters" :key="cluster.id">
									<td style="padding: 0.5em 1em;">{{ cluster.id }}</td>
									<td style="padding: 0.5em 1em;">{{ cluster.name }}</td>
									<td style="padding: 0.5em 1em;">
										<button
											@click="renderCluster(cluster)"
											:disabled="isProcessing || renderingClusterId === cluster.id"
										>
											{{ renderingClusterId === cluster.id ? t('journeys', 'Rendering...') : t('journeys', 'Render Video') }}
										</button>
										<button
											@click="renderClusterLandscape(cluster)"
											:disabled="isProcessing || renderingLandscapeId === cluster.id"
											style="margin-left: 0.5em;"
										>
											{{ renderingLandscapeId === cluster.id ? t('journeys', 'Rendering...') : t('journeys', 'Render Landscape') }}
										</button>
									</td>
								</tr>
							</tbody>
						</table>
					</div>
				</div>
				<div v-if="manualAlbums.length" class="cluster-summary">
					<h3>{{ t('journeys', 'Manual Photos albums') }}</h3>
					<div class="table-responsive">
						<table class="nc-table nc-table--hover nc-table--compact">
							<thead>
								<tr>
									<th style="text-align:left; min-width: 60px;">{{ t('journeys', 'ID') }}</th>
									<th style="text-align:left; min-width: 240px;">{{ t('journeys', 'Name') }}</th>
									<th style="text-align:left; min-width: 120px;">{{ t('journeys', 'Type') }}</th>
									<th style="text-align:left; min-width: 160px;">{{ t('journeys', 'Actions') }}</th>
								</tr>
							</thead>
							<tbody>
								<tr v-for="album in manualAlbums" :key="album.id">
									<td style="padding: 0.5em 1em;">{{ album.id }}</td>
									<td style="padding: 0.5em 1em;">{{ album.name || t('journeys', 'Untitled album') }}</td>
									<td style="padding: 0.5em 1em;">{{ formatAlbumType(album) }}</td>
									<td style="padding: 0.5em 1em;">
										<button @click="useAlbumId(album.id)">
											{{ t('journeys', 'Use ID') }}
										</button>
									</td>
								</tr>
							</tbody>
						</table>
					</div>
				</div>

				<div class="manual-album-render">
					<h3>{{ t('journeys', 'Render manual albums') }}</h3>
					<div class="manual-album-row">
						<label :for="'manualAlbumId'" class="manual-label">{{ t('journeys', 'Photos album ID') }}</label>
						<input id="manualAlbumId" type="number" min="1" v-model.number="manualAlbumId" />
					</div>
					<div class="manual-album-buttons">
						<button
							@click="renderManualAlbum('portrait')"
							:disabled="isProcessing || manualRenderBusy || !isManualAlbumInputValid"
						>
							{{ manualRenderBusy ? t('journeys', 'Rendering...') : t('journeys', 'Render Video') }}
						</button>
						<button
							@click="renderManualAlbum('landscape')"
							:disabled="isProcessing || manualLandscapeBusy || !isManualAlbumInputValid"
							style="margin-left: 0.5em;"
						>
							{{ manualLandscapeBusy ? t('journeys', 'Rendering...') : t('journeys', 'Render Landscape') }}
						</button>
					</div>
					<small class="manual-hint">
						{{ t('journeys', 'Use the album ID from the Photos app (hover an album to see its numeric ID in the URL).') }}
					</small>
				</div>

			</div>

		</NcSettingsSection>
	</div>
</template>

<script>
import { NcSettingsSection } from '@nextcloud/vue'
import { generateUrl } from '@nextcloud/router'
import axios from '@nextcloud/axios'
import { showError, showSuccess } from '@nextcloud/dialogs'

export default {
	name: 'PersonalSettingsPage',
	components: { NcSettingsSection },
	data() {
		return {
			isProcessing: false,
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
		async saveSettings() {
			this.isProcessing = true
			try {
				await axios.post(generateUrl('/apps/journeys/personal_settings/save_clustering_settings'), {
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
				})
				showSuccess(this.t('journeys', 'Settings saved.'))
			} catch (e) {
				showError(this.t('journeys', 'Failed to save settings.'))
			} finally {
				this.isProcessing = false
			}
		},
		async startClustering() {
			this.isProcessing = true
			this.error = null
			try {
				const resp = await axios.post(generateUrl('/apps/journeys/personal_settings/start_clustering'), {
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
				})
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
			if (!end || end === start) {
				return start
			}
			return `${start} – ${end}`
		},
	},
}
</script>

<style lang="scss" scoped>
.journeys_settings {
	max-width: 500px;
	margin: 2em auto;
}
.form-group {
	margin-bottom: 1em;
}
.clustering-settings-grid {
	display: flex;
	flex-wrap: wrap;
	gap: 2em 2em;
	align-items: flex-end;
}
.settings-field {
	display: flex;
	flex-direction: column;
	margin-bottom: 1em;
}
.settings-field label {
	margin-bottom: 0.5em;
	font-weight: 500;
}
.settings-field input {
	width: 120px;
	padding: 0.4em;
	border: 1px solid var(--color-border);
	border-radius: 4px;
	background: var(--color-background-input);
	color: var(--color-text);
}
.settings-buttons {
	display: flex;
	align-items: center;
	gap: 1em;
	margin-top: 0.5em;
}

.error {
	color: var(--color-error);
}
</style>
