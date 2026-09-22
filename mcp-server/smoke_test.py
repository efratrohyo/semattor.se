"""Verify GA4 and Search Console access — OUTSIDE Claude Desktop.

Run this BEFORE touching claude_desktop_config.json:

    uv run smoke_test.py

It isolates every Google-side problem (API not enabled, service account not
granted on a property, wrong property ID, bad key path) from every MCP-side
problem. If this passes, any remaining failure is in the Claude Desktop config.

GA4 and Search Console need SEPARATE access grants for the same service
account, so one can pass while the other fails.
"""

from __future__ import annotations

import os
import sys

# Importing server.py performs all the fail-fast checks: env vars present,
# key file readable, default site valid, client constructible.
try:
    import server
except Exception as exc:  # noqa: BLE001
    print(f"\n[FAIL] server.py could not start:\n       {exc}\n", file=sys.stderr)
    print("Common causes:", file=sys.stderr)
    print("  * GOOGLE_APPLICATION_CREDENTIALS not set, or pointing at a missing file",
          file=sys.stderr)
    print("  * the path has unescaped backslashes", file=sys.stderr)
    sys.exit(1)

from google.analytics.data_v1beta.types import (  # noqa: E402
    DateRange,
    Metric,
    RunReportRequest,
)


def check_ga4() -> int:
    print()
    print("=" * 66)
    print("  GA4 MCP smoke test")
    print("=" * 66)
    print(f"  Service account : {server.SERVICE_ACCOUNT_EMAIL}")
    print(f"  Key file        : {os.environ.get('GOOGLE_APPLICATION_CREDENTIALS')}")
    print(f"  Default site    : {server._resolve(None)[0]}")
    print(f"  GA4 properties  : {len(server.PROPERTIES)}")
    print(f"  GSC default     : {server.GSC_DEFAULT_SITE}")
    print("=" * 66)
    print()

    failures: list[str] = []
    saw_auth_failure = False

    for site_name, property_id in server.PROPERTIES.items():
        label = f"{site_name} ({property_id})"
        try:
            response = server._client.run_report(
                RunReportRequest(
                    property=f"properties/{property_id}",
                    date_ranges=[DateRange(start_date="7daysAgo", end_date="yesterday")],
                    metrics=[Metric(name="sessions"), Metric(name="totalUsers")],
                )
            )
            if response.rows:
                sessions = response.rows[0].metric_values[0].value
                users = response.rows[0].metric_values[1].value
                print(f"  [ OK ]  {label:<28}  {sessions:>9} sessions, {users:>9} users (last 7d)")
            else:
                print(f"  [ OK ]  {label:<28}  connected, but no data in the last 7 days")
        except Exception as exc:  # noqa: BLE001
            failures.append(site_name)
            text = str(exc)
            if "401" in text or "Unauthorized" in text or "invalid_grant" in text:
                saw_auth_failure = True
            # Drop the raw API tail — it is identical for every property and
            # makes the report unreadable. The translated part is what matters.
            explained = server._explain(exc, site_name, property_id)
            explained = explained.split(" Original error:")[0]
            print(f"  [FAIL]  {label}")
            print(f"          {explained}")
            print()

    print()
    if failures:
        print(f"  {len(failures)} of {len(server.PROPERTIES)} GA4 properties failed: "
              f"{', '.join(failures)}")
        print()
        if saw_auth_failure:
            print("  This is an AUTHENTICATION failure, not a permissions one — the key")
            print("  itself was rejected, so granting GA4 access will not help. Check that")
            print("  GOOGLE_APPLICATION_CREDENTIALS points at the right JSON key, that the")
            print("  key has not been revoked, and that this machine's clock is correct.")
        else:
            print("  Most likely fix: in GA4 Admin -> Property Access Management for each")
            print(f"  failing property, add {server.SERVICE_ACCOUNT_EMAIL} as a Viewer.")
            print("  That is granted inside GA4, NOT in Google Cloud IAM.")
    else:
        print(f"  All {len(server.PROPERTIES)} GA4 properties OK.")

    return 1 if failures else 0


def check_gsc() -> int:
    """List Search Console properties the service account can read."""
    print()
    print("=" * 66)
    print("  Search Console")
    print("=" * 66)

    result = server.list_gsc_sites()

    if "error" in result:
        print(f"  [FAIL]  {result['error'].split(' Original error:')[0]}")
        print()
        return 1

    sites = result.get("sites", [])
    if not sites:
        print("  [FAIL]  No properties visible to this service account.")
        print()
        print("  Search Console access is granted SEPARATELY from GA4 and from")
        print("  enabling the API. For each property:")
        print("    Search Console -> Settings -> Users and permissions -> Add user")
        print(f"    -> {server.SERVICE_ACCOUNT_EMAIL} -> Full")
        print()
        return 1

    for site in sites:
        print(f"  [ OK ]  {site['site_url']:<40} {site['permission_level']}")

    if result.get("warning"):
        print()
        print(f"  [WARN]  {result['warning']}")
        print()
        print("  The gsc_* tools still work if you pass the full site_url above;")
        print("  only the short names ('se', 'dk', ...) are affected.")
        print()
        return 0

    print()
    print(f"  All {len(sites)} Search Console properties OK, short names match.")
    print()
    return 0


def main() -> int:
    ga4_rc = check_ga4()
    gsc_rc = check_gsc()

    print("=" * 66)
    if ga4_rc == 0 and gsc_rc == 0:
        print("  Everything OK.")
        print("  Next: add the server to %APPDATA%\\Claude\\claude_desktop_config.json,")
        print("  then fully quit Claude Desktop from the system tray and reopen it.")
    else:
        print(f"  GA4: {'OK' if ga4_rc == 0 else 'FAILED'}   "
              f"Search Console: {'OK' if gsc_rc == 0 else 'FAILED'}")
        print("  Fix the failures above before touching claude_desktop_config.json.")
    print("=" * 66)
    print()
    return ga4_rc or gsc_rc


if __name__ == "__main__":
    sys.exit(main())
