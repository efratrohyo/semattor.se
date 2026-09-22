# GA4 + Search Console MCP Server

Query five GA4 properties **and** Google Search Console from Claude Desktop.
One service-account key covers everything.

| Site | Property ID | Short forms accepted |
|---|---|---|
| `GA4 se` **(default)** | 288325313 | `se`, `SE`, `GA4 se`, `288325313` |
| `GA4 dk` | 402979710 | `dk`, `DK`, … |
| `GA4 fi` | 444992118 | `fi`, `FI`, … |
| `GA4 nl` | 503085272 | `nl`, `NL`, … |
| `GA4 no` | 473792228 | `no`, `NO`, … |

Every tool takes an optional `site` argument. Omit it and you get **GA4 se**.

---

## Install on Windows

Target layout — the key sits **outside** `mcp-server\` so it can never be committed:

```
C:\Users\Shei\Documents\Code Claude Windows\
├── mcp-server\     <- this folder
└── secrets\
    └── ga4-sa.json <- your service-account key
```

### 1. Install uv

```powershell
winget install --id=astral-sh.uv -e
```

### 2. Find where uv actually landed

Do **not** guess this path — winget and the standalone installer use different
locations. Whatever this prints is what goes in `"command"` below.

```powershell
(Get-Command uv).Source
```

### 3. Build the environment

```powershell
cd "C:\Users\Shei\Documents\Code Claude Windows\mcp-server"
uv sync
```

`uv` downloads Python 3.12 itself (per `.python-version`) and installs the pinned
dependencies. No system Python needed. This also creates `uv.lock` on first run —
commit it, it is what makes the setup reproducible on another machine.

### 4. Put the key in place

Save the service-account JSON to
`C:\Users\Shei\Documents\Code Claude Windows\secrets\ga4-sa.json`.

### 4b. Grant the service account Search Console access

**Separate from GA4, and separate from enabling the API.** For each property:

> Search Console → select property → **Settings** → **Users and permissions**
> → **Add user** → paste the service-account email → **Full** → Add

Skip this and every `gsc_*` call returns 403. `list_gsc_sites` returning an empty list
is the symptom.

### 5. Prove access before involving Claude Desktop

```powershell
$env:GOOGLE_APPLICATION_CREDENTIALS = "C:\Users\Shei\Documents\Code Claude Windows\secrets\ga4-sa.json"
uv run smoke_test.py
```

This checks **all five GA4 properties** and prints last-7-days sessions for each, then
lists your Search Console properties. Do not continue until every row says `[ OK ]`.

GA4 and GSC are reported separately — one can pass while the other fails, because they
need separate grants.

A `[FAIL]` with *permission denied* means that property is missing the grant:
GA4 Admin → Property Access Management → add the service-account email (the
smoke test prints it) as **Viewer**. That is granted inside GA4, **not** in
Google Cloud IAM.

### 6. Wire up Claude Desktop

Open `%APPDATA%\Claude\claude_desktop_config.json` and merge in the contents of
`claude_desktop_config.example.json`, correcting the three paths for your machine.

Then **fully quit Claude Desktop from the system tray** — closing the window
leaves it running and the config will not reload — and reopen it.

### 7. Try it

> Using the ga4 tools, compare sessions and users across all markets for the last 28 days.

Then the combined question, which is the point of having both:

> Using the ga4 tools, show my top 10 pages by views for the last 28 days. Then using the
> gsc tools, show my top 10 search queries for the same period. Put them side by side.

---

## Tools

### GA4 (analytics — what people did on the site)

| Tool | What it does |
|---|---|
| `list_properties` | Which sites exist, which is default, the service-account email |
| `run_report` | Any GA4 report for one site — metrics, dimensions, filters, sorting |
| `compare_sites` | **The same report across several markets, in one table** |
| `top_pages` | Most-viewed pages for one site |
| `run_realtime_report` | Active users now (~last 30 min) |
| `get_metadata` | Valid dimension/metric names for a property, incl. custom ones |

### Search Console (search — what people searched to get there)

| Tool | What it does |
|---|---|
| `list_gsc_sites` | **Run first.** Verified properties + permission level |
| `gsc_search_analytics` | Clicks, impressions, CTR, position by query/page/country/device/date |
| `gsc_top_queries` | Top search queries by clicks |
| `gsc_list_sitemaps` | Submitted sitemaps, errors, warnings, indexed counts |
| `gsc_inspect_url` | Index status, canonicals, last crawl, robots state for one URL |

Two differences from the GA4 tools worth knowing:

- **Dates.** GA4 takes `28daysAgo`; Search Console needs `YYYY-MM-DD`. The tools accept
  both and translate for you.
- **Freshness.** Search Console data lags 2–3 days, so `end_date` defaults to `3daysAgo`,
  not `yesterday`. Ending at `yesterday` returns partial or empty rows.

GSC properties are identified by URL, not numeric ID. Short names (`se`, `dk`) are mapped
in `GSC_SITES` in `server.py`; a full `sc-domain:example.com` or `https://www.example.com/`
always works too.

`compare_sites` is the reason the multi-property setup earns its keep — it runs
the query per property and concatenates the rows with a `site` column. A site
that fails lands in `errors` rather than failing the whole call.

### Dimension filters

One condition, or a list ANDed together:

```json
{"field": "country", "value": "Sweden", "match_type": "EXACT"}
```

`match_type` is one of `EXACT`, `CONTAINS`, `BEGINS_WITH`, `ENDS_WITH`,
`FULL_REGEXP`, `PARTIAL_REGEXP`. Add `"negate": true` to invert.

---

## Moving this to another PC

Only **three values are per-machine**, and all three live in
`claude_desktop_config.json`:

1. absolute path to `uv.exe`
2. absolute path to this folder
3. absolute path to the service-account JSON

Everything else — Python version, every dependency version, the property map —
travels with the folder. On the new machine: install uv → copy or clone this
folder → `uv sync` → place the key → `uv run smoke_test.py` → edit the config.

Two rules that make or break it:

- **Never copy `.venv\`.** It hardcodes absolute paths and will fail in confusing
  ways. `.gitignore` excludes it; `uv sync` rebuilds it in seconds.
- **Never commit the key.** `.gitignore` excludes `*.json` here, and the key
  belongs in `..\secrets\` anyway. To move it, use a password manager — or just
  mint a second key for the new PC and revoke it independently.

---

## Adding a sixth market

Add one line to `PROPERTIES` and one to `GSC_SITES` in `server.py`, then grant the
**same** service account access in both GA4 (Viewer) and Search Console (Full).
Nothing else changes.

```python
PROPERTIES = {
    "GA4 se": "288325313",
    ...
    "GA4 de": "123456789",   # new
}
```

---

## Why the code looks like this

The official `googleanalytics/google-analytics-mcp` server has a long record of
Windows-only failures. Four of its design choices are deliberately inverted here:

| Their approach | Failure on Windows | What this server does |
|---|---|---|
| gRPC transport | `grpc.aio` channel creation deadlocks inside anyio's stdio context (issues #153, #139) | `transport="rest"` — no gRPC in the process |
| `gcloud` ADC | `google-auth` spawns `gcloud.cmd`, which inherits the piped stdio handles and hangs (#156, #154, #134) | explicit `from_service_account_file()` |
| lazy client creation | tools time out after the transport owns stdio | client built at import, before `mcp.run()` |
| `async def` tools | a slow call blocks the loop servicing stdio | sync `def` — FastMCP runs them in a worker thread |

Also: all logging goes to **stderr**, because stdout is the MCP transport and a
stray `print()` corrupts the JSON-RPC framing. And `mcp` is pinned `<2` because
SDK 2.0 renamed `Tool.inputSchema` and broke fresh installs (#199).

---

## Rolling back to GA4-only

```powershell
cd "C:\Users\Shei\Documents\Code Claude Windows\mcp-server"
Copy-Item server.py server.py.gsc-broken -Force      # keep it for diagnosis
Copy-Item <your-backup>\server.py server.py -Force   # restore GA4-only
Get-Process Claude -EA SilentlyContinue | Stop-Process -Force
```

Reopen Claude Desktop — the six GA4 tools return, the five GSC tools disappear.

You do **not** need to revert `pyproject.toml`: the two extra packages are inert if
unimported, and leaving them makes a retry quicker. `claude_desktop_config.json` needs no
revert either. No GA4 code path was changed, so the realistic failure mode is "GSC tools
error", not "GA4 broke".

## Troubleshooting

| Symptom | Cause | Fix |
|---|---|---|
| Server doesn't appear in Claude Desktop | Wrong `uv.exe` path, or Claude not fully restarted | `(Get-Command uv).Source`; quit from the **system tray** |
| `spawn ENOENT` in the log | Bare command name instead of an absolute path | Use the full `uv.exe` path in `"command"` |
| Config file won't load | Unescaped backslashes, or a trailing comma | Use `\\` in JSON; validate the file parses |
| `PERMISSION_DENIED` for one market | That property is missing the grant | Add the SA as Viewer in that property's GA4 Access Management |
| `SERVICE_DISABLED` | Data API not enabled on the Cloud project | Enable "Google Analytics Data API" |
| Tools appear but every call errors | Read the actual message — errors are translated, not raw | See `%APPDATA%\Claude\logs\mcp-server-ga4.log` |
| `list_gsc_sites` returns an empty list | Service account not added in Search Console | Settings → Users and permissions → Add user |
| GSC 404 on a site | `siteUrl` doesn't match exactly | `list_gsc_sites` gives the exact string; trailing slash matters |
| GSC returns no rows for recent dates | Data lags 2–3 days | Use `end_date` of `3daysAgo` or earlier |
| `gsc_inspect_url` 429 | Quota | 2,000/day and 600/min per property |

Inspect the tools interactively without Claude Desktop:

```powershell
uv run mcp dev server.py
```
