<?php

/**
 * Plugin Name: Headless Reviews Importer
 * Description: Settings page (Google keys, import frequency, imported languages, minimum rating), "Import now" button, last run display + "reviews" custom post type with meta fields and list columns.
 * Version:     1.5.1
 * Author:      Molnár Dávid
 * License:     GPLv2 or later
 * Update URI:  https://github.com/MDylan/headless-reviews-importer
 * Text Domain: hri-reviews-importer
 * Domain Path: /languages
 */

if (! defined('ABSPATH')) exit;

require_once __DIR__ . '/includes/secrets.php';

class HRI_Review_Importer
{
    // Option keys
    const OPTION_GOOGLE_PLACES_API_KEY  = 'google_places_api_key';
    const OPTION_GOOGLE_PLACE_ID        = 'google_place_id';
    const OPTION_CRON_TIME              = 'hri_import_cron_time';
    const OPTION_IMPORTED_LANGUAGES     = 'imported_languages';
    const OPTION_MIN_REVIEW_RATING      = 'hri_min_review_rating';
    const OPTION_LAST_RUN               = 'hri_import_last_run';
    const OPTION_SKIP_EMPTY_REVIEWS = 'hri_skip_empty_comments';
    const OPTION_GOOGLE_RATING =  'hri_google_rating';
    const OPTION_GOOGLE_RATINGS_TOTAL = 'hri_user_ratings_total';
    const OPTION_LAST_ERROR = 'hri_last_error';
    const OPTION_IMPORT_ORDER = 'hri_import_order';
    const OPTION_GOOGLE_API_VERSION = 'hri_google_api_version';
    const OPTION_GBP_CLIENT_ID = 'hri_gbp_client_id';
    const OPTION_GBP_CLIENT_SECRET = 'hri_gbp_client_secret';
    const OPTION_GBP_LOCATION = 'hri_gbp_location';
    const OPTION_GBP_TOKEN = 'hri_gbp_token';
    const OPTION_GBP_LOCATIONS = 'hri_gbp_locations';
    const OPTION_GBP_SYNCED = 'hri_gbp_synced';
    const OPTION_UPDATE_PRERELEASES = 'hri_update_prereleases';
    const OPTION_DATA_VERSION = 'hri_data_version';

    // Updates come from the releases of this public GitHub repository (see the "Update URI" header)
    const GITHUB_REPO = 'MDylan/headless-reviews-importer';

    // Business Profile API: max. review pages (50 reviews each) fetched per run
    const GBP_MAX_PAGES = 20;
    // Bump to make every site fetch all pages again (e.g. after changing how comments are parsed)
    const GBP_SYNC_VERSION = 1;

    // Bump when options left by an older version have to be removed or rewritten (see maybe_upgrade())
    const DATA_VERSION = 2;


    // Cron hook
    const CRON_HOOK = 'hri_import_cron_event';

    // Hook suffix of the settings page (set in add_menu())
    private $settings_hook = '';

    // Business Profile access token: 'token', 'expires_at'. Kept in memory for the current request only,
    // never stored; an import asks for a new one with the refresh token.
    private $gbp_access = null;

    public function __construct()
    {
        // I18n
        add_action('plugins_loaded', array($this, 'load_textdomain'));

        // Admin UI
        add_action('admin_menu', array($this, 'add_menu'));
        add_action('admin_init', array($this, 'maybe_upgrade'));
        add_action('admin_init', array($this, 'register_settings'));
        add_action('admin_notices', array($this, 'render_secret_notice'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_assets'));
        add_filter('plugin_action_links_' . plugin_basename(__FILE__), array($this, 'add_settings_link'));
        add_filter('plugin_row_meta', array($this, 'add_github_link'), 10, 2);

        // Import now (admin-post)
        add_action('admin_post_hri_import_now', array($this, 'handle_import_now'));
        add_action('admin_post_hri_import_relevant', array($this, 'handle_import_now'));

        // Cron
        add_action(self::CRON_HOOK, array($this, 'handle_cron'));
        add_action('update_option_' . self::OPTION_CRON_TIME, array($this, 'reschedule_cron_on_option_change'), 10, 2);
        register_activation_hook(__FILE__, array(__CLASS__, 'activate'));
        register_deactivation_hook(__FILE__, array(__CLASS__, 'deactivate'));

        // Reviews CPT + meta
        add_action('init', array($this, 'register_reviews_cpt'));
        add_action('add_meta_boxes', array($this, 'add_review_metaboxes'));
        add_action('save_post_reviews', array($this, 'save_review_meta'), 10, 2);

        // Reviews admin list columns
        add_filter('manage_reviews_posts_columns', array($this, 'add_reviews_columns'));
        add_action('manage_reviews_posts_custom_column', array($this, 'render_reviews_custom_column'), 10, 2);
        add_filter('manage_edit-reviews_sortable_columns', array($this, 'make_reviews_columns_sortable'));
        add_action('pre_get_posts', array($this, 'reviews_orderby_rating'));

        // Bricks dynamic data tags (aggregated Google rating)
        add_filter('bricks/dynamic_tags_list', array($this, 'bricks_add_tags'));
        add_filter('bricks/dynamic_data/render_tag', array($this, 'bricks_render_tag'), 20, 3);
        add_filter('bricks/dynamic_data/render_content', array($this, 'bricks_render_content'), 20, 3);
        add_filter('bricks/frontend/render_data', array($this, 'bricks_render_content'), 20, 2);

        add_action('hri_run_import', array($this, 'hri_run_imports'));

        // Google Business Profile (OAuth)
        add_action('admin_post_hri_gbp_connect', array($this, 'handle_gbp_connect'));
        add_action('admin_post_hri_gbp_oauth_callback', array($this, 'handle_gbp_oauth_callback'));
        add_action('admin_post_hri_gbp_disconnect', array($this, 'handle_gbp_disconnect'));
        add_action('admin_post_hri_gbp_fetch_locations', array($this, 'handle_gbp_fetch_locations'));

        // Updates from GitHub releases (hook name = host of the "Update URI" header)
        add_filter('update_plugins_github.com', array($this, 'check_for_update'), 10, 3);
        add_filter('plugins_api', array($this, 'plugin_info'), 20, 3);
        add_filter('upgrader_source_selection', array($this, 'fix_update_folder'), 10, 4);
    }

    /**
     * Set autoload for a single option (works for existing + new options).
     *
     * @param string       $name
     * @param mixed        $default   Default to create with if missing.
     * @param bool  $autoload  true or false
     */
    private function hri_set_option_autoload($name, $default = '', $autoload = false)
    {
        $exists = get_option($name, '__hri_missing__');
        $autoload = $autoload ? true : false;

        if ('__hri_missing__' === $exists) {
            // create with desired autoload
            add_option($name, $default, '', $autoload);
            return;
        } else {
            update_option($name, '', $autoload);
            // re-save value with desired autoload
            // For existing options, $autoload can only be updated using update_option() if $value is also changed.
            update_option($name, $exists, $autoload);
        }
    }


    /**
     * Set options autoload
     * 
     * @param bool $forcefalse true/false If true, all option's autoload will be set off
     */
    private function hri_enforce_autoloads($forcefalse = false)
    {
        // Used on fronted so autoload=YES
        $this->hri_set_option_autoload(self::OPTION_GOOGLE_RATING, '5.0', $forcefalse ? false : true);
        $this->hri_set_option_autoload(self::OPTION_GOOGLE_RATINGS_TOTAL, '1', $forcefalse ? false : true);

        // Only on admin/import – autoload=NO
        $this->hri_set_option_autoload(self::OPTION_GOOGLE_PLACES_API_KEY,   '', false);
        $this->hri_set_option_autoload(self::OPTION_GOOGLE_PLACE_ID,   '', false);
        $this->hri_set_option_autoload(self::OPTION_CRON_TIME,   'hourly', false);
        $this->hri_set_option_autoload(self::OPTION_MIN_REVIEW_RATING,   '4', false);
        $this->hri_set_option_autoload(self::OPTION_IMPORTED_LANGUAGES,   $this->get_default_lang_short(), false);
        $this->hri_set_option_autoload(self::OPTION_LAST_RUN,   '', false);
        $this->hri_set_option_autoload(self::OPTION_SKIP_EMPTY_REVIEWS,   1, false);
        $this->hri_set_option_autoload(self::OPTION_IMPORT_ORDER,   'newest', false);
        $this->hri_set_option_autoload(self::OPTION_GOOGLE_API_VERSION,   'legacy', false);
        $this->hri_set_option_autoload(self::OPTION_GBP_CLIENT_ID,   '', false);
        $this->hri_set_option_autoload(self::OPTION_GBP_CLIENT_SECRET,   '', false);
        $this->hri_set_option_autoload(self::OPTION_GBP_LOCATION,   '', false);
        $this->hri_set_option_autoload(self::OPTION_UPDATE_PRERELEASES,   0, false);
        $this->hri_set_option_autoload(self::OPTION_LAST_ERROR,   array(), false);
    }

    /**
     * Cleans up what older versions left in the database.
     * 1: up to 1.4.1 the plugin had a Facebook import; Meta retired the Page ratings endpoint, so its
     *    Page ID and access token go.
     * 2: up to 1.5.0 the Google secrets were stored unencrypted; reading them rewrites them encrypted.
     */
    public function maybe_upgrade()
    {
        $version = (int) get_option(self::OPTION_DATA_VERSION, 0);
        if ($version >= self::DATA_VERSION) {
            return;
        }

        if ($version < 1) {
            foreach (array('facebook_graph_api_key', 'facebook_page_id', 'hri_facebook_rating', 'hri_facebook_ratings_total') as $option) {
                delete_option($option);
            }
        }
        if ($version < 2) {
            $this->get_secret(self::OPTION_GOOGLE_PLACES_API_KEY);
            $this->get_secret(self::OPTION_GBP_CLIENT_SECRET);
            $this->gbp_refresh_token();
        }

        update_option(self::OPTION_DATA_VERSION, self::DATA_VERSION, true);
    }

    /**
     * Decrypted value of a secret option (Places API key, OAuth client secret); '' if it cannot be
     * decrypted. A value stored unencrypted by an older version is rewritten encrypted.
     */
    private function get_secret($option)
    {
        $stored = hri_decrypt_secret(get_option($option, ''));
        if ($stored['legacy'] && hri_secret_key() !== '') {
            update_option($option, hri_encrypt_secret(trim($stored['value'])), false);
        }
        return trim($stored['value']);
    }

    /**
     * Decrypted refresh token of the Business Profile connection; '' if there is none or it cannot be
     * decrypted. Older versions stored it unencrypted, next to the access token: that form is rewritten.
     */
    private function gbp_refresh_token()
    {
        $token = get_option(self::OPTION_GBP_TOKEN, array());
        if (! is_array($token)) {
            return '';
        }

        $stored = hri_decrypt_secret($token['refresh_token'] ?? '');
        if (($stored['legacy'] || isset($token['access_token'])) && $stored['value'] !== '' && hri_secret_key() !== '') {
            update_option(self::OPTION_GBP_TOKEN, array('refresh_token' => hri_encrypt_secret($stored['value'])), false);
        }
        return $stored['value'];
    }

    /** Names of the stored secrets that cannot be decrypted (the key in wp-config.php changed, or the database comes from another site) */
    private function secret_failures()
    {
        $token    = get_option(self::OPTION_GBP_TOKEN, array());
        $failures = array();

        if (hri_decrypt_secret(get_option(self::OPTION_GOOGLE_PLACES_API_KEY, ''))['failed']) {
            $failures[self::OPTION_GOOGLE_PLACES_API_KEY] = __('Google Places API key', 'hri-reviews-importer');
        }
        if (hri_decrypt_secret(get_option(self::OPTION_GBP_CLIENT_SECRET, ''))['failed']) {
            $failures[self::OPTION_GBP_CLIENT_SECRET] = __('Business Profile OAuth client secret', 'hri-reviews-importer');
        }
        if (is_array($token) && hri_decrypt_secret($token['refresh_token'] ?? '')['failed']) {
            $failures[self::OPTION_GBP_TOKEN] = __('Google account connection', 'hri-reviews-importer');
        }

        return $failures;
    }

    /** On every admin screen: the imports stop without a word while a secret cannot be decrypted */
    public function render_secret_notice()
    {
        if (! current_user_can('manage_options')) return;

        $failures = $this->secret_failures();
        if (empty($failures)) return;

        printf(
            '<div class="notice notice-error"><p><strong>%1$s</strong> %2$s</p><p>%3$s <a href="%4$s">%5$s</a></p></div>',
            esc_html__('Headless Reviews Importer:', 'hri-reviews-importer'),
            /* translators: %s: list of the stored secrets, e.g. "Google Places API key, Google account connection" */
            esc_html(sprintf(__('These stored values cannot be decrypted: %s. Reviews are not imported from Google until this is fixed.', 'hri-reviews-importer'), implode(', ', $failures))),
            esc_html__('The encryption key in wp-config.php (HRI_ENCRYPTION_KEY, or AUTH_KEY and SECURE_AUTH_KEY) has changed, or the database comes from another site. Enter the key or the client secret again, and reconnect the Google account if it is affected.', 'hri-reviews-importer'),
            esc_url(admin_url('options-general.php?page=hri-review-import-settings')),
            esc_html__('Reviews Import Settings', 'hri-reviews-importer')
        );
    }

    /** I18n */
    public function load_textdomain()
    {
        load_plugin_textdomain(
            'hri-reviews-importer',
            false,
            dirname(plugin_basename(__FILE__)) . '/languages'
        );
    }

    /** Admin menu + settings */
    public function add_menu()
    {
        $this->settings_hook = add_options_page(
            esc_html__('Reviews Import Settings', 'hri-reviews-importer'),
            esc_html__('Reviews Import Settings', 'hri-reviews-importer'),
            'manage_options',
            'hri-review-import-settings',
            array($this, 'render_settings_page')
        );
    }

    /** CSS + JS of the settings page */
    public function enqueue_admin_assets($hook)
    {
        if (! $this->settings_hook || $hook !== $this->settings_hook) {
            return;
        }

        $header = get_file_data(__FILE__, array('version' => 'Version'));

        wp_enqueue_style('hri-admin', plugins_url('assets/admin.css', __FILE__), array(), $header['version']);
        wp_enqueue_script('hri-admin', plugins_url('assets/admin.js', __FILE__), array(), $header['version'], true);
        wp_localize_script('hri-admin', 'hriAdmin', array(
            'copied'    => __('Copied', 'hri-reviews-importer'),
            'saveFirst' => __('Save your changes first.', 'hri-reviews-importer'),
        ));
    }

    /** "Settings" link on the Plugins screen */
    public function add_settings_link($links)
    {
        array_unshift($links, sprintf(
            '<a href="%s">%s</a>',
            esc_url(admin_url('options-general.php?page=hri-review-import-settings')),
            esc_html__('Settings', 'hri-reviews-importer')
        ));
        return $links;
    }

    /** "GitHub" link in the plugin's row on the Plugins screen: source, issues and the release notes */
    public function add_github_link($links, $file)
    {
        if ($file === plugin_basename(__FILE__)) {
            $links[] = sprintf(
                '<a href="%s" target="_blank" rel="noopener">GitHub</a>',
                esc_url('https://github.com/' . self::GITHUB_REPO)
            );
        }
        return $links;
    }

    public function register_settings()
    {
        // Keys and tokens: write-only fields (see render_secret_field())
        foreach (array(self::OPTION_GOOGLE_PLACES_API_KEY, self::OPTION_GBP_CLIENT_SECRET) as $secret) {
            register_setting('hri_settings_group', $secret, array(
                'type'              => 'string',
                'sanitize_callback' => function ($value) use ($secret) {
                    return $this->sanitize_secret($secret, $value);
                },
                'default'           => '',
            ));
        }
        register_setting('hri_settings_group', self::OPTION_GOOGLE_PLACE_ID, array(
            'type'              => 'string',
            'sanitize_callback' => array($this, 'sanitize_text'),
            'default'           => '',
        ));
        register_setting('hri_settings_group', self::OPTION_GOOGLE_API_VERSION, array(
            'type'              => 'string',
            'sanitize_callback' => array($this, 'sanitize_google_api_version'),
            'default'           => 'legacy',
        ));
        register_setting('hri_settings_group', self::OPTION_IMPORT_ORDER, array(
            'type'              => 'string',
            'sanitize_callback' => array($this, 'sanitize_import_order'),
            'default'           => 'newest',
        ));
        register_setting('hri_settings_group', self::OPTION_GBP_CLIENT_ID, array(
            'type'              => 'string',
            'sanitize_callback' => array($this, 'sanitize_text'),
            'default'           => '',
        ));
        register_setting('hri_settings_group', self::OPTION_GBP_LOCATION, array(
            'type'              => 'string',
            'sanitize_callback' => array($this, 'sanitize_text'),
            'default'           => '',
        ));
        register_setting('hri_settings_group', self::OPTION_CRON_TIME, array(
            'type'              => 'string',
            'sanitize_callback' => array($this, 'sanitize_cron_time'),
            'default'           => 'hourly',
        ));
        register_setting('hri_settings_group', self::OPTION_IMPORTED_LANGUAGES, array(
            'type'              => 'string',
            'sanitize_callback' => array($this, 'sanitize_imported_languages'),
            'default'           => $this->get_default_lang_short(),
        ));
        register_setting('hri_settings_group', self::OPTION_MIN_REVIEW_RATING, array(
            'type'              => 'integer',
            'sanitize_callback' => array($this, 'sanitize_min_rating'),
            'default'           => 4,
        ));
        register_setting('hri_settings_group', self::OPTION_SKIP_EMPTY_REVIEWS, array(
            'type'              => 'boolean',
            'sanitize_callback' => array($this, 'sanitize_checkbox'),
            'default'           => false,
        ));
        register_setting('hri_settings_group', self::OPTION_UPDATE_PRERELEASES, array(
            'type'              => 'boolean',
            'sanitize_callback' => array($this, 'sanitize_checkbox'),
            'default'           => false,
        ));

        // Rows of the Google tab that only belong to some of the APIs (see admin.css)
        $places = array('class' => 'hri-for hri-for-legacy hri-for-new');
        $gbp    = array('class' => 'hri-for hri-for-gbp');

        $this->add_field(self::OPTION_GOOGLE_API_VERSION, __('Source API', 'hri-reviews-importer'), 'google', 'render_google_api_version_field');
        $this->add_field(self::OPTION_GOOGLE_PLACES_API_KEY, __('Google Places API key', 'hri-reviews-importer'), 'google', 'render_secret_field', $places + array(
            'desc'      => __('Enable the selected Places API in your Google Cloud project, then create a key for it.', 'hri-reviews-importer'),
            'link_url'  => 'https://developers.google.com/maps/documentation/places/web-service/get-api-key',
            'link_text' => __('Get an API key (Places API).', 'hri-reviews-importer'),
        ));
        $this->add_field(self::OPTION_GOOGLE_PLACE_ID, __('Google Place ID', 'hri-reviews-importer'), 'google', 'render_text_field', $places + array(
            'desc'      => __('The ID of the business on Google Maps.', 'hri-reviews-importer'),
            'link_url'  => 'https://developers.google.com/maps/documentation/places/web-service/place-id',
            'link_text' => __('Place IDs documentation', 'hri-reviews-importer'),
        ));
        $this->add_field(self::OPTION_IMPORT_ORDER, __('Import order', 'hri-reviews-importer'), 'google', 'render_import_order_select', array(
            'class'       => 'hri-for hri-for-legacy',
            'description' => __('Which reviews Google returns (at most 5): the newest or the most relevant ones.', 'hri-reviews-importer'),
        ));

        $this->add_field('hri_gbp_guide', __('Setup guide', 'hri-reviews-importer'), 'google', 'render_gbp_guide_field', $gbp);
        $this->add_field('hri_gbp_redirect_uri', __('Authorized redirect URI', 'hri-reviews-importer'), 'google', 'render_gbp_redirect_uri_field', $gbp);
        $this->add_field(self::OPTION_GBP_CLIENT_ID, __('Business Profile OAuth client ID', 'hri-reviews-importer'), 'google', 'render_text_field', $gbp);
        $this->add_field(self::OPTION_GBP_CLIENT_SECRET, __('Business Profile OAuth client secret', 'hri-reviews-importer'), 'google', 'render_secret_field', $gbp);
        $this->add_field('hri_gbp_connection', __('Google account', 'hri-reviews-importer'), 'google', 'render_gbp_connection_field', $gbp);
        $this->add_field(self::OPTION_GBP_LOCATION, __('Business Profile location', 'hri-reviews-importer'), 'google', 'render_gbp_location_field', $gbp);

        $this->add_field(self::OPTION_CRON_TIME, __('Import frequency', 'hri-reviews-importer'), 'import', 'render_cron_select');
        $this->add_field(self::OPTION_MIN_REVIEW_RATING, __('Minimum imported rating', 'hri-reviews-importer'), 'import', 'render_min_rating');
        $this->add_field(self::OPTION_IMPORTED_LANGUAGES, __('Imported languages (short codes)', 'hri-reviews-importer'), 'import', 'render_languages_textarea');
        $this->add_field(self::OPTION_SKIP_EMPTY_REVIEWS, __('Skip empty reviews', 'hri-reviews-importer'), 'import', 'render_checkbox_field', array(
            'label' => __('If checked, reviews without a text comment will be skipped.', 'hri-reviews-importer'),
        ));

        $this->add_field(self::OPTION_UPDATE_PRERELEASES, __('Pre-release updates', 'hri-reviews-importer'), 'advanced', 'render_checkbox_field', array(
            'label' => __('If checked, pre-releases published on GitHub are offered as plugin updates too.', 'hri-reviews-importer'),
        ));

        // Ensure default languages option has a value
        if ('' === get_option(self::OPTION_IMPORTED_LANGUAGES, '')) {
            update_option(self::OPTION_IMPORTED_LANGUAGES, $this->get_default_lang_short());
        }
    }

    public function render_import_order_select($args)
    {
        $current = get_option(self::OPTION_IMPORT_ORDER, 'newest');

        echo '<select id="' . esc_attr(self::OPTION_IMPORT_ORDER) . '" name="' . esc_attr(self::OPTION_IMPORT_ORDER) . '">';
        printf(
            '<option value="newest"%s>%s</option>',
            selected($current, 'newest', false),
            esc_html__('Newest', 'hri-reviews-importer')
        );
        printf(
            '<option value="most_relevant"%s>%s</option>',
            selected($current, 'most_relevant', false),
            esc_html__('Most Relevant', 'hri-reviews-importer')
        );
        echo '</select>';

        if (! empty($args['description'])) {
            echo '<p class="description">' . esc_html($args['description']) . '</p>';
        }
    }

    public function sanitize_import_order($value)
    {
        $value = is_string($value) ? strtolower(trim($value)) : 'newest';
        return in_array($value, array('newest', 'most_relevant'), true) ? $value : 'newest';
    }

    /** Google APIs the reviews can be imported from: value => label, description */
    private function google_api_versions()
    {
        return array(
            'gbp'    => array(
                'label' => __('Business Profile API (OAuth)', 'hri-reviews-importer'),
                'desc'  => __('All reviews of a profile you manage, newest first. Needs an OAuth connection, and Google has to approve Business Profile API access for the Cloud project.', 'hri-reviews-importer'),
            ),
            'new'    => array(
                'label' => __('Places API (New)', 'hri-reviews-importer'),
                'desc'  => __('Needs an API key and a Place ID. At most 5 reviews, always the most relevant ones (there is no "newest" order).', 'hri-reviews-importer'),
            ),
            'legacy' => array(
                'label' => __('Places API (Legacy)', 'hri-reviews-importer'),
                'desc'  => __('Needs an API key and a Place ID. At most 5 reviews, the newest or the most relevant ones. Can no longer be enabled in new Google Cloud projects.', 'hri-reviews-importer'),
            ),
        );
    }

    public function render_google_api_version_field()
    {
        $current = $this->sanitize_google_api_version(get_option(self::OPTION_GOOGLE_API_VERSION, 'legacy'));

        printf('<div class="hri-choices" role="radiogroup" aria-label="%s">', esc_attr__('Source API', 'hri-reviews-importer'));
        foreach ($this->google_api_versions() as $key => $api) {
            printf(
                '<label class="hri-choice"><input type="radio" name="%1$s" value="%2$s"%3$s /><span><strong>%4$s</strong><span class="description">%5$s</span></span></label>',
                esc_attr(self::OPTION_GOOGLE_API_VERSION),
                esc_attr($key),
                checked($current, $key, false),
                esc_html($api['label']),
                esc_html($api['desc'])
            );
        }
        echo '</div>';
    }

    public function sanitize_google_api_version($value)
    {
        $value = is_string($value) ? strtolower(trim($value)) : 'legacy';
        return array_key_exists($value, $this->google_api_versions()) ? $value : 'legacy';
    }

    public function render_gbp_guide_field()
    {
        // Open until the connection is made, out of the way afterwards
        echo '<details class="hri-help"' . ($this->gbp_is_connected() ? '' : ' open') . '>';
        echo '<summary>' . esc_html__('How to set up the Business Profile API', 'hri-reviews-importer') . '</summary>';
        echo '<ol>';
        echo '<li>' . esc_html__('In your Google Cloud project (with approved Business Profile API access) enable: Google My Business API, My Business Account Management API, My Business Business Information API.', 'hri-reviews-importer') . '</li>';
        echo '<li>' . esc_html__('Create an OAuth client (type: Web application) with the authorized redirect URI below.', 'hri-reviews-importer') . '</li>';
        echo '<li>' . esc_html__('Save the client ID and client secret, then connect with a Google account that manages the Business Profile.', 'hri-reviews-importer') . '</li>';
        echo '<li>' . esc_html__('Choose the Business Profile location and save.', 'hri-reviews-importer') . '</li>';
        echo '</ol>';
        echo '</details>';
    }

    public function render_gbp_redirect_uri_field()
    {
        printf(
            '<input type="text" class="code hri-uri" id="hri_gbp_redirect_uri" value="%s" readonly /> ',
            esc_attr($this->gbp_redirect_uri())
        );
        printf(
            '<button type="button" class="button hri-copy" data-hri-copy="hri_gbp_redirect_uri">%s</button>',
            esc_html__('Copy', 'hri-reviews-importer')
        );
    }

    public function render_gbp_connection_field()
    {
        $connected  = $this->gbp_is_connected();
        $has_client = trim((string) get_option(self::OPTION_GBP_CLIENT_ID, '')) !== '' && trim((string) get_option(self::OPTION_GBP_CLIENT_SECRET, '')) !== '';

        echo '<div class="hri-inline">';
        printf(
            '<span class="hri-badge %s">%s</span> ',
            $connected ? 'hri-badge-ok' : 'hri-badge-off',
            $connected ? esc_html__('Connected', 'hri-reviews-importer') : esc_html__('Not connected', 'hri-reviews-importer')
        );
        if ($has_client) {
            $this->render_action_button('hri_gbp_connect', $connected ? __('Reconnect Google account', 'hri-reviews-importer') : __('Connect Google account', 'hri-reviews-importer'));
        }
        if ($connected) {
            $this->render_action_button('hri_gbp_disconnect', __('Disconnect', 'hri-reviews-importer'), 'button-link button-link-delete');
        }
        echo '</div>';
        if (! $has_client) {
            echo '<p class="description">' . esc_html__('Save the OAuth client ID and client secret first.', 'hri-reviews-importer') . '</p>';
        }
    }

    public function render_gbp_location_field()
    {
        $value     = get_option(self::OPTION_GBP_LOCATION, '');
        $locations = get_option(self::OPTION_GBP_LOCATIONS, array());
        $has_list  = is_array($locations) && ! empty($locations);

        if ($has_list) {
            echo '<select id="' . esc_attr(self::OPTION_GBP_LOCATION) . '" name="' . esc_attr(self::OPTION_GBP_LOCATION) . '">';
            echo '<option value="">' . esc_html__('— Select —', 'hri-reviews-importer') . '</option>';
            $listed = false;
            foreach ($locations as $location) {
                $listed = $listed || $location['name'] === $value;
                printf(
                    '<option value="%1$s"%2$s>%3$s</option>',
                    esc_attr($location['name']),
                    selected($value, $location['name'], false),
                    esc_html($location['title'] !== '' ? $location['title'] . ' – ' . $location['name'] : $location['name'])
                );
            }
            // A saved location that is not in the fetched list (e.g. typed in earlier) must survive a save
            if ($value !== '' && ! $listed) {
                printf('<option value="%1$s" selected>%1$s</option>', esc_attr($value));
            }
            echo '</select> ';
        } else {
            printf(
                '<input type="text" class="regular-text" id="%1$s" name="%1$s" value="%2$s" placeholder="accounts/123/locations/456" /> ',
                esc_attr(self::OPTION_GBP_LOCATION),
                esc_attr($value)
            );
        }

        if ($this->gbp_is_connected()) {
            $this->render_action_button('hri_gbp_fetch_locations', $has_list ? __('Refresh list', 'hri-reviews-importer') : __('Fetch locations', 'hri-reviews-importer'));
        }
        if (! $has_list) {
            echo '<p class="description">' . esc_html__('Format: accounts/{accountId}/locations/{locationId}. After connecting, the locations of the Google account can be chosen from a list.', 'hri-reviews-importer') . '</p>';
        }
    }


    /**
     * Add a field to a tab of the settings page.
     *
     * @param string $id       Option name, or any unique id for rows without an option.
     * @param string $label
     * @param string $tab      Key from settings_tabs().
     * @param string $callback Name of the rendering method.
     * @param array  $args     Passed to the callback; 'class' is set on the table row.
     */
    private function add_field($id, $label, $tab, $callback, $args = array())
    {
        add_settings_field(
            $id,
            esc_html($label),
            array($this, $callback),
            'hri-review-import-settings',
            'hri_section_' . $tab,
            array_merge(array('option' => $id), $args)
        );
    }

    // Renders a checkbox with a label and optional help text
    public function render_checkbox_field($args)
    {
        $option = $args['option'] ?? '';
        $label  = $args['label']  ?? '';
        $value  = (bool) get_option($option, false);

        printf(
            '<label><input type="checkbox" id="%1$s" name="%1$s" value="1" %2$s /> %3$s</label>',
            esc_attr($option),
            checked($value, true, false),
            esc_html($label)
        );
    }

    // Sanitizer for boolean checkbox values
    public function sanitize_checkbox($value)
    {
        return (! empty($value)) ? 1 : 0;
    }


    /** Tabs of the settings page: key => label */
    private function settings_tabs()
    {
        return array(
            'google'   => __('Google', 'hri-reviews-importer'),
            'import'   => __('Import', 'hri-reviews-importer'),
            'advanced' => __('Advanced', 'hri-reviews-importer'),
        );
    }

    public function render_settings_page()
    {
        if (! current_user_can('manage_options')) return;

        $tabs = $this->settings_tabs();
        // Coming back from an action: open the tab its button is on
        $forced = isset($_GET['hri_gbp']) ? 'google' : '';
        $active = ($forced !== '') ? $forced : key($tabs);
        $api    = $this->sanitize_google_api_version(get_option(self::OPTION_GOOGLE_API_VERSION, 'legacy'));
?>
        <div class="wrap hri-settings" <?php echo ($forced !== '') ? 'data-hri-forced-tab="1"' : ''; ?>>
            <h1><?php echo esc_html__('Reviews Import Settings', 'hri-reviews-importer'); ?></h1>
            <hr class="wp-header-end" />

            <?php
            $this->render_notices();
            $this->render_status_bar();
            ?>

            <nav class="nav-tab-wrapper hri-tabs">
                <?php foreach ($tabs as $key => $label) : ?>
                    <a href="#<?php echo esc_attr($key); ?>" class="nav-tab<?php echo ($key === $active) ? ' nav-tab-active' : ''; ?>"><?php echo esc_html($label); ?></a>
                <?php endforeach; ?>
            </nav>

            <form method="post" action="options.php" class="hri-form">
                <?php settings_fields('hri_settings_group'); ?>

                <?php foreach ($tabs as $key => $label) : ?>
                    <div class="hri-panel<?php echo ($key === $active) ? ' is-active' : ''; ?>" id="hri-tab-<?php echo esc_attr($key); ?>" <?php echo ('google' === $key) ? 'data-api="' . esc_attr($api) . '"' : ''; ?>>
                        <h2 class="hri-panel-title"><?php echo esc_html($label); ?></h2>
                        <table class="form-table" role="presentation">
                            <?php do_settings_fields('hri-review-import-settings', 'hri_section_' . $key); ?>
                        </table>
                    </div>
                <?php endforeach; ?>

                <?php submit_button(esc_html__('Save', 'hri-reviews-importer')); ?>
            </form>

            <?php $this->render_action_forms(); ?>
        </div>
    <?php
    }

    /** Results of the admin-post actions and the last stored error (WordPress moves notices below the page title) */
    private function render_notices()
    {
        $choose_location = (trim((string) get_option(self::OPTION_GBP_LOCATION, '')) === '')
            ? ' ' . __('Choose the Business Profile location and save.', 'hri-reviews-importer')
            : '';

        // Query argument of the redirect => value => notice type, message
        $results = array(
            'hri_import' => array(
                'done'  => array('success', __('Import executed.', 'hri-reviews-importer')),
                'error' => array('error', __('Import failed. See the last error below.', 'hri-reviews-importer')),
            ),
            'hri_gbp'    => array(
                'connected'    => array('success', __('Connected to Google Business Profile.', 'hri-reviews-importer') . $choose_location),
                'disconnected' => array('success', __('Disconnected from Google Business Profile.', 'hri-reviews-importer')),
                'locations'    => array('success', __('Locations fetched.', 'hri-reviews-importer') . $choose_location),
                'error'        => array('error', __('Google Business Profile action failed. See the "Last error" below.', 'hri-reviews-importer')),
            ),
        );
        foreach ($results as $arg => $notices) {
            $value = isset($_GET[$arg]) ? sanitize_key($_GET[$arg]) : '';
            if (isset($notices[$value])) {
                printf('<div class="notice notice-%s is-dismissible"><p>%s</p></div>', esc_attr($notices[$value][0]), esc_html($notices[$value][1]));
            }
        }

        if (hri_secret_key() === '') {
            printf(
                '<div class="notice notice-warning"><p>%s</p></div>',
                esc_html__('No encryption key: wp-config.php has no AUTH_KEY and SECURE_AUTH_KEY. Keys and tokens cannot be saved until you define HRI_ENCRYPTION_KEY there (a random string of at least 32 characters).', 'hri-reviews-importer')
            );
        }

        $last_error = get_option(self::OPTION_LAST_ERROR, array());
        if (! empty($last_error) && ! empty($last_error['message'])) : ?>
            <div class="notice notice-error">
                <p><strong><?php echo esc_html__('Last error', 'hri-reviews-importer'); ?>:</strong>
                    <?php echo esc_html($last_error['message']); ?>
                </p>
                <?php if (! empty($last_error['time'])) : ?>
                    <p><em><?php echo esc_html__('When', 'hri-reviews-importer'); ?>:</em>
                        <?php echo esc_html($last_error['time']); ?>
                    </p>
                <?php endif; ?>
            </div>
        <?php endif;
    }

    /** Last / next run, the Google rating of the last import and the "Import now" button */
    private function render_status_bar()
    {
        $format   = get_option('date_format') . ' ' . get_option('time_format');
        $last_run = get_option(self::OPTION_LAST_RUN, '');
        $next_run = wp_next_scheduled(self::CRON_HOOK);
        ?>
        <div class="hri-status">
            <dl class="hri-status-items">
                <div>
                    <dt><?php echo esc_html__('Last run', 'hri-reviews-importer'); ?></dt>
                    <dd><?php echo $last_run ? esc_html(mysql2date($format, $last_run)) : esc_html__('not run yet', 'hri-reviews-importer'); ?></dd>
                </div>
                <div>
                    <dt><?php echo esc_html__('Next run', 'hri-reviews-importer'); ?></dt>
                    <dd><?php echo $next_run ? esc_html(wp_date($format, $next_run)) : esc_html__('not scheduled', 'hri-reviews-importer'); ?></dd>
                </div>
                <?php if ($this->has_google_rating()) : ?>
                    <div>
                        <dt><?php echo esc_html__('Google rating', 'hri-reviews-importer'); ?></dt>
                        <dd><?php
                            /* translators: 1: average rating, 2: number of ratings */
                            echo esc_html(sprintf(__('%1$s (%2$s ratings)', 'hri-reviews-importer'), get_option(self::OPTION_GOOGLE_RATING, ''), number_format_i18n((float) get_option(self::OPTION_GOOGLE_RATINGS_TOTAL, 0))));
                            ?></dd>
                    </div>
                <?php endif; ?>
            </dl>
            <?php $this->render_action_button('hri_import_now', __('Import now', 'hri-reviews-importer')); ?>
        </div>
    <?php
    }

    /**
     * The admin-post actions of the page. A form cannot be nested into the settings form, so these
     * are empty forms after it and the buttons point to them (see render_action_button()).
     */
    private function render_action_forms()
    {
        // admin-post action => nonce action, nonce field
        $forms = array(
            'hri_import_now'          => array('hri_import_now_nonce', 'hri_import_now_nonce_field'),
            'hri_gbp_connect'         => array('hri_gbp_connect', 'hri_gbp_connect_field'),
            'hri_gbp_fetch_locations' => array('hri_gbp_fetch_locations', 'hri_gbp_fetch_locations_field'),
            'hri_gbp_disconnect'      => array('hri_gbp_disconnect', 'hri_gbp_disconnect_field'),
        );
        foreach ($forms as $action => $nonce) {
            printf('<form method="post" action="%s" id="hri-form-%s">', esc_url(admin_url('admin-post.php')), esc_attr($action));
            wp_nonce_field($nonce[0], $nonce[1]);
            printf('<input type="hidden" name="action" value="%s" /></form>', esc_attr($action));
        }
    }

    /** Submit button of an admin-post action; works anywhere on the page, inside the settings form too */
    private function render_action_button($action, $label, $class = 'button')
    {
        printf(
            '<button type="submit" class="%s hri-action" form="hri-form-%s">%s</button> ',
            esc_attr($class),
            esc_attr($action),
            esc_html($label)
        );
    }

    private function gbp_is_connected()
    {
        $token = get_option(self::OPTION_GBP_TOKEN, array());
        return is_array($token) && ! empty($token['refresh_token']);
    }

    /** The rating options hold placeholders until the first Google import */
    private function has_google_rating()
    {
        if (! get_option(self::OPTION_LAST_RUN, '')) return false;

        return (get_option(self::OPTION_GOOGLE_API_VERSION, 'legacy') === 'gbp')
            ? $this->gbp_is_connected()
            : (trim((string) get_option(self::OPTION_GOOGLE_PLACES_API_KEY, '')) !== '' && trim((string) get_option(self::OPTION_GOOGLE_PLACE_ID, '')) !== '');
    }

    /** Renderers */
    public function render_text_field($args)
    {
        $option      = $args['option'] ?? '';
        $placeholder = $args['placeholder'] ?? '';
        $value       = get_option($option, '');
        printf(
            '<input type="text" class="regular-text" id="%1$s" name="%1$s" value="%2$s" placeholder="%3$s" />',
            esc_attr($option),
            esc_attr($value),
            esc_attr($placeholder)
        );
        $this->render_field_description($args);
    }

    /**
     * Write-only field for keys and tokens: the saved value is never printed, only that there is one.
     * An empty field keeps the saved value (see sanitize_secret()).
     */
    public function render_secret_field($args)
    {
        $option = $args['option'] ?? '';
        $failed = hri_decrypt_secret(get_option($option, ''))['failed'];
        $value  = $this->get_secret($option);
        $saved  = ($value !== '' || $failed);
        $input  = sprintf(
            '<input type="password" class="regular-text hri-secret-input" id="%1$s" name="%1$s" value="" autocomplete="new-password" spellcheck="false" />',
            esc_attr($option)
        );

        if (! $saved) {
            echo '<div class="hri-secret">' . $input . '</div>';
        } else {
            // The last characters are enough to tell two long keys apart
            $hint = str_repeat('•', 8) . (strlen($value) >= 12 ? substr($value, -4) : '');
        ?>
            <div class="hri-secret has-value">
                <span class="hri-secret-saved">
                    <?php if ($failed) : ?>
                        <span class="hri-badge hri-badge-off"><?php echo esc_html__('Cannot be decrypted', 'hri-reviews-importer'); ?></span>
                    <?php else : ?>
                        <span class="hri-badge hri-badge-ok"><?php echo esc_html__('Saved', 'hri-reviews-importer'); ?></span>
                        <code><?php echo esc_html($hint); ?></code>
                    <?php endif; ?>
                </span>
                <span class="hri-secret-idle">
                    <button type="button" class="button hri-secret-change"><?php echo esc_html__('Change', 'hri-reviews-importer'); ?></button>
                    <button type="button" class="button-link button-link-delete hri-secret-delete"><?php echo esc_html__('Delete', 'hri-reviews-importer'); ?></button>
                </span>
                <span class="hri-secret-edit">
                    <?php echo $input; ?>
                    <button type="button" class="button hri-secret-cancel"><?php echo esc_html__('Cancel', 'hri-reviews-importer'); ?></button>
                    <span class="description"><?php echo esc_html__('Leave empty to keep the saved value.', 'hri-reviews-importer'); ?></span>
                </span>
                <span class="hri-secret-pending">
                    <span class="description"><?php echo esc_html__('Will be deleted when you save.', 'hri-reviews-importer'); ?></span>
                    <button type="button" class="button hri-secret-undo"><?php echo esc_html__('Undo', 'hri-reviews-importer'); ?></button>
                </span>
                <label class="hri-secret-clear">
                    <input type="checkbox" name="hri_clear_secret[<?php echo esc_attr($option); ?>]" value="1" />
                    <?php echo esc_html__('Delete the saved value', 'hri-reviews-importer'); ?>
                </label>
            </div>
<?php
        }
        $this->render_field_description($args);
    }

    /** Help text below a field: 'desc', optionally followed by a link ('link_url' + 'link_text') */
    private function render_field_description($args)
    {
        $desc = $args['desc'] ?? '';
        $link = '';
        if (! empty($args['link_url'])) {
            $link = sprintf('<a href="%s" target="_blank" rel="noopener">%s</a>', esc_url($args['link_url']), esc_html($args['link_text'] ?? $args['link_url']));
        }
        if ($desc !== '' || $link !== '') {
            echo '<p class="description">' . trim(esc_html($desc) . ' ' . $link) . '</p>';
        }
    }

    /** Import frequencies: WP-Cron schedule => label */
    private function cron_options()
    {
        return array(
            'hourly'     => __('Hourly', 'hri-reviews-importer'),
            'twicedaily' => __('Twice daily', 'hri-reviews-importer'),
            'daily'      => __('Daily', 'hri-reviews-importer'),
        );
    }

    public function render_cron_select()
    {
        $current = get_option(self::OPTION_CRON_TIME, 'hourly');
        echo '<select id="' . esc_attr(self::OPTION_CRON_TIME) . '" name="' . esc_attr(self::OPTION_CRON_TIME) . '">';
        foreach ($this->cron_options() as $key => $label) {
            printf(
                '<option value="%1$s"%3$s>%2$s</option>',
                esc_attr($key),
                esc_html($label),
                selected($current, $key, false)
            );
        }
        echo '</select>';
        //echo '<p class="description">' . esc_html__('hri_import_cron_time', 'hri-reviews-importer') . '</p>';
    }

    public function render_min_rating()
    {
        $current = (int) get_option(self::OPTION_MIN_REVIEW_RATING, 4);
        echo '<select id="' . esc_attr(self::OPTION_MIN_REVIEW_RATING) . '" name="' . esc_attr(self::OPTION_MIN_REVIEW_RATING) . '">';
        for ($i = 1; $i <= 5; $i++) {
            printf(
                '<option value="%1$d"%2$s>%1$d</option>',
                $i,
                selected($current, $i, false)
            );
        }
        echo '</select>';
        echo '<p class="description">' . esc_html__('Only ratings greater than or equal to this value will be imported.', 'hri-reviews-importer') . '</p>';
    }

    public function render_languages_textarea()
    {
        $value = get_option(self::OPTION_IMPORTED_LANGUAGES, $this->get_default_lang_short());
        printf(
            '<textarea id="%1$s" name="%1$s" rows="4" cols="20" class="code">%2$s</textarea>',
            esc_attr(self::OPTION_IMPORTED_LANGUAGES),
            esc_textarea($value)
        );
        $this->render_field_description(array(
            'desc'      => __('One code per line (e.g., "hu", "en"). "hu_HU", "en_US" are also accepted and will be normalized to short codes on save. The default language of the site is always included.', 'hri-reviews-importer'),
            'link_url'  => 'https://developers.google.com/maps/faq#languagesupport',
            'link_text' => __('Google Maps – Language support', 'hri-reviews-importer'),
        ));
    }

    /** Sanitizers */
    public function sanitize_text($value)
    {
        return is_string($value) ? trim($value) : '';
    }

    /**
     * The settings form never gets the saved value of a write-only field (see render_secret_field()),
     * so it sends an empty one unless a new value was typed in: empty keeps the saved value there.
     * A value typed in is stored encrypted (see includes/secrets.php).
     */
    private function sanitize_secret($option, $value)
    {
        $value = is_string($value) ? trim($value) : '';

        // Everything else that saves the option (e.g. hri_set_option_autoload()) means its value as it is.
        // Already encrypted: WordPress sanitizes the value of a new option twice.
        if (! isset($_POST['option_page']) || $_POST['option_page'] !== 'hri_settings_group' || strpos($value, HRI_SECRET_PREFIX) === 0) {
            return $value;
        }
        if ($value !== '') {
            try {
                return hri_encrypt_secret($value);
            } catch (\Throwable $e) {
                add_settings_error($option, 'hri_no_encryption_key', sprintf(
                    /* translators: 1: name of the field, 2: error message */
                    __('%1$s was not saved. %2$s Define HRI_ENCRYPTION_KEY in wp-config.php (a random string of at least 32 characters), then save it again.', 'hri-reviews-importer'),
                    $option === self::OPTION_GBP_CLIENT_SECRET ? __('Business Profile OAuth client secret', 'hri-reviews-importer') : __('Google Places API key', 'hri-reviews-importer'),
                    $e->getMessage()
                ));
                return (string) get_option($option, '');
            }
        }
        if (isset($_POST['hri_clear_secret']) && is_array($_POST['hri_clear_secret']) && ! empty($_POST['hri_clear_secret'][$option])) {
            return '';
        }

        return (string) get_option($option, '');
    }

    public function sanitize_cron_time($value)
    {
        $value = is_string($value) ? trim($value) : '';
        return array_key_exists($value, $this->cron_options()) ? $value : 'hourly';
    }

    public function sanitize_min_rating($value)
    {
        $n = intval($value);
        if ($n < 1) $n = 1;
        if ($n > 5) $n = 5;
        return $n;
    }

    /** Normalize languages to short codes (hu, en), ensure uniqueness + default language present */
    public function sanitize_imported_languages($value)
    {
        $default_short = $this->get_default_lang_short();
        $raw = (string) $value;
        $raw = str_replace(array("\r\n", "\r", ","), "\n", $raw);
        $parts = array_filter(array_map('trim', explode("\n", $raw)));
        $normalized = array();

        foreach ($parts as $p) {
            $short = $this->to_short_lang($p);
            if ($short) $normalized[] = $short;
        }

        if (empty($normalized)) $normalized[] = $default_short;
        $normalized = array_values(array_unique($normalized));
        if (! in_array($default_short, $normalized, true)) $normalized[] = $default_short;

        return implode("\n", $normalized);
    }

    /** Import now handler */
    public function handle_import_now()
    {
        if (! current_user_can('manage_options')) wp_die(esc_html__('Insufficient permissions.', 'hri-reviews-importer'));
        check_admin_referer('hri_import_now_nonce', 'hri_import_now_nonce_field');

        try {
            do_action('hri_run_import');
            update_option(self::OPTION_LAST_RUN, current_time('mysql'));
            $this->clear_last_error();
            $query = array('hri_import' => 'done');
        } catch (\Throwable $e) {
            $this->log_error($e->getMessage(), array('where' => 'handle_import_now'));
            $query = array('hri_import' => 'error');
        }

        wp_redirect(add_query_arg($query, admin_url('options-general.php?page=hri-review-import-settings')));
        exit;
    }

    /** Cron */
    public static function activate()
    {

        $self = new self();
        $self->hri_enforce_autoloads();

        $freq = get_option(self::OPTION_CRON_TIME, 'hourly');
        if (! in_array($freq, array('hourly', 'twicedaily', 'daily'), true)) $freq = 'hourly';
        if (! wp_next_scheduled(self::CRON_HOOK)) {
            wp_schedule_event(time() + 60, $freq, self::CRON_HOOK);
        }
    }

    public static function deactivate()
    {
        while ($ts = wp_next_scheduled(self::CRON_HOOK)) {
            wp_unschedule_event($ts, self::CRON_HOOK);
        }
        $self = new self();
        $self->hri_enforce_autoloads(true);
    }

    public function reschedule_cron_on_option_change($old_value, $new_value)
    {
        if ($old_value === $new_value) return;
        if (! in_array($new_value, array('hourly', 'twicedaily', 'daily'), true)) $new_value = 'hourly';

        while ($ts = wp_next_scheduled(self::CRON_HOOK)) {
            wp_unschedule_event($ts, self::CRON_HOOK);
        }
        wp_schedule_event(time() + 60, $new_value, self::CRON_HOOK);
    }

    public function handle_cron()
    {
        try {
            do_action('hri_run_import');
            update_option(self::OPTION_LAST_RUN, current_time('mysql'));
            $this->clear_last_error();
        } catch (\Throwable $e) {
            $this->log_error($e->getMessage(), array('where' => 'handle_cron'));
            // Cron esetén nem redirectelünk; csak eltároljuk a hibát.
        }
    }

    /** Reviews – Custom Post Type */
    public function register_reviews_cpt()
    {
        $labels = array(
            'name'               => esc_html__('Reviews', 'hri-reviews-importer'),
            'singular_name'      => esc_html__('Review', 'hri-reviews-importer'),
            'menu_name'          => esc_html__('Reviews', 'hri-reviews-importer'),
            'name_admin_bar'     => esc_html__('Review', 'hri-reviews-importer'),
            'add_new'            => esc_html__('Add New', 'hri-reviews-importer'),
            'add_new_item'       => esc_html__('Add New Review', 'hri-reviews-importer'),
            'new_item'           => esc_html__('New Review', 'hri-reviews-importer'),
            'edit_item'          => esc_html__('Edit Review', 'hri-reviews-importer'),
            'view_item'          => esc_html__('View Review', 'hri-reviews-importer'),
            'all_items'          => esc_html__('All Reviews', 'hri-reviews-importer'),
            'search_items'       => esc_html__('Search Reviews', 'hri-reviews-importer'),
            'not_found'          => esc_html__('No reviews found', 'hri-reviews-importer'),
            'not_found_in_trash' => esc_html__('No reviews found in Trash', 'hri-reviews-importer'),
        );

        $args = array(
            'labels'             => $labels,
            'public'             => true, // must stay public: Bricks only lists public post types in the query loop
            'show_in_rest'       => true,
            'publicly_queryable' => false, // no single pages, keeps reviews out of sitemaps
            'show_in_nav_menus'  => false,
            'rewrite'            => false,
            'exclude_from_search' => true,
            'show_ui'            => true,
            'show_in_menu'       => true, // dedicated admin menu
            'menu_position'      => 25,
            'menu_icon'          => 'dashicons-star-half',
            'supports'           => array('title', 'editor'), // Title: reviewer name; Editor: review text
            'capability_type'    => 'post',
            'map_meta_cap'       => true,
            // Editors and administrators only: with the capabilities of posts an Author could publish reviews
            'capabilities'       => array_fill_keys(array(
                'edit_posts',
                'edit_others_posts',
                'edit_private_posts',
                'edit_published_posts',
                'publish_posts',
                'read_private_posts',
                'delete_posts',
                'delete_others_posts',
                'delete_private_posts',
                'delete_published_posts',
                'create_posts',
            ), 'edit_others_posts'),
            'has_archive'        => false,
        );

        register_post_type('reviews', $args);
    }

    /** Metaboxes */
    public function add_review_metaboxes()
    {
        add_meta_box(
            'hri_review_main',
            esc_html__('Review details', 'hri-reviews-importer'),
            array($this, 'render_review_main_metabox'),
            'reviews',
            'normal',
            'default'
        );

        add_meta_box(
            'hri_review_translations',
            esc_html__('Review text (by language)', 'hri-reviews-importer'),
            array($this, 'render_review_translations_metabox'),
            'reviews',
            'normal',
            'default'
        );
    }

    public function render_review_main_metabox($post)
    {
        wp_nonce_field('hri_review_meta_nonce', 'hri_review_meta_nonce_field');

        $review_number = get_post_meta($post->ID, 'review_number', true);
        $review_id     = get_post_meta($post->ID, 'review_id', true);
        $review_source = get_post_meta($post->ID, 'review_source', true);
        $profile_photo_url = get_post_meta($post->ID, 'profile_photo_url', true);

        if ($review_source !== 'Google' && $review_source !== 'Facebook') {
            $review_source = '';
        }

    ?>
        <table class="form-table">
            <tr>
                <th><label for="review_number"><?php echo esc_html__('Rating (1–5)', 'hri-reviews-importer'); ?></label></th>
                <td>
                    <input type="number" min="1" max="5" id="review_number" name="review_number" value="<?php echo esc_attr($review_number); ?>" />
                </td>
            </tr>
            <tr>
                <th><label for="review_id"><?php echo esc_html__('Review ID', 'hri-reviews-importer'); ?></label></th>
                <td>
                    <input type="text" id="review_id" value="<?php echo esc_attr($review_id); ?>" readonly class="regular-text" />
                    <p class="description"><?php echo esc_html__('Non-editable field.', 'hri-reviews-importer'); ?></p>
                </td>
            </tr>
            <tr>
                <th><label for="review_source"><?php echo esc_html__('Review source', 'hri-reviews-importer'); ?></label></th>
                <td>
                    <select id="review_source" name="review_source">
                        <option value=""></option>
                        <option value="Google" <?php selected($review_source, 'Google'); ?>><?php echo esc_html__('Google', 'hri-reviews-importer'); ?></option>
                        <option value="Facebook" <?php selected($review_source, 'Facebook'); ?>><?php echo esc_html__('Facebook', 'hri-reviews-importer'); ?></option>
                    </select>
                </td>
            </tr>
            <tr>
                <th><label for="profile_photo_url"><?php echo esc_html__('Profile photo URL', 'hri-reviews-importer'); ?></label></th>
                <td>
                    <input type="text" id="profile_photo_url" name="profile_photo_url" value="<?php echo esc_attr($profile_photo_url); ?>" class="regular-text" />
                    <p class="description"><?php echo esc_html__('URL of the reviewer’s profile image.', 'hri-reviews-importer'); ?></p>
                </td>
            </tr>
        </table>
<?php
    }

    public function render_review_translations_metabox($post)
    {
        $langs = $this->get_imported_languages_short_array();
        if (empty($langs)) {
            echo '<p>' . esc_html__('No languages configured.', 'hri-reviews-importer') . '</p>';
            return;
        }

        echo '<p>' . esc_html__('Fields below are generated from the configured languages (short codes).', 'hri-reviews-importer') . '</p>';
        echo '<table class="form-table">';
        foreach ($langs as $lang) {
            $key   = 'review_' . $lang;
            $value = get_post_meta($post->ID, $key, true);
            echo '<tr>';
            /* translators: %s: language short code, e.g. "hu" */
            echo '<th><label for="' . esc_attr($key) . '">' . sprintf(esc_html__('review_%s', 'hri-reviews-importer'), esc_html($lang)) . '</label></th>';
            echo '<td><textarea id="' . esc_attr($key) . '" name="' . esc_attr($key) . '" rows="3" class="large-text">' . esc_textarea($value) . '</textarea></td>';
            echo '</tr>';
        }
        echo '</table>';
    }

    public function save_review_meta($post_id, $post)
    {
        if (! isset($_POST['hri_review_meta_nonce_field']) || ! wp_verify_nonce($_POST['hri_review_meta_nonce_field'], 'hri_review_meta_nonce')) {
            return;
        }
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
        if (! current_user_can('edit_post', $post_id)) return;

        // review_number (1–5)
        if (isset($_POST['review_number'])) {
            $n = intval($_POST['review_number']);
            if ($n < 1) $n = 1;
            if ($n > 5) $n = 5;
            update_post_meta($post_id, 'review_number', $n);
        }

        // review_id: non-editable – do not save here.

        // review_source
        if (isset($_POST['review_source'])) {
            $src = $_POST['review_source'] === 'Google' ? 'Google' : ($_POST['review_source'] === 'Facebook' ? 'Facebook' : '');
            update_post_meta($post_id, 'review_source', $src);
        }

        // review_{lang}
        $langs = $this->get_imported_languages_short_array();
        foreach ($langs as $lang) {
            $key = 'review_' . $lang;
            if (isset($_POST[$key])) {
                $val = wp_kses_post($_POST[$key]);
                update_post_meta($post_id, $key, $val);
            }
        }
        // Save profile_photo_url
        if (isset($_POST['profile_photo_url'])) {
            $url = esc_url_raw($_POST['profile_photo_url']);
            update_post_meta($post_id, 'profile_photo_url', $url);
        }
    }

    /** Reviews list columns */
    public function add_reviews_columns($columns)
    {
        $new = array();
        foreach ($columns as $key => $label) {
            $new[$key] = $label;
            if ('title' === $key) {
                $new['hri_source'] = esc_html__('Source', 'hri-reviews-importer');
                $new['hri_rating'] = esc_html__('Rating', 'hri-reviews-importer');
            }
        }
        return $new;
    }

    public function render_reviews_custom_column($column, $post_id)
    {
        if ('hri_source' === $column) {
            $src = get_post_meta($post_id, 'review_source', true);
            echo esc_html($src ? $src : '—');
        } elseif ('hri_rating' === $column) {
            $rating = get_post_meta($post_id, 'review_number', true);
            echo $rating !== '' ? esc_html($rating) : '—';
        }
    }

    public function make_reviews_columns_sortable($columns)
    {
        $columns['hri_rating'] = 'hri_rating';
        $columns['hri_source'] = 'hri_source';
        return $columns;
    }

    public function reviews_orderby_rating($query)
    {
        if (! is_admin() || ! $query->is_main_query()) return;
        $orderby = $query->get('orderby');
        $post_type = $query->get('post_type');
        if ('reviews' !== $post_type) return;

        if ('hri_rating' === $orderby) {
            $query->set('meta_key', 'review_number');
            $query->set('orderby', 'meta_value_num');
        } elseif ('hri_source' === $orderby) {
            $query->set('meta_key', 'review_source');
            $query->set('orderby', 'meta_value');
        }
    }

    /** Bricks dynamic data tags: the aggregated Google rating */
    private function bricks_tags()
    {
        return array(
            'hri_google_rating'                  => __('Google rating', 'hri-reviews-importer'),
            'hri_google_rating_formatted'        => __('Google rating (formatted)', 'hri-reviews-importer'),
            'hri_google_ratings_total'           => __('Number of Google ratings', 'hri-reviews-importer'),
            'hri_google_ratings_total_formatted' => __('Number of Google ratings (formatted)', 'hri-reviews-importer'),
        );
    }

    /**
     * Value of a tag. The plain tags keep the stored number (star elements, conditions),
     * the "_formatted" ones follow the locale of the site (display text).
     * Empty until the first Google import, so a placeholder is never shown to visitors.
     */
    private function bricks_tag_value($name)
    {
        if (! $this->has_google_rating()) return '';

        switch ($name) {
            case 'hri_google_rating':
                return number_format((float) get_option(self::OPTION_GOOGLE_RATING, 0), 1, '.', '');
            case 'hri_google_rating_formatted':
                return number_format_i18n((float) get_option(self::OPTION_GOOGLE_RATING, 0), 1);
            case 'hri_google_ratings_total':
                return (string) (int) get_option(self::OPTION_GOOGLE_RATINGS_TOTAL, 0);
            case 'hri_google_ratings_total_formatted':
                return number_format_i18n((int) get_option(self::OPTION_GOOGLE_RATINGS_TOTAL, 0));
        }

        return '';
    }

    public function bricks_add_tags($tags)
    {
        foreach ($this->bricks_tags() as $name => $label) {
            $tags[] = array(
                'name'  => '{' . $name . '}',
                'label' => $label,
                'group' => 'Reviews Importer',
            );
        }
        return $tags;
    }

    /**
     * Splits the inside of "{name:filter @key:'value'}" into the tag name and its fallback text.
     * Bricks hands the whole expression to the owner of the tag, so "@fallback" has to be handled here.
     * Filters (":plain") and the other keys are accepted and ignored: the values are plain numbers.
     *
     * @return array|null 'name' and 'fallback'; null when it is not a tag of this plugin.
     */
    private function bricks_parse_tag($expression)
    {
        if (! preg_match('/^(hri_google_[a-z_]+)((?::|\s+@).*)?$/s', $expression, $m)) return null;
        if (! array_key_exists($m[1], $this->bricks_tags())) return null;

        $fallback = '';
        if (isset($m[2]) && preg_match('/@fallback:(.*?)(?=\s+@[a-z_-]+:|$)/s', $m[2], $f)) {
            $fallback = trim($f[1]);
            // 'text' or "text": the quotes are not part of it
            if (preg_match('/^([\'"])(.*)\1$/s', $fallback, $q)) $fallback = $q[2];
        }

        return array('name' => $m[1], 'fallback' => $fallback);
    }

    /** Value of a parsed tag, or its fallback while there is no rating */
    private function bricks_resolve_tag($parsed)
    {
        $value = $this->bricks_tag_value($parsed['name']);
        return $value !== '' ? $value : $parsed['fallback'];
    }

    /** A single tag; the tags of other providers and non-string values pass through */
    public function bricks_render_tag($tag, $post, $context = 'text')
    {
        if (! is_string($tag)) return $tag;

        $parsed = $this->bricks_parse_tag(preg_replace('/^\{|\}$/', '', trim($tag)));
        if (! $parsed) return $tag;

        // A number would be read as an attachment ID there
        if ('image' === $context || 'media' === $context) return '';

        return $this->bricks_resolve_tag($parsed);
    }

    /** Tags inside a text / HTML content */
    public function bricks_render_content($content, $post = null, $context = 'text')
    {
        if (! is_string($content) || strpos($content, '{hri_google_') === false) return $content;

        $rendered = preg_replace_callback('/\{(hri_google_[^{}]*)\}/', function ($m) {
            $parsed = $this->bricks_parse_tag($m[1]);
            return $parsed ? esc_html($this->bricks_resolve_tag($parsed)) : $m[0];
        }, $content);

        return is_string($rendered) ? $rendered : $content;
    }

    /** Language helpers */
    private function get_default_locale()
    {
        return get_locale();
    }
    private function get_default_lang_short()
    {
        return $this->to_short_lang($this->get_default_locale());
    }
    private function get_imported_languages_short_array()
    {
        $raw = get_option(self::OPTION_IMPORTED_LANGUAGES, $this->get_default_lang_short());
        $raw = str_replace(array("\r\n", "\r", ","), "\n", (string)$raw);
        $parts = array_filter(array_map('trim', explode("\n", $raw)));
        $out = array();
        foreach ($parts as $p) {
            $s = $this->to_short_lang($p);
            if ($s) $out[] = $s;
        }
        $out = array_values(array_unique($out));
        if (empty($out)) $out[] = $this->get_default_lang_short();
        return $out;
    }
    private function to_short_lang($val)
    {
        $val = trim((string) $val);
        if ($val === '') return '';
        $val = str_replace(array(' ', '.'), '-', $val);
        $val = str_replace('_', '-', $val);
        $parts = explode('-', $val);
        $short = strtolower($parts[0]);
        $short = preg_replace('/[^a-z]/', '', $short);
        return $short ?: '';
    }

    public function hri_run_imports()
    {
        // The cron and "Import now" must not run at the same time: both would create the same new reviews
        require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
        if (! \WP_Upgrader::create_lock('hri_import', 15 * MINUTE_IN_SECONDS)) {
            throw new \RuntimeException(__('Another import is still running. Try again in a few minutes.', 'hri-reviews-importer'));
        }

        try {
            $this->run_imports();
        } finally {
            \WP_Upgrader::release_lock('hri_import');
        }
    }

    private function run_imports()
    {
        $langs = $this->get_imported_languages_short_array();
        $errors = array();
        $gbp = (get_option(self::OPTION_GOOGLE_API_VERSION, 'legacy') === 'gbp');

        // An undecryptable key would look like a missing one, and the import would finish without doing anything
        $failures = array_intersect_key($this->secret_failures(), array_flip($gbp
            ? array(self::OPTION_GBP_CLIENT_SECRET, self::OPTION_GBP_TOKEN)
            : array(self::OPTION_GOOGLE_PLACES_API_KEY)));
        if (!empty($failures)) {
            /* translators: %s: list of the stored secrets, e.g. "Google Places API key" */
            throw new \RuntimeException(sprintf(__('These stored values cannot be decrypted: %s. The encryption key in wp-config.php has changed, or the database comes from another site. Enter them again, or reconnect the Google account, on the settings page.', 'hri-reviews-importer'), implode(', ', $failures)));
        }

        $google_api_key = $this->get_secret(self::OPTION_GOOGLE_PLACES_API_KEY);
        $google_place_id = trim((string) get_option(self::OPTION_GOOGLE_PLACE_ID, ''));

        // Each language runs independently: one failure must not stop the rest.
        if ($gbp) {
            try {
                $this->hri_import_gbp_reviews();
            } catch (\Throwable $e) {
                $errors[] = 'Google (Business Profile): ' . $e->getMessage();
            }
        } elseif (!empty($google_api_key) && !empty($google_place_id) && !empty($langs)) {
            foreach ($langs as $lang) {
                try {
                    $this->hri_import_google_reviews($lang);
                } catch (\Throwable $e) {
                    $errors[] = 'Google (' . $lang . '): ' . $e->getMessage();
                }
            }
        }

        if (!empty($errors)) {
            throw new \RuntimeException(implode(' | ', $errors));
        }
    }

    /**
     * GET a Google endpoint and return the decoded JSON body.
     *
     * @param string $url
     * @param array  $headers Extra request headers.
     * @return array
     * @throws \RuntimeException On transport errors, non-200 responses or invalid JSON.
     */
    private function google_get_json($url, $headers = array())
    {
        $res = wp_remote_get($url, array(
            'headers' => array_merge(array('Referer' => get_site_url()), $headers),
            'timeout' => 20,
        ));
        if (is_wp_error($res)) {
            /* translators: %s: error message */
            throw new \RuntimeException(sprintf(__('HTTP error: %s', 'hri-reviews-importer'), $res->get_error_message()));
        }

        $code = wp_remote_retrieve_response_code($res);
        $data = json_decode(wp_remote_retrieve_body($res), true);

        // Places API (New) error format
        if (is_array($data) && !empty($data['error']['message'])) {
            /* translators: 1: error message, 2: status */
            throw new \RuntimeException(sprintf(__('API error: %1$s, Status: %2$s', 'hri-reviews-importer'), $data['error']['message'], $data['error']['status'] ?? $code));
        }
        if ($code !== 200 || !is_array($data)) {
            /* translators: %s: HTTP response code */
            throw new \RuntimeException(sprintf(__('Bad response (%s)', 'hri-reviews-importer'), $code));
        }

        return $data;
    }

    private function build_google_review($author_url, $time, $text, $author_name, $profile_photo_url, $rating, $review_id = null)
    {
        $time = (int) $time;

        return array(
            'review_id'            => ($review_id !== null) ? $review_id : md5(trim((string) $author_url) . $time),
            'text'                 => trim((string) $text),
            'author_name'          => trim((string) $author_name),
            'review_time'          => $time,
            'review_timestamp'     => date("Y-m-d H:i:s", $time),
            'review_timestamp_gmt' => gmdate("Y-m-d H:i:s", $time),
            'profile_photo_url'    => trim((string) $profile_photo_url),
            'rating'               => $rating,
        );
    }

    /** Places API (Legacy) – supports reviews_sort (newest / most_relevant) */
    private function fetch_google_place_legacy($language)
    {
        $google_api_key = $this->get_secret(self::OPTION_GOOGLE_PLACES_API_KEY);
        $google_place_id = trim((string) get_option(self::OPTION_GOOGLE_PLACE_ID, ''));
        $order = get_option(self::OPTION_IMPORT_ORDER, 'newest');

        $fields = array('formatted_address', 'icon', 'id', 'name', 'rating', 'reviews', 'url', 'user_ratings_total', 'vicinity');

        $url = 'https://maps.googleapis.com/maps/api/place/details/json'
            . '?placeid=' . rawurlencode($google_place_id)
            . '&key=' . rawurlencode($google_api_key)
            . '&fields=' . rawurlencode(implode(',', $fields))
            . '&reviews_sort=' . rawurlencode($order) // Last reviews first newest/most_relevant
            . '&reviews_no_translations=false'
            . (($language != NULL) ? '&language=' . rawurlencode($language) : '');

        $data = $this->google_get_json($url);

        $status = isset($data['status']) ? (string) $data['status'] : '';
        if ($status !== 'OK') {
            if (isset($data['error_message'])) {
                /* translators: 1: error message, 2: status */
                $msg = sprintf(__('API error: %1$s, Status: %2$s', 'hri-reviews-importer'), $data['error_message'], $status);
            } else {
                /* translators: %s: status */
                $msg = sprintf(__('API error, Status: %s', 'hri-reviews-importer'), $status);
            }
            throw new \RuntimeException($msg);
        }

        $result = (isset($data['result']) && is_array($data['result'])) ? $data['result'] : array();

        $reviews = array();
        foreach ((array) ($result['reviews'] ?? array()) as $value) {
            $reviews[] = $this->build_google_review(
                $value['author_url'] ?? '',
                $value['time'] ?? 0,
                $value['text'] ?? '',
                $value['author_name'] ?? '',
                $value['profile_photo_url'] ?? '',
                $value['rating'] ?? 0
            );
        }

        return array(
            'rating'        => $result['rating'] ?? null,
            'ratings_total' => $result['user_ratings_total'] ?? null,
            'reviews'       => $reviews,
        );
    }

    /** Places API (New) – max. 5 reviews, always sorted by relevance (no "newest" order) */
    private function fetch_google_place_new($language)
    {
        $google_api_key = $this->get_secret(self::OPTION_GOOGLE_PLACES_API_KEY);
        $google_place_id = trim((string) get_option(self::OPTION_GOOGLE_PLACE_ID, ''));

        $url = 'https://places.googleapis.com/v1/places/' . rawurlencode($google_place_id)
            . (($language != NULL) ? '?languageCode=' . rawurlencode($language) : '');

        $data = $this->google_get_json($url, array(
            'X-Goog-Api-Key'   => $google_api_key,
            'X-Goog-FieldMask' => 'rating,userRatingCount,reviews',
        ));

        $reviews = array();
        foreach ((array) ($data['reviews'] ?? array()) as $value) {
            $time = isset($value['publishTime']) ? strtotime((string) $value['publishTime']) : false;
            if (!$time) {
                continue;
            }

            // Same author URL form as the Legacy API, so review_id stays identical for already imported reviews
            $author_url = (string) ($value['authorAttribution']['uri'] ?? '');
            if (preg_match('#/maps/contrib/(\d+)#', $author_url, $m)) {
                $author_url = 'https://www.google.com/maps/contrib/' . $m[1] . '/reviews';
            }

            $text = (string) ($value['text']['text'] ?? '');
            if (trim($text) === '') {
                $text = (string) ($value['originalText']['text'] ?? '');
            }

            $reviews[] = $this->build_google_review(
                $author_url,
                $time,
                $text,
                $value['authorAttribution']['displayName'] ?? '',
                $value['authorAttribution']['photoUri'] ?? '',
                $value['rating'] ?? 0
            );
        }

        return array(
            'rating'        => $data['rating'] ?? null,
            'ratings_total' => $data['userRatingCount'] ?? null,
            'reviews'       => $reviews,
        );
    }

    public function hri_import_google_reviews($language)
    {
        $api_version = get_option(self::OPTION_GOOGLE_API_VERSION, 'legacy');

        $place = ($api_version === 'new')
            ? $this->fetch_google_place_new($language)
            : $this->fetch_google_place_legacy($language);

        if ($place['rating'] !== null) {
            $new_rating = number_format((float) $place['rating'], 1, '.', '');
            update_option(self::OPTION_GOOGLE_RATING, $new_rating);
        }

        if ($place['ratings_total'] !== null) {
            $new_rating = floatval($place['ratings_total']);

            update_option(self::OPTION_GOOGLE_RATINGS_TOTAL, $new_rating);
        }

        // No reviews for this language is not an error: nothing to import.
        $this->save_google_reviews($place['reviews'], $language);
    }

    /**
     * Create/update Google review posts.
     *
     * @param array  $reviews  Normalized reviews (see build_google_review()).
     * @param string $language Language field that receives the review text.
     * @return array Counts: 'new' (created posts), 'existing' (already imported posts).
     */
    private function save_google_reviews($reviews, $language)
    {
        $skip_empty = (bool) get_option(self::OPTION_SKIP_EMPTY_REVIEWS, false);
        $min_rating = (int) get_option(self::OPTION_MIN_REVIEW_RATING, 4);
        $counts = array('new' => 0, 'existing' => 0);

        foreach ($reviews as $review) {
            if ($skip_empty && $review['text'] === '') {
                continue; // skip this review
            }

            $existing = get_posts([
                'post_type' => 'reviews',
                'post_status' => 'any',
                'meta_query' => [[
                    'key'   => 'review_id',
                    'value' => $review['review_id'],
                ]],
                'fields' => 'ids',
                'numberposts' => 1
            ]);

            if (!$existing) {
                $post_status = ($review['rating'] >= $min_rating) ? 'publish' : 'draft';

                $post_id = wp_insert_post([
                    'post_type'    => 'reviews',
                    'post_title'   => sanitize_text_field($review['author_name']),
                    'post_status'  => $post_status,
                    'post_author'  => 0, // not the user who clicked "Import now"
                    'post_date' => $review['review_timestamp'],
                    'post_date_gmt' => $review['review_timestamp_gmt']
                ]);
                if ($post_id) {
                    $counts['new']++;
                }
            } else {
                //update existing review
                $post_id =  $existing[0];
                $counts['existing']++;
            }
            if ($post_id) {
                update_post_meta($post_id, 'review_number', $review['rating']);
                update_post_meta($post_id, 'review_timestamp', $review['review_timestamp']);
                update_post_meta($post_id, 'review_source', 'Google');
                update_post_meta($post_id, 'review_id', $review['review_id']);
                // Plain text: a link or an image typed into a review must not become HTML
                update_post_meta($post_id, 'review_' . $language, wp_kses($review['text'], array()));
                update_post_meta($post_id, 'profile_photo_url', esc_url_raw($review['profile_photo_url']));
            }
        }

        return $counts;
    }

    /* ---------- Google Business Profile API (OAuth) ---------- */

    private function gbp_redirect_uri()
    {
        return admin_url('admin-post.php?action=hri_gbp_oauth_callback');
    }

    private function gbp_redirect_back($status)
    {
        wp_redirect(add_query_arg('hri_gbp', $status, admin_url('options-general.php?page=hri-review-import-settings')));
        exit;
    }

    /**
     * POST to Google's OAuth token endpoint (code exchange / refresh).
     *
     * @param array $params Grant specific parameters.
     * @return array Decoded token response.
     * @throws \RuntimeException
     */
    private function gbp_token_request($params)
    {
        $res = wp_remote_post('https://oauth2.googleapis.com/token', array(
            'body'    => array_merge(array(
                'client_id'     => trim((string) get_option(self::OPTION_GBP_CLIENT_ID, '')),
                'client_secret' => $this->get_secret(self::OPTION_GBP_CLIENT_SECRET),
            ), $params),
            'timeout' => 20,
        ));
        if (is_wp_error($res)) {
            /* translators: %s: error message */
            throw new \RuntimeException(sprintf(__('HTTP error: %s', 'hri-reviews-importer'), $res->get_error_message()));
        }

        $code = wp_remote_retrieve_response_code($res);
        $data = json_decode(wp_remote_retrieve_body($res), true);

        if ($code !== 200 || !is_array($data) || empty($data['access_token'])) {
            $detail = '';
            if (is_array($data)) {
                $detail = trim((is_string($data['error'] ?? null) ? $data['error'] : '') . ' ' . (is_string($data['error_description'] ?? null) ? $data['error_description'] : ''));
            }
            if ($detail !== '') {
                /* translators: 1: HTTP response code, 2: error details */
                throw new \RuntimeException(sprintf(__('OAuth token error (%1$s): %2$s', 'hri-reviews-importer'), $code, $detail));
            }
            /* translators: %s: HTTP response code */
            throw new \RuntimeException(sprintf(__('OAuth token error (%s)', 'hri-reviews-importer'), $code));
        }

        return $data;
    }

    /**
     * Keep a token response: the refresh token is stored encrypted, the access token only in memory.
     *
     * @throws \RuntimeException When there is no encryption key.
     */
    private function gbp_store_token($data)
    {
        // A refresh response has no refresh_token: the stored one stays.
        if (!empty($data['refresh_token'])) {
            update_option(self::OPTION_GBP_TOKEN, array('refresh_token' => hri_encrypt_secret((string) $data['refresh_token'])), false);
        }
        $this->gbp_access = array(
            'token'      => (string) $data['access_token'],
            'expires_at' => time() + (int) ($data['expires_in'] ?? 3600),
        );
    }

    private function gbp_get_access_token()
    {
        if ($this->gbp_access && $this->gbp_access['expires_at'] - 60 > time()) {
            return $this->gbp_access['token'];
        }

        if (isset($this->secret_failures()[self::OPTION_GBP_TOKEN])) {
            throw new \RuntimeException(__('The stored Google account connection cannot be decrypted: the encryption key in wp-config.php has changed, or the database comes from another site. Reconnect the Google account on the settings page.', 'hri-reviews-importer'));
        }
        $refresh_token = $this->gbp_refresh_token();
        if ($refresh_token === '') {
            throw new \RuntimeException(__('Not connected. Connect a Google account on the settings page.', 'hri-reviews-importer'));
        }

        $data = $this->gbp_token_request(array(
            'grant_type'    => 'refresh_token',
            'refresh_token' => $refresh_token,
        ));
        $this->gbp_store_token($data);

        return $this->gbp_access['token'];
    }

    private function gbp_get_json($url, $language = '')
    {
        $headers = array('Authorization' => 'Bearer ' . $this->gbp_get_access_token());
        if ($language !== '') {
            $headers['Accept-Language'] = $language;
        }
        return $this->google_get_json($url, $headers);
    }

    /** Collect a list that Google returns page by page under $key. */
    private function gbp_get_paged($url, $key, $max_pages = 10)
    {
        $items = array();
        $page_token = '';
        for ($page = 0; $page < $max_pages; $page++) {
            $data = $this->gbp_get_json($page_token !== '' ? add_query_arg('pageToken', rawurlencode($page_token), $url) : $url);
            foreach ((array) ($data[$key] ?? array()) as $item) {
                $items[] = $item;
            }
            $page_token = (string) ($data['nextPageToken'] ?? '');
            if ($page_token === '') {
                break;
            }
        }
        return $items;
    }

    /**
     * Comments written in another language than the requested one come with a translation, as
     * "(Translated by Google) …\n\n(Original)\n…" or "…\n\n(Translated by Google)\n…": keep the
     * translation only. The labels are localized, so they are matched by shape, not by wording.
     */
    private function gbp_comment_text($comment)
    {
        $comment = trim(str_replace(array("\r\n", "\r"), "\n", (string) $comment));
        // "Googl": the name is inflected in some languages ("Preložené Googlom")
        $label = '\([^()\n]*Googl[^()\n]*\)';

        // Translation first: it ends at the next label line ("(Original)"), if there is one.
        if (preg_match('/^' . $label . '\s*(.*)$/su', $comment, $m)) {
            $parts = array_map('trim', preg_split('/(?:^|\n)[ \t]*\([^()\n]+\)[ \t]*\n/u', $m[1], 2));
            return ($parts[0] !== '' || !isset($parts[1])) ? $parts[0] : $parts[1];
        }
        // Original first: the translation follows its own label line.
        if (preg_match('/\n[ \t]*' . $label . '[ \t]*\n\s*(\S.*)$/su', $comment, $m)) {
            return trim($m[1]);
        }
        return $comment;
    }

    public function hri_import_gbp_reviews()
    {
        $location = trim((string) get_option(self::OPTION_GBP_LOCATION, ''), " \t\n\r\0\x0B/");
        if (!preg_match('#^accounts/[^/]+/locations/[^/]+$#', $location)) {
            throw new \RuntimeException(__('Missing or invalid location. Expected format: accounts/{accountId}/locations/{locationId}', 'hri-reviews-importer'));
        }

        // The API has no language parameter, the language is requested with the Accept-Language
        // header: every language gets its own requests, with the other reviews translated into it.
        $langs = $this->get_imported_languages_short_array();
        $stars = array('ONE' => 1, 'TWO' => 2, 'THREE' => 3, 'FOUR' => 4, 'FIVE' => 5);

        $base = 'https://mybusiness.googleapis.com/v4/' . $location . '/reviews?pageSize=50&orderBy=' . rawurlencode('updateTime desc');

        // Until a run got through all pages, never stop early: after an interrupted first run
        // (or a changed location / language list) the older reviews would never be fetched.
        $signature = md5($location . '|' . implode(',', $langs) . '|' . self::GBP_SYNC_VERSION);
        $full_sync = get_option(self::OPTION_GBP_SYNCED, '') !== $signature;
        $max_pages = self::GBP_MAX_PAGES;

        foreach ($langs as $i => $lang) {
            $page_token = '';

            for ($page = 0; $page < $max_pages; $page++) {
                $data = $this->gbp_get_json($page_token !== '' ? $base . '&pageToken=' . rawurlencode($page_token) : $base, $lang);

                if ($i === 0 && $page === 0) {
                    if (isset($data['averageRating'])) {
                        update_option(self::OPTION_GOOGLE_RATING, number_format((float) $data['averageRating'], 1, '.', ''));
                    }
                    if (isset($data['totalReviewCount'])) {
                        update_option(self::OPTION_GOOGLE_RATINGS_TOTAL, floatval($data['totalReviewCount']));
                    }
                }

                $reviews = array();
                foreach ((array) ($data['reviews'] ?? array()) as $value) {
                    $time = isset($value['createTime']) ? strtotime((string) $value['createTime']) : false;
                    if (empty($value['reviewId']) || !$time) {
                        continue;
                    }
                    $reviews[] = $this->build_google_review(
                        '',
                        $time,
                        $this->gbp_comment_text($value['comment'] ?? ''),
                        $value['reviewer']['displayName'] ?? '',
                        $value['reviewer']['profilePhotoUrl'] ?? '',
                        $stars[$value['starRating'] ?? ''] ?? 0,
                        'gbp:' . $value['reviewId']
                    );
                }

                $counts = $this->save_google_reviews($reviews, $lang);

                $page_token = (string) ($data['nextPageToken'] ?? '');
                if ($page_token === '') {
                    break;
                }
                // Newest first: the first language stops at the first page that only has already
                // imported reviews, the other languages then fetch the same number of pages.
                if (!$full_sync && $i === 0 && $counts['new'] === 0 && $counts['existing'] > 0) {
                    $max_pages = $page + 1;
                }
            }
        }

        if ($full_sync) {
            update_option(self::OPTION_GBP_SYNCED, $signature, false);
        }
    }

    public function handle_gbp_connect()
    {
        if (! current_user_can('manage_options')) {
            wp_die(esc_html__('Insufficient permissions.', 'hri-reviews-importer'));
        }
        check_admin_referer('hri_gbp_connect', 'hri_gbp_connect_field');

        $client_id = trim((string) get_option(self::OPTION_GBP_CLIENT_ID, ''));
        if ($client_id === '') {
            $this->log_error(__('Missing OAuth client ID.', 'hri-reviews-importer'), array('where' => 'handle_gbp_connect'));
            $this->gbp_redirect_back('error');
        }
        // The refresh token could not be stored after the consent screen
        if (hri_secret_key() === '') {
            $this->log_error(__('No encryption key: wp-config.php has no AUTH_KEY and SECURE_AUTH_KEY. Keys and tokens cannot be saved until you define HRI_ENCRYPTION_KEY there (a random string of at least 32 characters).', 'hri-reviews-importer'), array('where' => 'handle_gbp_connect'));
            $this->gbp_redirect_back('error');
        }

        wp_redirect('https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query(array(
            'client_id'     => $client_id,
            'redirect_uri'  => $this->gbp_redirect_uri(),
            'response_type' => 'code',
            'scope'         => 'https://www.googleapis.com/auth/business.manage',
            'access_type'   => 'offline',
            'prompt'        => 'consent', // always returns a refresh token
            'state'         => wp_create_nonce('hri_gbp_oauth'),
        )));
        exit;
    }

    public function handle_gbp_oauth_callback()
    {
        if (! current_user_can('manage_options')) {
            wp_die(esc_html__('Insufficient permissions.', 'hri-reviews-importer'));
        }

        try {
            $state = isset($_GET['state']) ? sanitize_text_field(wp_unslash($_GET['state'])) : '';
            if (! wp_verify_nonce($state, 'hri_gbp_oauth')) {
                throw new \RuntimeException(__('Invalid OAuth state. Start the connection again.', 'hri-reviews-importer'));
            }
            if (! empty($_GET['error'])) {
                /* translators: %s: error code returned by Google */
                throw new \RuntimeException(sprintf(__('Google OAuth error: %s', 'hri-reviews-importer'), sanitize_text_field(wp_unslash($_GET['error']))));
            }
            $code = isset($_GET['code']) ? sanitize_text_field(wp_unslash($_GET['code'])) : '';
            if ($code === '') {
                throw new \RuntimeException(__('Missing authorization code.', 'hri-reviews-importer'));
            }

            $data = $this->gbp_token_request(array(
                'grant_type'   => 'authorization_code',
                'code'         => $code,
                'redirect_uri' => $this->gbp_redirect_uri(),
            ));
            if (empty($data['refresh_token'])) {
                throw new \RuntimeException(__('Google did not return a refresh token.', 'hri-reviews-importer'));
            }
            $this->gbp_store_token($data);
            $this->clear_last_error();
            // Best effort: the connection stands even if the locations cannot be listed ("Fetch locations" retries it)
            try {
                $this->gbp_fetch_locations();
            } catch (\Throwable $e) {
                $this->log_error($e->getMessage(), array('where' => 'gbp_fetch_locations'));
            }
            $status = 'connected';
        } catch (\Throwable $e) {
            $this->log_error($e->getMessage(), array('where' => 'handle_gbp_oauth_callback'));
            $status = 'error';
        }

        $this->gbp_redirect_back($status);
    }

    public function handle_gbp_disconnect()
    {
        if (! current_user_can('manage_options')) {
            wp_die(esc_html__('Insufficient permissions.', 'hri-reviews-importer'));
        }
        check_admin_referer('hri_gbp_disconnect', 'hri_gbp_disconnect_field');

        $refresh_token = $this->gbp_refresh_token();
        if ($refresh_token !== '') {
            // Best effort: the local token is removed even if the revoke call fails.
            wp_remote_post('https://oauth2.googleapis.com/revoke', array(
                'body'    => array('token' => $refresh_token),
                'timeout' => 10,
            ));
        }
        delete_option(self::OPTION_GBP_TOKEN);
        delete_option(self::OPTION_GBP_LOCATIONS);

        $this->gbp_redirect_back('disconnected');
    }

    /**
     * Store the locations of every account the connected Google user has access to.
     *
     * @throws \RuntimeException When the request fails or there is no location.
     */
    private function gbp_fetch_locations()
    {
        $locations = array();
        $accounts = $this->gbp_get_paged('https://mybusinessaccountmanagement.googleapis.com/v1/accounts', 'accounts');
        foreach ($accounts as $account) {
            if (empty($account['name'])) {
                continue;
            }
            $url = 'https://mybusinessbusinessinformation.googleapis.com/v1/' . $account['name'] . '/locations?readMask=name,title&pageSize=100';
            foreach ($this->gbp_get_paged($url, 'locations') as $location) {
                if (empty($location['name'])) {
                    continue;
                }
                $locations[] = array(
                    'name'  => $account['name'] . '/' . $location['name'],
                    'title' => (string) ($location['title'] ?? ''),
                );
            }
        }
        if (empty($locations)) {
            throw new \RuntimeException(__('No Business Profile locations found for this Google account.', 'hri-reviews-importer'));
        }

        update_option(self::OPTION_GBP_LOCATIONS, $locations, false);
        if (count($locations) === 1 && trim((string) get_option(self::OPTION_GBP_LOCATION, '')) === '') {
            update_option(self::OPTION_GBP_LOCATION, $locations[0]['name']);
        }
    }

    public function handle_gbp_fetch_locations()
    {
        if (! current_user_can('manage_options')) {
            wp_die(esc_html__('Insufficient permissions.', 'hri-reviews-importer'));
        }
        check_admin_referer('hri_gbp_fetch_locations', 'hri_gbp_fetch_locations_field');

        try {
            $this->gbp_fetch_locations();
            $this->clear_last_error();
            $status = 'locations';
        } catch (\Throwable $e) {
            $this->log_error($e->getMessage(), array('where' => 'handle_gbp_fetch_locations'));
            $status = 'error';
        }

        $this->gbp_redirect_back($status);
    }

    /** Plugin folder name; WordPress identifies the plugin by this on the update screens */
    private function update_slug()
    {
        $slug = dirname(plugin_basename(__FILE__));
        return ('.' === $slug) ? basename(__FILE__, '.php') : $slug;
    }

    /**
     * Newest release of the GitHub repository (unauthenticated API call, cached).
     *
     * @return array|null version, package, url, body, published – or null if there is none or GitHub cannot be reached.
     */
    private function get_latest_release()
    {
        $prereleases = (bool) get_option(self::OPTION_UPDATE_PRERELEASES, false);
        $cache_key   = 'hri_update_release_' . ($prereleases ? 'pre' : 'stable');

        // "Check again" on the Updates screen has to see a release published a minute ago
        $cached = empty($_GET['force-check']) ? get_transient($cache_key) : false;
        if (is_array($cached)) {
            return ! empty($cached) ? $cached : null;
        }

        $res = wp_remote_get('https://api.github.com/repos/' . self::GITHUB_REPO . '/releases?per_page=10', array(
            'headers' => array('Accept' => 'application/vnd.github+json'),
            'timeout' => 15,
        ));
        $data = (is_wp_error($res) || wp_remote_retrieve_response_code($res) !== 200)
            ? null
            : json_decode(wp_remote_retrieve_body($res), true);

        if (! is_array($data)) {
            // Unreachable or rate limited (60 requests/hour per IP): do not retry on every admin page load
            set_transient($cache_key, array(), 30 * MINUTE_IN_SECONDS);
            return null;
        }

        $release = null;
        foreach ($data as $item) {
            if (! is_array($item) || empty($item['tag_name']) || ! empty($item['draft'])) {
                continue;
            }
            if (! $prereleases && ! empty($item['prerelease'])) {
                continue;
            }

            $version = ltrim((string) $item['tag_name'], 'vV');
            if (! preg_match('/^\d+(\.\d+){1,3}(-?[A-Za-z][0-9A-Za-z.-]*)?$/', $version) || ($release && version_compare($version, $release['version'], '<='))) {
                continue;
            }

            // The zip attached by the release workflow has the right folder name; the tag archive does not (see fix_update_folder).
            // Only that zip is accepted, no other asset of the release.
            $package    = 'https://github.com/' . self::GITHUB_REPO . '/archive/refs/tags/' . rawurlencode((string) $item['tag_name']) . '.zip';
            $asset_name = basename(self::GITHUB_REPO) . '-' . $version . '.zip';
            $asset_base = 'https://github.com/' . self::GITHUB_REPO . '/releases/download/';
            foreach ((array) ($item['assets'] ?? array()) as $asset) {
                $asset_url = (string) ($asset['browser_download_url'] ?? '');
                if ((string) ($asset['name'] ?? '') === $asset_name && strpos($asset_url, $asset_base) === 0) {
                    $package = $asset_url;
                    break;
                }
            }

            $release = array(
                'version'   => $version,
                'package'   => $package,
                'url'       => (string) ($item['html_url'] ?? 'https://github.com/' . self::GITHUB_REPO . '/releases'),
                'body'      => (string) ($item['body'] ?? ''),
                'published' => (string) ($item['published_at'] ?? ''),
            );
        }

        set_transient($cache_key, $release ? $release : array(), HOUR_IN_SECONDS);

        return $release;
    }

    /** Update check: WordPress compares the returned version with the installed one */
    public function check_for_update($update, $plugin_data, $plugin_file)
    {
        // The hook is shared by every plugin whose Update URI points to github.com
        if ($plugin_file !== plugin_basename(__FILE__)) {
            return $update;
        }

        $release = $this->get_latest_release();
        if (! $release) {
            return $update;
        }

        return array(
            'slug'    => $this->update_slug(),
            'version' => $release['version'],
            'url'     => 'https://github.com/' . self::GITHUB_REPO,
            'package' => $release['package'],
        );
    }

    /** "View details" popup on the Plugins and Updates screens */
    public function plugin_info($result, $action, $args)
    {
        if ('plugin_information' !== $action || empty($args->slug) || $args->slug !== $this->update_slug()) {
            return $result;
        }

        $release = $this->get_latest_release();
        if (! $release) {
            return $result;
        }

        $header    = get_file_data(__FILE__, array('name' => 'Plugin Name', 'author' => 'Author'));
        $changelog = trim($release['body']) !== ''
            ? wpautop(esc_html($release['body']))
            : sprintf('<p><a href="%1$s" target="_blank">%1$s</a></p>', esc_url($release['url']));

        return (object) array(
            'name'          => $header['name'],
            'slug'          => $this->update_slug(),
            'version'       => $release['version'],
            'author'        => $header['author'],
            'homepage'      => 'https://github.com/' . self::GITHUB_REPO,
            'download_link' => $release['package'],
            'last_updated'  => $release['published'],
            'external'      => true, // not hosted on wordpress.org
            'sections'      => array('changelog' => $changelog),
        );
    }

    /**
     * A GitHub tag archive unpacks to "{repo}-{version}"; WordPress would install it under that name
     * and the plugin would end up deactivated. Rename it to the installed folder name.
     */
    public function fix_update_folder($source, $remote_source, $upgrader, $hook_extra = array())
    {
        global $wp_filesystem;

        if (is_wp_error($source) || empty($hook_extra['plugin']) || $hook_extra['plugin'] !== plugin_basename(__FILE__)) {
            return $source;
        }

        $folder = dirname(plugin_basename(__FILE__));
        if ('.' === $folder || basename(untrailingslashit($source)) === $folder || untrailingslashit($source) === untrailingslashit($remote_source)) {
            return $source;
        }

        $target = trailingslashit($remote_source) . $folder;
        if (! $wp_filesystem || ! $wp_filesystem->move(untrailingslashit($source), $target, true)) {
            return new \WP_Error('hri_update_folder', __('Could not rename the folder of the downloaded update.', 'hri-reviews-importer'));
        }

        return trailingslashit($target);
    }

    private function log_error($message, $context = array())
    {
        $payload = array(
            'message' => is_string($message) ? $message : print_r($message, true),
            'time'    => current_time('mysql'),
            'context' => ! empty($context) ? $context : null,
        );
        update_option(self::OPTION_LAST_ERROR, $payload);
    }

    private function clear_last_error()
    {
        delete_option(self::OPTION_LAST_ERROR);
    }
}

new HRI_Review_Importer();
