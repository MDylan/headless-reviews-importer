=== Headless Google/Facebook Reviews Importer ===
Contributors: MDylan (David Molnar)
Tags: reviews, google, facebook, importer, headless, custom post type, cron
Requires at least: 5.8
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.3.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

A headless Google/Facebook reviews importer: settings page, scheduled and manual import, minimum rating filter, multilingual text fields, a dedicated "reviews" custom post type, and extra admin list columns. Default admin labels are in English and fully translatable (i18n).

== IMPORTANT == 
Facebook import is not tested yet.

== Description ==

**Headless Google/Facebook Reviews Importer** is a developer-friendly plugin that prepares the infrastructure for importing reviews (Google / Facebook):

- Settings page under **Settings → Reviews Import Settings**.
- API keys: `facebook_graph_api_key`, `facebook_page_id`, `google_places_api_key`, `google_place_id`.
- Import frequency (hourly / twice daily / daily) + **"Import now"** button.
- **Last run** display.
- **Minimum imported rating**: only ratings greater than or equal to this value are published.
- **Imported languages** (short codes: `hu`, `en`, ...). On save, values like `hu_HU`, `en_US`, `de-DE` are normalized to short codes, and the site’s default language is always included.  
  See also: https://developers.google.com/maps/faq#languagesupport
- **Google Places API version**: `Places API (Legacy)` (default), `Places API (New)` or `Business Profile API (OAuth)`.
  - Business Profile API imports all reviews of a profile you manage, newest first. It uses an OAuth connection (client ID + secret, "Connect Google account" on the settings page) instead of the API key and Place ID, and Google has to approve Business Profile API access for the Cloud project. Each configured language is requested separately (`Accept-Language` header): reviews written in that language are stored as written, the others with Google's translation into it. The first run, and the first run after changing the location or the languages, fetches all reviews; later runs only the newest ones.
  - Legacy supports the **Import order** setting (newest / most relevant), but it can no longer be enabled in new Google Cloud projects.
  - New returns at most 5 reviews, always sorted by relevance (there is no "newest" order), and the `reviews` field is billed under the Enterprise + Atmosphere SKU.
- Custom post type: **reviews** (dedicated admin menu). Reviews have no public single pages and are not included in sitemaps; they remain available to query loops (e.g. Bricks) and the REST API.
  - Meta fields:  
    - `profile_photo_url` - The reviewer's profile photo
    - `review_number` (1–5) – rating  
    - `review_id` – non-editable identifier (set by the importer)  
    - `review_source` – "Google" or "Facebook"  
    - `review_{lang}` – dynamic fields based on configured languages (e.g., `review_hu`, `review_en`)
- Admin list extra columns: **Source**, **Rating** (sortable).
- Full **i18n** support: Text Domain: `hri-reviews-importer`, Domain Path: `/languages`. Default labels are in English.

Aggregated Google rating fields:
- hri_google_rating: Google rating value
- hri_google_rating: Total number of Google reviews
- hri_facebook_rating: Facebook rating value
- hri_facebook_ratings_total: Total number of Facebook reviews

== Features ==

- Settings page + cron scheduling
- "Import now" button (admin-post)
- Minimum rating filter
- Language code normalization (short codes)
- `reviews` CPT + meta fields
- Admin list columns: Source, Rating (sortable)
- Translatable with PO/MO files

== Installation ==

1. Download `headless-reviews-importer-x.y.z.zip` from the latest release:
   https://github.com/MDylan/headless-reviews-importer/releases
2. Upload it under **Plugins → Add New → Upload Plugin** (or unpack it to `wp-content/plugins/headless-reviews-importer/`).
3. Activate the plugin on the **Plugins** page.
4. Open **Settings → Reviews Import Settings**, fill in the API keys, set the import frequency, minimum rating, and languages.

== Updates ==

From version 1.3.0 the plugin updates itself from the releases of its public GitHub repository; no token or account is needed.

- A new release shows up on the **Plugins** and **Dashboard → Updates** screens like any other plugin update, and WordPress's own **Enable auto-updates** switch works for it. WordPress looks for updates about twice a day; **Check again** on the Updates screen looks immediately.
- Only stable releases are offered by default. Tick **Pre-release updates** on the settings page to get pre-releases too (e.g. on a test site).
- Sites running 1.2.0 or older have to be updated by hand once, by uploading the zip.

Publishing a release: set the new version in the plugin header (`Version`) and in this file (`Stable tag`), then publish a GitHub release tagged `vX.Y.Z`. A workflow attaches the installable zip to the release; it fails if the tag and the plugin version differ.

== Usage ==

- **Immediate import:** click **Import now** on the settings page.  
- **Scheduled import:** runs via cron at the selected frequency (hook: `hri_import_cron_event`).  
- **Minimum rating:** reviews below the threshold may be saved as draft or skipped, depending on your importer logic.  
- **Languages:** enter one short code per line (`hu`, `en`, ...). Values are normalized on save.

== Facebook usage - !!! NOT TESTED !!! ==
You need to create a Meta App with these privileges:
- pages_show_list
- pages_read_user_content
- pages_read_engagement