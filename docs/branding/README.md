# DeFt Icon

The owner-supplied article-layout and AI-badge artwork was adjusted with the
built-in image generation tool on 2026-11-01. The selected opaque master is
`deft-icon-master.png` (1254 x 1254 pixels). Transparent candidates were rejected
because their outer edges contained visible residue. The selected version keeps
the cream plaque, teal border, sage content blocks and orange AI badge, with less
empty space and clearer small-size framing.

## Files and Uses

- `wordpress/atshift-deft/assets/icon-20x20.png`: WordPress administration menu.
- `wordpress/atshift-deft/assets/icon-128x128.png`: Articles heading and GitHub README.
- `wordpress/atshift-deft/assets/icon-256x256.png`: high-density brand icon.
- `wordpress-org/assets/icon-128x128.png` and `icon-256x256.png`: identical directory copies.

The three plugin icons are included in both distribution ZIPs. The master and
this note are not packaged. The original user image is retained unchanged outside
the repository. The selected master is opaque; do not describe it as transparent.
This is an unreleased working-tree change; the version has not been bumped.
Local validation builds in `.local/icon-preview/build/` are not release artifacts.
Do not replace the published 0.7.2 ZIPs or the previously submitted review package.

After directory approval and separate publication approval, upload only the two
directory icons to the SVN repository's top-level `assets/` directory, alongside
`trunk/` and `tags/`, not inside either. Including them in a review ZIP does not
publish a directory icon. See the [official asset requirements](https://developer.wordpress.org/plugins/wordpress-org/plugin-assets/).

## Export

Each PNG was downsampled directly from the master, not from another thumbnail:

```sh
sips -z 20 20 docs/branding/deft-icon-master.png --out wordpress/atshift-deft/assets/icon-20x20.png
sips -z 128 128 docs/branding/deft-icon-master.png --out wordpress/atshift-deft/assets/icon-128x128.png
sips -z 256 256 docs/branding/deft-icon-master.png --out wordpress/atshift-deft/assets/icon-256x256.png
cp wordpress/atshift-deft/assets/icon-128x128.png wordpress/atshift-deft/assets/icon-256x256.png wordpress-org/assets/
```

## Selected Edit Prompt

Use case: precise-object-edit
Asset type: production WordPress plugin directory icon for atshift DeFt.
Use the provided original image as the edit target. Make a clean square software
icon from this exact design: same cream rounded plaque, dark teal inset outline,
pale sage title bar, three lines to the left of the mountain/sun image, two lower
text-column blocks, and orange lower-left speech badge with exact white uppercase
letters "AI". Preserve the warm tactile soft 3D style and these shapes/colors.
No additional text or objects.
Adjust ONLY framing/background and clarity: view nearly straight on, center the
combined plaque and badge in a square, enlarge it to occupy about 90% of canvas
height and width while retaining the entire silhouette and a modest even margin.
Background must be completely opaque, flat warm near-white (#faf8f4), no textured
background, no gradient, no vignette, no floor line. Remove floor shadow and stray
pixels. Smooth clean edges against this uniform background. Keep shallow bevels
and self-shadows within the icon. Make lettering and details crisp for a 128px
thumbnail. One final square image only, not a grid or mockup.
