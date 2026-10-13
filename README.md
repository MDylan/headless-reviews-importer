# Headless Reviews Importer

[![Latest release](https://img.shields.io/github/v/release/MDylan/headless-reviews-importer?include_prereleases&label=release)](https://github.com/MDylan/headless-reviews-importer/releases)
[![License: GPL v2 or later](https://img.shields.io/badge/license-GPLv2%2B-blue)](https://www.gnu.org/licenses/gpl-2.0.html)

A WordPress plugin that imports Google reviews into a `reviews` custom post type, on a schedule or on demand. It is headless: it stores the reviews and the overall Google rating, and leaves the design to your theme or page builder (for example a Bricks query loop).

> [!NOTE]
> Version 1.5.0 removed the Facebook import, because Meta retired the API behind it. See [Facebook reviews](#facebook-reviews).

## Contents

- [Features](#features)
- [Requirements](#requirements)
- [Installation](#installation)
- [Settings](#settings)
- [Google API versions](#google-api-versions)
- [Stored data](#stored-data)
- [Bricks dynamic data tags](#bricks-dynamic-data-tags)
- [Updates](#updates)
- [Uninstall](#uninstall)
- [Facebook reviews](#facebook-reviews)
- [Publishing a release](#publishing-a-release)
- [License](#license)

## Features

- Scheduled import (hourly, twice daily or daily) and an **Import now** button
- Three Google sources: Places API (Legacy), Places API (New) and Business Profile API (OAuth)
- Minimum rating filter: reviews below it are saved as drafts
- Review text in several languages, one field per language
- A `reviews` custom post type with its own admin menu and sortable **Source** and **Rating** columns
- The overall Google rating and review count, available as Bricks dynamic data tags
- Self-update from the GitHub releases of this repository
- Translatable; a Hungarian translation is included

## Requirements

- WordPress 5.8 or newer
- PHP 7.4 or newer

## Installation

1. Download `headless-reviews-importer-x.y.z.zip` from the [latest release](https://github.com/MDylan/headless-reviews-importer/releases).
2. Upload it under **Plugins → Add New → Upload Plugin**, or unpack it to `wp-content/plugins/headless-reviews-importer/`.
3. Activate the plugin on the **Plugins** page.
4. Open **Settings → Reviews Import Settings**, fill in the API keys, and set the import frequency, the minimum rating and the languages.

## Settings

The settings page is under **Settings → Reviews Import Settings**. It has three tabs (Google, Import, Advanced) and a status bar with the last run, the next run and the **Import now** button. The Google tab only shows the fields of the selected API.

| Setting | What it does |
|---|---|
| **Source API** | Chooses the Google source, see [Google API versions](#google-api-versions). |
| **Import frequency** | Hourly, twice daily or daily. The scheduled import runs on the cron hook `hri_import_cron_event`. |
| **Minimum imported rating** | Reviews with at least this rating are published. The ones below it are saved as drafts. |
| **Imported languages (short codes)** | One short code per line (`hu`, `en`, ...). Values like `hu_HU`, `en_US` or `de-DE` are normalized to short codes on save, and the default language of the site is always included. See Google's [language support](https://developers.google.com/maps/faq#languagesupport). |
| **Pre-release updates** | Offers pre-releases as plugin updates too, see [Updates](#updates). |

Keys and tokens (Places API key, OAuth client secret) are write-only. A saved value is never sent back to the browser; only its last 4 characters are shown. Leave the field empty to keep the saved value, use **Change** to replace it and **Delete** to remove it.

## Google API versions

| | Places API (Legacy) | Places API (New) | Business Profile API (OAuth) |
|---|---|---|---|
| Reviews imported | at most 5 per language | at most 5 per language | all reviews of the profile |
| Order | newest or most relevant (**Import order** setting) | most relevant only | newest first |
| Sign-in | API key + Place ID | API key + Place ID | OAuth client ID + secret, **Connect Google account** |
| Note | Default. Can no longer be enabled in new Google Cloud projects. | The `reviews` field is billed under the Enterprise + Atmosphere SKU. | Google has to approve Business Profile API access for the Cloud project. |

With the Business Profile API:

- After connecting, the locations of the Google account are fetched and can be chosen from a list. A single location is selected by itself.
- Each configured language is requested separately. Reviews written in that language are stored as written, the others with Google's translation into it.
- The first run fetches all reviews, and so does the first run after changing the location or the languages. Later runs fetch only the newest ones.

## Stored data

### The `reviews` post type

Reviews have no public single pages and are not included in sitemaps. They stay available to query loops (for example in Bricks) and to the REST API. Only users who can edit the posts of others (Editors and Administrators) can add, edit or delete them.

The post title is the reviewer's name. The rest is in meta fields:

| Meta key | Content |
|---|---|
| `review_{lang}` | Review text, one field per configured language (`review_hu`, `review_en`, ...). Imported text is plain text, without HTML. |
| `review_number` | Rating, 1 to 5 |
| `review_source` | `Google` or `Facebook`. Imported reviews are always `Google`; `Facebook` is for reviews added by hand. |
| `review_timestamp` | Date of the review (`Y-m-d H:i:s`), set by the importer |
| `profile_photo_url` | URL of the reviewer's profile photo |
| `review_id` | Identifier set by the importer, not editable |

### Options

| Option | Content |
|---|---|
| `hri_google_rating` | Overall Google rating |
| `hri_user_ratings_total` | Total number of Google reviews |

Until the first Google import the two Google options hold the placeholders `5.0` and `1`.

## Bricks dynamic data tags

The overall Google rating is available in Bricks as dynamic data, in the **Reviews Importer** group:

| Tag | Output |
|---|---|
| `{hri_google_rating}` | Rating with a decimal point, e.g. `4.8`. For star elements and conditions. |
| `{hri_google_rating_formatted}` | Rating in the number format of the site language |
| `{hri_google_ratings_total}` | Number of reviews, digits only |
| `{hri_google_ratings_total_formatted}` | Number of reviews in the number format of the site language |

- The tags are empty until the first Google import.
- They accept `@fallback`, shown while the tag is empty: `{hri_google_rating @fallback:'–'}`.
- Filters such as `:plain` are accepted and change nothing.

[AGENTS.md](AGENTS.md) describes every field and tag for building a review strip or an overall rating badge in Bricks.

## Updates

From version 1.3.0 the plugin updates itself from the releases of this repository. No token or account is needed.

- A new release shows up on the **Plugins** and **Dashboard → Updates** screens like any other plugin update, and WordPress's own **Enable auto-updates** switch works for it.
- WordPress looks for updates about twice a day. **Check again** on the Updates screen looks immediately.
- Only stable releases are offered by default. Tick **Pre-release updates** on the settings page to get pre-releases too, for example on a test site.
- Sites running 1.2.0 or older have to be updated by hand once, by uploading the zip.

## Uninstall

Deleting the plugin on the **Plugins** screen removes its settings, API keys and tokens, and revokes the Google connection. The imported reviews stay in the database. Deactivating the plugin removes nothing.

## Facebook reviews

The plugin does not import from Facebook. Meta deprecated Page recommendations in Graph API v22.0 and offers no replacement:

- According to Meta's changelog the Page ratings endpoint stops working in v22.0. In practice it answers with an empty list instead of an error: checked on v26.0 in October 2026, against a Page that has reviews.
- v21.0 is the last version that returns them. It is only available to Meta apps that already called it, and Meta removes it on January 21, 2027.

Facebook reviews can still be shown: add them by hand under **Reviews → Add New** and set **Review source** to `Facebook`. They are stored like imported reviews, so query loops and the Bricks tags treat them the same.

Updating from 1.4.1 or older deletes the saved Facebook Page ID and Page Access Token. Reviews stay untouched.

## Publishing a release

1. Set the new version in the plugin header (`Version`) and in `readme.txt` (`Stable tag`).
2. Publish a GitHub release tagged `vX.Y.Z`.

A workflow attaches the installable zip to the release. It fails if the tag and the plugin version differ.

## License

[GPLv2 or later](https://www.gnu.org/licenses/gpl-2.0.html)
