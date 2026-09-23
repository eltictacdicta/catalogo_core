# Delta for tarifario/catalogo-integration

`R-TAR-HOOK-002` changes because D12 of `caracteristicas-producto` removes the
opcional-owned visibility flags the injected opcional surface used, and one
requirement is ADDED covering tarifario's visibility consumers. The frozen hook
names, the neutral permission-filter extension point, the host-neutrality
requirements, the article tab surface, the familia-override removal, the currency
contract and the strict-TDD mapping are unchanged.

`R-TAR-CUR-010` of `tarifario/tarifa` is referenced, not redefined.

## MODIFIED Requirements

### Requirement: Equivalent hook surface on ventas_opcional (R-TAR-HOOK-002) [PR1 point · PR2 injection]

`ventas_opcional` MUST expose the same two frozen hook points and receive the
equivalent injected tarifas surface when `tarifario` registers. The injected
surface MUST render catalog/tarifa visibility as a derived read-only value
resolved from the parent product's effective feature values for the selected
tarifa (D12 existential union) and MUST NOT expose or persist an opcional-owned
`en_catalogo`/`en_tarifa` state.
(Previously: the injected opcional surface could read and write the
opcional-owned visibility flags.)

#### Scenario: Tab injected on ventas_opcional when active

- GIVEN `tarifario` active with its hook registrations
- WHEN `ventas_opcional` renders
- THEN the tarifas tab header and pane appear at the frozen hook positions
- Test scope: `plugin-suite`

#### Scenario: Opcional visibility is derived, not opcional-owned

- GIVEN an opcional whose parent product is visible for the selected tarifa
- WHEN the injected surface renders
- THEN the visibility is shown as a derived read-only value
- AND no opcional visibility field is rendered as editable and none is persisted
- Test scope: `plugin-suite`

#### Scenario: Multi-parent union on the injected surface

- GIVEN an opcional attached to two products, one visible and one not
- WHEN the injected surface renders visibility
- THEN it shows visible (any-parent union)
- Test scope: `plugin-suite`

#### Scenario: No tab when hooks are unregistered

- GIVEN no plugin registers the hooks
- WHEN `ventas_opcional` renders
- THEN the markers render empty and no tab appears
- Test scope: `host-suite`

## ADDED Requirements

### Requirement: R-TAR-HOOK-011 — Tarifario visibility consumers resolve feature values

`tarif_catalogo_view` — the only tarifario consumer with a catalog/tarifa
visibility read — MUST resolve `en_catalogo`/`en_tarifa` through the
`caracteristicas-producto` resolver when the read-through flag is enabled, and
through the legacy columns while it is disabled, preserving the pre-change output
in both cases.

The requirement's surface is explicitly delimited (DEV-16):
`tarif_configurador_opcionales` has **no** catalog/tarifa visibility read after
WU-4 — its `activa`/`visible` state is association state, a different concept — and
`tarif_articulo` has no visibility read either: it maps the import payload into
article-scope feature values (`factory_caracteristicas`) and no longer writes the
price-row flags. Neither is a read-through consumer, and neither may reintroduce an
opcional-owned visibility flag.

The SQL **membership** filters of `tarif_catalogo_view` and of the
`all_en_catalogo*` / `count_en_*` model helpers stay legacy-by-design during the soak
(DEV-17), ratified in `MEMBERSHIP_FILTERS_LEGACY_BY_DESIGN`; they MUST be rewritten to
the feature tables before the legacy columns are dropped (CAR-15 clause 2).

The JSON import path MUST keep the legacy columns and the feature values consistent
(DEV-18): an imported row's visibility is derived once and written to both.

`en_sap` and every unrelated tarifario read MUST remain unchanged. Opcional visibility
MUST be derived from the parent product, never from an opcional-owned flag.

#### Scenario: Catalog export set is preserved

- GIVEN the pre-change exported article set for a tarifa
- WHEN the export runs after the change with the read-through flag enabled
- THEN the exported set is identical
- Test scope: `plugin-suite`

#### Scenario: Catalog visibility reads follow the resolver

- GIVEN an article whose effective feature values differ from its stale legacy columns
- WHEN `tarif_catalogo_view` renders its visibility for the selected tarifa
- THEN it reflects the resolver value
- Test: `plugins/tarifario/tests/Controller/TarifCatalogoVisibilityReadThroughTest.php`

#### Scenario: Legacy membership filters are ratified and inventory-frozen

- GIVEN the catalog membership filters after the change
- WHEN their legacy-column reads are inspected
- THEN they match the frozen DEV-17 inventory and the closure plan is recorded
- Test: `plugins/tarifario/tests/Controller/TarifCatalogoMembershipFilterRatificationTest.php`

#### Scenario: JSON import dual-writes the imported visibility

- GIVEN a JSON import row carrying visible `en_catalogo`/`en_tarifa`
- WHEN the batch is processed
- THEN the legacy column and the feature value carry the same value for the selected tarifa
- AND the resolver returns that visibility instead of NULL
- Test: `plugins/tarifario/tests/Controller/TarifCatalogoJsonImportVisibilityTest.php`

#### Scenario: en_sap and unrelated reads are untouched

- GIVEN the tarifario consumers after the change
- WHEN their sources are inspected
- THEN `en_sap` reads and every non-visibility read are unchanged
- Test scope: `plugin-suite`
