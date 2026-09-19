"""Verify GA4 access for every configured property — OUTSIDE Claude Desktop.

Run this BEFORE touching claude_desktop_config.json:

    uv run smoke_test.py

It isolates every Google-side problem (API not enabled, service account not
granted on a property, wrong property ID, bad key path) from every MCP-side
problem. If this passes, any remaining failure is in the Claude Desktop config.
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


def main() -> int:
    print()
    print("=" * 66)
    print("  GA4 MCP smoke test")
    print("=" * 66)
    print(f"  Service account : {server.SERVICE_ACCOUNT_EMAIL}")
    print(f"  Key file        : {os.environ.get('GOOGLE_APPLICATION_CREDENTIALS')}")
    print(f"  Default site    : {server._resolve(None)[0]}")
    print(f"  Properties      : {len(server.PROPERTIES)}")
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
        print(f"  {len(failures)} of {len(server.PROPERTIES)} properties failed: "
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
        print()
        return 1

    print(f"  All {len(server.PROPERTIES)} properties OK.")
    print("  Next: add the server to %APPDATA%\\Claude\\claude_desktop_config.json,")
    print("  then fully quit Claude Desktop from the system tray and reopen it.")
    print()
    return 0


if __name__ == "__main__":
    sys.exit(main())
