# Philippine Map Pin Validation

Map pickers validate land before emitting a location. Clicks, marker drags, and
address search use the same offline geometry as PHP's `PhilippineLandPin` rule.
The server applies the rule to applicant/mobile profiles, organization contact
details, programs, formal-application handoffs, program events, stage sessions,
application activities, and benefit-release venues. Empty optional locations
remain optional; partial pins and submitted water/foreign pins are rejected.
Existing stored coordinates are not rewritten by this release.

## Boundary Sources

- Land: geoBoundaries gbOpen PHL ADM0, boundary ID PHL-ADM0-24100683, pinned
  revision `9469f09`. Source attribution: OCHA Philippines and National Mapping
  and Resource Information Authority (NAMRIA). License: CC BY 3.0 IGO,
  https://creativecommons.org/licenses/by/3.0/igo/.
  Metadata: https://www.geoboundaries.org/api/current/gbOpen/PHL/ADM0/.
  Source: https://media.githubusercontent.com/media/wmgeolab/geoBoundaries/9469f09/releaseData/gbOpen/PHL/ADM0/geoBoundaries-PHL-ADM0.geojson.
- Inland water: GSHHG 2.3.7, full resolution, lake, island-in-lake, and pond
  hierarchy within the Philippine region. Paul Wessel (University of Hawai'i,
  SOEST) and Walter H. F. Smith (NOAA). LGPL v3 or later. Licenses accompany
  the distributed data in `public/maps/GSHHG-*`.
  Source archive: https://www.soest.hawaii.edu/pwessel/gshhg/gshhg-bin-2.3.7.zip.
  License supplement: https://zenodo.org/records/7007502.

Reproduce with `python scripts/build_philippine_map_data.py` after downloading
the source files to the locations named by the script. All islands are kept.
Rings are simplified with 0.00010-degree tolerance and delta-encoded at six
decimal places; per-polygon bounds avoid decoding the whole country on PHP.
The shared output is `public/maps/philippines-land-v1.json` (1.8 MB uncompressed).

This is an approximate map guard, not a cadastral or legal-boundary authority.
It rejects offshore points and mapped inland lakes, but small/unmapped streams,
ponds, reclaimed areas, and shoreline inaccuracies may not be represented.
Users near the coast should select the actual building/land location. Boundary
updates should be reviewed against the land/water regression fixtures before
publishing a new versioned file. Do not silently widen a rejected point using
a distance buffer, which would admit water.

Nominatim reverse lookup returns a nearby mapped object, not proof the clicked
point is land: https://nominatim.org/release-docs/latest/api/Reverse/.
Therefore lookup failure cannot bypass the geographic check. Valid land pins
remain selectable if the optional address lookup is unavailable; users then
enter the address themselves. A missing boundary file fails closed for new pins.

## Map Reliability

Leaflet 1.9.4 and its CSS/marker images are served from `public/maps/leaflet`,
with its BSD license. No external script CDN is required. Vue uses shallow
references for Leaflet instances; initialization rechecks the mounted element
after asynchronous loading. Script loading and address requests have timeouts.
New picks supersede prior lookups, and closing a modal cancels pending lookups.
Confirmation is disabled while checking or without a valid land pin.

The `/vendor/` URL is intentionally blocked by the hosting security rules;
public map assets must not be placed there or those protections relaxed.

Map tiles and optional address lookup still require internet access. These
services do not control the server's land-validation decision.

## Tile Identity And Privacy (2026-11-05)

The shared map uses the canonical `https://tile.openstreetmap.org/{z}/{x}/{y}.png`
endpoint. Tile images and optional address lookups explicitly use
`referrerPolicy: 'strict-origin'`: HTTPS requests identify the site's real origin
without revealing private paths, opaque record tokens, or query parameters.
The application's global `no-referrer` header remains unchanged for all other
requests. Leaflet 1.9.4 sets the image policy before assigning its tile URL.
No proxy, alternate subdomain rotation, user-agent spoofing, cache bypass,
prefetching, or automatic retry storm is used to work around a provider block.

Policy: https://operations.osmfoundation.org/policies/tiles/.
Leaflet option: https://leafletjs.com/reference-1.9.4.html#tilelayer-referrerpolicy.
Automated map checks must intercept tile requests with fixtures, preserve
normal browser caching, and inspect origin-only headers without repeatedly
requesting real third-party tiles. A block already applied by OpenStreetMap may
need time or operator review to clear; a corrected request is not a guarantee
that an upstream service will immediately lift an existing block.
