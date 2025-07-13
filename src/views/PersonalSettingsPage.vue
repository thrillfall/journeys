<template>
	<div class="journeys_settings">
		<NcSettingsSection :title="t('journeys', 'Journeys Album Clustering')"
			:description="t('journeys', 'Configure and start clustering your photos into journeys (vacations/trips).')">
			<div class="form-group">
				<button @click="startClustering" :disabled="isProcessing">
					{{ isProcessing ? t('journeys', 'Clustering...') : t('journeys', 'Start Clustering') }}
				</button>
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
		}
	},
	async mounted() {
		try {
			const resp = await axios.get(generateUrl('/apps/journeys/personal_settings/last_run'))
			this.lastRun = resp.data.lastRun
		} catch (e) {
			// ignore if not available
		}
	},
	methods: {
		async startClustering() {
			this.isProcessing = true
			this.error = null
			try {
				const resp = await axios.post(generateUrl('/apps/journeys/personal_settings/start_clustering'))
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
.error {
	color: var(--color-error);
}
</style>
