<template>
	<div class="journeys_settings">
		<NcSettingsSection :title="t('journeys', 'Journeys Album Clustering')"
			:description="t('journeys', 'Configure and start clustering your photos into journeys (vacations/trips).')">
			<div class="app-note">
				<p>{{ t('journeys', 'Your trips and videos are now in the Journeys app. Only the settings stay here.') }}</p>
				<a :href="appUrl" class="app-note__link">{{ t('journeys', 'Open Journeys') }}</a>
			</div>
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
									:checked="autoCluster"
									type="switch"
									@update:checked="answerAutoCluster">
									{{ t('journeys', 'Create journeys automatically every night') }}
								</NcCheckboxRadioSwitch>
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
									{{ t('journeys', 'Auto-generate videos for trips (away from home, at least one night)') }}
								</NcCheckboxRadioSwitch>
							<NcCheckboxRadioSwitch
									:checked="includeMotionFromGCam"
									type="switch"
									@update:checked="value => onToggleSetting('includeMotionFromGCam', value)">
									{{ t('journeys', 'Include motion from GCam photos (Live)') }}
								</NcCheckboxRadioSwitch>
							<NcCheckboxRadioSwitch
									:checked="includeVideoClips"
									type="switch"
									@update:checked="value => onToggleSetting('includeVideoClips', value)">
									{{ t('journeys', 'Include short clips (with sound) from videos') }}
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
			autoCluster: true,
			lastRun: null,
			error: null,
			appUrl: generateUrl('/apps/journeys/') + '#/trips',
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
			includeVideoClips: true,
			videoOrientation: 'portrait',
		}
	},
	async mounted() {
		try {
			const settingsResp = await axios.get(generateUrl('/apps/journeys/personal_settings/get_clustering_settings'))
			if (settingsResp.data) {
				this.minClusterSize = settingsResp.data.minClusterSize
				this.maxTimeGap = settingsResp.data.maxTimeGap
				this.maxDistanceKm = settingsResp.data.maxDistanceKm
				this.autoCluster = settingsResp.data.autoCluster !== false
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
				this.includeVideoClips = settingsResp.data.includeVideoClips !== undefined ? !!settingsResp.data.includeVideoClips : true
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
	},
	methods: {
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
				includeVideoClips: this.includeVideoClips,
				videoOrientation: this.videoOrientation,
				nearTimeGap: this.nearTimeGap,
				nearDistanceKm: this.nearDistanceKm,
				awayTimeGap: this.awayTimeGap,
				awayDistanceKm: this.awayDistanceKm,
			}
		},
		async answerAutoCluster(enabled) {
			const previous = this.autoCluster
			this.autoCluster = enabled
			try {
				await axios.post(generateUrl('/apps/journeys/personal_settings/auto_cluster'), { enabled })
			} catch (e) {
				this.autoCluster = previous
				showError(this.t('journeys', 'Failed to save settings.'))
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
  max-width: 960px;
  margin: 2em auto;
}

.settings-panels {
  margin-top: 1rem;
  display: grid;
  gap: 1.5rem;
  grid-template-columns: repeat(auto-fit, minmax(min(260px, 100%), 1fr));
}

.settings-card {
  background: var(--color-main-background, var(--color-background-darker));
  border: 1px solid var(--color-border);
  border-radius: var(--border-radius, 8px);
  box-shadow: var(--box-shadow, 0 2px 6px rgba(0, 0, 0, 0.05));
  padding: 1rem 1.25rem;
  display: flex;
  flex-direction: column;
  min-width: 0;
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
  grid-template-columns: repeat(auto-fit, minmax(min(180px, 100%), 1fr));
  gap: 0.75rem 1rem;
}

.home-grid {
  grid-template-columns: repeat(auto-fit, minmax(min(160px, 100%), 1fr));
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

.app-note {
  margin: 1rem 0;
  padding: 0.75rem 1rem;
  border-left: 4px solid var(--color-primary-element);
  border-radius: var(--border-radius, 8px);
  background: var(--color-primary-element-light);
  color: var(--color-primary-element-light-text);
}

.app-note p {
  margin: 0;
}

.app-note__link {
  display: inline-block;
  margin-top: 0.4rem;
  font-weight: 600;
  text-decoration: underline;
}

@media (max-width: 640px) {
  .journeys_settings {
    margin: 1em auto;
  }

  .settings-card {
    padding: 0.85rem;
  }

  .settings-actions {
    flex-direction: column;
    align-items: flex-start;
  }
  .settings-actions .last-run {
    margin-left: 0;
  }
}
</style>
