<?php
// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
//
// The standalone public chat page a website frames (delivery-public-web-chat).
// One self-contained bundle: no shared chunks, nothing that needs a sign-in.
// The channel's disclosure and endpoint arrive as initial state.

use OCP\Util;

Util::addScript('hermiq', 'hermiq-public-chat-window');
Util::addTranslations('hermiq');
?>
<main id="hermiq-public-chat" class="hermiq-public-chat"></main>
