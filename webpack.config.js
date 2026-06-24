const path = require('path')
const webpackConfig = require('@nextcloud/webpack-vue-config')

webpackConfig.entry = {
  personalSettings: path.join(__dirname, 'src', 'personalSettings.js'),
  diary: path.join(__dirname, 'src', 'diary.js'),
}

module.exports = webpackConfig
