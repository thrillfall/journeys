<template>
	<div class="journeys_settings">
		<NcSettingsSection :title="t('journeys', 'Journeys Album Clustering')"
			:description="t('journeys', 'Configure and start clustering your photos into journeys (vacations/trips).')">
			<div class="form-group clustering-settings-grid">
				<div class="settings-field">
					<label :for="'minClusterSize'">{{ t('journeys', 'Minimum Cluster Size') }}</label>
					<input id="minClusterSize" type="number" min="1" v-model.number="minClusterSize" />
				</div>
				<div class="settings-field">
					<label :for="'maxTimeGap'">{{ t('journeys', 'Max Time Gap (seconds)') }}</label>
					<input id="maxTimeGap" type="number" min="1" v-model.number="maxTimeGap" />
				</div>
				<div class="settings-field">
					<label :for="'maxDistanceKm'">{{ t('journeys', 'Max Distance (km)') }}</label>
					<input id="maxDistanceKm" type="number" min="0.1" step="0.1" v-model.number="maxDistanceKm" />
				</div>
				<div class="settings-field">
					<label>
						<input type="checkbox" v-model="homeAwareEnabled" />
						{{ t('journeys', 'Enable home-aware clustering') }}
					</label>
				</div>
				<template v-if="homeAwareEnabled">
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
				</template>
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

				<!-- Cluster summary table -->
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
			homeAwareEnabled: false,
			homeLat: null,
			homeLon: null,
			homeRadiusKm: 50,
		}
	},
	async mounted() {
		try {
			const settingsResp = await axios.get(generateUrl('/apps/journeys/personal_settings/get_clustering_settings'))
			if (settingsResp.data) {
				this.minClusterSize = settingsResp.data.minClusterSize
				this.maxTimeGap = settingsResp.data.maxTimeGap
				this.maxDistanceKm = settingsResp.data.maxDistanceKm
				this.homeAwareEnabled = !!settingsResp.data.homeAwareEnabled
				this.homeLat = settingsResp.data.homeLat
				this.homeLon = settingsResp.data.homeLon
				this.homeRadiusKm = settingsResp.data.homeRadiusKm
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
		async saveSettings() {
			this.isProcessing = true
			try {
				await axios.post(generateUrl('/apps/journeys/personal_settings/save_clustering_settings'), {
					minClusterSize: this.minClusterSize,
					maxTimeGap: this.maxTimeGap,
					maxDistanceKm: this.maxDistanceKm,
					homeAwareEnabled: this.homeAwareEnabled,
					homeLat: this.homeLat,
					homeLon: this.homeLon,
					homeRadiusKm: this.homeRadiusKm,
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
				homeAwareEnabled: this.homeAwareEnabled,
				homeLat: this.homeLat,
				homeLon: this.homeLon,
				homeRadiusKm: this.homeRadiusKm,
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
				if (resp.data && Array.isArray(resp.data.clusters)) {
					this.clusters = resp.data.clusters
				} else {
					this.clusters = []
				}
			} catch (e) {
				this.clusters = []
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
