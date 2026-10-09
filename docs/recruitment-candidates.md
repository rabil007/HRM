# Recruitment Candidates

Candidate management through screening, interview selection, and Offer/JOL (Phase 2). Joining is a handoff stage for Phase 3 (employee conversion / filled counts are out of scope).

## Migrations

Additive migrations (do not edit deployed history):

- `2026_10_09_051756_create_recruitment_candidates_tables.php` — `recruitment_candidates`, `recruitment_candidate_stage_transitions`
- `2026_10_09_051757_add_recruitment_candidates_permissions.php` — Spatie permission rows only (no auto-grant to existing roles)
- `2026_10_09_120000_create_recruitment_candidate_offers_table.php` — `recruitment_candidate_offers` (current + revision history)
- `2026_10_09_120001_add_recruitment_candidate_offer_permissions.php` — Offer/JOL Spatie permission rows

Legacy `candidates` / `interviews` / `job_offers` / `job_postings` tables are **unchanged**. Existing rows stay as-is; no requirement links are invented for them.

### Existing-data handling

- New candidates always require company + Open requirement + Open position line.
- Snapshots store `requirement_number_snapshot` and `position_title_snapshot` at create time.
- Requirement / line FKs use `nullOnDelete` so history survives parent removal.
- Application code **blocks** hard-deleting a requirement (force delete) or a position line when candidates still reference it.
- Soft-deleted requirements remain readable via snapshots when FKs are nullified or parents are missing; workflow actions stay disabled until a valid Open parent exists.

### Master data

`MasterDataUsage` counts both legacy `candidates.nationality_id` and `recruitment_candidates.nationality_id` for Countries delete protection.

## Permissions

Registered in `ApplicationPermissionDefinitions` and synced by `PermissionsSeeder`:

| Permission | Effect |
| --- | --- |
| `recruitment.candidates.view` | Company-wide list, Kanban, show |
| `recruitment.candidates.create` | Add candidate (plus ownership) |
| `recruitment.candidates.update` | Profile / interview / CV upload (plus ownership) |
| `recruitment.candidates.move` | Stage moves, select, interview-path reject, undo selected (plus ownership) |
| `recruitment.candidates.manage` | Ownership override only; also required with `offer.revise` for offer corrections |
| `recruitment.candidates.cv.download` | Private CV download (also requires `view`) |
| `recruitment.candidates.offer.prepare` | Prepare Draft Offer/JOL from Selected Interview (plus ownership) |
| `recruitment.candidates.offer.update` | Edit Draft offer details/documents (plus ownership) |
| `recruitment.candidates.offer.send` | Mark offer Sent (records external send; does **not** email) |
| `recruitment.candidates.offer.decide` | Accept (→ Joining) or reject offer (→ Rejected) from Sent |
| `recruitment.candidates.offer.revise` | Create audited Draft revision of Sent/Accepted/Rejected (requires `manage`) |
| `recruitment.candidates.offer.download` | Private offer/acceptance document download (also requires `view`) |

Re-seed: `php artisan db:seed --class=PermissionsSeeder`. Assign via Roles & permissions. Owner receives the catalog via AdminSeeder. Do not hardcode role names. CC notification recipients gain no candidate action rights.

### Ownership

Create / update / move / offer actions require the action permission **and** (`assigned_to` on the linked requirement **or** `recruitment.candidates.manage`). Ownership follows the requirement’s current assigned recruiter after reassignment or transfer. Reopen Rejected additionally requires `manage` + `move`. Offer revise requires `offer.revise` + `manage`.

## Workflow

Forward path (manual only; no Client Approval stage):

**Applied → Screening → Interview → Offer/JOL → Joining** (Joined / employee conversion is Phase 3).

### Phase 1 (screening / interview)

- Forward only: Applied → Screening → Interview (explicit actions; no drag-and-drop).
- Reject from Applied, Screening, or Interview (reason required). Interview rejection sets `interview_outcome = not_selected`.
- Select keeps stage = Interview with Selected badge until Offer/JOL is prepared.
- Undo Selected clears outcome to pending (audited). Blocked after an Offer/JOL exists.
- Reopen Rejected restores recorded `pre_rejection_stage`. When restoring Offer/JOL, Selected outcome is preserved and Joining is normalized back to Offer/JOL.
- Profile updates never change stage, outcome, or requirement links.
- Interview field changes are audited separately.
- Optimistic `lock_version` + expected stage/outcome guard concurrent updates.

### Phase 2 (Offer/JOL)

- **Prepare Offer**: only Selected Interview candidates; Open requirement + Open line; creates Draft offer (`is_current`), moves stage to Offer/JOL, keeps `interview_outcome = selected`.
- Offer statuses: **Draft → Sent → Accepted | Rejected**.
- One current offer per candidate; previous revisions kept (`is_current = false`, `supersedes_offer_id`, `revision_number`).
- Details: salary amount (required, explicit — never auto-filled from position range), currency, proposed joining date, offer date, optional expiry, notes.
- Position line salary min/max/currency shown as reference only.
- Private offer document + signed-acceptance uploads use local-disk storage under `recruitment/candidates/{company}/{candidate}/offers/{offer}/` with path validation and post-commit cleanup of replaced files.
- **Mark Sent**: records `sent_at` / `sent_by`. Does **not** send email.
- **Accepted** (from Sent only): records acceptance, moves candidate to **Joining**.
- **Rejected** (from Sent only): reason + decision date, offer preserved as Rejected, candidate → Rejected with `pre_rejection_stage = offer_jol`. Does **not** set `interview_outcome = not_selected` (offer decline ≠ interview rejection).
- Sent / Accepted / Rejected offers cannot be silently edited. **Revise** (manage + revise + reason) creates a new Draft revision and returns the candidate to Offer/JOL.
- Joining is Phase 3 handoff only — no Joined confirmation, filled-count updates, or employee creation.

## UI

- Navigation: Recruitment → Candidates (`recruitment-nav.ts` `available: true`). No separate Offers menu.
- Index: Table and Kanban. Columns include Offer/JOL and Joining. Kanban columns are independently paginated with server stage totals. After candidate/offer actions, expanded columns refresh loaded page ranges via `through_page_{stage}` (Phase 1 fix preserved).
- Candidate detail: Offer/JOL section with status, salary, dates, documents, and eligible actions. Selected Interview remains distinguishable from candidates with prepared offers (`Offer: Draft/Sent/…` badge).
- Shared Add Candidate sheet from Candidates and Requirement show.
- Requirement show: compact candidate summary, Add, View all (filtered).

## Out of scope

Workbook import, reminders, candidate emails, employee conversion, headcount / `ready_to_close` calculations, Client Approval stage, Joined confirmation.
