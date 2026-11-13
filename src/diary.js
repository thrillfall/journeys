import { generateFilePath } from '@nextcloud/router'

import Vue from 'vue'
import '@nextcloud/dialogs/styles/toast.scss'
import App from './views/App.vue'

// eslint-disable-next-line
__webpack_public_path__ = generateFilePath(appName, '', 'js/')

Vue.mixin({ methods: { t, n } })

Vue.prototype.OC = window.OC
Vue.prototype.OCA = window.OCA

export default new Vue({
	el: '#content',
	render: h => h(App),
})
