import Vue from 'vue'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

const api = path => generateUrl('/apps/journeys/api' + path)

const POLL_MS = 30000

export const media = Vue.observable({
	journeys: [],
	videos: [],
	pending: [],
	albums: [],
	homeKnown: true,
	defaultOrientation: 'portrait',
	loaded: false,
	error: null,
})

let pollTimer = null

function schedulePoll() {
	clearTimeout(pollTimer)
	if (media.pending.length > 0) {
		pollTimer = setTimeout(() => refresh(), POLL_MS)
	}
}

export async function refresh() {
	try {
		const [j, v] = await Promise.all([axios.get(api('/journeys')), axios.get(api('/videos'))])
		media.journeys = j.data.journeys ?? []
		media.homeKnown = j.data.homeKnown !== false
		media.defaultOrientation = j.data.defaultOrientation === 'landscape' ? 'landscape' : 'portrait'
		media.videos = v.data.videos ?? []
		media.pending = v.data.pending ?? []
		media.error = null
	} catch (e) {
		media.error = e
	} finally {
		media.loaded = true
		schedulePoll()
	}
}

export async function loadAlbums() {
	const resp = await axios.get(api('/albums'))
	media.albums = resp.data.albums ?? []
}

export async function render(albumId, orientation) {
	await axios.post(api(`/albums/${albumId}/render`), { orientation })
	if (!media.pending.some(p => p.albumId === albumId && p.orientation === orientation)) {
		media.pending.push({ albumId, orientation, albumName: albumName(albumId) })
	}
	const journey = media.journeys.find(j => j.id === albumId)
	if (journey && !journey.pending.includes(orientation)) {
		journey.pending.push(orientation)
	}
	schedulePoll()
}

export async function rename(albumId, customName) {
	const resp = await axios.put(api(`/journeys/${albumId}/name`), { customName })
	const journey = media.journeys.find(j => j.id === albumId)
	if (journey) {
		journey.customName = resp.data.customName ?? null
	}
	return resp.data
}

export function displayName(journey) {
	return journey.customName || journey.name || t('journeys', 'Untitled journey')
}

function albumName(albumId) {
	const journey = media.journeys.find(j => j.id === albumId)
	if (journey) {
		return displayName(journey)
	}
	return media.albums.find(a => a.id === albumId)?.name ?? ''
}
