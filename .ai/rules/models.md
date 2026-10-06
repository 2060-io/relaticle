---
paths:
  - 'app/Models/**'
  - 'app/Casts/**'
---

# Models

## Activity timeline email titles

`VisibleEmailScope` admits metadata-only emails (participants and timestamp). Field masking is a view/policy concern. Timeline titles must go through `$viewer->can('viewSubject', $email)` and render `(subject hidden)` when that is false. Never copy `$email->subject` onto a teammate-facing surface.

## Email canonicalization

- Emails are stored canonical: trimmed, lowercase. The `AsCanonicalEmail`
  inbound cast enforces it on `User.email` and `WorkspaceInvitation.email`; reuse it
  for any new email column. Casts never touch query input, so every lookup
  against an email column must canonicalize first via
  `App\Support\EmailAddress::canonicalize()`; never write a raw
  `where('email', $userInput)`. Same ladder for future scalar normalization:
  one inbound cast per concept in `app/Casts`, value objects only for
  compound values.

## Relation changes in the activity log

A relation change is logged by record name through `App\Support\ActivityLog\RelationChangeLog`.
It writes the `custom_field_changes` event with `type: relation` and a `{value, label}` pair per
side, because the timeline, the workspace Activity page, MCP, chat and the sysadmin panel already
render that shape by label. `tests/Feature/ActivityLog/RelationChangeActivityTest.php` is the gate.

- A new foreign key on a model using `LogsRelationChanges` goes in both `logExcept` and
  `relationChangeLabels()`. A key left out of the first is logged as a raw id.
- A new many-to-many relation on `Task` or `Note` takes `->using()` with a pivot class from
  `App\Models\Pivots` that uses `LogsLinkChanges`, on both sides of the relation. Without it,
  attach and detach write nothing.
- A foreign key is logged on update only: a record created with one writes its `created` row
  alone. A link is logged whenever its pivot row is created or deleted, and the workspace
  Activity page folds links made during a create into the `created` row.
