<?php

/**
 * Plugin Name: Headless Google/Facebook Reviews Importer
 * Description: Settings page (Facebook/Google keys, import frequency, imported languages, minimum rating), "Import now" button, last run display + "reviews" custom post type with meta fields and list columns.
 * Version:     1.3.0
 * Author:      Molnár Dávid
 * License:     GPLv2 or later
 * Update URI:  https://github.com/MDylan/headless-reviews-importer
 * Text Domain: hri-reviews-importer
 * Domain Path: /languages
 */

if (! defined('ABSPATH')) exit;

class HRI_Review_Importer
{
    // Option keys
    const OPTION_FACEBOOK_GRAPH_API_KEY = 'facebook_graph_api_key';
    const OPTION_FACEBOOK_PAGE_ID       = 'facebook_page_id';
    const OPTION_GOOGLE_PLACES_API_KEY  = 'google_places_api_key';
    const OPTION_GOOGLE_PLACE_ID        = 'google_place_id';
    const OPTION_CRON_TIME              = 'hri_import_cron_time';
    const OPTION_IMPORTED_LANGUAGES     = 'imported_languages';
    const OPTION_MIN_REVIEW_RATING      = 'hri_min_review_rating';
    const OPTION_LAST_RUN               = 'hri_import_last_run';
    const OPTION_SKIP_EMPTY_REVIEWS = 'hri_skip_empty_comments';
    const OPTION_GOOGLE_RATING =  'hri_google_rating';
    const OPTION_GOOGLE_RATINGS_TOTAL = 'hri_user_ratings_total';
    const OPTION_FACEBOOK_RATING = 'hri_facebook_rating';
    const OPTION_FACEBOOK_RATINGS_TOTAL = 'hri_facebook_ratings_total';
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

    // Updates come from the releases of this public GitHub repository (see the "Update URI" header)
    const GITHUB_REPO = 'MDylan/headless-reviews-importer';

    // Business Profile API: max. review pages (50 reviews each) fetched per run
    const GBP_MAX_PAGES = 20;
    // Bump to make every site fetch all pages again (e.g. after changing how comments are parsed)
    const GBP_SYNC_VERSION = 1;


    // Cron hook
    const CRON_HOOK = 'hri_import_cron_event';

    public function __construct()
    {
        // I18n
        add_action('plugins_loaded', array($this, 'load_textdomain'));

        // Admin UI
        add_action('admin_menu', array($this, 'add_menu'));
        add_action('admin_init', array($this, 'register_settings'));
        add_filter('plugin_action_links_' . plugin_basename(__FILE__), array($this, 'add_settings_link'));

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

        add_action('hri_run_import', array($this, 'hri_run_imports'));

        add_action('admin_post_hri_test_fb_connection', array($this, 'handle_test_fb_connection'));

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
        $this->hri_set_option_autoload(self::OPTION_FACEBOOK_RATING, '5.0', $forcefalse ? false : true);
        $this->hri_set_option_autoload(self::OPTION_FACEBOOK_RATINGS_TOTAL, '1', $forcefalse ? false : true);

        // Only on admin/import – autoload=NO
        $this->hri_set_option_autoload(self::OPTION_FACEBOOK_GRAPH_API_KEY,   '', false);
        $this->hri_set_option_autoload(self::OPTION_FACEBOOK_PAGE_ID,   '', false);
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
        add_options_page(
            esc_html__('Reviews Import Settings', 'hri-reviews-importer'),
            esc_html__('Reviews Import Settings', 'hri-reviews-importer'),
            'manage_options',
            'hri-review-import-settings',
            array($this, 'render_settings_page')
        );
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

    public function register_settings()
    {
        register_setting('hri_settings_group', self::OPTION_FACEBOOK_GRAPH_API_KEY, array(
            'type'              => 'string',
            'sanitize_callback' => array($this, 'sanitize_text'),
            'default'           => '',
        ));
        register_setting('hri_settings_group', self::OPTION_FACEBOOK_PAGE_ID, array(
            'type'              => 'string',
            'sanitize_callback' => array($this, 'sanitize_text'),
            'default'           => '',
        ));
        register_setting('hri_settings_group', self::OPTION_GOOGLE_PLACES_API_KEY, array(
            'type'              => 'string',
            'sanitize_callback' => array($this, 'sanitize_text'),
            'default'           => '',
        ));
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
        register_setting('hri_settings_group', self::OPTION_GBP_CLIENT_SECRET, array(
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

        add_settings_section(
            'hri_settings_section',
            esc_html__('Import & API settings', 'hri-reviews-importer'),
            function () {
                echo '<p>' . esc_html__('Provide API keys, import schedule, and imported languages.', 'hri-reviews-importer') . '</p>';
                echo '<p><strong>' . esc_html__('Languages note:', 'hri-reviews-importer') . '</strong> ' .
                    esc_html__('from formats like "hu_HU", "en_US" only the short code is used ("hu", "en"). On save we normalize to the short code. Field cannot be empty and the site default language is always ensured.', 'hri-reviews-importer') .
                    '</p>';
                printf(
                    '<p>%s <a href="%s" target="_blank" rel="noopener">%s</a></p>',
                    esc_html__('See:', 'hri-reviews-importer'),
                    esc_url('https://developers.google.com/maps/faq#languagesupport'),
                    esc_html__('Google Maps – Language support', 'hri-reviews-importer')
                );

                // --- Added note blocks ---
                echo '<p><strong>' . esc_html__('How to get the required Google Places API Key:', 'hri-reviews-importer') . '</strong></p>';
                printf(
                    '<ul style="margin-top:0;"><li>%s <a href="%s" target="_blank" rel="noopener">%s</a></li><li>%s</li></ul>',
                    esc_html__('Use:', 'hri-reviews-importer'),
                    esc_url('https://developers.google.com/maps/documentation/places/web-service/get-api-key'),
                    esc_html__('Get an API key (Places API).', 'hri-reviews-importer'),
                    esc_html__('Then follow the explained steps.', 'hri-reviews-importer')
                );
                echo '<p>' . esc_html__('Enable the API that matches the "Google Places API version" setting below. Places API (Legacy) can no longer be enabled in new Google Cloud projects; Places API (New) returns at most 5 reviews sorted by relevance and cannot return the newest ones.', 'hri-reviews-importer') . '</p>';

                echo '<p><strong>' . esc_html__('How to find the required Place ID:', 'hri-reviews-importer') . '</strong></p>';
                printf(
                    '<ul style="margin-top:0;"><li>%s <a href="%s" target="_blank" rel="noopener">%s</a></li><li>%s</li></ul>',
                    esc_html__('Use:', 'hri-reviews-importer'),
                    esc_url('https://developers.google.com/maps/documentation/places/web-service/place-id'),
                    esc_html__('Place IDs documentation', 'hri-reviews-importer'),
                    esc_html__('Search for the desired business name.', 'hri-reviews-importer')
                );
                // --- /Added note blocks ---
            },
            'hri-review-import-settings'
        );


        $this->add_text_field(self::OPTION_FACEBOOK_GRAPH_API_KEY, __('Facebook Graph API Key', 'hri-reviews-importer'), 'hri-review-import-settings');
        $this->add_text_field(self::OPTION_FACEBOOK_PAGE_ID, __('Facebook Page ID', 'hri-reviews-importer'), 'hri-review-import-settings');
        $this->add_text_field(self::OPTION_GOOGLE_PLACES_API_KEY, __('Google Places API key', 'hri-reviews-importer'), 'hri-review-import-settings', __('Paste your Places API key here.', 'hri-reviews-importer'));
        $this->add_text_field(self::OPTION_GOOGLE_PLACE_ID, __('Google Place ID', 'hri-reviews-importer'), 'hri-review-import-settings', __('Your Google Place ID', 'hri-reviews-importer'));

        add_settings_field(
            self::OPTION_GOOGLE_API_VERSION,
            esc_html__('Google Places API version', 'hri-reviews-importer'),
            array($this, 'render_google_api_version_select'),
            'hri-review-import-settings',
            'hri_settings_section',
            array(
                'description' => esc_html__('Use "Places API (New)" if your Google Cloud project cannot enable the Legacy API. "Business Profile API" imports all reviews (newest first) of a profile you manage; it needs the OAuth connection below instead of the API key and Place ID.', 'hri-reviews-importer'),
            )
        );

        add_settings_field(
            self::OPTION_IMPORT_ORDER,
            esc_html__('Import order', 'hri-reviews-importer'),
            array($this, 'render_import_order_select'),
            'hri-review-import-settings',
            'hri_settings_section',
            array(
                'description' => esc_html__('Defines the order used when fetching reviews from Google Places. Only applies to the Legacy API; Places API (New) always returns the most relevant reviews.', 'hri-reviews-importer'),
            )
        );

        $this->add_text_field(self::OPTION_GBP_CLIENT_ID, __('Business Profile OAuth client ID', 'hri-reviews-importer'), 'hri-review-import-settings', __('Only for the Business Profile API.', 'hri-reviews-importer'));
        $this->add_text_field(self::OPTION_GBP_CLIENT_SECRET, __('Business Profile OAuth client secret', 'hri-reviews-importer'), 'hri-review-import-settings', __('Only for the Business Profile API.', 'hri-reviews-importer'));

        add_settings_field(
            self::OPTION_GBP_LOCATION,
            esc_html__('Business Profile location', 'hri-reviews-importer'),
            array($this, 'render_gbp_location_field'),
            'hri-review-import-settings',
            'hri_settings_section'
        );

        add_settings_field(
            self::OPTION_CRON_TIME,
            esc_html__('Import frequency', 'hri-reviews-importer'),
            array($this, 'render_cron_select'),
            'hri-review-import-settings',
            'hri_settings_section'
        );

        add_settings_field(
            self::OPTION_MIN_REVIEW_RATING,
            esc_html__('Minimum imported rating', 'hri-reviews-importer'),
            array($this, 'render_min_rating'),
            'hri-review-import-settings',
            'hri_settings_section'
        );

        add_settings_field(
            self::OPTION_IMPORTED_LANGUAGES,
            esc_html__('Imported languages (short codes)', 'hri-reviews-importer'),
            array($this, 'render_languages_textarea'),
            'hri-review-import-settings',
            'hri_settings_section'
        );

        add_settings_field(
            self::OPTION_SKIP_EMPTY_REVIEWS,
            esc_html__('Skip empty reviews', 'hri-reviews-importer'),
            array($this, 'render_checkbox_field'),
            'hri-review-import-settings',
            'hri_settings_section',
            array(
                'option' => self::OPTION_SKIP_EMPTY_REVIEWS,
                'label'  => esc_html__('If checked, reviews without a text comment will be skipped.', 'hri-reviews-importer'),
            )
        );

        add_settings_field(
            self::OPTION_UPDATE_PRERELEASES,
            esc_html__('Pre-release updates', 'hri-reviews-importer'),
            array($this, 'render_checkbox_field'),
            'hri-review-import-settings',
            'hri_settings_section',
            array(
                'option' => self::OPTION_UPDATE_PRERELEASES,
                'label'  => esc_html__('If checked, pre-releases published on GitHub are offered as plugin updates too.', 'hri-reviews-importer'),
            )
        );

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

    public function render_google_api_version_select($args)
    {
        $current = get_option(self::OPTION_GOOGLE_API_VERSION, 'legacy');

        echo '<select id="' . esc_attr(self::OPTION_GOOGLE_API_VERSION) . '" name="' . esc_attr(self::OPTION_GOOGLE_API_VERSION) . '">';
        printf(
            '<option value="legacy"%s>%s</option>',
            selected($current, 'legacy', false),
            esc_html__('Places API (Legacy)', 'hri-reviews-importer')
        );
        printf(
            '<option value="new"%s>%s</option>',
            selected($current, 'new', false),
            esc_html__('Places API (New)', 'hri-reviews-importer')
        );
        printf(
            '<option value="gbp"%s>%s</option>',
            selected($current, 'gbp', false),
            esc_html__('Business Profile API (OAuth)', 'hri-reviews-importer')
        );
        echo '</select>';

        if (! empty($args['description'])) {
            echo '<p class="description">' . esc_html($args['description']) . '</p>';
        }
    }

    public function sanitize_google_api_version($value)
    {
        $value = is_string($value) ? strtolower(trim($value)) : 'legacy';
        return in_array($value, array('legacy', 'new', 'gbp'), true) ? $value : 'legacy';
    }

    public function render_gbp_location_field()
    {
        $value     = get_option(self::OPTION_GBP_LOCATION, '');
        $locations = get_option(self::OPTION_GBP_LOCATIONS, array());

        printf(
            '<input type="text" class="regular-text" id="%1$s" name="%1$s" value="%2$s" list="%1$s_list" placeholder="accounts/123/locations/456" />',
            esc_attr(self::OPTION_GBP_LOCATION),
            esc_attr($value)
        );
        if (is_array($locations) && ! empty($locations)) {
            echo '<datalist id="' . esc_attr(self::OPTION_GBP_LOCATION) . '_list">';
            foreach ($locations as $location) {
                printf('<option value="%s">%s</option>', esc_attr($location['name']), esc_html($location['title']));
            }
            echo '</datalist>';
        }
        echo '<p class="description">' . esc_html__('Only for the Business Profile API. Format: accounts/{accountId}/locations/{locationId}. After connecting, "Fetch locations" below lists the available values.', 'hri-reviews-importer') . '</p>';
    }


    private function add_text_field($option, $label, $page, $desc = '')
    {
        add_settings_field(
            $option,
            esc_html($label),
            array($this, 'render_text_field'),
            $page,
            'hri_settings_section',
            array(
                'option'      => $option,
                'placeholder' => '',
                'desc'        => $desc,
            )
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


    public function render_settings_page()
    {
        if (! current_user_can('manage_options')) return;

        $last_run = get_option(self::OPTION_LAST_RUN, '');
?>
        <div class="wrap">
            <h1><?php echo esc_html__('Reviews Import Settings', 'hri-reviews-importer'); ?></h1>

            <form method="post" action="options.php">
                <?php
                settings_fields('hri_settings_group');
                do_settings_sections('hri-review-import-settings');
                submit_button(esc_html__('Save', 'hri-reviews-importer'));
                ?>
            </form>

            <hr />
            <h2><?php echo esc_html__('Import actions', 'hri-reviews-importer'); ?></h2>
            <p><strong><?php echo esc_html__('Last run:', 'hri-reviews-importer'); ?></strong>
                <?php echo $last_run ? esc_html($last_run) : esc_html__('not run yet', 'hri-reviews-importer'); ?>
            </p>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <?php wp_nonce_field('hri_import_now_nonce', 'hri_import_now_nonce_field'); ?>
                <input type="hidden" name="action" value="hri_import_now" />
                <input type="hidden" name="sort_type" value="newest" />
                <?php submit_button(esc_html__('Import now', 'hri-reviews-importer'), 'secondary'); ?>
            </form>
            <?php if (isset($_GET['hri_import']) && $_GET['hri_import'] === 'done') : ?>
                <div class="notice notice-success is-dismissible">
                    <p><?php echo esc_html__('Import executed.', 'hri-reviews-importer'); ?>
                    </p>
                </div>
            <?php endif; ?>

            <?php
            $last_error = get_option(self::OPTION_LAST_ERROR, array());

            if (isset($_GET['hri_import']) && $_GET['hri_import'] === 'error') : ?>
                <div class="notice notice-error is-dismissible">
                    <p><?php echo esc_html__('Import failed. See the last error below.', 'hri-reviews-importer'); ?></p>
                </div>
            <?php endif; ?>

            <?php if (! empty($last_error) && ! empty($last_error['message'])) : ?>
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
            <?php endif; ?>

            <h2><?php echo esc_html__('Google Business Profile connection', 'hri-reviews-importer'); ?></h2>
            <?php $this->render_gbp_connection(); ?>

            <?php if (isset($_GET['hri_fbtest']) && $_GET['hri_fbtest'] === 'ok') : ?>
                <div class="notice notice-success is-dismissible">
                    <p><?php echo esc_html__('Facebook connection test succeeded.', 'hri-reviews-importer'); ?></p>
                </div>
            <?php elseif (isset($_GET['hri_fbtest']) && $_GET['hri_fbtest'] === 'error') : ?>
                <div class="notice notice-error is-dismissible">
                    <p><?php echo esc_html__('Facebook connection test failed. See the "Last error" below.', 'hri-reviews-importer'); ?></p>
                </div>
            <?php endif; ?>

            <h2><?php echo esc_html__('Facebook connection test', 'hri-reviews-importer'); ?></h2>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <?php wp_nonce_field('hri_test_fb', 'hri_test_fb_field'); ?>
                <input type="hidden" name="action" value="hri_test_fb_connection" />
                <?php submit_button(esc_html__('Test Facebook connection', 'hri-reviews-importer'), 'secondary'); ?>
            </form>


            <hr />
            <p><em><?php echo esc_html__('Tip:', 'hri-reviews-importer'); ?></em>
                <?php echo esc_html__('Enter one code per line (e.g. "hu", "en"). We also accept "hu_HU", "en_US" but they will be normalized to the short code on save.', 'hri-reviews-importer'); ?>
            </p>
        </div>
    <?php
    }

    private function render_gbp_connection()
    {
        $token      = get_option(self::OPTION_GBP_TOKEN, array());
        $connected  = is_array($token) && ! empty($token['refresh_token']);
        $has_client = trim((string) get_option(self::OPTION_GBP_CLIENT_ID, '')) !== '' && trim((string) get_option(self::OPTION_GBP_CLIENT_SECRET, '')) !== '';
        $locations  = get_option(self::OPTION_GBP_LOCATIONS, array());
        $status     = isset($_GET['hri_gbp']) ? sanitize_key($_GET['hri_gbp']) : '';

        $notices = array(
            'connected'    => array('success', __('Connected to Google Business Profile.', 'hri-reviews-importer')),
            'disconnected' => array('success', __('Disconnected from Google Business Profile.', 'hri-reviews-importer')),
            'locations'    => array('success', __('Locations fetched. Set the "Business Profile location" field above and save.', 'hri-reviews-importer')),
            'error'        => array('error', __('Google Business Profile action failed. See the "Last error" above.', 'hri-reviews-importer')),
        );
        if (isset($notices[$status])) {
            printf('<div class="notice notice-%s is-dismissible"><p>%s</p></div>', esc_attr($notices[$status][0]), esc_html($notices[$status][1]));
        }
    ?>
        <p><?php echo esc_html__('Only needed when "Google Places API version" is set to "Business Profile API (OAuth)".', 'hri-reviews-importer'); ?></p>
        <ol>
            <li><?php echo esc_html__('In your Google Cloud project (with approved Business Profile API access) enable: Google My Business API, My Business Account Management API, My Business Business Information API.', 'hri-reviews-importer'); ?></li>
            <li><?php echo esc_html__('Create an OAuth client (type: Web application) with this authorized redirect URI:', 'hri-reviews-importer'); ?>
                <code><?php echo esc_html($this->gbp_redirect_uri()); ?></code>
            </li>
            <li><?php echo esc_html__('Save the client ID and client secret above, then connect with a Google account that manages the Business Profile.', 'hri-reviews-importer'); ?></li>
            <li><?php echo esc_html__('Fetch the locations, set the "Business Profile location" field and save.', 'hri-reviews-importer'); ?></li>
        </ol>
        <p><strong><?php echo esc_html__('Status:', 'hri-reviews-importer'); ?></strong>
            <?php echo $connected ? esc_html__('connected', 'hri-reviews-importer') : esc_html__('not connected', 'hri-reviews-importer'); ?>
        </p>

        <?php if ($has_client) : ?>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <?php wp_nonce_field('hri_gbp_connect', 'hri_gbp_connect_field'); ?>
                <input type="hidden" name="action" value="hri_gbp_connect" />
                <?php submit_button($connected ? esc_html__('Reconnect Google account', 'hri-reviews-importer') : esc_html__('Connect Google account', 'hri-reviews-importer'), 'secondary'); ?>
            </form>
        <?php else : ?>
            <p><em><?php echo esc_html__('Save the OAuth client ID and client secret first.', 'hri-reviews-importer'); ?></em></p>
        <?php endif; ?>

        <?php if ($connected) : ?>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <?php wp_nonce_field('hri_gbp_fetch_locations', 'hri_gbp_fetch_locations_field'); ?>
                <input type="hidden" name="action" value="hri_gbp_fetch_locations" />
                <?php submit_button(esc_html__('Fetch locations', 'hri-reviews-importer'), 'secondary'); ?>
            </form>
            <?php if (is_array($locations) && ! empty($locations)) : ?>
                <ul>
                    <?php foreach ($locations as $location) : ?>
                        <li><?php echo esc_html($location['title']); ?>: <code><?php echo esc_html($location['name']); ?></code></li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <?php wp_nonce_field('hri_gbp_disconnect', 'hri_gbp_disconnect_field'); ?>
                <input type="hidden" name="action" value="hri_gbp_disconnect" />
                <?php submit_button(esc_html__('Disconnect', 'hri-reviews-importer'), 'delete'); ?>
            </form>
        <?php endif; ?>
    <?php
    }

    /** Renderers */
    public function render_text_field($args)
    {
        $option      = $args['option'] ?? '';
        $placeholder = $args['placeholder'] ?? '';
        $desc        = $args['desc'] ?? '';
        $value       = get_option($option, '');
        printf(
            '<input type="text" class="regular-text" id="%1$s" name="%1$s" value="%2$s" placeholder="%3$s" />',
            esc_attr($option),
            esc_attr($value),
            esc_attr($placeholder)
        );
        if ($desc) {
            printf('<p class="description">%s</p>', esc_html($desc));
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
            '<textarea id="%1$s" name="%1$s" rows="6" cols="60" class="large-text code">%2$s</textarea>',
            esc_attr(self::OPTION_IMPORTED_LANGUAGES),
            esc_textarea($value)
        );
        echo '<p class="description">' . esc_html__('One code per line (e.g., "hu", "en"). "hu_HU", "en_US" are also accepted and will be normalized to short codes on save.', 'hri-reviews-importer') . '</p>';
    }

    /** Sanitizers */
    public function sanitize_text($value)
    {
        return is_string($value) ? trim($value) : '';
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
        $langs = $this->get_imported_languages_short_array();
        $errors = array();

        $google_api_key = trim((string) get_option(self::OPTION_GOOGLE_PLACES_API_KEY, ''));
        $google_place_id = trim((string) get_option(self::OPTION_GOOGLE_PLACE_ID, ''));

        // Each language and Facebook run independently: one failure must not stop the rest.
        if (get_option(self::OPTION_GOOGLE_API_VERSION, 'legacy') === 'gbp') {
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

        //Facebook import
        try {
            $this->hri_import_facebook_reviews();
        } catch (\Throwable $e) {
            $errors[] = 'Facebook: ' . $e->getMessage();
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
        $google_api_key = trim((string) get_option(self::OPTION_GOOGLE_PLACES_API_KEY, ''));
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
        $google_api_key = trim((string) get_option(self::OPTION_GOOGLE_PLACES_API_KEY, ''));
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
                    'post_title'   => $review['author_name'],
                    'post_status'  => $post_status,
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
                update_post_meta($post_id, 'review_' . $language, wp_kses_post($review['text']));
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
                'client_secret' => trim((string) get_option(self::OPTION_GBP_CLIENT_SECRET, '')),
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

    private function gbp_store_token($data, $refresh_token = '')
    {
        update_option(self::OPTION_GBP_TOKEN, array(
            'access_token'  => (string) $data['access_token'],
            'expires_at'    => time() + (int) ($data['expires_in'] ?? 3600),
            // A refresh response has no refresh_token: keep the stored one.
            'refresh_token' => (string) ($data['refresh_token'] ?? $refresh_token),
        ), false);
    }

    private function gbp_get_access_token()
    {
        $token = get_option(self::OPTION_GBP_TOKEN, array());
        if (!is_array($token) || empty($token['refresh_token'])) {
            throw new \RuntimeException(__('Not connected. Connect a Google account on the settings page.', 'hri-reviews-importer'));
        }
        if (!empty($token['access_token']) && (int) ($token['expires_at'] ?? 0) - 60 > time()) {
            return $token['access_token'];
        }

        $data = $this->gbp_token_request(array(
            'grant_type'    => 'refresh_token',
            'refresh_token' => $token['refresh_token'],
        ));
        $this->gbp_store_token($data, $token['refresh_token']);

        return (string) $data['access_token'];
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

        $token = get_option(self::OPTION_GBP_TOKEN, array());
        if (is_array($token) && ! empty($token['refresh_token'])) {
            // Best effort: the local token is removed even if the revoke call fails.
            wp_remote_post('https://oauth2.googleapis.com/revoke', array(
                'body'    => array('token' => $token['refresh_token']),
                'timeout' => 10,
            ));
        }
        delete_option(self::OPTION_GBP_TOKEN);
        delete_option(self::OPTION_GBP_LOCATIONS);

        $this->gbp_redirect_back('disconnected');
    }

    public function handle_gbp_fetch_locations()
    {
        if (! current_user_can('manage_options')) {
            wp_die(esc_html__('Insufficient permissions.', 'hri-reviews-importer'));
        }
        check_admin_referer('hri_gbp_fetch_locations', 'hri_gbp_fetch_locations_field');

        try {
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
            if (! preg_match('/^\d/', $version) || ($release && version_compare($version, $release['version'], '<='))) {
                continue;
            }

            // The zip attached by the release workflow has the right folder name; the tag archive does not (see fix_update_folder)
            $package = 'https://github.com/' . self::GITHUB_REPO . '/archive/refs/tags/' . rawurlencode((string) $item['tag_name']) . '.zip';
            foreach ((array) ($item['assets'] ?? array()) as $asset) {
                if (! empty($asset['browser_download_url']) && substr((string) ($asset['name'] ?? ''), -4) === '.zip') {
                    $package = (string) $asset['browser_download_url'];
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

    public function hri_import_facebook_reviews()
    {
        $page_id     = trim((string) get_option(self::OPTION_FACEBOOK_PAGE_ID, ''));
        $page_token  = trim((string) get_option(self::OPTION_FACEBOOK_GRAPH_API_KEY, '')); // Page Access Token!
        $min_rating  = (int) get_option(self::OPTION_MIN_REVIEW_RATING, 4);
        $skip_empty  = (bool) get_option(self::OPTION_SKIP_EMPTY_REVIEWS, false);

        if ($page_id === '' || $page_token === '') {
            return;
        }

        // Első nyelv a beállított listából (ide tesszük a review szöveget)
        $langs_raw = (string) get_option(self::OPTION_IMPORTED_LANGUAGES, 'en');
        $lang = 'en';
        foreach (preg_split('/[\r\n,]+/', $langs_raw) as $piece) {
            $piece = strtolower(trim((string) $piece));
            if ($piece !== '') {
                $lang = preg_replace('/[^a-z]/', '', explode('-', str_replace('_', '-', $piece))[0]);
                break;
            }
        }

        $base  = 'https://graph.facebook.com/v21.0/' . rawurlencode($page_id) . '/ratings';
        // Kérhető hasznos mezők (ha valamelyik hiányzik: API/engedély kérdése)
        $fields = 'id,created_time,recommendation_type,review_text,has_rating,rating,'
            . 'reviewer{id,name,picture.height(120).width(120)}';
        $limit  = 25;

        $url = add_query_arg(array(
            'fields'       => $fields,
            'limit'        => $limit,
            'access_token' => $page_token,
        ), $base);

        while ($url) {
            $res = wp_remote_get($url, array('timeout' => 20));
            if (is_wp_error($res)) {
                /* translators: %s: error message */
                throw new \RuntimeException(sprintf(__('HTTP error: %s', 'hri-reviews-importer'), $res->get_error_message()));
            }

            $code = wp_remote_retrieve_response_code($res);
            $body = json_decode(wp_remote_retrieve_body($res), true);

            if ($code !== 200 || !is_array($body)) {
                /* translators: 1: HTTP response code, 2: beginning of the response body */
                throw new \RuntimeException(sprintf(__('Bad response (%1$s): %2$s', 'hri-reviews-importer'), $code, substr((string) wp_remote_retrieve_body($res), 0, 500)));
            }

            // Facebook hibaüzenet?
            if (!empty($body['error']['message'])) {
                /* translators: %s: error message */
                throw new \RuntimeException(sprintf(__('Graph API error: %s', 'hri-reviews-importer'), $body['error']['message']));
            }

            foreach ((array) ($body['data'] ?? []) as $item) {
                $rec_id   = isset($item['id']) ? (string) $item['id'] : '';
                if ($rec_id === '') {
                    continue;
                }

                $review_id  = 'facebook:' . $rec_id; // ütközés elkerülésére prefix
                $created_iso = isset($item['created_time']) ? (string) $item['created_time'] : null;
                $text        = isset($item['review_text']) ? (string) $item['review_text'] : '';

                if ($skip_empty && trim($text) === '') {
                    continue;
                }

                // Értékelés: ha van csillag (1–5), azt használjuk, különben Recommendation típusból képezünk
                $rating = null;
                if (isset($item['rating']) && is_numeric($item['rating'])) {
                    $rating = (float) $item['rating'];
                } elseif (!empty($item['recommendation_type'])) {
                    // positive/negative → 5 vagy 1 (igény szerint állítható)
                    $rating = ($item['recommendation_type'] === 'positive') ? 5.0 : 1.0;
                }

                // Dátumok
                $post_date_gmt = $created_iso ? gmdate('Y-m-d H:i:s', strtotime($created_iso)) : current_time('mysql', true);
                $post_date     = get_date_from_gmt($post_date_gmt, 'Y-m-d H:i:s');

                // Reviewer adatok
                $reviewer_name = $item['reviewer']['name'] ?? __('Facebook User', 'hri-reviews-importer');
                $profile_photo_url = $item['reviewer']['picture']['data']['url'] ?? '';

                // Meglévő bejegyzés?
                $existing = get_posts(array(
                    'post_type'      => 'reviews',
                    'post_status'    => 'any',
                    'meta_key'       => 'review_id',
                    'meta_value'     => $review_id,
                    'fields'         => 'ids',
                    'posts_per_page' => 1,
                    'no_found_rows'  => true,
                ));

                $post_status = ($rating !== null && $rating >= $min_rating) ? 'publish' : 'draft';

                if (empty($existing)) {
                    $post_id = wp_insert_post(array(
                        'post_type'     => 'reviews',
                        'post_title'    => sanitize_text_field($reviewer_name),
                        'post_status'   => $post_status,
                        'post_date'     => $post_date,
                        'post_date_gmt' => $post_date_gmt,
                        'post_name'     => sanitize_title('review-facebook-' . $rec_id),
                        'meta_input'    => array(
                            'review_id'         => $review_id,
                            'review_source'     => 'Facebook',
                            'review_number'     => ($rating !== null ? (float) $rating : null),
                            'profile_photo_url' => esc_url_raw($profile_photo_url),
                        ),
                    ));
                } else {
                    $post_id = (int) $existing[0];
                    wp_update_post(array(
                        'ID'          => $post_id,
                        'post_status' => $post_status,
                        // opcionális: frissítsd a dátumot a review időpontjára
                        // 'post_date'     => $post_date,
                        // 'post_date_gmt' => $post_date_gmt,
                    ));
                    if ($rating !== null) {
                        update_post_meta($post_id, 'review_number', (float) $rating);
                    }
                    update_post_meta($post_id, 'profile_photo_url', esc_url_raw($profile_photo_url));
                    update_post_meta($post_id, 'review_source', 'Facebook');
                }

                if (!empty($post_id)) {
                    // Szöveg betöltése a beállított első nyelvi mezőbe
                    update_post_meta($post_id, 'review_' . $lang, wp_kses_post($text));
                    // Opcionális timestamp mentés
                    update_post_meta($post_id, 'review_timestamp', $post_date);
                }
            }

            // Lapozás
            $url = $body['paging']['next'] ?? null;
            if ($url) {
                // Append access_token only if not present
                if (strpos($url, 'access_token=') === false) {
                    $url = add_query_arg('access_token', $page_token, $url);
                }
            }
        }
    }

    public function handle_test_fb_connection()
    {
        if (! current_user_can('manage_options')) {
            wp_die(esc_html__('Insufficient permissions.', 'hri-reviews-importer'));
        }
        check_admin_referer('hri_test_fb', 'hri_test_fb_field');

        $page_id    = trim((string) get_option(self::OPTION_FACEBOOK_PAGE_ID, ''));
        $page_token = trim((string) get_option(self::OPTION_FACEBOOK_GRAPH_API_KEY, ''));

        try {
            if ($page_id === '' || $page_token === '') {
                throw new \RuntimeException(__('Missing Facebook Page ID or Page Access Token.', 'hri-reviews-importer'));
            }

            // v21.0 ajánlott a ratings/recommendations eléréséhez
            $base  = 'https://graph.facebook.com/v21.0/' . rawurlencode($page_id) . '/ratings';
            $url   = add_query_arg(array(
                'fields'       => 'id',
                'limit'        => 1,
                'access_token' => $page_token,
            ), $base);

            $res = wp_remote_get($url, array('timeout' => 15));
            if (is_wp_error($res)) {
                /* translators: %s: error message */
                throw new \RuntimeException(sprintf(__('HTTP error: %s', 'hri-reviews-importer'), $res->get_error_message()));
            }

            $code    = wp_remote_retrieve_response_code($res);
            $bodyraw = wp_remote_retrieve_body($res);
            $body    = json_decode($bodyraw, true);

            if ($code !== 200) {
                /* translators: 1: HTTP response code, 2: beginning of the response body */
                throw new \RuntimeException(sprintf(__('Bad response code: %1$s – %2$s', 'hri-reviews-importer'), $code, substr($bodyraw, 0, 300)));
            }
            if (! is_array($body)) {
                throw new \RuntimeException(__('Invalid JSON response.', 'hri-reviews-importer'));
            }
            if (! empty($body['error']['message'])) {
                /* translators: %s: error message */
                throw new \RuntimeException(sprintf(__('Graph API error: %s', 'hri-reviews-importer'), $body['error']['message']));
            }

            // siker
            if (method_exists($this, 'clear_last_error')) {
                $this->clear_last_error();
            }
            $q = array('hri_fbtest' => 'ok');
        } catch (\Throwable $e) {
            if (method_exists($this, 'log_error')) {
                $this->log_error($e->getMessage(), array('where' => 'handle_test_fb_connection'));
            } else {
                error_log('HRI FB test: ' . $e->getMessage());
            }
            $q = array('hri_fbtest' => 'error');
        }

        wp_redirect(add_query_arg($q, admin_url('options-general.php?page=hri-review-import-settings')));
        exit;
    }
}

new HRI_Review_Importer();
