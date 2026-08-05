<?php
/**
 * AJAX entry point for the personal Nextcloud file browser.
 * Accepts GET/POST requests from _nc_browser.php JS.
 *
 * Actions (GET): list, download, shares_with_me
 * Actions (POST, CSRF-protected): mkdir, rename, delete, upload,
 *                                  share_link, share_user, share_delete
 *
 * All responses are JSON except `download` which streams the file.
 */
require_once __DIR__ . '/../../controllers/nc_browser.php';

nc_browser_handle();
