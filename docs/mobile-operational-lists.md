# Mobile operational lists

On phones and small PWA screens, selected high-value operational indexes render compact record cards. **Desktop and tablet (`md` and up) keep `OrganizationDataTable`** (or the existing grid/list toggle). Cards are an alternate rendering of the same server-paginated result set—not a second page, route, or API.

## Shared pattern

| Piece                  | Location                                                                             |
| ---------------------- | ------------------------------------------------------------------------------------ |
| Breakpoint helpers     | `resources/js/lib/mobile-operational-list.ts` (`md` / 768px, matching `useIsMobile`) |
| List / card / overflow | `resources/js/components/mobile-record-list.tsx`                                     |
| Domain field choices   | `features/{domain}/lib/*-mobile-card.ts` plus a thin `*-mobile-card.tsx` wrapper     |

Visibility:

- Mobile: `md:hidden`
- Desktop: `hidden md:block`

Do not introduce a generic rendering DSL. Keep identity, status, and action decisions in the domain wrapper.

## Domains using this pattern

| Screen                                    | Mobile card                    | Notes                                                                                    |
| ----------------------------------------- | ------------------------------ | ---------------------------------------------------------------------------------------- |
| Employees                                 | `EmployeeMobileCard`           | Compact identity; no private contact/salary fields                                       |
| Crew assignments                          | `CrewAssignmentMobileCard`     | Current P0–P6 phase from `CrewAssignment`; planned sign-off is not actual disembarkation |
| Leave requests                            | `LeaveRequestMobileCard`       | Approve only when `can_approve_current_step`                                             |
| Attendance records                        | `AttendanceRecordMobileCard`   | Self-service omits employee identity; `attendance.records.manage` shows it               |
| Documents (index/compliance/search table) | `DocumentComplianceMobileCard` | Expiry/compliance from existing presenters; no file contents                             |
| Payroll periods (pay runs hub)            | `PayrollPeriodMobileCard`      | Status and workflow only; no salary figures                                              |

Master-data, configuration, and dense payroll matrices (records, timesheets, salary inputs) stay as tables.

## Data, filters, and actions

Both views consume the same Inertia props, `useServerPaginationFilters` (or domain equivalent), permissions/`can` flags, and mutation handlers. Pagination stays server-side. Destructive actions still use the existing AlertDialog confirmations.
