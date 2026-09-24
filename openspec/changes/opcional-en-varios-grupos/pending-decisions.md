# Pending decisions — opcional-en-varios-grupos

- **Change**: `opcional-en-varios-grupos` (plugin-local, `catalogo_core`)
- **Artifact store**: `openspec`
- **Status**: RESOLVED — maintainer answered 2026-09-24 (auto pace; pre-propose gate cleared)
- **Recorded**: 2026-09-24
- **Source**: `exploration.md` (open questions)

Proposal (`sdd-propose`) MUST NOT run until every group below is resolved.

## Resolution (2026-09-24)

| Group | Token chosen | Notes |
|---|---|---|
| 1 — TPV semantics | `tpv_dedupe_at_add` | Visible in every group; cart dedupes a second selection of the same opcional id. |
| 2 — Assignment UI | `ui_checkbox_list` | Active groups rendered as checkboxes; multiple saved together. |
| 3 — `id_grupo` fate | `column_freeze_then_drop` | Bridge is the single source of truth; column frozen this change, dropped in a follow-up soak-drop change. |
| 4 — Research | `research_skip` | No external evidence lane. |

## Resolution 2 — design open questions (2026-09-24)

| Question | Token chosen | Notes |
|---|---|---|
| Cross-repo companion edits | `scope_include_companions` | This change includes the tpvmod cart-add dedupe (AD-12/13) and BOTH tarifario bulk-delete cleanups. Commits land per plugin repo. |
| Inactive-group memberships | `membership_preserve_all` | Checkbox renders active ∪ already-assigned; "Grupo" column lists ALL memberships including inactive groups; `set_grupos()` is lossless. |

Non-blocking (pinned by design, not asked): tarifario stats key `relaciones_grupo`; active-only labels discarded in favour of `membership_preserve_all`.

## Group 1 — TPV semantics when an opcional belongs to two groups the article also has

Scenario: opcional X is in groups G1 and G2; article A is also assigned to G1 and G2.

| Token | Label | Effect |
|---|---|---|
| `tpv_dedupe_at_add` | Present in both, dedupe at cart-add | X is selectable under each of its groups; the cart resolver ignores a second selection of the same opcional id. Single line / single charge. Mirrors the existing `$seen` dedupe in `catalogo_articulo_opcional::get_opcionales_from_articulo()`. |
| `tpv_single_occurrence` | Shown once | X appears only in its first group (by `orden`); the second group renders empty and is skipped. No double charge, but a group may appear empty. |
| `tpv_duplicate` | Duplicate presentation | X is selectable in both groups. Risk: same variant added twice → two cart lines / double charge. |
| `tpv_forbid_config` | Forbid the configuration | Reject adding X to a second group when both groups are assigned to a shared article. Brittle; couples group editing to article assignments. |

Recorded recommendation: `tpv_dedupe_at_add`.

## Group 2 — Assignment UI shape on the opcional

| Token | Label | Effect |
|---|---|---|
| `ui_checkbox_list` | Checkbox list | Render the active groups as checkboxes; multiple selections saved at once. |
| `ui_multiselect` | Multi-select | `<select multiple>` of active groups. |
| `ui_add_remove_rows` | Single select + add/remove rows | Keep the single-group select; list memberships in a table with add/remove actions. |

Recorded recommendation: `ui_checkbox_list`.

## Group 3 — Fate of `catalogo_opcionales.id_grupo`

| Token | Label | Effect |
|---|---|---|
| `column_freeze_then_drop` | Freeze now, drop later | This change adds the bridge, migrates, and rewrites every reader/writer; `id_grupo` stops being read/written but the physical column stays for rollback. A follow-up soak-drop change removes it. Matches the repo precedent (`caracteristicas-post-soak-drop`). |
| `column_drop_now` | Drop in this change | Append the destructive `ALTER TABLE ... DROP COLUMN id_grupo` to this change after a verified backfill. Single release, larger review. |
| `column_keep_primary` | Keep as primary group | Keep `id_grupo` as a "primary group" and the bridge as extra memberships. Two sources of truth; not recommended. |

Recorded recommendation: `column_freeze_then_drop`.

## Group 4 — External research before proposal

| Token | Label | Effect |
|---|---|---|
| `research_skip` | Skip | No external evidence lane; the change is internal schema + PHP. |
| `research_run` | Run `sdd-research` | Collect source-backed evidence before the proposal. |

Recorded recommendation: `research_skip`.

## Non-blocking notes (not asked; fold into design with recommended defaults)

- The group editor's "available opcionales" list should include opcionales not already in **this** group (today it uses `all_sin_grupo()`), so an opcional can be added to a second group.
- Removing the last membership does **not** restore previously deleted loose article relations (assumed no).
- Group `activo` does not affect membership; it only affects listing/TPV presentation.
