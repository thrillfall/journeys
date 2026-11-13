<template>
	<div class="journeys-page">
		<header class="page-header">
			<div class="page-header__text">
				<h2>{{ t('journeys', 'Videos') }}</h2>
				<p>{{ t('journeys', 'Saved in Documents / Journeys Movies') }}</p>
			</div>
			<NcButton type="primary" @click="openAlbumDialog">
				<template #icon>
					<Plus :size="20" />
				</template>
				{{ t('journeys', 'Video from album …') }}
			</NcButton>
		</header>

		<section v-if="media.pending.length" class="pending" :aria-label="t('journeys', 'In progress')">
			<h3>{{ t('journeys', 'In progress') }}</h3>
			<ul>
				<li v-for="p in media.pending" :key="p.albumId + '-' + p.orientation">
					<NcLoadingIcon :size="20" />
					<span class="pending__name">{{ p.albumName || t('journeys', 'Album {id}', { id: p.albumId }) }}</span>
					<span class="pending__meta">{{ orientationLabel(p.orientation) }}</span>
				</li>
			</ul>
			<p class="pending__hint">
				{{ t('journeys', 'Videos are rendered in the background by the next cron run. This page updates by itself.') }}
			</p>
		</section>

		<NcLoadingIcon v-if="!media.loaded" :size="32" class="page-loading" />

		<NcEmptyContent v-else-if="!media.videos.length"
			:name="t('journeys', 'No videos yet')"
			:description="t('journeys', 'Create one from a trip or from any of your Photos albums.')">
			<template #icon>
				<VideoOutline />
			</template>
		</NcEmptyContent>

		<ul v-else class="card-grid card-grid--videos">
			<li v-for="video in media.videos" :key="video.fileId" class="video-tile">
				<div class="video-tile__thumb" :class="'video-tile__thumb--' + video.orientation">
					<img v-if="!brokenThumbs[video.fileId]"
						:src="previewUrl(video.fileId)"
						alt=""
						loading="lazy"
						@error="$set(brokenThumbs, video.fileId, true)">
					<button type="button"
						class="play-button video-tile__play"
						:aria-label="t('journeys', 'Play {name}', { name: title(video) })"
						@click="play(video)">
						<Play :size="24" />
					</button>
				</div>
				<div class="video-tile__body">
					<h3 :title="video.name">{{ title(video) }}</h3>
					<p class="video-tile__meta">
						{{ orientationLabel(video.orientation) }} · {{ fileSize(video.size) }} · {{ date(video.mtime) }}
					</p>
					<a class="video-tile__link" :href="filesUrl(video.fileId)">
						<FolderOpen :size="16" />
						{{ t('journeys', 'Show in Files') }}
					</a>
				</div>
			</li>
		</ul>

		<NcModal v-if="dialog.open" size="normal" @close="dialog.open = false">
			<form class="album-dialog" @submit.prevent="submitAlbum">
				<h3>{{ t('journeys', 'Video from a Photos album') }}</h3>
				<p class="album-dialog__hint">
					{{ t('journeys', 'For albums you made yourself in Photos. Trips have their own buttons.') }}
				</p>
				<NcLoadingIcon v-if="dialog.loading" :size="32" />
				<p v-else-if="!media.albums.length" class="album-dialog__hint">
					{{ t('journeys', 'You have no albums of your own in Photos yet.') }}
				</p>
				<fieldset v-else class="album-dialog__albums">
					<legend class="hidden-visually">{{ t('journeys', 'Album') }}</legend>
					<NcCheckboxRadioSwitch v-for="album in media.albums"
						:key="album.id"
						type="radio"
						name="journeys-album"
						:value="String(album.id)"
						:checked.sync="dialog.albumId">
						{{ album.name || t('journeys', 'Untitled album') }}
					</NcCheckboxRadioSwitch>
				</fieldset>
				<fieldset class="album-dialog__orientation">
					<legend>{{ t('journeys', 'Format') }}</legend>
					<NcCheckboxRadioSwitch type="radio"
						name="journeys-orientation"
						value="portrait"
						:checked.sync="dialog.orientation">
						{{ t('journeys', 'Portrait') }}
					</NcCheckboxRadioSwitch>
					<NcCheckboxRadioSwitch type="radio"
						name="journeys-orientation"
						value="landscape"
						:checked.sync="dialog.orientation">
						{{ t('journeys', 'Landscape') }}
					</NcCheckboxRadioSwitch>
				</fieldset>
				<div class="album-dialog__actions">
					<NcButton @click="dialog.open = false">
						{{ t('journeys', 'Cancel') }}
					</NcButton>
					<NcButton type="primary" native-type="submit" :disabled="!dialog.albumId || dialog.saving">
						{{ t('journeys', 'Create video') }}
					</NcButton>
				</div>
			</form>
		</NcModal>
	</div>
</template>

<script>
import { showError, showSuccess } from '@nextcloud/dialogs'
import NcButton from '@nextcloud/vue/dist/Components/NcButton.js'
import NcCheckboxRadioSwitch from '@nextcloud/vue/dist/Components/NcCheckboxRadioSwitch.js'
import NcEmptyContent from '@nextcloud/vue/dist/Components/NcEmptyContent.js'
import NcLoadingIcon from '@nextcloud/vue/dist/Components/NcLoadingIcon.js'
import NcModal from '@nextcloud/vue/dist/Components/NcModal.js'
import FolderOpen from 'vue-material-design-icons/FolderOpen.vue'
import Play from 'vue-material-design-icons/Play.vue'
import Plus from 'vue-material-design-icons/Plus.vue'
import VideoOutline from 'vue-material-design-icons/VideoOutline.vue'

import { media, loadAlbums, render } from '../store/media.js'
import { fileSize, filesUrl, orientationLabel, playVideo, previewUrl } from '../util/format.js'

export default {
	name: 'VideosView',
	components: {
		NcButton, NcCheckboxRadioSwitch, NcEmptyContent, NcLoadingIcon, NcModal,
		FolderOpen, Play, Plus, VideoOutline,
	},
	data() {
		return {
			media,
			brokenThumbs: {},
			dialog: { open: false, loading: false, saving: false, albumId: '', orientation: 'portrait' },
		}
	},
	methods: {
		fileSize,
		filesUrl,
		orientationLabel,
		previewUrl,
		play: playVideo,
		title(video) {
			return video.albumName || video.name.replace(/\.mp4$/i, '')
		},
		date(mtime) {
			return mtime ? new Date(mtime * 1000).toLocaleDateString() : ''
		},
		async openAlbumDialog() {
			this.dialog = { open: true, loading: true, saving: false, albumId: '', orientation: media.defaultOrientation }
			try {
				await loadAlbums()
			} catch (e) {
				showError(t('journeys', 'Could not load your albums.'))
			} finally {
				this.dialog.loading = false
			}
		},
		async submitAlbum() {
			const albumId = Number(this.dialog.albumId)
			if (!albumId) {
				return
			}
			this.dialog.saving = true
			try {
				await render(albumId, this.dialog.orientation)
				const album = media.albums.find(a => a.id === albumId)
				showSuccess(t('journeys', '“{name}” is queued. You will get a notification when the video is ready.', { name: album?.name || albumId }))
				this.dialog.open = false
			} catch (e) {
				showError(e?.response?.data?.error || t('journeys', 'Failed to queue video.'))
			} finally {
				this.dialog.saving = false
			}
		},
	},
}
</script>

<style scoped lang="scss">
@use '../styles/page';

.card-grid--videos {
	grid-template-columns: repeat(auto-fill, minmax(min(100%, 220px), 1fr));
}

.pending {
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large, 12px);
	background: var(--color-background-hover);
	padding: 12px 16px;

	h3 {
		margin: 0 0 6px;
		font-size: 1em;
		font-weight: 700;
	}

	ul {
		display: flex;
		flex-direction: column;
		gap: 6px;
	}

	li {
		display: flex;
		flex-wrap: wrap;
		align-items: center;
		gap: 4px 10px;
	}

	&__name {
		font-weight: 600;
		overflow-wrap: anywhere;
	}

	&__meta,
	&__hint {
		color: var(--color-text-maxcontrast);
	}

	&__hint {
		margin: 8px 0 0;
		font-size: 0.9em;
	}
}

.video-tile {
	display: flex;
	flex-direction: column;
	gap: 8px;
	min-width: 0;

	&__thumb {
		position: relative;
		aspect-ratio: 16 / 10;
		border-radius: var(--border-radius-large, 12px);
		background: var(--color-background-dark);
		overflow: hidden;
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

	&__play {
		position: absolute;
		left: 50%;
		top: 50%;
		transform: translate(-50%, -50%);
	}

	&__body {
		min-width: 0;

		h3 {
			margin: 0;
			font-size: 1em;
			font-weight: 700;
			overflow: hidden;
			text-overflow: ellipsis;
			white-space: nowrap;
		}
	}

	&__meta {
		margin: 2px 0 4px;
		color: var(--color-text-maxcontrast);
		font-size: 0.9em;
	}

	&__link {
		display: inline-flex;
		align-items: center;
		gap: 4px;
		min-height: 32px;
		color: var(--color-main-text);
		font-size: 0.9em;
		text-decoration: underline;
	}
}

.album-dialog {
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
	}

	fieldset {
		border: 0;
		margin: 0;
		padding: 0;
	}

	legend {
		font-weight: 600;
		margin-bottom: 4px;
	}

	&__albums {
		max-height: 40vh;
		overflow-y: auto;
	}

	&__orientation {
		display: flex;
		flex-wrap: wrap;
		gap: 0 16px;
	}

	&__actions {
		display: flex;
		flex-wrap: wrap;
		justify-content: flex-end;
		gap: 8px;
	}
}

@media (max-width: 600px) {
	.card-grid--videos {
		grid-template-columns: repeat(2, minmax(0, 1fr));
	}

	.video-tile__link {
		font-size: 0.85em;
	}
}
</style>
