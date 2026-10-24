# Mezmur Audio — Storage & Streaming Setup (free tier, ~5,000 daily users, ~3,000 hymns)

**Bottom line: stay on Cloudflare R2.** It is the only mainstream object store whose free
tier has **free egress with no cap**, and egress is the entire cost of an audio service.
Every "simpler" alternative caps egress at 1–3 GB/day and would cost you real money at
your traffic. What confused you was almost certainly the **custom-domain** step and the
"buy a Workers plan" prompt — both are explained below, and neither requires paying.

---

## 1. Your actual numbers

Assume an average hymn of **4:30** (270 s). Size = bitrate × duration ÷ 8:

| Encode | Per hymn | 3,000 hymns | Fits R2's 10 GB free? |
|---|---|---|---|
| 128 kbps AAC | 3.38 MB | **10.1 GB** | ✗ just over |
| 112 kbps AAC | 2.95 MB | **8.9 GB** | ✓ tight |
| **96 kbps AAC** | **2.53 MB** | **7.6 GB** | ✓ **comfortable** |
| 64 kbps AAC | 1.69 MB | 5.1 GB | ✓ very safe |

**Recommendation: 96 kbps AAC, mono or stereo.** Mezmur is voice + choir, not a full
orchestral mix; 96 kbps AAC is transparent for that material and leaves you 2.4 GB of
headroom for growth. At 128 kbps you cross 10 GB and start paying ~$0.15/month — trivial,
but there is no reason to.

### Traffic

- 5,000 daily users × ~2 hymns = **~10,000 plays/day ≈ 300,000/month**
- At 96 kbps, a full play is 2.53 MB → **~26 GB egress/month**

### Cost on R2 free tier

| Meter | Free allowance | Your usage | Bill |
|---|---|---|---|
| Storage | 10 GB-month | 7.6 GB | **$0** |
| **Egress** | **unlimited, always free** | 26 GB | **$0** |
| Class A (writes) | 1M/month | ~3,000 ever, then rare | **$0** |
| Class B (reads) | 10M/month | ~0.3–1M (less with caching) | **$0** |

**Total: $0/month, permanently.** Even if you blew past every allowance, overage is
$0.015/GB storage, $4.50/M writes, $0.36/M reads — a bad month would cost a few dollars,
not hundreds.

### Why not the alternatives

| Provider | Free storage | Free egress | Your 26 GB/month would cost |
|---|---|---|---|
| **Cloudflare R2** | 10 GB, permanent | **unlimited, $0 forever** | **$0** |
| Backblaze B2 | 10 GB | 1 GB/day (~30 GB/mo) | ~$0 today, **breaks on any spike**, $0.01/GB after |
| Wasabi | 1 TB for 30 days only | none after trial | full price |
| AWS S3 | 5 GB, expires after 12 months | 100 GB for 12 months, then $0.09/GB | **~$2.30/mo, then forever** |
| Bunny CDN | none | none | ~$0.26/mo (cheap, not free) |
| Serve from your cPanel host | your disk quota | your bandwidth quota | 12 GB of files + 26 GB/mo through shared hosting = **throttled or suspended** |

Shared hosting is the one to avoid specifically: 3,000 audio files at 7–12 GB plus
26 GB/month of streaming through one PHP box is exactly what gets an account suspended,
and it is the reason this codebase uploads straight to R2 in the first place.

---

## 2. The six setup steps (about 10 minutes)

### Step 1 — Create the bucket
Cloudflare dashboard → **R2 Object Storage** → **Create bucket**.
Name it `fkss-media`. Location: **Automatic**.

> If the dashboard asks you to **purchase a plan** before creating a bucket, look for the
> small **"R2 Free"** / **"Continue with free plan"** link — R2 does not require the
> $5 Workers Paid plan. That prompt is the #1 reason people give up here.

### Step 2 — Do **not** use the `r2.dev` URL
`pub-xxxx.r2.dev` is **rate-limited, development-only, has no caching, and no WAF**.
Using it for 5,000 users will get you throttled. Everything below uses a custom domain.

### Step 3 — Connect a custom domain
Bucket → **Settings** → **Public access** → **Custom Domains** → **Connect Domain** →
enter `media.arkeonethiopia.com`.

Cloudflare **adds the DNS record for you** and issues the TLS certificate. You do not
create a CNAME by hand, and you do not need a Worker.

Requirements:
- `arkeonethiopia.com` must be an **active zone in the same Cloudflare account**.
- **Disable CNAME flattening** on that hostname if it is enabled.
- HTTPS only — R2 custom domains do not serve plain HTTP.

> **If `arkeonethiopia.com` is not on Cloudflare:** either move its nameservers to
> Cloudflare (free, but it changes your DNS hosting — do it deliberately), or register a
> separate cheap domain purely for media (~$10/year) and add it to your Cloudflare
> account. The app only needs the value of `MEZMUR_MEDIA_PUBLIC_BASE`, so the media
> domain does not have to match your site domain.

### Step 4 — Create an API token
R2 → **Manage R2 API Tokens** → **Create API Token**:
- Permission: **Object Read & Write**
- Scope: **Apply to specific buckets only** → `fkss-media`
- TTL: forever (or rotate yearly)

Copy the **Access Key ID** and the **Secret Access Key**. The secret is shown **once**.

### Step 5 — Fill in `.fkss_env.php`
This file lives **above** `public_html` (e.g. `/home/USER/.fkss_env.php`), never in git.

```php
// ── Mezmur audio media (Cloudflare R2) ─────────────────────────
define('MEZMUR_MEDIA_ACCOUNT_ID',        'YOUR_32_HEX_ACCOUNT_ID');   // dashboard right rail → Account ID
define('MEZMUR_MEDIA_ACCESS_KEY_ID',     'YOUR_ACCESS_KEY_ID');       // from step 4
define('MEZMUR_MEDIA_SECRET_ACCESS_KEY', 'YOUR_SECRET_ACCESS_KEY');   // from step 4
define('MEZMUR_MEDIA_BUCKET',            'fkss-media');
define('MEZMUR_MEDIA_PUBLIC_BASE',       'https://media.arkeonethiopia.com');  // NO trailing slash
define('MEZMUR_MEDIA_MAX_BYTES',         10485760);   // 10 MB
define('MEZMUR_MEDIA_ALLOWED_EXT',       'm4a,aac,mp3,opus,ogg');
```

`MEZMUR_MEDIA_ACCOUNT_ID` is the 32-hex **Account ID**, shown in the dashboard's right
hand rail — not the bucket name and not the API token ID.

### Step 6 — Bucket CORS (needed for the **web console** upload only)
Bucket → **Settings** → **CORS policy** → add:

```json
[
  {
    "AllowedOrigins": ["https://felegekidusan.arkeonethiopia.com"],
    "AllowedMethods": ["PUT"],
    "AllowedHeaders": ["Content-Type", "Content-Length"],
    "ExposeHeaders": ["ETag"],
    "MaxAgeSeconds": 3600
  }
]
```

The Flutter app is a native client and does **not** need CORS. If you only upload from
the phone you can skip this — but the web console's direct PUT will fail without it.

---

## 3. Verify it works

1. Admin console → Mezmur → a hymn's **audio** button → **Choose audio file…**
2. The progress bar should reach 100% and you should see **"Audio attached — streaming from the CDN."**
3. In the R2 dashboard the object appears at `mz/audio/{hymn_id}/{uuid}.m4a`.
4. Open the printed `audio_url` in a browser — it must **stream**, not download.

If it says *"R2 media storage is not configured"*, one of the five constants in step 5 is
missing or misspelled. If it says *"Audio columns are missing"*, run `sql/038` or press
**Sync DB schema** in the Mezmur console (the reconciler now adds them).

---

## 4. What actually makes it feel like Spotify

Storage choice is only half of it. These four things matter more:

**a) Prefer M4A/AAC over MP3.** An MP3's exact duration and seek table live in an optional
Xing/VBRI header; files without one (common in VBR encodes and in files produced by
naive converters) give the player only an approximate duration and byte-offset guessing
for seeks — which shows up as a wrong total time and a scrubber that jumps. An M4A puts
duration in the `moov` atom, and `-movflags +faststart` moves that atom to the front of
the file so the player knows the duration after the first few kilobytes. If your existing
library is already well-formed CBR MP3 it will work fine; if you are converting anything,
convert to M4A. `just_audio` (already in the app) handles both.

```bash
# batch-convert a folder to streamable M4A at 96 kbps (faststart puts moov first)
for f in *.mp3 *.wav *.flac; do
  [ -e "$f" ] || continue
  ffmpeg -i "$f" -c:a aac -b:a 96k -movflags +faststart "${f%.*}.m4a"
done
```

**b) Cloudflare caching on the custom domain.** A cache hit does **not** count as a
Class B read and is served from the edge nearest the user. Cloudflare caches common
static extensions by default; to cache every hymn, add a **Cache Rule** (or the legacy
Cache Everything page rule) for `media.arkeonethiopia.com/mz/audio/*`. Free plans cache
files up to 512 MB, so your ~2.5 MB hymns are fine.

**c) Range requests.** Seeking requires HTTP 206 partial responses. R2 and Cloudflare both
support them natively — nothing to configure, but this is the feature that makes scrubbing
instant rather than a re-download.

**d) The player is already built.** `MezmurAudioPlayerController` uses `just_audio` +
`just_audio_background`: streaming (no download), background playback, lock-screen
transport, queue from the current filtered view.

---

## 5. Later, when you outgrow free

You will not, for a long time. But the escape hatch is cheap and requires **no code
change**: `MEZMUR_MEDIA_PUBLIC_BASE` is the only place a media hostname appears, and the
database stores only an object key — so moving to a bigger bucket, a different domain, or
even a different provider touches one line of config, never your data.

---

## 6. Your situation: `arkeonethiopia.com` is NOT on Cloudflare — pick a path

**Verified from here:** `felegekidusan.arkeonethiopia.com` → `135.181.160.205`, responds
`HTTP/2 200` with `server: LiteSpeed` and **no `cf-ray` header**. So your site is on a
LiteSpeed shared host and is not proxied by Cloudflare.

**Could not verify from here:** your actual nameservers — this sandbox has no `dig`/`nslookup`
and the stub resolver does not return NS records. Run this yourself:

```bash
dig +short NS arkeonethiopia.com
```

If the answer is anything other than two `*.ns.cloudflare.com` lines, you are in the
situation below.

R2's custom-domain feature requires the domain to be an **active zone in the same
Cloudflare account** as the bucket. You have two ways to get there.

### Path B — dedicated media domain (**recommended**)

Buy a cheap domain **through Cloudflare Registrar**. This is the trick that removes all the
confusion: a domain registered with Cloudflare automatically uses Cloudflare nameservers,
so it lands in your account as an active zone **immediately** — no nameserver migration,
no propagation wait, no risk to your live site.

At-cost pricing, no markup: **`.org` ≈ $8.50/yr**, `.com` ≈ $10.46/yr, `.net` ≈ $11.86/yr.

```
1. dash.cloudflare.com → sign up free
2. Domain Registration → Register Domains → pick e.g.  fkssmedia.org
3. R2 Object Storage → Create bucket → fkss-media   (pick "R2 Free" if prompted)
4. bucket → Settings → Public access → Custom Domains → Connect Domain → media.fkssmedia.org
      ↳ Cloudflare writes the DNS record and issues the TLS certificate for you
5. R2 → Manage R2 API Tokens → Object Read & Write, scoped to fkss-media → copy the key + secret
6. .fkss_env.php  →  MEZMUR_MEDIA_PUBLIC_BASE = https://media.fkssmedia.org
```

**Cost: ~$8.50/year. Storage, bandwidth, TLS: $0. Your website: completely untouched.**

### Path A — move `arkeonethiopia.com` to Cloudflare (free, better long term, one risk)

Add the existing domain to Cloudflare, let it import your DNS records, then change
nameservers at your registrar. Costs nothing and additionally gives your **whole site**
Cloudflare's free CDN, DDoS protection and WAF — genuinely valuable at 5,000 daily users,
since your origin is one shared LiteSpeed box.

```
1. dash.cloudflare.com → Add a site → arkeonethiopia.com → Free plan
2. Cloudflare scans and imports your DNS records
3. COMPARE the imported list against your registrar's current records — Cloudflare
   occasionally misses TXT/CNAME/MX. Anything missing will break something.
4. Change nameservers at your registrar to the two Cloudflare gives you
5. Wait for propagation (minutes to 48 h)
6. Then R2 → Custom Domains → media.arkeonethiopia.com
```

⚠ **The one real risk:** make sure **MX, SPF (TXT) and any SRV records are grey-clouded
(DNS only), never proxied (orange)**. Proxying a mail record breaks email delivery. Check
`dig +short MX arkeonethiopia.com` before and after.

### Which to choose

| | Path B | Path A |
|---|---|---|
| Cost | ~$8.50/yr | $0 |
| Time | 15 min | 15 min + up to 48 h propagation |
| Risk to your live site | **none** | low, but real (missed records, mail) |
| Bonus | — | free CDN/WAF/DDoS for the whole site |

**Recommendation: do Path B now.** It unblocks audio today with zero risk to a working
site, and it is the only recurring cost in the whole audio stack. Do Path A later,
deliberately, when you want the CDN benefits for the main site — they are independent
decisions, and `MEZMUR_MEDIA_PUBLIC_BASE` is the only line that changes.

---

## 7. Your library is empty — so set the format right from day one

You have no existing files, which is the easy case. Encode every hymn as you add it:

```bash
ffmpeg -i input.whatever -c:a aac -b:a 96k -ar 44100 -movflags +faststart output.m4a
```

- **96 kbps** → a 4:30 hymn is 2.53 MB → 3,000 hymns ≈ **7.6 GB**, inside the 10 GB free tier
- **`-movflags +faststart`** → duration metadata at the front of the file, so the progress
  bar is correct and seeking is instant from the first request
- **`.m4a`** → already first in `MEZMUR_MEDIA_ALLOWED_EXT`; no code change needed
- The **10 MB** cap (`MEZMUR_MEDIA_MAX_BYTES`) gives you room up to ~14 minutes at 96k
