# Mezmur audio: storage and streaming setup

**Status:** reference. Mezmur audio is stored in Cloudflare R2 and streamed through a custom domain ([ADR-0015](../adr/0015-mezmur-media-object-storage.md)). This guide covers sizing, the one-time setup, verification and streaming quality. Whether R2 is configured on a given server is not recorded in the repository; check that server's `.fkss_env.php`.

Host names below are placeholders: `<your-domain>` is a domain in your Cloudflare account and `<admin-site-host>` is the host that serves the admin console.

---

## 1. Sizing and cost

Planning assumptions: about 3,000 hymns, an average length of 4 min 30 s (270 s), and about 5,000 daily users playing about 2 hymns each. Size = bitrate × duration ÷ 8.

| Encoding | Per hymn | 3,000 hymns | Fits the 10 GB R2 free tier? |
|---|---|---|---|
| 128 kbps AAC | 3.38 MB | **10.1 GB** | No, just over |
| 112 kbps AAC | 2.95 MB | **8.9 GB** | Yes, tight |
| **96 kbps AAC** | **2.53 MB** | **7.6 GB** | **Yes, comfortable** |
| 64 kbps AAC | 1.69 MB | 5.1 GB | Yes, very safe |

**Recommended encoding: 96 kbps AAC (`.m4a`), mono or stereo.** Mezmur is voice and choir rather than a full orchestral mix; 96 kbps AAC is transparent for that material and leaves about 2.4 GB of headroom.

**Traffic:** about 10,000 plays per day, or 300,000 per month. At 96 kbps a full play is 2.53 MB, so egress is about 26 GB per month.

Pricing below is as published by the providers in September 2026. Check current pricing before relying on it.

| R2 meter | Free allowance | Expected usage | Cost |
|---|---|---|---|
| Storage | 10 GB-month | 7.6 GB | $0 |
| **Egress** | **unlimited** | 26 GB | **$0** |
| Class A (writes) | 1M per month | about 3,000 once, then rare | $0 |
| Class B (reads) | 10M per month | about 0.3–1M (less with caching) | $0 |

Overage rates: $0.015 per GB of storage, $4.50 per million writes, $0.36 per million reads.

| Provider | Free storage | Free egress | Cost at 26 GB per month |
|---|---|---|---|
| **Cloudflare R2** | 10 GB, permanent | **unlimited** | **$0** |
| Backblaze B2 | 10 GB | 1 GB per day (about 30 GB per month) | about $0, but a traffic spike exceeds it; $0.01 per GB after |
| Wasabi | 1 TB for a 30-day trial only | none after the trial | full price |
| AWS S3 | 5 GB for 12 months | 100 GB for 12 months, then $0.09 per GB | about $2.30 per month after the first year |
| Bunny CDN | none | none | about $0.26 per month |
| The shared web host | disk quota | bandwidth quota | 7–12 GB of files plus 26 GB per month of streaming risks throttling or suspension |

Serving audio from the shared host is the option to avoid. This is why the application uploads directly to R2.

---

## 2. Setup (one time)

### Step 1: create the bucket
Cloudflare dashboard → **R2 Object Storage** → **Create bucket**. Name: `fkss-media`. Location: **Automatic**.

> If the dashboard asks to purchase a plan before creating a bucket, use the **"R2 Free"** / **"Continue with free plan"** link. R2 does not require the paid Workers plan.

### Step 2: do not use the `r2.dev` URL
`pub-xxxx.r2.dev` is rate-limited and intended for development: no caching and no WAF. Use a custom domain.

### Step 3: connect a custom domain
Bucket → **Settings** → **Public access** → **Custom Domains** → **Connect Domain** → enter `media.<your-domain>`.

Cloudflare adds the DNS record and issues the TLS certificate; no manual CNAME and no Worker are needed.

Requirements:
- `<your-domain>` must be an **active zone in the same Cloudflare account** as the bucket. If the site's domain is not on Cloudflare, see [section 6](#6-custom-domain-when-the-site-domain-is-not-on-cloudflare).
- Disable CNAME flattening on that hostname if it is enabled.
- HTTPS only; R2 custom domains do not serve plain HTTP.

### Step 4: create an API token
R2 → **Manage R2 API Tokens** → **Create API Token**:
- Permission: **Object Read & Write**
- Scope: **Apply to specific buckets only** → `fkss-media`
- TTL: no expiry, or rotate yearly

Copy the **Access Key ID** and the **Secret Access Key**. The secret is shown only once.

### Step 5: configure `.fkss_env.php`
This file lives **above** the web root (for example `~/.fkss_env.php`) and is never committed.

```php
// Mezmur audio media (Cloudflare R2)
define('MEZMUR_MEDIA_ACCOUNT_ID',        'YOUR_32_HEX_ACCOUNT_ID');   // dashboard right-hand panel → Account ID
define('MEZMUR_MEDIA_ACCESS_KEY_ID',     'YOUR_ACCESS_KEY_ID');       // from step 4
define('MEZMUR_MEDIA_SECRET_ACCESS_KEY', 'YOUR_SECRET_ACCESS_KEY');   // from step 4
define('MEZMUR_MEDIA_BUCKET',            'fkss-media');
define('MEZMUR_MEDIA_PUBLIC_BASE',       'https://media.<your-domain>');  // no trailing slash
define('MEZMUR_MEDIA_MAX_BYTES',         10485760);   // optional; 10 MB here
define('MEZMUR_MEDIA_ALLOWED_EXT',       'm4a,aac,mp3,opus,ogg');   // optional
```

- The first four values are required: storage counts as configured only when all four are set (`MezmurMediaService::isConfigured()`). `MEZMUR_MEDIA_PUBLIC_BASE` is required for playback URLs.
- `MEZMUR_MEDIA_ACCOUNT_ID` is the 32-character hexadecimal **Account ID**, not the bucket name or the API token ID.
- When unset, `MEZMUR_MEDIA_MAX_BYTES` defaults to 15 MB and `MEZMUR_MEDIA_ALLOWED_EXT` to `mp3, m4a, ogg, wav, aac, opus`.

### Step 6: bucket CORS (web console uploads only)
Bucket → **Settings** → **CORS policy**:

```json
[
  {
    "AllowedOrigins": ["https://<admin-site-host>"],
    "AllowedMethods": ["PUT"],
    "AllowedHeaders": ["Content-Type", "Content-Length"],
    "ExposeHeaders": ["ETag"],
    "MaxAgeSeconds": 3600
  }
]
```

The Android app is a native client and does not need CORS. The web console uploads directly with `PUT` and fails without this rule.

---

## 3. Verify

1. Admin console → Mezmur → a hymn's **audio** button → **Choose audio file…**
2. The progress bar reaches 100% and the console shows **"Audio attached — streaming from the CDN."**
3. In the R2 dashboard the object appears at `mz/audio/{hymn_id}/{uuid}.m4a`.
4. Open the returned `audio_url` in a browser: it must **stream**, not download.

If the console reports *"R2 media storage is not configured"*, one of the four required values in step 5 is missing or misspelled. If it reports *"Audio columns are missing"*, apply `sql/038_mezmur_audio_media.sql` or use **Sync DB schema** in the Mezmur console.

---

## 4. Streaming quality

**a) Prefer M4A/AAC over MP3.** An MP3's exact duration and seek table live in an optional Xing/VBRI header. Files without one (common with VBR encodes and simple converters) give the player only an approximate duration and byte-offset seeking, which shows as a wrong total time and a jumping scrubber. An M4A stores the duration in the `moov` atom, and `-movflags +faststart` moves that atom to the front, so the player knows the duration after the first few kilobytes. Well-formed CBR MP3 files work; when converting, convert to M4A. `just_audio` handles both.

```bash
# Batch-convert a folder to streamable M4A at 96 kbps (faststart puts moov first)
for f in *.mp3 *.wav *.flac; do
  [ -e "$f" ] || continue
  ffmpeg -i "$f" -c:a aac -b:a 96k -movflags +faststart "${f%.*}.m4a"
done
```

**b) Cloudflare caching on the custom domain.** A cache hit is not a Class B read and is served from the edge nearest the user. To cache every hymn, add a **Cache Rule** for `media.<your-domain>/mz/audio/*`. Free plans cache files up to 512 MB.

**c) Range requests.** Seeking uses HTTP 206 partial responses. R2 and Cloudflare support them natively; nothing to configure.

**d) Player.** `MezmurAudioPlayerController` uses `just_audio` and `just_audio_background`: streaming without download, background playback, lock-screen controls, and a queue built from the current filtered view.

---

## 5. Changing provider or domain later

No code change is needed. `MEZMUR_MEDIA_PUBLIC_BASE` is the only place a media host name appears, and the database stores only object keys. Moving to another bucket, domain or S3-compatible provider changes configuration only.

---

## 6. Custom domain when the site domain is not on Cloudflare

R2 custom domains require the domain to be an active zone in the same Cloudflare account as the bucket. Check where the site's domain is hosted:

```bash
dig +short NS <site-domain>
```

If the answer is not two `*.ns.cloudflare.com` lines, choose one of the options below.

### Option 1: dedicated media domain (lower risk)

Register a separate domain through **Cloudflare Registrar**. A domain registered there uses Cloudflare nameservers, so it is an active zone immediately: no nameserver migration and no effect on the live site. At-cost pricing in September 2026: `.org` about $8.50 per year, `.com` about $10.46, `.net` about $11.86.

```
1. Cloudflare dashboard → Domain Registration → Register Domains → choose <media-domain>
2. R2 Object Storage → Create bucket → fkss-media
3. Bucket → Settings → Public access → Custom Domains → Connect Domain → media.<media-domain>
4. R2 → Manage R2 API Tokens → Object Read & Write, scoped to fkss-media → copy the key and secret
5. .fkss_env.php → MEZMUR_MEDIA_PUBLIC_BASE = https://media.<media-domain>
```

### Option 2: move the site domain to Cloudflare

Add the existing domain to Cloudflare, let it import the DNS records, then change the nameservers at the registrar. This costs nothing and also gives the whole site Cloudflare's CDN, DDoS protection and WAF.

```
1. Cloudflare dashboard → Add a site → <site-domain> → Free plan
2. Cloudflare scans and imports the DNS records
3. Compare the imported records with the registrar's current records; TXT, CNAME and MX records are sometimes missed
4. Change the nameservers at the registrar to the two Cloudflare provides
5. Wait for propagation (minutes to 48 hours)
6. R2 → Custom Domains → media.<site-domain>
```

**Risk:** MX, SPF (TXT) and SRV records must stay **DNS only** (grey cloud), never proxied; proxying a mail record breaks email delivery. Compare `dig +short MX <site-domain>` before and after.

| | Option 1 | Option 2 |
|---|---|---|
| Cost | about $8.50 per year | $0 |
| Time | about 15 minutes | about 15 minutes plus up to 48 hours of propagation |
| Risk to the live site | none | low, but real (missed records, mail) |
| Additional benefit | none | CDN, WAF and DDoS protection for the whole site |

The two options are independent: Option 1 can be used first and Option 2 adopted later. Only `MEZMUR_MEDIA_PUBLIC_BASE` changes.

---

## 7. Encoding new hymns

```bash
ffmpeg -i input.wav -c:a aac -b:a 96k -ar 44100 -movflags +faststart output.m4a
```

- **96 kbps:** a 4 min 30 s hymn is 2.53 MB, so 3,000 hymns are about 7.6 GB, inside the 10 GB free tier.
- **`-movflags +faststart`:** duration metadata at the front of the file, so the progress bar is correct and seeking works from the first request.
- **`.m4a`:** accepted by default and included in the example `MEZMUR_MEDIA_ALLOWED_EXT`.
- With a 10 MB limit (`MEZMUR_MEDIA_MAX_BYTES` in the example), a 96 kbps file can be up to about 14 minutes long.
