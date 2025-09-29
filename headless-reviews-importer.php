<?php

/**
 * Plugin Name: Headless Google/Facebook Reviews Importer
 * Description: Settings page (Facebook/Google keys, import frequency, imported languages, minimum rating), "Import now" button, last run display + "reviews" custom post type with meta fields and list columns.
 * Version:     0.9.0
 * Author:      Molnár Dávid
 * License:     GPLv2 or later
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


    // Cron hook
    const CRON_HOOK = 'hri_import_cron_event';

    private $cron_options = array(
        'hourly'     => 'Hourly',
        'twicedaily' => 'Twice daily',
        'daily'      => 'Daily',
    );

    public function __construct()
    {
        // I18n
        add_action('plugins_loaded', array($this, 'load_textdomain'));

        // Admin UI
        add_action('admin_menu', array($this, 'add_menu'));
        add_action('admin_init', array($this, 'register_settings'));

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
        register_setting('hri_settings_group', self::OPTION_IMPORT_ORDER, array(
            'type'              => 'string',
            'sanitize_callback' => array($this, 'sanitize_import_order'),
            'default'           => 'newest',
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
                    esc_html__('Get an API key (Places API). You need the Legacy Places API for this plugin', 'hri-reviews-importer'),
                    esc_html__('Then follow the explained steps.', 'hri-reviews-importer')
                );

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
        $this->add_text_field(self::OPTION_GOOGLE_PLACES_API_KEY, __('Google Places API key', 'hri-reviews-importer'), 'hri-review-import-settings', __('Paste here your Places API API key.', 'hri-reviews-importer'));
        $this->add_text_field(self::OPTION_GOOGLE_PLACE_ID, __('Google Place ID', 'hri-reviews-importer'), 'hri-review-import-settings', __('Your Google Place ID', 'hri-reviews-importer'));

        add_settings_field(
            self::OPTION_IMPORT_ORDER,
            esc_html__('Import order', 'hri-reviews-importer'),
            array($this, 'render_import_order_select'),
            'hri-review-import-settings',
            'hri_settings_section',
            array(
                'description' => esc_html__('Defines the order used when fetching reviews from Google Places.', 'hri-reviews-importer'),
            )
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

            <hr />
            <p><em><?php echo esc_html__('Tip:', 'hri-reviews-importer'); ?></em>
                <?php echo esc_html__('Enter one code per line (e.g. "hu", "en"). We also accept "hu_HU", "en_US" but they will be normalized to the short code on save.', 'hri-reviews-importer'); ?>
            </p>
        </div>
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

    public function render_cron_select()
    {
        $current = get_option(self::OPTION_CRON_TIME, 'hourly');
        echo '<select id="' . esc_attr(self::OPTION_CRON_TIME) . '" name="' . esc_attr(self::OPTION_CRON_TIME) . '">';
        foreach ($this->cron_options as $key => $label) {
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
        return is_string($value) ? trim(wp_kses_post($value)) : '';
    }

    public function sanitize_cron_time($value)
    {
        $value = is_string($value) ? trim($value) : '';
        return array_key_exists($value, $this->cron_options) ? $value : 'hourly';
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

        wp_redirect(add_query_arg('hri_import', 'done', admin_url('options-general.php?page=hri-review-import-settings')));
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
            'public'             => true,
            'show_in_rest'       => true,
            'publicly_queryable' => true,
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

        $google_api_key = get_option(self::OPTION_GOOGLE_PLACES_API_KEY, '');
        $google_place_id = get_option(self::OPTION_GOOGLE_PLACE_ID, '');

        if (!empty($google_api_key) && !empty($google_place_id) && !empty($langs)) {
            foreach ($langs as $lang) {
                $this->hri_import_google_reviews($lang);
            }
        }

        //TODO: Facebook import
    }

    public function hri_import_google_reviews($language)
    {

        $headers = [
            'Content-Type' => 'application/json',
            'Referer' => get_site_url()
        ];

        $google_api_key = get_option(self::OPTION_GOOGLE_PLACES_API_KEY, '');
        $google_place_id = get_option(self::OPTION_GOOGLE_PLACE_ID, '');
        $skip_empty = (bool) get_option(self::OPTION_SKIP_EMPTY_REVIEWS, false);
        $order = get_option(HRI_Review_Importer::OPTION_IMPORT_ORDER, 'newest');

        $fields = array('formatted_address', 'icon', 'id', 'name', 'rating', 'reviews', 'url', 'user_ratings_total', 'vicinity');
        // $language = "en";

        $url = 'https://maps.googleapis.com/maps/api/place/details/json'
            . '?placeid=' . rawurlencode($google_place_id)
            . '&key=' . rawurlencode($google_api_key)
            . '&fields=' . rawurlencode(implode(',', $fields))
            . '&reviews_sort=' . $order // Last reviews first newest/most_relevant
            . '&reviews_no_translations=false'
            . (($language != NULL) ? '&language=' . rawurlencode($language) : '');
        //error_log("URL: " . $url);
        if (version_compare(PHP_VERSION, '8.1') >= 0) {
            $data_string = wp_remote_retrieve_body(@wp_remote_get($url, $headers));
        } else {
            $data_string = wp_remote_retrieve_body(wp_remote_get($url, $headers));
        }

        $data_array = ($data_string != NULL) ? json_decode($data_string, TRUE) : NULL;
        //error_log('Google API response: ' . print_r($data_array, true));

        if (isset($data_array['error_message'])) {
            //error_log('Google API error: ' . $data_array['error_message']);
            $msg = 'Google API error: ' . $data_array['error_message'] . ', Status: ' . $data_array['status'];
            throw new \RuntimeException($msg);
            return;
        }
        $reviews = array();
        if (isset($data_array['result']['reviews'])) {
            $data = $data_array['result'];
            foreach ($data['reviews'] as $key => $value) {
                if ($skip_empty && empty(trim((string) $value['text']))) {
                    continue; // skip this review
                }
                $review_id = md5(trim($value['author_url']) . trim($value['time']));
                $reviews[$key]['review_id'] = $review_id;
                $reviews[$key]['text'] = trim($value['text']);
                $reviews[$key]['author_name'] = trim($value['author_name']);
                $reviews[$key]['review_time'] = $value['time'];
                $reviews[$key]['review_timestamp'] = date("Y-m-d H:i:s", $value['time']);
                $reviews[$key]['review_timestamp_gmt'] = gmdate("Y-m-d H:i:s", $value['time']);
                $reviews[$key]['profile_photo_url'] = trim($value['profile_photo_url']);
                $reviews[$key]['rating'] = $value['rating'];
            }
        } else {
            //error_log('Google API error: No reviews.');
            $msg = 'Google API error: No reviews.';
            throw new \RuntimeException($msg);
            return;
        }

        if (isset($data_array['result']['rating'])) {
            $raw_rating = isset($data_array['result']['rating']) ? $data_array['result']['rating'] : 0;
            $new_rating = number_format((float) $raw_rating, 1, '.', ''); 
            update_option(self::OPTION_GOOGLE_RATING, $new_rating);
        }

        if (isset($data_array['result']['user_ratings_total'])) {
            $new_rating = floatval($data_array['result']['user_ratings_total']);

            update_option(self::OPTION_GOOGLE_RATINGS_TOTAL, $new_rating);
        }

        if (!empty($reviews)) {
            $min_rating = get_option(self::OPTION_MIN_REVIEW_RATING, 4);
            foreach ($reviews as $review) {
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
                } else {
                    //update existing review
                    $post_id =  $existing[0];
                }
                if ($post_id) {
                    update_post_meta($post_id, 'review_number', $review['rating']);
                    update_post_meta($post_id, 'review_timestamp', $review['review_timestamp']);
                    update_post_meta($post_id, 'review_source', 'Google');
                    update_post_meta($post_id, 'review_id', $review['review_id']);
                    update_post_meta($post_id, 'review_' . $language, $review['text']);
                    update_post_meta($post_id, 'profile_photo_url', esc_url_raw($review['profile_photo_url']));
                }
            }
        } else {
            $msg = 'There is no reviews with comment';
            throw new \RuntimeException($msg);
        }
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

