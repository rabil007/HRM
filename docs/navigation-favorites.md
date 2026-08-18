# Navigation favorites

Users can pin **navigation destinations** they open often. Favorites are personal shortcuts, not a permission grant and not a second search system.

This phase does **not** favorite business records (employees, documents, crew assignments). Recently viewed records are [Recent items](./recent-items.md), a separate Cmd+K surface.

## What can be favorited

Only keys from the server catalog (`App\Support\Navigation\NavigationDestinationCatalog`, mirrored in `resources/js/lib/navigation-favorites.ts`). Examples: Employees, Documents, Crew Assignments, Crew Planning, Leave requests, Attendance records, Payroll, and other existing sidebar destinations.

The client submits a catalog **key**, never a URL. Unknown keys and client-supplied `url` / `href` / `user_id` / `company_id` are rejected.

## Persistence

Favorites are stored on `navigation_favorites` (`user_id`, `destination_key`, `position`). They are user-global: there is no `company_id`. Maximum **20** per user. Adding an existing key is idempotent. Toggles are not written to the activity log.

Shared Inertia data sends only `favorite_destination_keys` (ordered keys). Labels, icons, and hrefs are resolved from the catalog and `getSidebarData()`.

## Permissions and companies

Rendering uses the same Phase 3A rules as the sidebar (`nav-visibility.ts` / `isSidebarUrlVisible`). Backend `can:` middleware on the destination remains authoritative.

If Employees is favorited in company A (`employees.view`) and the user switches to company B without that permission, the favorite stays stored but is hidden. Switching back to A shows it again. Platform destinations follow `auth.platform`, not `platform_access` as a stand-in for tenant modules.

## UI

- **Header** star next to breadcrumbs: “Add to favorites” / “Remove from favorites”.
- **Sidebar** “Favorites” group at the top of desktop and mobile navigation, only when at least one stored key is currently accessible.
- **Cmd/Ctrl+K** lists accessible favorites first and omits those URLs from the normal Commands groups. Record search is unchanged.

## Routes

| Method | Path | Name |
|--------|------|------|
| POST | `/favorites` | `favorites.store` |
| DELETE | `/favorites/{destination}` | `favorites.destroy` |

Authenticated user only. `user_id` is never accepted from the client.
