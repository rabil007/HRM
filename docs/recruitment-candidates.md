# Recruitment Candidates (Phase 1)

Candidate management through screening and interview selection. Later stages (Offer/JOL, Joining, employee conversion) are out of scope.

## Migrations

Additive migrations (do not edit deployed history):

- `2026_10_09_051756_create_recruitment_candidates_tables.php` — `recruitment_candidates`, `recruitment_candidate_stage_transitions`
- `2026_10_09_051757_add_recruitment_candidates_permissions.php` — Spatie permission rows only (no auto-grant to existing roles)

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
| `recruitment.candidates.move` | Stage moves, select, reject, undo selected (plus ownership) |
| `recruitment.candidates.manage` | Ownership override only; does not bypass company, action permissions, or workflow validation |
| `recruitment.candidates.cv.download` | Private CV download (also requires `view`) |

Re-seed: `php artisan db:seed --class=PermissionsSeeder`. Assign via Roles & permissions. Owner receives the catalog via AdminSeeder. Do not hardcode role names. CC notification recipients gain no candidate action rights.

### Ownership

Create / update / move require the action permission **and** (`assigned_to` on the linked requirement **or** `recruitment.candidates.manage`). Ownership follows the requirement’s current assigned recruiter after reassignment or transfer. Reopen Rejected additionally requires `manage` + `move`.

## Workflow (Phase 1)

- Forward only: Applied → Screening → Interview (explicit actions; no drag-and-drop).
- Reject from Applied, Screening, or Interview (reason required). Interview rejection sets `interview_outcome = not_selected`.
- Select keeps stage = Interview with Selected badge until Phase 2.
- Undo Selected clears outcome to pending (audited).
- Reopen Rejected restores recorded `pre_rejection_stage`, clears outcome, requires reason + manage.
- Profile updates never change stage, outcome, or requirement links.
- Interview field changes are audited separately.
- Optimistic `lock_version` + expected stage/outcome guard concurrent updates.

## UI

- Navigation: Recruitment → Candidates (`recruitment-nav.ts` `available: true`).
- Index: Table and Kanban. Kanban columns are independently paginated with server stage totals (not derived from the table page).
- Shared Add Candidate sheet from Candidates and Requirement show.
- Requirement show: compact candidate summary, Add, View all (filtered).

## Out of scope

Workbook import, reminders, candidate emails, Offer/JOL, Joining, employee conversion, headcount / `ready_to_close` calculations, Client Approval stage.
