import { generateUrl } from '@nextcloud/router'

export function parseDate(value) {
	if (!value) return null
	const date = new Date(String(value).replace(' ', 'T'))
	return isNaN(date.getTime()) ? null : date
}

export function dateRange(start, end) {
	const s = parseDate(start)
	const e = parseDate(end) || s
	if (!s) return ''
	const format = new Intl.DateTimeFormat(undefined, { day: 'numeric', month: 'short', year: 'numeric' })
	if (s.toDateString() === e.toDateString()) {
		return format.format(s)
	}
	return typeof format.formatRange === 'function'
		? format.formatRange(s, e)
		: `${format.format(s)} – ${format.format(e)}`
}

export function fileSize(bytes) {
	if (!bytes && bytes !== 0) return ''
	const units = ['B', 'KB', 'MB', 'GB']
	let value = Number(bytes)
	let i = 0
	while (value >= 1024 && i < units.length - 1) {
		value /= 1024
		i++
	}
	return `${value.toFixed(value >= 10 || i === 0 ? 0 : 1)} ${units[i]}`
}

export function previewUrl(fileId, size = 512) {
	return generateUrl('/core/preview?fileId={fileId}&x={size}&y={size}&a=1', { fileId, size })
}

export function filesUrl(fileId) {
	const params = new URLSearchParams({ dir: '/Documents/Journeys Movies', openfile: String(fileId) })
	return generateUrl('/apps/files/?' + params.toString())
}

export function orientationLabel(orientation) {
	return orientation === 'landscape' ? t('journeys', 'Landscape') : t('journeys', 'Portrait')
}

/**
 * Opens the video in the Nextcloud Viewer when the Viewer app is installed,
 * otherwise in Files.
 */
export function playVideo(video) {
	if (window.OCA?.Viewer?.open) {
		window.OCA.Viewer.open({ path: video.path })
		return
	}
	window.open(filesUrl(video.fileId), '_blank', 'noopener')
}
