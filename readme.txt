=== Headless Reviews Importer ===
Contributors: MDylan (David Molnar)
Tags: reviews, google, importer, headless, custom post type, cron
Requires at least: 5.8
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.5.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

A headless Google reviews importer: settings page, scheduled and manual import, minimum rating filter, multilingual text fields, a dedicated "reviews" custom post type, and extra admin list columns. Default admin labels are in English and fully translatable (i18n).

== IMPORTANT ==
Version 1.5.0 removed the Facebook import, because Meta retired the API behind it. See "Facebook reviews" below.

== Description ==

**Headless Reviews Importer** is a developer-friendly plugin that prepares the infrastructure for importing Google reviews:

- Settings page under **Settings → Reviews Import Settings**, with tabs (Google, Import, Advanced) and a status bar (last run, next run, **Import now**). The Google tab only shows the fields of the selected API.
- API keys: `google_places_api_key`, `google_place_id`.
- Keys and tokens (Places API key, OAuth client secret) are write-only on the settings page: a saved value is never sent back to the browser, only its last 4 characters are shown. Leave the field empty to keep the saved value; **Change** replaces it, **Delete** removes it.
- Import frequency (hourly / twice daily / daily) + **"Import now"** button.
- **Last run** display.
- **Minimum imported rating**: only ratings greater than or equal to this value are published.
- **Imported languages** (short codes: `hu`, `en`, ...). On save, values like `hu_HU`, `en_US`, `de-DE` are normalized to short codes, and the site’s default language is always included.  
  See also: https://developers.google.com/maps/faq#languagesupport
- **Google Places API version**: `Places API (Legacy)` (default), `Places API (New)` or `Business Profile API (OAuth)`.
  - Business Profile API imports all reviews of a profile you manage, newest first. It uses an OAuth connection (client ID + secret, "Connect Google account" on the settings page) instead of the API key and Place ID, and Google has to approve Business Profile API access for the Cloud project. After connecting, the locations of the Google account are fetched automatically and can be chosen from a list (a single location is selected by itself). Each configured language is requested separately (`Accept-Language` header): reviews written in that language are stored as written, the others with Google's translation into it. The first run, and the first run after changing the location or the languages, fetches all reviews; later runs only the newest ones.
  - Legacy supports the **Import order** setting (newest / most relevant), but it can no longer be enabled in new Google Cloud projects.
  - New returns at most 5 reviews, always sorted by relevance (there is no "newest" order), and the `reviews` field is billed under the Enterprise + Atmosphere SKU.
- Custom post type: **reviews** (dedicated admin menu). Reviews have no public single pages and are not included in sitemaps; they remain available to query loops (e.g. Bricks) and the REST API. Only users who can edit the posts of others (Editors and Administrators) can add, edit or delete reviews. The imported review text is stored as plain text, without HTML.
  - Meta fields:  
    - `profile_photo_url` - The reviewer's profile photo
    - `review_number` (1–5) – rating  
    - `review_id` – non-editable identifier (set by the importer)
    - `review_source` – "Google" or "Facebook" (imported reviews are always "Google"; "Facebook" is for reviews added by hand)
    - `review_timestamp` – date of the review (`Y-m-d H:i:s`, set by the importer)
    - `review_{lang}` – dynamic fields based on configured languages (e.g., `review_hu`, `review_en`)
- Admin list extra columns: **Source**, **Rating** (sortable).
- Full **i18n** support: Text Domain: `hri-reviews-importer`, Domain Path: `/languages`. Default labels are in English.

Aggregated rating options (`wp_options`):
- hri_google_rating: Google rating value
- hri_user_ratings_total: Total number of Google reviews

Until the first Google import the Google options hold the placeholders `5.0` and `1`.

Bricks dynamic data tags for the aggregated Google rating (group **Reviews Importer**; empty until the first Google import):
- `{hri_google_rating}` – rating with a decimal point, e.g. `4.8` (star elements, conditions)
- `{hri_google_rating_formatted}` – rating in the number format of the site language
- `{hri_google_ratings_total}` – number of reviews, digits only
- `{hri_google_ratings_total_formatted}` – number of reviews in the number format of the site language

The tags accept `@fallback` (e.g. `{hri_google_rating @fallback:'–'}`), shown while the tag is empty. Filters such as `:plain` are accepted and change nothing.

`AGENTS.md` in the GitHub repository describes every field and tag for building the frontend in Bricks:
https://github.com/MDylan/headless-reviews-importer/blob/main/AGENTS.md

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

== Uninstall ==

Deleting the plugin on the **Plugins** screen removes its settings, API keys and tokens, and revokes the Google connection. The imported reviews stay in the database. Deactivating the plugin removes nothing.

== Updates ==

From version 1.3.0 the plugin updates itself from the releases of its public GitHub repository; no token or account is needed.

- A new release shows up on the **Plugins** and **Dashboard → Updates** screens like any other plugin update, and WordPress's own **Enable auto-updates** switch works for it. WordPress looks for updates about twice a day; **Check again** on the Updates screen looks immediately.
- Only stable releases are offered by default. Tick **Pre-release updates** on the settings page to get pre-releases too (e.g. on a test site).
- Sites running 1.2.0 or older have to be updated by hand once, by uploading the zip.

Publishing a release: set the new version in the plugin header (`Version`) and in this file (`Stable tag`), then publish a GitHub release tagged `vX.Y.Z`. A workflow attaches the installable zip to the release; it fails if the tag and the plugin version differ.

== Usage ==

- **Immediate import:** click **Import now** on the settings page.  
- **Scheduled import:** runs via cron at the selected frequency (hook: `hri_import_cron_event`).  
- **Minimum rating:** reviews below the threshold are saved as drafts.  
- **Languages:** enter one short code per line (`hu`, `en`, ...). Values are normalized on save.

== Facebook reviews ==

The plugin does not import from Facebook. Meta deprecated Page recommendations in Graph API v22.0 and offers no replacement:

- According to Meta's changelog the Page ratings endpoint stops working in v22.0. In practice it answers with an empty list instead of an error: checked on v26.0 in October 2026, against a Page that has reviews.
- v21.0 is the last version that returns them. It is only available to Meta apps that already called it, and Meta removes it on January 21, 2027.

Facebook reviews can still be shown: add them by hand under **Reviews → Add New** and set **Review source** to "Facebook". They are stored like imported reviews.

Updating from 1.4.1 or older deletes the saved Facebook Page ID and Page Access Token. Reviews stay untouched.