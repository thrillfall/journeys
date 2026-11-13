<template>
	<NcContent app-name="journeys">
		<NcAppNavigation :aria-label="t('journeys', 'Journeys')">
			<template #list>
				<NcAppNavigationItem :name="t('journeys', 'Travel diaries')"
					:class="{ 'journeys-nav--active': view === 'diary' }"
					:aria-current="view === 'diary' ? 'page' : null"
					href="#/diary">
					<template #icon>
						<BookOpenVariant :size="20" />
					</template>
				</NcAppNavigationItem>
				<NcAppNavigationItem :name="t('journeys', 'Trips')"
					:class="{ 'journeys-nav--active': view === 'trips' }"
					:aria-current="view === 'trips' ? 'page' : null"
					href="#/trips">
					<template #icon>
						<MapMarkerPath :size="20" />
					</template>
					<template v-if="tripCount" #counter>
						<NcCounterBubble :count="tripCount" />
					</template>
				</NcAppNavigationItem>
				<NcAppNavigationItem :name="t('journeys', 'Videos')"
					:class="{ 'journeys-nav--active': view === 'videos' }"
					:aria-current="view === 'videos' ? 'page' : null"
					:loading="media.pending.length > 0"
					href="#/videos">
					<template #icon>
						<VideoOutline :size="20" />
					</template>
					<template v-if="media.videos.length" #counter>
						<NcCounterBubble :count="media.videos.length" />
					</template>
				</NcAppNavigationItem>
			</template>
			<template #footer>
				<ul class="journeys-nav-footer">
					<NcAppNavigationItem :name="t('journeys', 'Settings')" :href="settingsUrl">
						<template #icon>
							<CogOutline :size="20" />
						</template>
					</NcAppNavigationItem>
				</ul>
			</template>
		</NcAppNavigation>
		<NcAppContent>
			<DiaryApp v-if="view === 'diary'" />
			<TripsView v-else-if="view === 'trips'" />
			<VideosView v-else />
		</NcAppContent>
	</NcContent>
</template>

<script>
import { emit } from '@nextcloud/event-bus'
import { generateUrl } from '@nextcloud/router'
import NcContent from '@nextcloud/vue/dist/Components/NcContent.js'
import NcAppNavigation from '@nextcloud/vue/dist/Components/NcAppNavigation.js'
import NcAppNavigationItem from '@nextcloud/vue/dist/Components/NcAppNavigationItem.js'
import NcAppContent from '@nextcloud/vue/dist/Components/NcAppContent.js'
import NcCounterBubble from '@nextcloud/vue/dist/Components/NcCounterBubble.js'
import BookOpenVariant from 'vue-material-design-icons/BookOpenVariant.vue'
import MapMarkerPath from 'vue-material-design-icons/MapMarkerPath.vue'
import VideoOutline from 'vue-material-design-icons/VideoOutline.vue'
import CogOutline from 'vue-material-design-icons/CogOutline.vue'

import DiaryApp from './DiaryApp.vue'
import TripsView from './TripsView.vue'
import VideosView from './VideosView.vue'
import { media, refresh } from '../store/media.js'

const VIEWS = ['diary', 'trips', 'videos']

function viewFromHash() {
	const name = window.location.hash.replace(/^#\/?/, '')
	return VIEWS.includes(name) ? name : 'diary'
}

export default {
	name: 'App',
	components: {
		NcContent, NcAppNavigation, NcAppNavigationItem, NcAppContent, NcCounterBubble,
		BookOpenVariant, MapMarkerPath, VideoOutline, CogOutline,
		DiaryApp, TripsView, VideosView,
	},
	data() {
		return {
			media,
			view: viewFromHash(),
			settingsUrl: generateUrl('/settings/user/journeys'),
		}
	},
	computed: {
		tripCount() {
			return media.journeys.filter(j => j.kind === 'trip').length
		},
	},
	mounted() {
		window.addEventListener('hashchange', this.onHashChange)
		refresh()
	},
	beforeDestroy() {
		window.removeEventListener('hashchange', this.onHashChange)
	},
	methods: {
		onHashChange() {
			this.view = viewFromHash()
			// Same breakpoint as NcAppNavigation: below it the navigation is a drawer over the content.
			if (document.documentElement.clientWidth < 1024) {
				emit('toggle-navigation', { open: false })
			}
		},
	},
}
</script>

<style scoped>
.journeys-nav-footer {
	padding: 4px 0;
}

/* The bundled NcAppNavigationItem build only marks router links as active. */
.journeys-nav--active :deep(.app-navigation-entry) {
	background-color: var(--color-primary-element-light);
}

.journeys-nav--active :deep(.app-navigation-entry-link) {
	font-weight: bold;
}
</style>
