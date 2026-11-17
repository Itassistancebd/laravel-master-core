# Master Core Global UI and CRUD Standards

These standards apply to future Master Core modules and consuming applications. They establish a consistent experience across modules; they do not authorize frontend work in a phase that excludes it.

## Shared visual language

- Keep page titles, typography, spacing, colors, control sizing, and interaction patterns consistent across the application.
- Use responsive layouts that remain usable on desktop, tablet, and mobile viewports.
- Build reusable Vue components for shared controls and workflows. Extend an existing shared component when it fits instead of introducing a one-off variant.
- Maintain consistent design and behavior across all future modules.

## Forms

- Use the shared form styles, standard input heights, and consistent outline and focus styles.
- Keep labels visible, clear, and consistently placed. Associate labels with their controls.
- Display validation feedback next to the affected field, preserve entered values when safe, and summarize form-level errors when useful.
- Use searchable single-select and multiselect controls for long or filterable option lists.
- Keep loading, disabled, and error states clear and accessible.

## Data tables

Use the shared DataTable for tabular records. It must support:

- A serial-number (SL) column and row selection.
- Search, filters, and sortable columns, with filtering and pagination performed server-side for backend data.
- Bulk actions that apply only to selected rows and report their outcome.
- User-controlled column visibility.
- Striped rows and a visible row-hover state.
- Standard View, Edit, and Delete actions when those actions are available to the current user.
- Page-size options of 10, 25, 50, and 100 rows.
- Pagination showing up to five page links at a time, with navigation controls as appropriate.

Keep table state and result counts understandable. Show a useful empty state when no records match, and distinguish that from an initial loading state or a failed request.

## CRUD, trash, and bulk operations

- Follow consistent create, view, edit, and delete flows across modules.
- Use soft deletion where the module's data lifecycle requires recovery. Provide a trash view and restore action for soft-deleted records.
- Require a clear confirmation before permanent deletion and explain that the action cannot be undone.
- Confirm bulk operations, state their scope, and show success or failure results. Validate authorization and record state on the server for every affected row.
- Keep list, trash, restore, and permanent-delete behavior consistent within each module.

## Feedback and interaction states

- Use concise smart alerts and toast notifications for completed actions and relevant errors.
- Show loading indicators during asynchronous work and prevent duplicate submissions when needed.
- Provide useful empty states and confirmation dialogs for consequential actions.
- Make keyboard focus, disabled controls, validation states, and errors visually clear.

## Backend behavior

- Enforce authorization on the backend for reads, writes, bulk operations, restore, and permanent deletion. UI visibility alone is not authorization.
- Validate input on the server, even when client-side validation is also present.
- Perform search, filtering, sorting, and pagination on the server for backend data. Allow only known, validated sort and filter fields.
- Return predictable result and validation structures so shared UI components can handle them consistently.

## Code comments

Write meaningful comments in PHP, CSS, HTML, JavaScript, and TypeScript when they explain intent, constraints, non-obvious behavior, or an important tradeoff. Keep comments accurate as code changes. Avoid comments that merely restate the next line or duplicate names and types.
