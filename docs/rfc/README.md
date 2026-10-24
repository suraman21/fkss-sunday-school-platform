# Requests for comments (RFCs)

An RFC proposes a significant change **before** it is made, so the team can review the plan, risks and alternatives. Use one for structural changes (folders, module boundaries, deployment layout), cross-cutting technical changes, or anything that is hard to reverse.

| RFC | Title | Status |
|---|---|---|
| [0001](0001-repository-structure.md) | Repository structure and root clean-up | Accepted (Phase 1 and Option 1 implemented; Phase 3 proposed) |

## How RFCs relate to ADRs

- An **RFC** is a proposal, written before the work, and open for discussion.
- An **ADR** ([`docs/adr/`](../adr/README.md)) records a decision once it has been made.
- An accepted RFC usually produces one or more ADRs.

## Process

1. Copy the structure of RFC 0001: summary, constraints with evidence, proposal in phases, risks, non-goals, open questions, decision.
2. Number it sequentially (`NNNN-short-title.md`) and open a pull request with status **Proposed**.
3. Discuss in the pull request. The decision makers record the outcome in the RFC's **Decision** section.
4. Nothing proposed in an RFC is implemented until its status is **Accepted**.
