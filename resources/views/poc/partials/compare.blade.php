<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,600&family=IBM+Plex+Sans:wght@400;500;600&family=IBM+Plex+Mono:wght@400;500&display=swap">
<style>
/* Layout: one reading column, wide tables scroll in place; two engine columns side by side where compared. */
:root {
  --bg:#f4f6f3; --surface:#ffffff; --fg:#18201b; --muted:#5a665f; --line:#d6ddd7;
  --osm:#0f6b4f; --osm-soft:#e1efe8; --goo:#1d4fa8; --goo-soft:#e3eaf7; --warn:#9a4a14; --warn-soft:#f6e9dd;
  --display:"Fraunces", Georgia, serif; --body:"IBM Plex Sans", system-ui, sans-serif; --mono:"IBM Plex Mono", ui-monospace, monospace;
}
@media (prefers-color-scheme: dark) { :root:not([data-theme="light"]) {
  --bg:#121614; --surface:#1a201d; --fg:#e4ebe6; --muted:#9aa8a0; --line:#2c3530;
  --osm:#5cc59c; --osm-soft:#183329; --goo:#8fb0f0; --goo-soft:#1b2640; --warn:#e8a06a; --warn-soft:#3a2617; color-scheme:dark; } }
:root[data-theme="dark"] {
  --bg:#121614; --surface:#1a201d; --fg:#e4ebe6; --muted:#9aa8a0; --line:#2c3530;
  --osm:#5cc59c; --osm-soft:#183329; --goo:#8fb0f0; --goo-soft:#1b2640; --warn:#e8a06a; --warn-soft:#3a2617; color-scheme:dark; }
body { background:var(--bg); color:var(--fg); font:15px/1.6 var(--body); }
.wrap { max-width:1040px; margin:0 auto; padding-block:40px 64px; padding-left:max(16px, env(safe-area-inset-left, 0px)); padding-right:max(16px, env(safe-area-inset-right, 0px)); display:flex; flex-direction:column; gap:44px; }
h1 { font:600 clamp(28px,4.4vw,42px)/1.15 var(--display); margin:0; text-wrap:balance; }
h2 { font:600 24px/1.25 var(--display); margin:0 0 6px; text-wrap:balance; }
h3 { font-size:15px; margin:0; font-weight:600; }
p { margin:0; max-width:68ch; }
.lede { color:var(--muted); font-size:17px; margin-top:12px; }
.eyebrow { font:500 12px var(--mono); letter-spacing:.06em; text-transform:uppercase; color:var(--muted); }
section { display:flex; flex-direction:column; gap:16px; }
.verdict { background:var(--surface); border:1px solid var(--line); border-left:4px solid var(--osm); padding:18px 20px; display:flex; flex-direction:column; gap:8px; }
.two { display:grid; grid-template-columns:1fr 1fr; gap:16px; }
.two > * { min-width:0; }
@media (max-width:720px){ .two { grid-template-columns:1fr; } }
.engine { background:var(--surface); border:1px solid var(--line); padding:16px 18px; display:flex; flex-direction:column; gap:10px; }
.tag { display:inline-block; font:500 12px var(--mono); padding:2px 8px; border-radius:3px; }
.tag.osm { background:var(--osm-soft); color:var(--osm); } .tag.goo { background:var(--goo-soft); color:var(--goo); }
.tag.same { background:var(--line); color:var(--fg); }
.scroll { overflow-x:auto; border:1px solid var(--line); background:var(--surface); }
table { border-collapse:collapse; width:100%; min-width:680px; font-size:14px; }
th, td { text-align:left; vertical-align:top; padding:10px 12px; border-bottom:1px solid var(--line); }
th { font:500 12px var(--mono); letter-spacing:.04em; text-transform:uppercase; color:var(--muted); background:var(--bg); }
tr:last-child td { border-bottom:0; }
td.num { font-variant-numeric:tabular-nums; font-family:var(--mono); white-space:nowrap; }
code { font:13px var(--mono); background:var(--bg); padding:1px 5px; border-radius:3px; }
pre { margin:0; font:12.5px/1.55 var(--mono); background:var(--bg); border:1px solid var(--line); padding:12px; overflow-x:auto; }
ul { margin:0; padding-left:18px; display:flex; flex-direction:column; gap:6px; }
.pro li::marker { content:"+  "; color:var(--osm); font-weight:600; }
.con li::marker { content:"–  "; color:var(--warn); font-weight:600; }
.flow { display:grid; grid-template-columns:repeat(5,1fr); gap:8px; }
.flow > div { background:var(--surface); border:1px solid var(--line); padding:10px 12px; display:flex; flex-direction:column; gap:4px; min-width:0; }
.flow .n { font:500 12px var(--mono); color:var(--muted); }
@media (max-width:820px){ .flow { grid-template-columns:1fr 1fr; } }
@media (max-width:460px){ .flow { grid-template-columns:1fr; } }
.note { background:var(--warn-soft); color:var(--fg); padding:12px 14px; border-left:3px solid var(--warn); font-size:14px; }
.muted { color:var(--muted); font-size:13px; }
.chart { display:flex; flex-direction:column; gap:10px; }
.bar-row { display:grid; grid-template-columns:110px 1fr; gap:10px; align-items:center; font-size:13px; }
.bars { display:flex; flex-direction:column; gap:3px; }
.bar { height:14px; display:flex; align-items:center; }
.bar span { display:block; height:100%; }
.bar em { font:12px var(--mono); font-style:normal; margin-left:8px; color:var(--muted); white-space:nowrap; }
.legend { display:flex; gap:16px; flex-wrap:wrap; font-size:13px; }
.legend i { display:inline-block; width:10px; height:10px; margin-right:6px; vertical-align:middle; }
</style>

<div class="wrap">
<header>
  <div class="eyebrow">EasyPeasy · audience builder · map engine</div>
  <h1>MapLibre + OSM or Google Maps, both drawing with Terra Draw</h1>
  <p class="lede">The same workflow on each engine: find an area, draw it, edit it, send the polygon to PostGIS for a letterbox count and quote, then save it to a campaign. Prices are list prices in USD, checked October 2026. Confirm them before you budget.</p>
</header>

<div class="verdict">
  <div class="eyebrow">Recommendation</div>
  <p><strong>Draw with Terra Draw on both engines, and keep MapLibre + OSM vector tiles as the default.</strong> Add Google only as an optional satellite or Street View layer for clients who ask for it. Terra Draw runs on either engine through an adapter, so which engine you use is a config switch, not a rewrite. Your <code>MapProvider</code> interface already has <code>maplibre</code> and <code>google</code>.</p>
</div>

<section>
  <div><div class="eyebrow">The workflow</div><h2>The same five steps on both engines</h2></div>
  <div class="flow">
    <div><span class="n">1 · Find</span><h3>Search an address or suburb</h3><span class="muted">G-NAF lookup in PostGIS</span></div>
    <div><span class="n">2 · Draw</span><h3>Pins, trace or radius</h3><span class="muted">Terra Draw modes</span></div>
    <div><span class="n">3 · Edit</span><h3>Move vertices, drag, delete</h3><span class="muted">Terra Draw select mode</span></div>
    <div><span class="n">4 · Estimate</span><h3>Count letterboxes, then quote</h3><span class="muted"><code>POST /api/areas/estimate</code></span></div>
    <div><span class="n">5 · Save</span><h3>Attach to the campaign</h3><span class="muted"><code>PUT campaigns/{id}/areas</code></span></div>
  </div>
  <p class="muted">Steps 1, 4 and 5 run on your server and don't change. Only the basemap and the drawing layer differ between engines.</p>
</section>

<section>
  <div><div class="eyebrow">What's used where</div><h2>Component map</h2></div>
  <div class="scroll"><table>
    <thead><tr><th>Concern</th><th>MapLibre + OSM</th><th>Google Maps</th></tr></thead>
    <tbody>
      <tr><td>Map renderer</td><td><code>maplibre-gl</code> 5.x, already installed</td><td>Maps JavaScript API, loaded with <code>importLibrary('maps')</code></td></tr>
      <tr><td>Street basemap</td><td>OpenFreeMap (free), MapTiler, Stadia, or self-hosted Protomaps PMTiles</td><td>Google vector map, styled through a cloud Map ID</td></tr>
      <tr><td>Satellite</td><td>MapTiler Satellite or Esri World Imagery, both paid and with patchier rural detail</td><td>Google imagery, which is the strongest option for regional Australia</td></tr>
      <tr><td>Drawing</td><td><code>terra-draw</code> + <code>terra-draw-maplibre-gl-adapter</code></td><td><code>terra-draw</code> + <code>terra-draw-google-maps-adapter</code>, since the Drawing Library was removed in May 2026</td></tr>
      <tr><td>Draw modes (app → Terra Draw)</td><td colspan="2"><span class="tag same">identical</span> pins → <code>TerraDrawPolygonMode</code> · trace → <code>TerraDrawFreehandMode</code> · radius → <code>TerraDrawCircleMode</code> · edit → <code>TerraDrawSelectMode</code> · suburb pick is unchanged (server boundary)</td></tr>
      <tr><td>Output format</td><td colspan="2"><span class="tag same">identical</span> GeoJSON <code>[lng, lat]</code>, sent straight to PostGIS. No <code>LatLng</code> conversion needed.</td></tr>
      <tr><td>Address search</td><td>G-NAF in PostGIS, free and the authoritative AU source</td><td>G-NAF, optionally with Places Autocomplete for business names (billed)</td></tr>
      <tr><td>Letterbox count, 20 km rule, validation</td><td colspan="2"><span class="tag same">identical</span> PostGIS, through <code>LetterboxEstimator</code></td></tr>
      <tr><td>Job and invoice thumbnails</td><td>Render from your own tiles or with a static renderer, at no cost</td><td>Maps Static API, billed per image</td></tr>
      <tr><td>Street View</td><td>Not available. Mapillary is the closest substitute.</td><td>Built in</td></tr>
      <tr><td>Storing results</td><td>Unrestricted under the ODbL attribution terms</td><td>Your own polygons are fine. Google content can't be cached except place IDs.</td></tr>
      <tr><td>CI and Playwright</td><td>Runs offline from a local style</td><td>Needs a test key and live network, or the map mocked</td></tr>
    </tbody>
  </table></div>
</section>

<section>
  <div><div class="eyebrow">Proof of concept</div><h2>One drawing setup, two adapters</h2></div>
  <p>The POC is at <em>Live POC</em> tab of this page, switch engines, draw in each mode, and press <em>Estimate</em> to hit the real endpoint. Shapes carry over when you switch engines.</p>
  <div class="two">
    <div class="engine"><span class="tag osm">MapLibre adapter</span>
<pre>const map = new maplibregl.Map({
  container, center, zoom,
  style: 'https://tiles.openfreemap.org/styles/liberty',
});
await map.once('style.load');
const adapter =
  new TerraDrawMapLibreGLAdapter({ map });</pre></div>
    <div class="engine"><span class="tag goo">Google adapter</span>
<pre>const map = new google.maps.Map(el, {
  center, zoom, mapId: MAP_ID,
});
// wait for projection before attaching
const adapter = new TerraDrawGoogleMapsAdapter({
  lib: google.maps, map, coordinatePrecision: 9,
});</pre></div>
  </div>
<pre>// Identical on both engines
const draw = new TerraDraw({ adapter, modes: [
  new TerraDrawPolygonMode(),   // "pins"
  new TerraDrawFreehandMode(),  // "trace"
  new TerraDrawCircleMode(),    // "radius"
  new TerraDrawSelectMode({ flags: { polygon: { feature: { draggable: true,
      coordinates: { midpoints: true, draggable: true, deletable: true } } } } }),
]});
draw.start(); draw.setMode('polygon');
draw.on('finish', () => {
  const polygon = draw.getSnapshot().at(-1).geometry;   // GeoJSON, ready for PostGIS
  post('/api/areas/estimate', { geometry: polygon });
});</pre>
  <div class="note"><strong>Integration path.</strong> Today <code>AudienceBuilder.tsx</code> draws on its own SVG overlay through <code>project</code>/<code>unproject</code>. Moving to Terra Draw would replace that overlay with one <code>createDraw(provider)</code> helper next to <code>createProvider</code>. You'd keep the overlay's custom bits, like the radius ring and pickup pin, as Terra Draw styles or a small extra layer. Rough effort: 3–5 days including tests.</div>
</section>

<section>
  <div><div class="eyebrow">Cost estimate</div><h2>Monthly running cost by traffic</h2></div>
  <p>A "map load" is one page view that shows an interactive map: the draw wizard, a campaign view or an admin job view. Assumptions: one map load per visit, address search through G-NAF (free), and around 20 vector tiles per load on MapLibre.</p>
  <div class="scroll"><table>
    <thead><tr><th>Line item</th><th>Unit price</th><th>5k loads/mo</th><th>25k loads/mo</th><th>100k loads/mo</th></tr></thead>
    <tbody>
      <tr><td><strong>Google</strong> · Dynamic Maps</td><td class="num">10k free, then $7 / 1k</td><td class="num">$0</td><td class="num">$105</td><td class="num">$630</td></tr>
      <tr><td>Google · Places Autocomplete (optional, 1 session per load)</td><td class="num">10k free, then ≈$2.83 / 1k</td><td class="num">$0</td><td class="num">$42</td><td class="num">$255</td></tr>
      <tr><td>Google · Static Maps thumbnails (2 per load)</td><td class="num">10k free, then $2 / 1k</td><td class="num">$0</td><td class="num">$80</td><td class="num">$380</td></tr>
      <tr><td><strong>Google total</strong> (maps only / with extras)</td><td></td><td class="num"><strong>$0 / $0</strong></td><td class="num"><strong>$105 / $227</strong></td><td class="num"><strong>$630 / $1,265</strong></td></tr>
      <tr><td><strong>MapLibre</strong> · OpenFreeMap tiles</td><td class="num">Free, no SLA</td><td class="num">$0</td><td class="num">$0</td><td class="num">$0</td></tr>
      <tr><td>MapLibre · MapTiler Flex (managed with SLA)</td><td class="num">$25 incl. 500k tiles, then ≈$0.10 / 1k</td><td class="num">$25</td><td class="num">$25</td><td class="num">≈$175</td></tr>
      <tr><td>MapLibre · self-hosted Protomaps PMTiles (AU extract on R2/S3 + CDN)</td><td class="num">storage + egress</td><td class="num">≈$5</td><td class="num">≈$8</td><td class="num">≈$20</td></tr>
      <tr><td><strong>MapLibre total</strong> (managed / self-hosted)</td><td></td><td class="num"><strong>$25 / $5</strong></td><td class="num"><strong>$25 / $8</strong></td><td class="num"><strong>$175 / $20</strong></td></tr>
      <tr><td>Optional satellite on MapLibre (MapTiler)</td><td class="num">counted inside the tile quota</td><td class="num">+$0</td><td class="num">+$0–25</td><td class="num">+$50–100</td></tr>
    </tbody>
  </table></div>

  <div class="chart" role="img" aria-label="Monthly cost at 100k map loads: Google with extras $1,265, Google maps only $630, MapTiler managed $175, self-hosted $20">
    <div class="legend"><span><i style="background:var(--goo)"></i>Google</span><span><i style="background:var(--osm)"></i>MapLibre + OSM</span></div>
    <div class="bar-row"><span>5k / mo</span><div class="bars">
      <div class="bar"><span style="width:0.2%;background:var(--goo)"></span><em>$0 Google</em></div>
      <div class="bar"><span style="width:2%;background:var(--osm)"></span><em>$25 MapTiler</em></div></div></div>
    <div class="bar-row"><span>25k / mo</span><div class="bars">
      <div class="bar"><span style="width:18%;background:var(--goo)"></span><em>$227 Google + extras</em></div>
      <div class="bar"><span style="width:2%;background:var(--osm)"></span><em>$25 MapTiler</em></div></div></div>
    <div class="bar-row"><span>100k / mo</span><div class="bars">
      <div class="bar"><span style="width:80%;background:var(--goo)"></span><em>$1,265 Google + extras</em></div>
      <div class="bar"><span style="width:14%;background:var(--osm)"></span><em>$175 MapTiler</em></div></div></div>
  </div>
  <p class="muted">Estimates only. Google's Essentials SKUs get cheaper per 1k above 100k a month. MapTiler's tile count depends on how much users pan. Set a Google Cloud budget alert and per-API quotas either way. One-off build cost is similar for both, at 3–5 days, because Terra Draw is shared.</p>
</section>

<section>
  <div><div class="eyebrow">Pros and cons</div><h2>Trade-offs</h2></div>
  <div class="two">
    <div class="engine"><span class="tag osm">MapLibre + OSM + Terra Draw</span>
      <h3>Pros</h3><ul class="pro">
        <li>Flat, predictable cost that stays low at scale</li>
        <li>No licence limits on storing, caching or combining data with PostGIS and G-NAF</li>
        <li>Full styling control for coverage zones, runs and GPS traces</li>
        <li>Works offline in CI, so Playwright runs stay deterministic</li>
        <li>Already your default provider, so this path has the fewest changes</li>
      </ul>
      <h3>Cons</h3><ul class="con">
        <li>Satellite imagery is weaker in regional areas</li>
        <li>No Street View</li>
        <li>Free tile hosts have no SLA, so pay MapTiler or self-host for production</li>
        <li>You own style upgrades and attribution</li>
      </ul></div>
    <div class="engine"><span class="tag goo">Google Maps + Terra Draw</span>
      <h3>Pros</h3><ul class="pro">
        <li>Best satellite imagery, and Street View for checking delivery areas</li>
        <li>Map is familiar to business clients</li>
        <li>Places search finds business names, not only addresses</li>
        <li>Google handles uptime, scaling and map updates</li>
      </ul>
      <h3>Cons</h3><ul class="con">
        <li>Cost scales per load and grows quickly with admin and job pages</li>
        <li>Drawing Library is gone, so you depend on Terra Draw anyway</li>
        <li>ToS: Google data must sit on a Google map, with no caching</li>
        <li>CI needs a live key or mocks, and you have to manage key leaks and quotas</li>
        <li>Less control over styling vector overlays</li>
      </ul></div>
  </div>
</section>

<section>
  <div><div class="eyebrow">Decision guide</div><h2>When to choose which</h2></div>
  <div class="scroll"><table>
    <thead><tr><th>If…</th><th>Choose</th></tr></thead>
    <tbody>
      <tr><td>The goal is low cost, owning your data, and drawing plus counting are the core value</td><td><span class="tag osm">MapLibre + OSM</span></td></tr>
      <tr><td>Clients judge areas from rooftops, or ask for Street View</td><td><span class="tag goo">Google</span>, or MapLibre with Google kept for satellite view only</td></tr>
      <tr><td>You expect more than 50k map loads a month within a year</td><td><span class="tag osm">MapLibre + OSM</span></td></tr>
      <tr><td>Users search for businesses by name, not by address</td><td><span class="tag goo">Google</span> Places, on a Google map</td></tr>
    </tbody>
  </table></div>
</section>
</div>
