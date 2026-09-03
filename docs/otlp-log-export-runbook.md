# OTLP Log Export — Operational Runbook

Phase 8.8.8 — `back_vibes` staging & production.

---

## 1. Architecture overview

```
Laravel / Monolog
  → LOG_STACK=stderr,otel
  ├── stderr  (always on — DigitalOcean App Platform collects this)
  └── otel  (OpenTelemetryLoggerFactory)
        → FailSafeOtelHandler (swallows exporter exceptions)
          → OtelMonologHandler
            → Globals::loggerProvider()  (created by SdkAutoloader)
              → BatchLogRecordProcessor
                → OtlpProtobufLogsExporter (HTTP/protobuf)
                  → https://otel-staging.ixora-app.app/v1/logs
                    → OpenTelemetry Collector
                      → Loki → Grafana
```

Correlation taps (`TraceCorrelationLogTap` and the four error-context taps)
are injected automatically into **every** channel by `TelemetryServiceProvider`,
including `otel`.  Each `LogRecord` carries `extra.trace_id` and `extra.span_id`.

---

## 2. Required App Platform variables

### API component (`back_vibes-api`)

| Variable | Value |
|---|---|
| `OTEL_SERVICE_NAME` | `back_vibes-api` |
| `OTEL_SDK_DISABLED` | `false` |
| `OTEL_PHP_AUTOLOAD_ENABLED` | `true` |
| `OTEL_TRACES_EXPORTER` | `otlp` |
| `OTEL_METRICS_EXPORTER` | `otlp` |
| `OTEL_LOGS_EXPORTER` | `otlp` |
| `OTEL_EXPORTER_OTLP_ENDPOINT` | `https://otel-staging.ixora-app.app` |
| `OTEL_EXPORTER_OTLP_PROTOCOL` | `http/protobuf` |
| `OTEL_EXPORTER_OTLP_HEADERS` | `Authorization=Bearer <OTEL_INGEST_API_KEY_BACKEND>` (encrypted secret) |
| `OTEL_RESOURCE_ATTRIBUTES` | `service.namespace=ixora,service.version=<release-tag>,deployment.environment=staging` |
| `LOG_CHANNEL` | `stack` |
| `LOG_STACK` | `stderr,otel` |
| `OTEL_LOG_LEVEL` | `info` |
| `OTEL_PHP_DISABLED_INSTRUMENTATIONS` | *(leave empty or absent)* |

### Queue/scheduler worker component (`back_vibes-worker`)

Same as above except:

| Variable | Value |
|---|---|
| `OTEL_SERVICE_NAME` | `back_vibes-worker` |

---

## 3. Enable or disable OTLP logs per component

**Enable:**
```
OTEL_LOGS_EXPORTER=otlp
LOG_STACK=stderr,otel
```

**Disable (emergency kill switch):**
```
OTEL_LOGS_EXPORTER=none
LOG_STACK=stderr
```

stderr always remains active regardless. Application and platform logs are
never lost.

---

## 4. How stderr stays available

The `LOG_STACK=stderr,otel` configuration uses a Laravel `stack` channel. The
`otel` channel is protected by `FailSafeOtelHandler`, which catches any
exception thrown by the OTLP exporter and never re-throws. The `stderr`
channel runs unconditionally. The Collector being unreachable does NOT prevent
any record from appearing on stderr.

---

## 5. Rotate the backend bearer token

1. Generate a new `OTEL_INGEST_API_KEY_BACKEND` secret in DigitalOcean App
   Platform → Settings → Environment Variables.
2. Update the Collector configuration (`ixora-infra`) with the new key
   simultaneously (rolling restart acceptable — a brief 401 window causes
   `FailSafeOtelHandler` to suppress the error without breaking the app).
3. Re-deploy the API and worker components.
4. Verify with: `php artisan ixora:telemetry-validate` → should print
   `OTLP Authorization header   set (not shown)`.

---

## 6. Validate Collector acceptance

### Automated (zero secrets)

```bash
php artisan ixora:telemetry-validate
```

Emits one validation span, one metric, and one `INFO` log record (message:
`Ixora observability validation signal`), force-flushes, then exits 0 on
success.

### Manual contract check (HTTP)

```bash
# Test authentication — expect 401:
curl -s -o /dev/null -w "%{http_code}" \
  -X POST https://otel-staging.ixora-app.app/v1/logs \
  -H "Content-Type: application/x-protobuf"

# The real test is done through the artisan command above —
# never paste the bearer token in shell history.
```

---

## 7. Query Loki

All `back_vibes` logs are labelled by `service_name` (set via
`OTEL_SERVICE_NAME` on the Collector's resource processor).

```logql
# All API logs
{service_name="back_vibes-api"}

# Last 100 error+ records
{service_name="back_vibes-api"} | level >= "error" | limit 100

# Filter by trace (use the value from Tempo)
{service_name="back_vibes-api"} | json | extra_trace_id="<trace-id>"
```

> `trace_id` and `span_id` appear as JSON attributes under the key
> `extra.trace_id` / `extra.span_id` (psr3 attribute mode).  
> They are **not** promoted as Loki labels (label explosion prevention).

---

## 8. Correlate a Loki record with a Tempo trace

1. In Grafana → Explore → Loki, find the log line.
2. Copy the `extra.trace_id` field value.
3. Switch to Tempo data source, paste the trace ID.
4. The trace appears with full span tree.

If the Grafana Loki data source has a "Derived fields" rule mapping
`extra.trace_id` → `${__value.raw}` on the Tempo data source, clicking the
trace ID in Loki opens Tempo automatically.

---

## 9. Identify duplicate records

Each `logger()` call produces **one** record on stderr and **one** OTLP
`LogRecord`.  Duplicates would appear as two identical lines in Loki with the
same `extra.trace_id`, same `datetime`, and same `message`.

If duplicates appear:
- Confirm `LOG_STACK` does not contain `otel` twice.
- Confirm `FailSafeOtelHandler` has `bubble=false` (it always does).
- Confirm no second `OtelMonologHandler` is registered on any channel.

---

## 10. Diagnose 401, 404, 429, timeout, and connection failures

All failures are swallowed by `FailSafeOtelHandler`.  The first occurrence of
each exception class per process instance is written to PHP `error_log` (which
reaches DigitalOcean App Platform's stderr stream, separate from the
application log).

**To observe failures:**  
Search App Platform logs for `[ixora.telemetry] OTLP log export failure`.

| Status | Likely cause | Fix |
|---|---|---|
| 401 | Wrong or missing `OTEL_EXPORTER_OTLP_HEADERS` | Rotate token §5 |
| 404 | Wrong endpoint path | Confirm `/v1/logs` in Collector config |
| 429 | Too many requests | Reduce log level or rate-limit at Collector |
| Timeout / connection refused | Collector down or wrong `OTEL_EXPORTER_OTLP_ENDPOINT` | Check Collector health |

---

## 11. Rollback

**Immediate (no deploy required via App Platform env):**
```
OTEL_LOGS_EXPORTER=none
LOG_STACK=stderr
```

This disables OTLP log export instantly while keeping all application logs on
stderr and DigitalOcean platform logs intact.

**Code rollback:**  
Revert the `feature/observability-otlp-log-export` merge from `develop`.
The `otel` channel in `config/logging.php` falls back to `NullHandler` when
`OTEL_LOGS_EXPORTER=none` is set, so even with old code in place and
`LOG_STACK=stderr,otel`, stderr is never affected.

---

## 12. Resource attribute finding (Phase 4)

`OTEL_SERVICE_NAMESPACE` and `OTEL_SERVICE_VERSION` are **not** standard
OpenTelemetry SDK environment variables.  The SDK's `Service` resource detector
only reads `OTEL_SERVICE_NAME`.

To have `service.namespace`, `service.version`, and `deployment.environment`
appear in SDK-generated resources (LogRecords, Spans, Metrics), set them via:

```
OTEL_RESOURCE_ATTRIBUTES=service.namespace=ixora,service.version=<release-tag>,deployment.environment=staging
```

`config/telemetry.php` continues to read `OTEL_SERVICE_NAMESPACE` and
`OTEL_SERVICE_VERSION` for the Laravel application layer (metric attribute
labels, `TelemetryConfig` object).  This is correct and unchanged.

**Impact on existing dashboards:** existing dashboards that query
`deployment.environment` are unaffected — the attribute name is NOT changed.
The Phase 8.8.8 implementation does not rename `deployment.environment` to
`deployment.environment.name`.

---

## 13. `deployment.environment` vs `deployment.environment.name`

OTel semantic conventions 1.28+ suggest renaming the attribute to
`deployment.environment.name`.  **This is NOT applied** in Phase 8.8.8.
Existing Tempo and Loki dashboards reference `deployment.environment`.
A rename requires a coordinated dashboard migration.  Track as a separate
ADR when the team is ready to migrate.
