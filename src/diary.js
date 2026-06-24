import { generateFilePath } from '@nextcloud/router'

import Vue from 'vue'
import DiaryApp from './views/DiaryApp.vue'

// eslint-disable-next-line
__webpack_public_path__ = generateFilePath(appName, '', 'js/')

Vue.mixin({ methods: { t, n } })

Vue.prototype.OC = window.OC
Vue.prototype.OCA = window.OCA

export default new Vue({
	el: '#journeys_diary',
	render: h => h(DiaryApp),
})
