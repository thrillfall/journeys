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
						<table class="nc-table nc-table--hover nc-table--zebra nc-table--compact">
							<thead>
								<tr>
									<th style="text-align:left; min-width: 200px;">{{ t('journeys', 'Album Name') }}</th>
									<th style="text-align:right; min-width: 100px;">{{ t('journeys', 'Image Count') }}</th>
								</tr>
							</thead>
							<tbody>
								<tr v-for="(cluster, idx) in clusters" :key="idx">
									<td style="padding: 0.5em 1em;">{{ cluster.albumName }}</td>
									<td style="padding: 0.5em 1em; text-align: right;">{{ cluster.imageCount }}</td>
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
				this.clusters = resp.data.clusters || []
			} catch (e) {
				this.error = this.t('journeys', 'Failed to start clustering.')
				showError(this.error)
			} finally {
				this.isProcessing = false
			}
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
