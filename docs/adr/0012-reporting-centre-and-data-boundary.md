# ADR-0012: Reporting Centre: in-house governed registry; sensitive datasets for Information only

| Status | Date | Deciders |
|---|---|---|
| Accepted | 2026-10-18 (engine); 2026-10-19 (boundary) | Project owner |

> Reconstructed record (written 2026-10-22). The decision itself was made on the date shown. Evidence is cited so it can be checked; where the original rationale was not recorded, that is stated.

## Context

Information and HR needed flexible reports and exports. A separate BI server (for example Metabase) cannot run on shared hosting, and paid PHP reporting add-ons were not wanted.

## Decision

- **Engine:** a small in-house registry declares fields, metrics and per-field view and export roles. It produces one prepared `SELECT`, with fixed budgets (200 per page, 5,000 per export) and audited, formula-neutralised exports. Vendored open-source UI libraries render the results. The implementation (`5d10294`) follows "Option C" of the stack decision document; that document still says "proposed", and approval is not recorded in it.
- **Boundary (owner decision):** future cross-department or sensitive datasets (finance, material, detailed education, telemetry) go to the **Information Department only**. HR keeps only what its own work needs. Department datasets (V2-4) are on hold.

## Consequences

- **Positive:** field-level permissions in one place; runs on shared hosting.
- **Negative:** an in-house query builder must be maintained and security-tested.

## Evidence

`admin/backend/reporting/ReportRegistry.php`; commits `5d10294`, `072005e`, `d226dd8`.
