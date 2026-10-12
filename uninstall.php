<?php

/**
 * Runs when the plugin is deleted on the Plugins screen: removes the settings, the keys and the
 * tokens. The imported reviews are content, they stay.
 */

if (! defined('WP_UNINSTALL_PLUGIN')) exit;

// Best effort: the stored token is removed even if the revoke call fails.
$hri_token = get_option('hri_gbp_token', array());
if (is_array($hri_token) && ! empty($hri_token['refresh_token'])) {
    wp_remote_post('https://oauth2.googleapis.com/revoke', array(
        'body'    => array('token' => $hri_token['refresh_token']),
        'timeout' => 10,
    ));
}

// The option keys of HRI_Review_Importer (the plugin itself is not loaded here)
$hri_options = array(
    'facebook_graph_api_key',
    'facebook_page_id',
    'google_places_api_key',
    'google_place_id',
    'hri_import_cron_time',
    'imported_languages',
    'hri_min_review_rating',
    'hri_import_last_run',
    'hri_skip_empty_comments',
    'hri_google_rating',
    'hri_user_ratings_total',
    'hri_facebook_rating',
    'hri_facebook_ratings_total',
    'hri_last_error',
    'hri_import_order',
    'hri_google_api_version',
    'hri_gbp_client_id',
    'hri_gbp_client_secret',
    'hri_gbp_location',
    'hri_gbp_token',
    'hri_gbp_locations',
    'hri_gbp_synced',
    'hri_update_prereleases',
    'hri_import.lock', // lock of a running import
);
foreach ($hri_options as $hri_option) {
    delete_option($hri_option);
}

delete_transient('hri_update_release_stable');
delete_transient('hri_update_release_pre');
