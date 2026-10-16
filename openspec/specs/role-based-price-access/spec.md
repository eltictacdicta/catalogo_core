# Delta for role-based-price-access

## ADDED Requirements

### Requirement: Role-group storage model (R-RG-001)

The system MUST persist optional role groups in four new tables: `catalogo_grupo_roles` (group definition, roles via `codrol` rows), `catalogo_grupo_usuarios` (membership: nick + rol within the group: gestor/editor/revisor/visualizador), `catalogo_grupo_tarifas` (group ↔ price list), `catalogo_grupo_articulos` (group ↔ article referencia), each with FKs and cascade deletes following the `catalogo_opcional_precios.xml` pattern. The legacy `tarifa` model MUST NOT be used.

#### Scenario: Group with members and scopes persists

- GIVEN an admin creating group G with user U (rol gestor), list L1, and article A
- WHEN the group is saved
- THEN the four tables hold the expected rows linked to G

#### Scenario: Deleting a group cascades

- GIVEN group G with users, lists and articles
- WHEN G is deleted
- THEN all referencing rows in the other three tables are deleted

### Requirement: First-party listener on ArticlePermissionFilterEvent (R-RG-002)

The plugin MUST register a first-party listener (via `Init.php`) on `ArticlePermissionFilterEvent` (`catalogo_core.article_permission_filter`) that evaluates group gating. The event resolves allow by default; the listener MUST fail-closed on internal errors (deny with a user-facing reason) and MUST NOT modify the event class.

#### Scenario: Zero listeners leaves behavior unchanged

- GIVEN the groups master setting off (listener short-circuits)
- WHEN an article edit persists
- THEN the event resolves allow (default behavior unchanged)

#### Scenario: Listener denies out-of-scope editor

- GIVEN user U with rol editor in group G scoped to article A and list L1
- WHEN U attempts an edit on article B (not in G)
- THEN the listener calls deny with a reason and the edit is blocked before persistence

### Requirement: Admin dominance (R-RG-003)

Admin users MUST bypass role-group gating entirely; the listener MUST short-circuit to allow for admins regardless of group state.

#### Scenario: Admin edits restricted article

- GIVEN article B scoped to a group not containing the admin
- WHEN an admin edits B
- THEN the event resolves allow

### Requirement: Role matrix (R-RG-004)

Within a group: gestor MUST have full edit rights on all scoped articles and lists; editor MUST have edit rights only on articles explicitly assigned to the group; revisor and visualizador MUST be read-only (no edit denies). A user's effective rol in a group is the one in `catalogo_grupo_usuarios`.

#### Scenario: Gestor edits all scoped articles

- GIVEN gestor U in group G scoped to articles A1..An and lists L1..Ln
- WHEN U edits any of A1..An
- THEN the event resolves allow

#### Scenario: Editor limited to assigned articles

- GIVEN editor U in group G scoped to article A1 only
- WHEN U edits A2 (in list scope but not assigned)
- THEN the event denies

#### Scenario: Revisor is read-only

- GIVEN revisor U in group G scoped to article A1
- WHEN U attempts an edit on A1
- THEN the event denies with a read-only reason

### Requirement: Master setting provably inert when off (R-RG-005)

The feature is OPTIONAL and gated by the role-groups master setting in `CatalogoOptions` (default off). When off, the listener MUST short-circuit to allow on every dispatch, making the feature provably inert (no deny reachable, no DB reads required for the verdict).

#### Scenario: Master off allows everything

- GIVEN the master setting off and any user/article/list combination
- WHEN the listener runs
- THEN it returns without denying (event resolves allow)

#### Scenario: Master on activates gating

- GIVEN the master setting on and the listener registered
- WHEN a non-admin editor attempts an out-of-scope edit
- THEN the deny path is reachable and enforced
