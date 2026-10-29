# WordPress.org listing assets

Everything in this directory is published to the plugin's SVN `/assets`
directory by `.github/workflows/deploy.yml`. **None of it ships in the plugin
zip** — `.distignore` excludes the whole directory.

## Files and required dimensions

| File | Dimensions | Purpose |
|---|---|---|
| `icon-256x256.png` | 256 × 256 | Plugin icon (also accepted: `icon.svg`) |
| `icon-128x128.png` | 128 × 128 | Plugin icon, low-DPI |
| `banner-1544x500.png` | 1544 × 500 | Header banner, retina |
| `banner-772x250.png` | 772 × 250 | Header banner, standard |
| `screenshot-1.png` … `screenshot-7.png` | 3069 × 2046 (any size works; keep one ratio) | Numbered to match the `== Screenshots ==` list in `readme.txt` |

## Status

The icons are the real artwork; `assets/images/flyaffiliate-icon.png`, which
the admin's top bar shows, is the 128 × 128 one. The seven screenshots are the
real listing art: Dashboard, Affiliates, Commissions, Visits, Payouts,
Extensions and Settings, captured from a real install. The banners are still
**flat teal placeholders generated at the correct dimensions**, there so the
deploy path and the dimension requirements are verifiable. They must be
replaced with real artwork.

Each screenshot needs a matching caption in `readme.txt` under
`== Screenshots ==`, in order: `screenshot-N.png` is caption `N.`. Adding,
removing or reordering one means renumbering the files and the captions
together.
