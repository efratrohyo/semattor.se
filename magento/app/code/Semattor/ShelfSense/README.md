# ShelfSense – smart sortering per kategori (Semattor_ShelfSense)

Välj per kategori i admin hur produkterna ska sorteras:

| Val | Vad som händer |
|---|---|
| **Standard** *(förval)* | Ingenting. Kategorin följer sina positionsnummer exakt som idag. |
| **Nyheter först** | Senast tillagd först = högst produkt-ID först. |
| **Mest relevant (smart)** | Poäng av försäljning (senaste trenden räknas dubbelt) + nyhetsboost som klingar av + din gamla manuella ordning. |
| **Bästsäljare** | Flest sålda enheter senaste 90 dagarna (netto, minus avbokat/returnerat). Lika → trend → högst produkt-ID. |

Extra:
- **Fäst SKU:er överst** – skriv SKU:er (en per rad) som alltid ska ligga först, i den ordningen.
- **Slutsålda sist** (global inställning) – slutsålda/avstängda produkter hamnar sist.

## Varför det funkar med vilket tema som helst

ShelfSense ändrar ingen mall, ingen layout och ingen frontend-kod. Den skriver bara om
**positionsnumren** (`catalog_category_product.position`) i de kategorier där du valt
ett sorteringssätt. Temat sorterar redan på position, så det visar automatiskt den nya
ordningen – Luma, Hyvä eller eget tema spelar ingen roll.

- Kategorier på **Standard** läses eller ändras aldrig.
- Inga produkter läggs till eller tas bort ur kategorier – bara ordningen ändras.
- Första gången en kategori får ett sorteringsval sparas de gamla positionerna i en
  backup. Byter du tillbaka till **Standard** återställs den gamla ordningen exakt.

## Installation

Kopiera mappen till `app/code/Semattor/ShelfSense` i Magento och kör:

```bash
bin/magento module:enable Semattor_ShelfSense
bin/magento setup:upgrade
bin/magento setup:di:compile
bin/magento cache:flush
```

## Användning

1. **Katalog → Kategorier → välj kategori → "ShelfSense – smart sortering"**.
2. Välj sortering och spara. Ordningen räknas om direkt, och sedan varje natt 03:15.
3. Inställningar (vikter, antal dagar, cron): **Butiker → Konfiguration → Katalog → ShelfSense**.

Testa först utan att ändra något:

```bash
bin/magento shelfsense:apply --category=123 --dry-run
```

Visar exakt ordning med sålda enheter, trend, datum och poäng. Kör om alla direkt:

```bash
bin/magento shelfsense:apply
```

## Bra att veta

- Det kunden ser som **"Populärt"** i sorteringsmenyn är Magentos *Position*. Det är den
  ordningen ShelfSense styr. Kunden ser inga nya val. Väljer kunden själv t.ex. pris gäller
  kundens val.
- Ankarkategorier (som visar produkter från underkategorier): bara produkter som ligger
  **direkt** i kategorin får nya positioner.
- Vill du ändra den manuella ordningen i en kategori med ShelfSense aktivt: sätt först
  *Standard* och spara, dra om ordningen, välj sedan sortering igen. (Annars skriver
  ShelfSense över dina ändringar vid nästa omräkning.)
- Positioner är globala i Magento, så valet gäller alla butiksvyer. Försäljning räknas
  från alla butiker.
- Avinstallation: sätt alla kategorier till *Standard* och spara (återställer ordningen)
  innan modulen tas bort.
