# Building the review frontend in Bricks

This plugin only imports and stores reviews. It renders nothing on the frontend: no shortcode, no block, no template, no JSON-LD. A review strip or an overall rating badge is built in Bricks from the fields below.

All of the code is in `headless-reviews-importer.php`. When a field or a tag changes there, update this file.

## Review fields

Each review is a post of the custom post type `reviews`. Use a Bricks query loop on that post type; inside the loop the tags below bind to the current review.

| What | Bricks tag | Format | Notes |
|---|---|---|---|
| Reviewer name | `{post_title}` | text | |
| Review text | `{cf_review_<lang>}`, e.g. `{cf_review_hu}` | plain text, no HTML | One meta key per language configured under **Settings → Reviews Import Settings → Imported languages**. Can be empty or missing for a language. |
| Rating | `{cf_review_number}` | number, 1 to 5 | |
| Reviewer photo | `{cf_profile_photo_url}` | absolute URL | Remote image on Google's or Facebook's servers, not in the media library. Can be empty, and old URLs can stop working, so give the avatar a fallback. |
| Source | `{cf_review_source}` | `Google` or `Facebook` | Exactly these strings, capitalised. |
| Review date | `{post_date}` | date | Takes a format, e.g. `{post_date:Y. F j.}`. Show the date only, not the time: for Google reviews the stored time is UTC, not site time. |
| Review date (raw) | `{cf_review_timestamp}` | `Y-m-d H:i:s` | Same moment as `{post_date}`. |
| External ID | `{cf_review_id}` | string | Used by the importer to find existing reviews. Not for display. |

`{post_content}` and `{post_excerpt}` are empty for imported reviews. The text is only in the `review_<lang>` meta.

## Aggregate rating tags

The overall Google rating is stored in `wp_options`, which Bricks cannot read by itself, so the plugin registers four dynamic data tags (plugin 1.4.0 or newer). They are in the **Reviews Importer** group of the dynamic data picker and work anywhere, inside or outside a loop.

| Tag | Example | Use it for |
|---|---|---|
| `{hri_google_rating}` | `4.8` | Star elements, conditions, CSS values. Always one decimal with a dot. |
| `{hri_google_rating_formatted}` | `4,8` on a Hungarian site | Text shown to visitors. Follows the site locale. |
| `{hri_google_ratings_total}` | `1234` | Conditions, calculations. Digits only. |
| `{hri_google_ratings_total_formatted}` | `1 234` on a Hungarian site | Text shown to visitors. Follows the site locale. |

- **These numbers come from Google, not from the imported posts.** They cover every review of the place, including the ones below the minimum rating and the ones the API did not return. The count is therefore not the number of `reviews` posts.
- **The tags are empty until a Google import has completed.** They are also empty while Google is not configured (no API key and Place ID, or no connected Business Profile account). Put an "is not empty" condition on the badge so it hides itself in that state.
- **There are no Facebook aggregate tags.** The importer never fills the Facebook rating and count.
- The tags take no modifiers: `{hri_google_rating:something}` is left unresolved.

Outside Bricks the same values are `get_option('hri_google_rating')` and `get_option('hri_user_ratings_total')`. Before the first import those options hold the placeholders `5.0` and `1`; the tags hide them, a direct `get_option()` call does not.

## Recipe: review strip

Query loop, in `WP_Query` terms:

- `post_type`: `reviews`
- `post_status`: `publish` (the default). Reviews below the minimum rating are saved as drafts, so they stay out by themselves.
- `orderby`: `date`, `DESC` for the newest first. For the best first, use `meta_key` `review_number` with `orderby` `meta_value_num`.
- `meta_query`, as needed:
  - `review_source` `=` `Google`, to show one source only
  - `review_hu` `!=` `''` (use the language of the page), to leave out reviews without text in that language
  - `review_number` `>=` `5` with type `NUMERIC`, to show only the top ratings

Card contents:

- avatar: an image with `{cf_profile_photo_url}` as its URL, with a fallback
- name: `{post_title}`
- stars: bound to `{cf_review_number}`, see [Stars](#stars)
- text: `{cf_review_<lang>}`
- date: `{post_date}` with a date format

Do not link a card to `{post_url}`: reviews have no single pages (`publicly_queryable` is false). The link to the review on Google is not stored either.

## Recipe: overall rating badge

- number: `{hri_google_rating_formatted}`
- stars: bound to `{hri_google_rating}`, see [Stars](#stars)
- count: `{hri_google_ratings_total_formatted}` with your own label, e.g. `{hri_google_ratings_total_formatted} értékelés`
- condition on the wrapper: `{hri_google_rating}` is not empty

A link to the Google profile has to be typed in by hand; the plugin does not store the place URL.

## Stars

Always feed stars from the plain tags (`{cf_review_number}`, `{hri_google_rating}`), never from a `_formatted` one: a decimal comma breaks both options below.

**With BricksExtras:** the Star Rating element (`xstarrating`). Set `starRating` to the tag and `totalStars` to `5`. Set `markedIcon` explicitly, otherwise the element renders nothing. It rounds to half stars, so `4.8` shows as five stars; keep the number next to it.

**Without BricksExtras:** a div with the custom attribute `style` = `--rating: {hri_google_rating}` (or `{cf_review_number}`), plus `role` = `img` and an `aria-label`, and this CSS:

```css
.review-stars {
  display: inline-block;
  line-height: 1;
}
.review-stars::before {
  content: "★★★★★";
  background: linear-gradient(90deg, #fbbc04 calc(var(--rating) / 5 * 100%), #dadce0 0);
  -webkit-background-clip: text;
  background-clip: text;
  -webkit-text-fill-color: transparent;
}
```

## What is not stored

Do not design for these; the data is not there:

- link to the review, or to the reviewer's profile
- the owner's reply
- place name, address, Maps link
- how many reviews each star level has
- the review's original language, or whether the text is a translation
- a featured image (the photo is only a URL)

## Things that go wrong

- **Only five reviews show up.** The Places APIs return at most five reviews per language. Only the Business Profile API imports all of them.
- **A review is missing.** It is probably a draft: its rating was below the minimum when it was imported. For Google reviews, lowering the minimum later does not publish existing drafts.
- **Text is missing in one language.** Each language has its own meta key, and Facebook reviews only fill the first configured language.
- **HTML shows up in a review.** Imported text is plain text, but a review edited by hand in wp-admin can contain HTML.
- **The REST API returns no fields.** `/wp-json/wp/v2/reviews` only has the title and the date; the meta fields are not registered for REST.
- **The aggregate tags are empty although reviews are imported.** The plugin records a run only when the whole import finished without an error. Check the error shown on the settings page.

## Before writing a tag into an element

Check it against a real review: list the tags with `bricks/list-dynamic-data-tags` using the ID of a `reviews` post, then render the tag with `bricks/preview-dynamic-tag`. A mistyped tag is printed as literal text on the page, without an error.
