# High-Impact Action Confirmations

High-impact actions in OMS-HRM must explain their effect **before final submission**. The confirmation UI is educational safety UX only — backend validation, authorization, tenancy, and movement state checks remain authoritative.

## When to show impact preview

Show a confirmation or in-dialog impact preview when an action can materially affect:

- operational history
- employee movement/state
- payroll/financial state
- approvals/finalisation
- deletion/destructive changes
- bulk destructive changes
- records that are difficult to undo

Do **not** add confirmation for simple actions such as open, view, search, filter, navigate, preview, download, or editing fields before Save.

## Presentation levels

| Level | Use for | Visual treatment |
| --- | --- | --- |
| **Normal impact** | Expected state transitions (Start Assignment, Join Vessel, Record Arrival) | Compact summary above the final submit button |
| **High impact** | Important operational changes (Transfer Vessel, Redeploy, Confirm Disembarkation, Travel Home, Close Assignment) | Full `ActionImpactPreview` with current/destination context and impact list |
| **Destructive** | Cancel, void, delete, destructive bulk actions | Destructive styling plus explicit consequences |

Use `ActionImpactPreview` from `resources/js/components/action-impact-preview.tsx`. Business meaning comes from the caller/action configuration — the shared component must not contain domain-specific rules.

## One dialog only

If an action already opens a form/dialog, **do not** open a second confirmation dialog after submit. Enhance the existing dialog with a compact **What will happen** section above the final action button.

Exception: standalone destructive actions with no existing form may use `AlertDialog` + `ActionImpactPreview` (for example Send Announcement, Void Assignment).

## Crew movement reference

Crew movement actions configure impact copy in `movement-action-config.ts` and render through `buildMovementImpactPreview()`.

| Preview tier | Actions |
| --- | --- |
| Full / high | Transfer Vessel, Redeploy, Confirm Disembarkation, Travel Home, Close Assignment |
| Full / destructive | Cancel Assignment |
| Light / normal | Start Assignment, Record Arrival, Send to Training, Complete Training, Mark Ready, Join Vessel, Start Join Standby, Start Demobilisation Standby |
| None | Plan Sign-Off (forecast only) |

Corrections and void use the same shared component in their existing dialogs.

## Stale data

If record state changes after the dialog opens, backend rejection remains authoritative. Where practical, show a refresh/review message instead of forcing stale submissions.

## Follow-up domains

Apply the same pattern to other high-risk modules when touching their workflows: payroll finalisation, destructive attendance corrections, approved leave cancellation, document delete/replace, employee deactivation, and bulk destructive updates.
