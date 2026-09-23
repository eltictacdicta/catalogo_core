# Research — caracteristicas-producto

> Schema: `gentle-ai.sdd-research/v1`
> Phase: `research` (read-only; no source code modified).
> Change: `caracteristicas-producto`
> SDD root: `plugins/catalogo_core/openspec/` (`ownership: plugin-local`, `strict_tdd: true`).
> Core `openspec/` is reference only — no entries created or planned there.
> Revision: 2
> Outcome: **done**
> Supersedes revision 1 (`outcome: blocked`, admission denied). See §9.

## 1. Retained intent (selected research lane)

Restored from the blocked revision 1 (§1 and §4) and engram observation `809`. The
orchestrator selected, and this phase retains, the following lane:

- **Lane:** PrestaShop's product **Feature / FeatureValue** model, evaluated through the
  Context7 MCP (`/prestashop/docs`), as a *conceptual reference* for a new generic
  `caracteristica` entity in `catalogo_core` (name + value type `bool|string`, assignment
  scopes product / all products / family-subfamily, per-tarifa values, clone on tarifa
  copy, `importable`/`exportable`/`listable` flags).
- **Purpose:** inform two open product decisions recorded in `explore.md` §7:
  - **D2 — value storage shape:** Option A′ (1 definition + 3 tarifa-keyed scope value
    tables with `DEF` inheritance) vs a PrestaShop-like shape (definition + predefined
    value catalog + product↔value link).
  - **D4 — predefined value catalog vs free text:** whether to model a predefined-value
    catalog (PrestaShop `feature_value`, including its `custom` flag) or plain free text
    typed by `tipo` (`bool` | `string`).
- **Requested source class:** `documentation` only.
- **Non-requested / unadmitted class:** `open-web`. The PrestaShop GitHub documentation
  URLs in revision 1 §4 are **not** fetched directly; content was admitted only through the
  granted Context7 provider. Any claim below is attributed to the Context7 documentation
  provider that returned it.

## 2. Admission and observed grants

| Item | Value |
|---|---|
| Required capability contract | `gentle-ai.sdd-research-capability/v1` |
| Declared grant (exact) | `class=documentation`, `provider=context7`, `library=/prestashop/docs`, `authorized_by=user` |
| Authorization (verbatim) | "documentate a través del MCP de Context7" |
| Exact `documentation` grants observed | 1 — Context7 library `/prestashop/docs` |
| Exact `open-web` grants observed | none (empty) |
| Requested classes | `documentation` |
| Admission result | **GRANTED** (for `documentation` via Context7 `/prestashop/docs`) |
| Persistence tools | available, but explicitly **not** evidence grants |

Rationale:

- The `sdd-research` contract admits evidence only from an explicit
  `gentle-ai.sdd-research-capability/v1` declaration with an exact grant for the requested
  class. This run carries such a declaration for `documentation`, scoped to the Context7
  library `/prestashop/docs`.
- `open-web` is not granted, so the `github.com/prestashop/docs` URLs are treated as
  Context7 *origins* of returned documentation, not as an independent web-fetch grant. No
  un-granted fetch was performed.
- All claims in §5 are traced to excerpts returned by three Context7 queries against
  `/prestashop/docs`. Nothing is asserted from the un-granted open web.

## 3. Questions

| # | Question | Status |
|---|---|---|
| Q1 | What is the structure of PrestaShop's Feature / FeatureValue model (definition, values, localization)? | Answered (§4, §5) |
| Q2 | How are features and values associated to products? | Answered (§4, §5) |
| Q3 | How does PrestaShop distinguish predefined values from custom per-product values? | Answered (§4, §5) |
| Q4 | Is there evidence that features feed product listing / faceting? | **Partially** answered — generic faceted search is evidenced; a feature→facet mapping is not (§6) |
| Q5 | How does this model inform D2 (storage shape) and D4 (catalog vs free text)? | Answered as recommendations (§7) |

## 4. Validated claims

Each claim maps to one or more source IDs in §5. A claim is stated only at the level the
admitted excerpt supports.

| # | Validated claim | Sources |
|---|---|---|
| C1 | A PrestaShop **product feature (definition)** has an `id`, a `position` (ordering), and a **localized** `name` per language. | S7, S6 |
| C2 | A **`product_feature_value`** row has `id`, a **required** `id_feature`, an **optional boolean** `custom`, and a **required** localized `value` bounded by `isGenericName` (documented max 255 characters). | S1 |
| C3 | A feature value is created against an existing feature by supplying `id_feature` plus a per-language `value`; the tutorial shows a `product_feature_value` create payload. | S2 |
| C4 | `AddFeatureValueCommand` is the command that adds **predefined** feature values. "Predefined values are distinct from custom values, which are exclusively assigned to specific products." Its constructor takes `featureId` and `localizedValues` (array); handler `AddFeatureValueHandler`; returns `FeatureValueId`. | S4 |
| C5 | The Feature domain exposes `AddFeatureCommand`, `AddFeatureValueCommand`, `EditFeatureCommand`, `EditFeatureValueCommand`, plus queries `GetFeatureForEditing` and `GetFeatureValueForEditing`. | S6 |
| C6 | A product is linked to a feature/value pair through `associations > product_features > product_feature` carrying `id` (= feature id) and `id_feature_value`. | S2 |
| C7 | Setting product feature values is documented as `POST /products/{productId}/feature-values` with body `featureValues: [{featureId, valueId}]`; the response carries `featureValueIds`. | S5 |
| C8 | The same operation is separately documented as `SetProductFeatureValuesCommand` → `POST /api/products/{id}/features` with `featureValues: [{featureId, valueId}]`; removal is `RemoveAllFeatureValuesFromProductCommand` → `DELETE /api/products/{id}/features`. | S3 |
| C9 | PrestaShop provides a faceted-search capability that lets customers filter products by multiple criteria, powered by a dedicated module mapping selected filters into SQL queries. (The excerpt does **not** name features as a facet criterion.) | S8 |

## 5. Sources

All sources were returned by the Context7 provider for library `/prestashop/docs`. `URL`
is the documentation origin reported by Context7; `class` is the granted evidence class.

| ID | class | Title | Publisher | URL | accessed_at | Excerpt |
|---|---|---|---|---|---|---|
| S1 | documentation | Product Feature Value resource (schema + fields) | PrestaShop (via Context7 `/prestashop/docs`) | https://github.com/prestashop/docs/blob/9.x/webservice/resources/product_feature_values.md | 2026-09-14 | `<product_feature_value><id/><id_feature/><custom/><value><language id="1">…</language></value></product_feature_value>`; fields: `id_feature` (isUnsignedId, required), `custom` (isBool, optional), `value` (isGenericName, required, max 255). |
| S2 | documentation | Create a product (tutorial): feature association + feature-value create payload | PrestaShop (via Context7 `/prestashop/docs`) | https://github.com/prestashop/docs/blob/9.x/webservice/tutorials/create-product-az.md | 2026-09-14 | `<associations><product_features><product_feature><id>{{id_feature}}</id><id_feature_value>{{id_feature_value}}</id_feature_value></product_feature></product_features></associations>`; and `<product_feature_value><id_feature>…</id_feature><value><language id="1">…</language></value></product_feature_value>`. |
| S3 | documentation | Product feature commands (`product/index.md`) | PrestaShop (via Context7 `/prestashop/docs`) | https://github.com/prestashop/docs/blob/9.x/development/architecture/domain/references/product/index.md | 2026-09-14 | `SetProductFeatureValuesCommand` POST `/api/products/{id}/features` body `featureValues: [{featureId, valueId}]`; `RemoveAllFeatureValuesFromProductCommand` DELETE `/api/products/{id}/features`. |
| S4 | documentation | Add Feature Value Command | PrestaShop (via Context7 `/prestashop/docs`) | https://github.com/prestashop/docs/blob/9.x/development/architecture/domain/references/feature/_partials/add-feature-value-command.md | 2026-09-14 | "This command is used to add predefined feature values. Predefined values are distinct from custom values, which are exclusively assigned to specific products." Constructor `featureId` (int), `localizedValues` (array); handler `AddFeatureValueHandler`; returns `FeatureValueId`. |
| S5 | documentation | Set product feature values command (`POST /products/{productId}/feature-values`) | PrestaShop (via Context7 `/prestashop/docs`) | https://github.com/prestashop/docs/blob/9.x/development/architecture/domain/references/product/_partials/set-product-feature-values-command.md | 2026-09-14 | POST `/products/{productId}/feature-values`; body `featureValues: [{featureId, valueId}]`; response `featureValueIds`. |
| S6 | documentation | Feature domain commands and queries (`feature/index.md`) | PrestaShop (via Context7 `/prestashop/docs`) | https://github.com/prestashop/docs/blob/9.x/development/architecture/domain/references/feature/index.md | 2026-09-14 | Commands: `AddFeatureCommand`, `AddFeatureValueCommand`, `EditFeatureCommand`, `EditFeatureValueCommand`. Queries: `GetFeatureForEditing`, `GetFeatureValueForEditing`. |
| S7 | documentation | Product Feature XML schema (`product_features.md`) | PrestaShop (via Context7 `/prestashop/docs`) | https://github.com/prestashop/docs/blob/9.x/webservice/resources/product_features.md | 2026-09-14 | `<product_feature><id/><position/><name><language id="1">…</language></name></product_feature>`. |
| S8 | documentation | Faceted search — Introduction | PrestaShop (via Context7 `/prestashop/docs`) | https://github.com/prestashop/docs/blob/9.x/development/components/faceted-search/_index.md | 2026-09-14 | "PrestaShop provides a faceted search capability that allows customers to filter products using multiple criteria simultaneously. This feature is powered by a dedicated module that maps user-selected filters directly into SQL queries to retrieve relevant product results." |

## 6. Evidence quality, contradictions, uncertainty, freshness

### 6.1 Contradictions / documentation inconsistencies

- **Endpoint shape for setting product feature values (S3 vs S5).** S5 documents
  `POST /products/{productId}/feature-values`; S3 documents the same command as
  `POST /api/products/{id}/features`. Both agree on the payload
  (`featureValues: [{featureId, valueId}]`). This is an internal inconsistency in the
  PrestaShop docs (route prefix and segment differ); the *semantics* are consistent.
  Treat the route path as version/API-surface dependent, not as a substantive model
  conflict.

### 6.2 Uncertainty / gaps (bounded; do not over-claim)

- **GET representation of product feature values is unvalidated.** Revision 1 §4 cited a
  `get-product-feature-values.md` partial returning `ProductFeatureValue {id, name, value}`.
  Context7 did **not** surface that page in this run, so **no GET-shape claim is made**.
  Open gap; a granted re-query could close it.
- **Feature→faceting/listing linkage is not directly evidenced.** S8 evidences generic
  faceted search only; it does not name features as a facet criterion or show a
  feature-based filter. Any "features power the listing/facets" statement is an
  **assumption**, not a validated claim (Q4: partially answered).
- **Bounded negatives (our extensions).** Within the admitted sources, **no** evidence
  appeared of (a) family/subfamily-scoped feature assignment, (b) a distinct global
  "all products" scope, (c) any price-list / per-tarifa dimension or inheritance, or
  (d) import/export/list visibility flags on features. The PrestaShop model as retrieved
  is definition → value → **product** link only. This is absence-of-evidence from a
  partial doc retrieval, not proof of absence; still a strong indication that these are
  our extensions.
- **Value typing.** Admitted sources describe feature value `value` as a string
  (`isGenericName`, max 255). No boolean/typed feature value was evidenced. Our
  `tipo = bool|string` is therefore an extension, not a PrestaShop concept.
- **No evidence on localization strategy tradeoffs.** PrestaShop localizes `name` and
  `value` per language id; whether we keep single-language is a product choice, not an
  evidence question.

### 6.3 Freshness

- Sources are from the PrestaShop `9.x` documentation branch and were accessed
  `2026-09-14`. The API/domain layer (commands, handlers, value objects) is a
  modern-PrestaShop surface that can evolve; re-check on a major-version bump.

### 6.4 Confidence

- **High:** C1–C8 (domain/webservice structure, predefined-vs-custom distinction, product
  association, bidirectional set/remove commands). Multiple independent pages agree.
- **Medium:** C9 (generic faceted search exists; linkage to features not established).
- **Unsupported (not claimed):** a features→facets mechanism; any family/global/tarifa
  scope; typed (`bool`) values. See §6.2.

## 7. Concept mapping — PrestaShop → our `caracteristica`

Disposition key: **keep** (same concept), **simplify** (drop an optional dimension),
**extend** (our addition with no evidenced PrestaShop counterpart).

| PrestaShop concept | Evidence | Our concept | Disposition |
|---|---|---|---|
| `product_feature` definition: `id`, `position`, localized `name` | S7, S6 | `catalogo_caracteristica`: `codigo`, `nombre`, `orden`, flags | **keep** (definition + ordering) · **simplify** (drop per-language localization) |
| Predefined value catalog (`product_feature_value`, `custom = false`) | S1, S4 | D4 optional predefined-value catalog | **keep** concept (see §7.D4) |
| Custom value (`custom = true`, product-specific) | S1, S4 | free-text value typed by `tipo` | **keep** concept as the free-text path |
| Product↔feature/value link (`associations > product_features`) | S2 (C6) | product-scope value rows on `articulo` (`referencia`) | **keep** as product scope |
| Set/remove product feature values (`{featureId, valueId}`) | S5, S3 | assignment write/clear API | **keep** semantics |
| Localized `name` / `value` per `<language id>` | S7, S1 | single-language `nombre` / `valor` | **simplify** |
| `position` ordering | S7 | `orden` | **keep** |
| Generic faceted search (no feature linkage evidenced) | S8 | `listable` flag + batched list columns | **extend** (mechanism differs; faceting linkage unproven) |
| No family/subfamily-scoped assignment evidenced | §6.2 | `familia` scope + propagation | **extend** (ours) |
| No distinct global "all products" scope evidenced | §6.2 | `global` scope value table | **extend** (ours) |
| No per-tarifa dimension / inheritance evidenced | §6.2 | `codtarifa` in value tables + `DEF` inheritance + `copy_from_tarifa` clone | **extend** (ours) |
| No value type; `value` is a string (max 255) | S1 | `tipo = bool \| string` | **extend** (ours) |
| No import/export/list flags evidenced | §6.2 | `importable` / `exportable` / `listable` | **extend** (ours) |

**Summary:** PrestaShop contributes the *definition → value → product link* spine and the
*predefined vs custom value* distinction. Every scope beyond the single product, the
per-tarifa dimension and clone, the boolean type, and the import/export/list flags are
**our extensions**, not PrestaShop concepts.

## 8. Non-authoritative product choices (separate from evidence)

These remain **product decisions owned by the orchestrator/product owner**. They are
**not** evidence and are **not** confirmed by this research. `explore.md` §7 currently
records:

- **D2 (recommendation in explore.md):** Option A′ — one definition + three tarifa-keyed
  scope tables with `DEF` inheritance. Fallback Option B (7 tables).
- **D4 (recommendation in explore.md):** free text now, typed by `tipo`; predefined-value
  catalog deferred as an additive later change.
- **D1/D3/D5–D11:** unchanged by this research (ownership, supersession, precedence,
  deletion, rendering, import/export contract, slug, type scope, referential behavior).

**Status: `pending`.** `proposal_ready` is **not** satisfied until the orchestrator
confirms D2 and D4 (and the remaining decisions).

### 8.1 Recommendations *informed by* PrestaShop (advisory, non-authoritative)

**D2 — storage shape.** PrestaShop's evidenced shape is a three-role model:
(a) definition table `product_feature`; (b) value rows `product_feature_value` carrying a
discriminator (`custom`); (c) a separate product↔value link (`associations >
product_features`). Two relevant, evidence-based observations:

1. **A discriminated single value table is proven viable.** PrestaShop stores both
   catalog values and product-specific values in *one* value table, distinguished by the
   `custom` boolean (S1, S4). This is direct precedent for collapsing scope/value variants
   behind a discriminator column rather than a table per variant.
2. **But the product link is kept separate from the value row** (S2, C6). PrestaShop does
   not store the product scope inside `product_feature_value`. That separation is
   structurally closer to Option A′'s separation of value storage by scope than to a single
   table holding both catalog and assignment.

   **Advisory:** PrestaShop weakly favors (a) discriminated value rows plus (b) a distinct
   assignment/link relation. It is **neutral on the tarifa dimension** (not evidenced) and
   therefore does **not** refute Option A′. If table-count reduction is the priority, a
   single value table with `scope` + `scope_ref` + `codtarifa` + a `custom`-like
   discriminator is a supportable alternative to A′'s three tables; the tradeoff is
   polymorphic `scope_ref` and weaker explicit FKs. Option A′'s three tables keep explicit
   FKs and a uniform per-scope resolver. **No change to the pending D2 recommendation.**

**D4 — predefined catalog vs free text.** This is the strongest evidence-backed point in
the lane:

- PrestaShop **explicitly separates** predefined feature values from custom values that are
  "exclusively assigned to specific products" (S4), and materializes the distinction as the
  `custom` boolean on the value row (S1). A predefined catalog is therefore a mature,
  well-defined concept.
- Our free-text-now option maps cleanly onto PrestaShop's **custom** path; a later catalog
  maps onto its **predefined** path. Because both coexist in one table behind `custom`,
  PrestaShop demonstrates the migration is additive.
- Our `tipo = bool` has **no** PrestaShop counterpart (values are strings) and is an
  extension.

  **Advisory (non-authoritative):** keeping free text typed by `tipo` for this slice is
  compatible with PrestaShop's custom path. When the catalog is added, a
  discriminator/`origen`-style column from day one keeps the addition schema-additive.
  PrestaShop favors the catalog *eventually*; it does not require it now. **No change to
  the pending D4 recommendation.**

## 9. Recovery / supersession note

Revision 1 of this artifact was `outcome: blocked` because the capability declaration was
empty (`documentation=[]; open-web=[]`) and admission failed closed. Revision 1's retained
intent (§1) and candidate references (§4) were used as the recovery seed. This revision 2
re-runs the lane under the granted `documentation` (Context7 `/prestashop/docs`) capability
and produces the previously missing deliverables. Revision 1 is superseded; its unadmitted
GitHub references are now represented by S1–S3, S5 (fetched via the granted provider), and
no claim rests on an un-granted fetch.

## 10. skill_resolution

- **`fsframework-plugin-sdd` — routing confirmed.** The change is 100% plugin-local
  (`plugins/catalogo_core/` + `plugins/tarifario/` registration) → SDD lives in
  `plugins/catalogo_core/openspec/changes/caracteristicas-producto/`. **No entry** in core
  `openspec/` (anti-pattern avoided).
- **`sdd-research` — loaded and applied.** The launch prompt supplied the shared contracts
  (`_shared/research-lifecycle.md`, `_shared/sdd-phase-common.md`) plus the phase
  deliverable; the phase skill was loaded from its installed location
  (`/home/javier/.config/opencode/skills/sdd-research/SKILL.md`), alongside
  `_shared/persistence-contract.md`. Executed as the dedicated sub-agent: no delegation, no
  `task` call.
