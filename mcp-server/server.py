"""GA4 MCP server for Claude Desktop — multi-property (SE / DK / FI / NL / NO).

Windows hardening (see README "Why the code looks like this"):
  * REST transport, never gRPC        -> avoids the anyio/grpc.aio stdio deadlock
  * explicit service-account file     -> avoids google-auth shelling out to gcloud
  * client built at import time       -> before anyio owns stdin/stdout
  * tools are sync `def`, not `async` -> FastMCP runs them in a worker thread
  * logging to stderr only            -> stdout is the MCP transport; print() corrupts it
"""

from __future__ import annotations

import json
import logging
import os
import re
import sys
from typing import Any

from google.analytics.data_v1beta import BetaAnalyticsDataClient
from google.analytics.data_v1beta.types import (
    DateRange,
    Dimension,
    Filter,
    FilterExpression,
    FilterExpressionList,
    GetMetadataRequest,
    Metric,
    OrderBy,
    RunRealtimeReportRequest,
    RunReportRequest,
)
from google.oauth2 import service_account
from mcp.server.fastmcp import FastMCP

# stderr only — anything on stdout breaks the JSON-RPC framing.
logging.basicConfig(
    level=logging.INFO,
    stream=sys.stderr,
    format="%(asctime)s %(levelname)s ga4-mcp: %(message)s",
)
log = logging.getLogger("ga4-mcp")

SCOPES = ["https://www.googleapis.com/auth/analytics.readonly"]

# --------------------------------------------------------------------------
# Properties
# --------------------------------------------------------------------------
# One service-account key covers all of these. To add a market, add a line
# here and grant the same service account Viewer on that GA4 property.
PROPERTIES: dict[str, str] = {
    "GA4 se": "288325313",
    "GA4 dk": "402979710",
    "GA4 fi": "444992118",
    "GA4 nl": "503085272",
    "GA4 no": "473792228",
}

DEFAULT_SITE = os.environ.get("GA4_DEFAULT_SITE", "GA4 se").strip() or "GA4 se"


def _norm(value: str) -> str:
    """Fold a site name to a lookup key: 'GA4 se' / 'SE' / 'ga4_se' -> 'se'."""
    key = re.sub(r"[^a-z0-9]", "", value.lower())
    return key[3:] if key.startswith("ga4") else key


# Accept the canonical name, any spelling of it, and the bare property ID.
_INDEX: dict[str, tuple[str, str]] = {}
for _name, _pid in PROPERTIES.items():
    _INDEX[_norm(_name)] = (_name, _pid)
    _INDEX[_pid] = (_name, _pid)


def _resolve(site: str | None) -> tuple[str, str]:
    """Return (canonical_name, property_id) for a site, or raise ValueError."""
    raw = (site or DEFAULT_SITE).strip()
    hit = _INDEX.get(_norm(raw)) or _INDEX.get(raw)
    if hit:
        return hit
    if raw.isdigit():  # a property ID we don't know about — let it through
        return (f"property {raw}", raw)
    raise ValueError(
        f"Unknown site {raw!r}. Valid sites: {', '.join(PROPERTIES)} "
        f"(short forms like 'se' or 'dk' work too, as does a numeric property ID)."
    )


if _norm(DEFAULT_SITE) not in _INDEX and DEFAULT_SITE not in _INDEX:
    raise RuntimeError(
        f"GA4_DEFAULT_SITE={DEFAULT_SITE!r} is not one of: {', '.join(PROPERTIES)}"
    )

# --------------------------------------------------------------------------
# Credentials + client — built eagerly, before mcp.run() takes over stdio.
# --------------------------------------------------------------------------
_key_path = os.environ.get("GOOGLE_APPLICATION_CREDENTIALS", "").strip()
if not _key_path:
    raise RuntimeError(
        "GOOGLE_APPLICATION_CREDENTIALS is not set. Point it at the service-account "
        "JSON key file in the 'env' block of claude_desktop_config.json."
    )
if not os.path.isfile(_key_path):
    raise RuntimeError(
        f"GOOGLE_APPLICATION_CREDENTIALS points at {_key_path!r}, which is not a file. "
        "On Windows, escape backslashes in JSON (C:\\\\Users\\\\...) or use forward slashes."
    )

# NOT google.auth.default(): on Windows that can shell out to gcloud, whose
# subprocess inherits the piped stdio handles and deadlocks the server.
_creds = service_account.Credentials.from_service_account_file(_key_path, scopes=SCOPES)
SERVICE_ACCOUNT_EMAIL = getattr(_creds, "service_account_email", "(unknown)")

# transport="rest" keeps gRPC out of the process entirely.
_client = BetaAnalyticsDataClient(credentials=_creds, transport="rest")

log.info(
    "ready — service account %s, %d properties, default %s",
    SERVICE_ACCOUNT_EMAIL,
    len(PROPERTIES),
    DEFAULT_SITE,
)

mcp = FastMCP("ga4")

# --------------------------------------------------------------------------
# Helpers
# --------------------------------------------------------------------------
MAX_LIMIT = 1000


def _coerce(value: str) -> Any:
    """GA4 returns every metric as a string; give Claude numbers where we can."""
    try:
        return int(value)
    except ValueError:
        pass
    try:
        return round(float(value), 4)
    except ValueError:
        return value


def _tabulate(response: Any) -> dict[str, Any]:
    """Flatten a runReport/runRealtimeReport response into rows of plain dicts."""
    dim_names = [h.name for h in response.dimension_headers]
    met_names = [h.name for h in response.metric_headers]

    rows = []
    for row in response.rows:
        record: dict[str, Any] = {}
        for name, cell in zip(dim_names, row.dimension_values):
            record[name] = cell.value
        for name, cell in zip(met_names, row.metric_values):
            record[name] = _coerce(cell.value)
        rows.append(record)

    totals: dict[str, Any] = {}
    if response.totals:
        for name, cell in zip(met_names, response.totals[0].metric_values):
            totals[name] = _coerce(cell.value)

    return {
        "rows": rows,
        "totals": totals,
        "row_count": getattr(response, "row_count", len(rows)),
        "returned": len(rows),
    }


_MATCH_TYPES = {
    "EXACT": Filter.StringFilter.MatchType.EXACT,
    "CONTAINS": Filter.StringFilter.MatchType.CONTAINS,
    "BEGINS_WITH": Filter.StringFilter.MatchType.BEGINS_WITH,
    "ENDS_WITH": Filter.StringFilter.MatchType.ENDS_WITH,
    "FULL_REGEXP": Filter.StringFilter.MatchType.FULL_REGEXP,
    "PARTIAL_REGEXP": Filter.StringFilter.MatchType.PARTIAL_REGEXP,
}


def _build_filter(
    spec: dict[str, Any] | list[dict[str, Any]] | None,
) -> FilterExpression | None:
    """Build a dimension filter from one condition dict, or several ANDed together.

    Each condition: {"field": "country", "value": "Sweden",
                     "match_type": "EXACT", "case_sensitive": false, "negate": false}
    """
    if not spec:
        return None
    conditions = spec if isinstance(spec, list) else [spec]

    built: list[FilterExpression] = []
    for cond in conditions:
        field = cond.get("field") or cond.get("dimension")
        if not field or "value" not in cond:
            raise ValueError(
                "Each dimension_filter condition needs 'field' and 'value', "
                f"got {cond!r}"
            )
        match_name = str(cond.get("match_type", "EXACT")).upper()
        if match_name not in _MATCH_TYPES:
            raise ValueError(
                f"match_type {match_name!r} is not one of: {', '.join(_MATCH_TYPES)}"
            )
        expr = FilterExpression(
            filter=Filter(
                field_name=field,
                string_filter=Filter.StringFilter(
                    value=str(cond["value"]),
                    match_type=_MATCH_TYPES[match_name],
                    case_sensitive=bool(cond.get("case_sensitive", False)),
                ),
            )
        )
        if cond.get("negate"):
            expr = FilterExpression(not_expression=expr)
        built.append(expr)

    if len(built) == 1:
        return built[0]
    return FilterExpression(and_group=FilterExpressionList(expressions=built))


def _build_order(
    order_by: str | None, descending: bool, metrics: list[str], dimensions: list[str]
) -> list[OrderBy]:
    if not order_by:
        return []
    if order_by in metrics:
        return [
            OrderBy(metric=OrderBy.MetricOrderBy(metric_name=order_by), desc=descending)
        ]
    if order_by in dimensions:
        return [
            OrderBy(
                dimension=OrderBy.DimensionOrderBy(dimension_name=order_by),
                desc=descending,
            )
        ]
    raise ValueError(
        f"order_by={order_by!r} must be one of the requested metrics "
        f"({', '.join(metrics) or 'none'}) or dimensions "
        f"({', '.join(dimensions) or 'none'})."
    )


def _explain(exc: Exception, site_name: str, property_id: str) -> str:
    """Turn API errors into something actionable rather than a stack trace."""
    text = str(exc)
    if "Unauthorized" in text or "401" in text or "invalid_grant" in text:
        return (
            f"Authentication failed (401) for {SERVICE_ACCOUNT_EMAIL}. The key file at "
            f"{_key_path} is wrong, revoked, or belongs to a deleted service account — "
            f"or this machine's clock is skewed, which invalidates the signed token. "
            f"Re-download the key from Cloud console -> IAM -> Service Accounts -> Keys. "
            f"Original error: {text}"
        )
    if "PERMISSION_DENIED" in text or "403" in text:
        return (
            f"Permission denied for {site_name} (property {property_id}). "
            f"In GA4 Admin -> Property Access Management for that property, add "
            f"{SERVICE_ACCOUNT_EMAIL} with at least the Viewer role. "
            f"Note this is granted inside GA4, not in Google Cloud IAM."
        )
    if "SERVICE_DISABLED" in text or "has not been used in project" in text:
        return (
            "The Google Analytics Data API is not enabled on the Cloud project that "
            "owns this service account. Enable 'Google Analytics Data API' in the "
            f"Cloud console, wait a minute, then retry. Original error: {text}"
        )
    if "NOT_FOUND" in text or "404" in text:
        return (
            f"Property {property_id} ({site_name}) was not found. Check the numeric "
            f"property ID in GA4 Admin -> Property Settings. Original error: {text}"
        )
    if "INVALID_ARGUMENT" in text or "400" in text:
        return (
            f"GA4 rejected the request — usually a misspelled dimension or metric "
            f"name, or one that isn't valid for this property. Call get_metadata "
            f"for the valid names. Original error: {text}"
        )
    return f"{type(exc).__name__}: {text}"


# --------------------------------------------------------------------------
# Tools
# --------------------------------------------------------------------------
@mcp.tool()
def list_properties() -> dict[str, Any]:
    """List the GA4 properties this server can query, and which one is the default.

    Call this first if you are unsure which site names are available. Every other
    tool accepts a `site` argument using any of the names shown here.
    """
    return {
        "default_site": _resolve(None)[0],
        "properties": [
            {"site": name, "property_id": pid, "short_form": _norm(name)}
            for name, pid in PROPERTIES.items()
        ],
        "service_account": SERVICE_ACCOUNT_EMAIL,
        "note": "site accepts 'GA4 se', 'se', or the numeric property ID.",
    }


@mcp.tool()
def run_report(
    metrics: list[str],
    start_date: str = "28daysAgo",
    end_date: str = "yesterday",
    dimensions: list[str] | None = None,
    site: str | None = None,
    limit: int = 50,
    order_by: str | None = None,
    descending: bool = True,
    dimension_filter: dict[str, Any] | list[dict[str, Any]] | None = None,
) -> dict[str, Any]:
    """Run a GA4 report for one property.

    Args:
        metrics: GA4 metric API names, e.g. ["sessions", "totalUsers", "screenPageViews"].
        start_date: "YYYY-MM-DD" or a GA4 relative date like "28daysAgo", "today".
        end_date: "YYYY-MM-DD" or a relative date like "yesterday".
        dimensions: GA4 dimension API names, e.g. ["date", "country", "pagePath"].
        site: Which property to query. Defaults to the server's default site.
        limit: Maximum rows to return (capped at 1000).
        order_by: A name from `metrics` or `dimensions` to sort by.
        descending: Sort direction for order_by.
        dimension_filter: One condition, or a list ANDed together, each shaped
            {"field": "country", "value": "Sweden", "match_type": "EXACT",
             "case_sensitive": false, "negate": false}.

    Returns rows as plain dicts keyed by dimension/metric name, plus totals.
    """
    try:
        site_name, property_id = _resolve(site)
        dims = dimensions or []
        request = RunReportRequest(
            property=f"properties/{property_id}",
            date_ranges=[DateRange(start_date=start_date, end_date=end_date)],
            dimensions=[Dimension(name=d) for d in dims],
            metrics=[Metric(name=m) for m in metrics],
            limit=max(1, min(limit, MAX_LIMIT)),
            order_bys=_build_order(order_by, descending, metrics, dims),
            dimension_filter=_build_filter(dimension_filter),
        )
        result = _tabulate(_client.run_report(request))
        result["site"] = site_name
        result["property_id"] = property_id
        result["date_range"] = {"start_date": start_date, "end_date": end_date}
        return result
    except ValueError as exc:
        return {"error": str(exc)}
    except Exception as exc:  # noqa: BLE001 - surface as text, never crash the server
        name, pid = (site or DEFAULT_SITE, "?")
        try:
            name, pid = _resolve(site)
        except ValueError:
            pass
        log.warning("run_report failed for %s: %s", name, exc)
        return {"error": _explain(exc, name, pid)}


@mcp.tool()
def compare_sites(
    metrics: list[str],
    start_date: str = "28daysAgo",
    end_date: str = "yesterday",
    sites: list[str] | None = None,
    dimensions: list[str] | None = None,
    limit: int = 10,
) -> dict[str, Any]:
    """Run the same report across several properties and return one combined table.

    This is the tool for cross-market questions: "compare sessions across all
    markets", "which market grew most last month", "top pages per country site".

    Args:
        metrics: GA4 metric API names applied to every site.
        start_date: "YYYY-MM-DD" or a relative date like "28daysAgo".
        end_date: "YYYY-MM-DD" or a relative date like "yesterday".
        sites: Which properties to include. Defaults to all configured sites.
        dimensions: Optional breakdown applied within each site.
        limit: Max rows per site (kept small because results are concatenated).

    Each returned row carries a "site" key. Sites that fail are reported in
    "errors" rather than failing the whole call.
    """
    targets = sites or list(PROPERTIES)
    dims = dimensions or []

    combined: list[dict[str, Any]] = []
    site_totals: dict[str, Any] = {}
    errors: dict[str, str] = {}

    for target in targets:
        try:
            site_name, property_id = _resolve(target)
        except ValueError as exc:
            errors[str(target)] = str(exc)
            continue
        try:
            request = RunReportRequest(
                property=f"properties/{property_id}",
                date_ranges=[DateRange(start_date=start_date, end_date=end_date)],
                dimensions=[Dimension(name=d) for d in dims],
                metrics=[Metric(name=m) for m in metrics],
                limit=max(1, min(limit, MAX_LIMIT)),
                order_bys=_build_order(
                    metrics[0] if metrics and not dims else None, True, metrics, dims
                ),
            )
            table = _tabulate(_client.run_report(request))
            for row in table["rows"]:
                combined.append({"site": site_name, **row})
            site_totals[site_name] = table["totals"]
        except Exception as exc:  # noqa: BLE001
            log.warning("compare_sites failed for %s: %s", site_name, exc)
            errors[site_name] = _explain(exc, site_name, property_id)

    return {
        "date_range": {"start_date": start_date, "end_date": end_date},
        "metrics": metrics,
        "rows": combined,
        "totals_by_site": site_totals,
        "errors": errors,
    }


@mcp.tool()
def top_pages(
    start_date: str = "28daysAgo",
    end_date: str = "yesterday",
    site: str | None = None,
    limit: int = 20,
) -> dict[str, Any]:
    """Most-viewed pages for one property, with views, users and average engagement time.

    A convenience wrapper over run_report for the most common question.
    """
    return run_report(
        metrics=["screenPageViews", "totalUsers", "userEngagementDuration"],
        start_date=start_date,
        end_date=end_date,
        dimensions=["pagePath", "pageTitle"],
        site=site,
        limit=limit,
        order_by="screenPageViews",
        descending=True,
    )


@mcp.tool()
def run_realtime_report(
    metrics: list[str] | None = None,
    dimensions: list[str] | None = None,
    site: str | None = None,
    limit: int = 50,
) -> dict[str, Any]:
    """Realtime GA4 data for one property — roughly the last 30 minutes.

    Args:
        metrics: Defaults to ["activeUsers"].
        dimensions: e.g. ["unifiedScreenName"], ["country"], ["deviceCategory"].
        site: Which property to query. Defaults to the server's default site.
        limit: Maximum rows to return.

    Note that realtime supports a smaller set of dimensions and metrics than
    standard reports.
    """
    mets = metrics or ["activeUsers"]
    try:
        site_name, property_id = _resolve(site)
        request = RunRealtimeReportRequest(
            property=f"properties/{property_id}",
            dimensions=[Dimension(name=d) for d in (dimensions or [])],
            metrics=[Metric(name=m) for m in mets],
            limit=max(1, min(limit, MAX_LIMIT)),
        )
        result = _tabulate(_client.run_realtime_report(request))
        result["site"] = site_name
        result["property_id"] = property_id
        return result
    except ValueError as exc:
        return {"error": str(exc)}
    except Exception as exc:  # noqa: BLE001
        name, pid = (site or DEFAULT_SITE, "?")
        try:
            name, pid = _resolve(site)
        except ValueError:
            pass
        log.warning("run_realtime_report failed for %s: %s", name, exc)
        return {"error": _explain(exc, name, pid)}


@mcp.tool()
def get_metadata(site: str | None = None, contains: str | None = None) -> dict[str, Any]:
    """List the dimensions and metrics valid for a property, including custom ones.

    Use this instead of guessing API names — custom dimensions differ per property.

    Args:
        site: Which property to inspect. Defaults to the server's default site.
        contains: Optional case-insensitive filter on API name or display name,
            e.g. "revenue" or "session".
    """
    try:
        site_name, property_id = _resolve(site)
        response = _client.get_metadata(
            GetMetadataRequest(name=f"properties/{property_id}/metadata")
        )

        needle = contains.lower() if contains else None

        def keep(api_name: str, display: str) -> bool:
            return needle is None or needle in api_name.lower() or needle in display.lower()

        dimensions = [
            {"api_name": d.api_name, "display_name": d.ui_name, "custom": d.custom_definition}
            for d in response.dimensions
            if keep(d.api_name, d.ui_name)
        ]
        metrics = [
            {"api_name": m.api_name, "display_name": m.ui_name, "custom": m.custom_definition}
            for m in response.metrics
            if keep(m.api_name, m.ui_name)
        ]
        return {
            "site": site_name,
            "property_id": property_id,
            "dimensions": dimensions,
            "metrics": metrics,
            "dimension_count": len(dimensions),
            "metric_count": len(metrics),
        }
    except ValueError as exc:
        return {"error": str(exc)}
    except Exception as exc:  # noqa: BLE001
        name, pid = (site or DEFAULT_SITE, "?")
        try:
            name, pid = _resolve(site)
        except ValueError:
            pass
        log.warning("get_metadata failed for %s: %s", name, exc)
        return {"error": _explain(exc, name, pid)}


if __name__ == "__main__":
    mcp.run()
