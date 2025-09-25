=== Headless Google/Facebook Reviews Importer ===
Contributors: Molnár Dávid
Tags: reviews, google, facebook, importer, headless, custom post type, cron
Requires at least: 5.8
Tested up to: 6.6
Requires PHP: 7.4
Stable tag: 1.4.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

A headless Google/Facebook reviews importer: settings page, scheduled and manual import, minimum rating filter, multilingual text fields, a dedicated "reviews" custom post type, and extra admin list columns. Default admin labels are in English and fully translatable (i18n).

== Description ==

**Headless Google/Facebook Reviews Importer** is a developer-friendly plugin that prepares the infrastructure for importing reviews (Google / Facebook):

- Settings page under **Settings → Reviews Import Settings**.
- API keys: `facebook_graph_api_key`, `facebook_page_id`, `google_places_api_key`, `google_place_id`.
- Import frequency (hourly / twice daily / daily) + **"Import now"** button.
- **Last run** display.
- **Minimum imported rating**: only ratings greater than or equal to this value are published.
- **Imported languages** (short codes: `hu`, `en`, ...). On save, values like `hu_HU`, `en_US`, `de-DE` are normalized to short codes, and the site’s default language is always included.  
  See also: https://developers.google.com/maps/faq#languagesupport
- Custom post type: **reviews** (dedicated admin menu).
  - Meta fields:  
    - `review_number` (1–5) – rating  
    - `review_id` – non-editable identifier (set by the importer)  
    - `review_source` – "Google" or "Facebook"  
    - `review_{lang}` – dynamic fields based on configured languages (e.g., `review_hu`, `review_en`)
- Admin list extra columns: **Source**, **Rating** (sortable).
- Full **i18n** support: Text Domain: `hri-reviews-importer`, Domain Path: `/languages`. Default labels are in English.

> The plugin does not perform actual API calls — wire up your import logic to the provided hooks.

== Features ==

- Settings page + cron scheduling
- "Import now" button (admin-post)
- Minimum rating filter
- Language code normalization (short codes)
- `reviews` CPT + meta fields
- Admin list columns: Source, Rating (sortable)
- Translatable with PO/MO files

== Installation ==

1. Copy the plugin to:  
   `wp-content/plugins/hri-reviews-importer/`
2. The main file should be:  
   `hri-reviews-importer.php`
3. Activate the plugin on the **Plugins** page.
4. Open **Settings → Reviews Import Settings**, fill in the API keys, set the import frequency, minimum rating, and languages.

== Usage ==

- **Immediate import:** click **Import now** on the settings page.  
- **Scheduled import:** runs via cron at the selected frequency (hook: `hri_import_cron_event`).  
- **Minimum rating:** reviews below the threshold may be saved as draft or skipped, depending on your importer logic.  
- **Languages:** enter one short code per line (`hu`, `en`, ...). Values are normalized on save.
