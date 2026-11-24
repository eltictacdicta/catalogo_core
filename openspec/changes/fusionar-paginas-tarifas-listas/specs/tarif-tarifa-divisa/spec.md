# Delta: tarif-tarifa-divisa

## ADDED Requirements

### Requirement: Currency visible in the tariff list (R-TAR-CUR-008)

The tariff list in `View/tarif_tarifas.html.twig` MUST show each tariff's
`coddivisa` in its own "Moneda" column placed right after "Nombre", so the
operator does not have to open the edit modal to know the currency of a
tariff's prices. The value MUST be autoescaped (no `|raw`) and MUST fall back
to `EUR` when empty. The column is unconditional: it is shown whether or not
`tarifario` is active.

#### Scenario: Header declares the column after Nombre

- GIVEN the tariff list table
- WHEN its header is inspected
- THEN a `<th>` with text "Moneda" immediately follows `<th>Nombre</th>`

#### Scenario: Each row prints the tariff currency

- GIVEN a tariff with `coddivisa = 'USD'`
- WHEN the list renders
- THEN its row has a cell containing `USD`

#### Scenario: Header and rows stay aligned

- GIVEN the list table
- WHEN unconditional `<th>` and `<td>` cells are counted
- THEN both counts are equal
