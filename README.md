# og-card

Every post on your site gets a 1200x630 share card - from one PHP file and one JSON manifest.

No image editor. No SaaS subscription. No build step. Just the GD extension that is almost certainly already enabled on your host.

## Why

Every article I ship needs an `og:image`, because links without a card die on social platforms. Making a 1200x630 PNG by hand for each one is death by a thousand exports, and the hosted card generators are another account, another bill, another vendor.

One day I looked at what I actually needed and laughed: dark background, accent color, kicker, headline, footer. That is a function, not a service.

So this is a function. ~250 lines of PHP, zero dependencies, no mbstring required.

## Quick start

1. Drop `og.php` and `cards.json` into your site root (same dir, `public_html` is fine).
2. Add a card for each of your posts:

```json
{
  "defaults": {
    "meta": "yoursite.com",
    "author": "Your Name",
    "accent": "#3b82f6"
  },
  "cards": {
    "my-post-slug": {
      "kicker": "Series or category",
      "title": "The headline that shows up in the link preview"
    }
  }
}
```

3. Point your meta tags at it:

```html
<meta property="og:image" content="https://yoursite.com/og.php?a=my-post-slug">
<meta name="twitter:image" content="https://yoursite.com/og.php?a=my-post-slug">
```

Done. That post now has a share card forever, and adding a new post is one JSON entry.

## Pretty URLs

With the included `.htaccess`, cards get clean URLs that Facebook and friends are equally happy with:

```
https://yoursite.com/og/my-post-slug.png
```

## What it does

- **1200x630 PNG** - the exact size every major platform wants
- **Disk cache** - each card renders once, then serves from `cache/`
- **ETag + 304** - crawlers re-checking your links don't re-download anything
- **Auto-shrinking title** - long headlines drop font size until they fit in 4 lines
- **404 card** - an unknown slug gets a red card telling you to add the slug, so broken previews are self-diagnosing
- **Slug whitelist** - `[a-z0-9-]` only, max 80 chars, so there is nothing to inject

## Pre-render everything

If you'd rather ship static files than run PHP per request:

```
php og.php
```

renders every card in the manifest to `sample/` once. Point your meta tags at the static PNGs and PHP never runs in production at all.

## Manifest

| Field | Required | Notes |
|---|---|---|
| `title` | yes | Falls back to the slug, but write a real headline |
| `kicker` | no | Small tracked uppercase line above the title |
| `accent` | no | Any hex color; falls back to `defaults` then blue |
| `meta` | no | Footer left (usually your domain); falls back to `defaults` |
| `author` | no | Footer right; falls back to `defaults` |

## Sample output

The `sample/` directory holds four real renders from this repo's own `cards.json` - the demo gallery is in `demo/`.

## License

MIT. Take it, ship it, stop exporting PNGs by hand.
