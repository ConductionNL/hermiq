// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * The public chat embed script (delivery-public-web-chat), loaded by a website
 * with one tag:
 *
 *   <script src="https://cloud.example.nl/apps/hermiq/js/public-chat.js?channel=<token>" async></script>
 *
 * It adds a chat button to the page and opens hermiq's chat page for that
 * channel in a frame. It runs on someone else's website, so it brings its own
 * styles, defined as custom properties the website may override, and touches
 * nothing outside the two elements it adds.
 *
 * @spec openspec/changes/delivery-public-web-chat/specs/public-chat-channel/spec.md#requirement-a-visitor-chats-without-signing-in-and-gets-answers-only-from-public-content-req-pubchat-002
 */

import { frameUrl } from './chat.js'

const STYLE = `
.hermiq-chat-button{--hermiq-chat-accent:#00679e;--hermiq-chat-on-accent:#fff;position:fixed;right:16px;bottom:16px;z-index:2147483000;
border:0;border-radius:24px;padding:12px 18px;font:600 16px/1.2 system-ui,sans-serif;cursor:pointer;
background:var(--hermiq-chat-accent);color:var(--hermiq-chat-on-accent);box-shadow:0 2px 8px rgba(0,0,0,.3)}
.hermiq-chat-button:focus-visible{outline:3px solid var(--hermiq-chat-accent);outline-offset:3px}
.hermiq-chat-frame{position:fixed;right:16px;bottom:72px;z-index:2147483000;width:min(380px,calc(100vw - 32px));
height:min(560px,calc(100vh - 96px));border:1px solid #767676;border-radius:12px;background:#fff;box-shadow:0 4px 16px rgba(0,0,0,.3)}
.hermiq-chat-frame[hidden]{display:none}
`

/**
 * Add the button and the frame for the channel this script was loaded for.
 *
 * @param {Document} doc The page.
 * @param {string} scriptSrc This script's URL.
 * @return {HTMLButtonElement|null} The button, or null when the script names no channel.
 * @spec openspec/changes/delivery-public-web-chat/specs/public-chat-channel/spec.md#requirement-a-visitor-chats-without-signing-in-and-gets-answers-only-from-public-content-req-pubchat-002
 */
export function mountEmbed(doc, scriptSrc) {
	const src = frameUrl(scriptSrc)
	if (src === null) {
		return null
	}

	const style = doc.createElement('style')
	style.textContent = STYLE
	doc.head.appendChild(style)

	const frame = doc.createElement('iframe')
	frame.className = 'hermiq-chat-frame'
	frame.title = 'Chat'
	frame.hidden = true
	frame.setAttribute('loading', 'lazy')

	const button = doc.createElement('button')
	button.type = 'button'
	button.className = 'hermiq-chat-button'
	button.textContent = 'Chat'
	button.setAttribute('aria-expanded', 'false')
	button.addEventListener('click', () => {
		const opening = frame.hidden
		if (opening && !frame.src) {
			frame.src = src
		}
		frame.hidden = !opening
		button.setAttribute('aria-expanded', String(opening))
	})

	doc.body.appendChild(frame)
	doc.body.appendChild(button)
	return button
}

if (typeof document !== 'undefined' && document.currentScript) {
	const scriptSrc = document.currentScript.src
	const start = () => mountEmbed(document, scriptSrc)
	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', start)
	} else {
		start()
	}
}
