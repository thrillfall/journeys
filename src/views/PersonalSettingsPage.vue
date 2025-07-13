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
