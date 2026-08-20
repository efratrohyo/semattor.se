---
description: Cross-link a rug collection's configurable products (Form + Colour-and-form) in Magento
argument-hint: <CollectionName>   e.g. /add-rug-forms Nelly
allowed-tools: Semattor Live:builtin_system_restapi
---

# Add "Form" + "Colour and form" links for a rug collection

You are wiring up the two custom recommendation boxes on a rug collection's
**configurable** products in Magento, exactly the way the "Livia" collection is done.
The collection to process is: **$ARGUMENTS** (if empty, ask which collection).

## ⛔ WRITE-SAFETY RULE (read first — this is mandatory)

Magento's `POST /V1/products/{sku}/links` endpoint re-saves the whole product and is
**NOT safe under concurrency**. Two link writes that run at the same time can clobber
each other — one silently loses its `newlink`/`customlink` even though the API returns
`success: true`. This has already happened once (two Grå shapes lost their links).

Therefore, when writing links you MUST:

1. **Write ONE SKU at a time, strictly sequentially.** Never fire the link POSTs in
   parallel / in a single batch of tool calls. Send the next POST only after the
   previous one has returned. (Read-only GETs may be batched; writes may not.)
2. **Verify each SKU immediately after writing it**, before moving to the next: re-GET
   `product_links` and confirm this SKU now has the expected `newlink` + `customlink`
   count AND its `related` count is unchanged. If it doesn't match, **re-write that same
   SKU and re-verify** (up to 3 attempts) before continuing.
3. Do **not** use `/async/bulk/...` for these writes — bulk runs them concurrently and
   reintroduces the exact race this rule exists to prevent.
4. After all SKUs are done, run **one final full-collection sweep** (a read of every
   SKU) to confirm every product still holds its links. If any regressed, fix it
   sequentially and sweep again.

If you ever catch yourself about to emit more than one link-write tool call in the same
turn, STOP and do them one turn at a time instead.

## Background — how the links work (do not change this logic)

Each rug comes in 3 shapes, detected from the product NAME:
- name starts with `Ullmatta`   -> shape = rectangular
- name starts with `Gångmatta`  -> shape = runner
- name starts with `Rund Matta` -> shape = round

The colour is the trailing part of the name after the collection word
(e.g. "Rund Matta Nelly Grön" -> colour = "Grön").

Two custom product-link types must be written:

1. **`newlink`  ("Form")** = the SAME colour, the OTHER two shapes.
   Ordered by shape (rectangular, runner, round), skipping the current one,
   positions 1..2.

2. **`customlink` ("Colour and form")** = EVERY OTHER colour, ALL three shapes.
   Group by the other colours; within each colour order shapes
   (rectangular, runner, round). Positions 1..N in that order.

The pre-existing `related` links (typically `124758-*` and `VD-*` SKUs) are a
separate shared set. **NEVER add, remove, or resend `related` links.**

## Steps

1. **Discover** the collection's configurable products:
   `GET /V1/products` with query:
   - `searchCriteria[filterGroups][0][filters][0][field] = name`
   - `...[value] = %$ARGUMENTS%`, `...[conditionType] = like`
   - `searchCriteria[filterGroups][1][filters][0][field] = type_id`
   - `...[value] = configurable`, `...[conditionType] = eq`
   - `fields = items[sku,name,type_id],total_count`
   - `searchCriteria[pageSize] = 100`

2. **Parse** each item into `{sku, colour, shape}` using the name rules above.
   Group by colour. Each colour should have exactly 3 shapes — if any colour has
   fewer, list the gap and ask before continuing. Print the parsed grid
   (colour × shape × sku) and get a quick confirm.

3. **Compute**, for every product P (colour C, shape S):
   - `newlink`   -> the 2 products with colour C and shape ≠ S (shape order), pos 1..2
   - `customlink` -> all products with colour ≠ C (colour groups, shape order), pos 1..N

   Recompute for the WHOLE collection, not just new colours. This keeps existing
   colours (e.g. an already-live Beige) pointing at any newly added colours.
   (If the user says "only touch the new colours", still compute the customlink
   additions for existing colours so cross-links stay complete — just skip
   rewriting their newlink.)

4. **Safety check (before writing):** for each SKU, `GET /V1/products/{sku}` with
   `fields = product_links[link_type,linked_product_sku]` and record how many
   `related` links it has. Keep this number.

5. **Write** per product using the links sub-resource so other link types are
   preserved — **following the WRITE-SAFETY RULE above (one SKU at a time,
   verify-and-retry after each; never in parallel, never via async/bulk):**
   `POST /V1/products/{sku}/links`
   body: `{ "items": [ ...only the newlink and customlink items for this sku... ] }`
   Each item: `{ "sku": "<sku>", "link_type": "newlink"|"customlink",
   "linked_product_sku": "<target>", "position": <n> }`

6. **Verify (after writing):** re-GET `product_links` for each SKU and confirm:
   - `related` count is UNCHANGED from step 4 (if it changed, STOP and report)
   - `newlink` and `customlink` match what was computed
   Then do the final full-collection sweep from the WRITE-SAFETY RULE.
   Print a short summary table: sku | #newlink | #customlink | #related(before→after).

7. Do **not** touch prices, stock, categories, descriptions, images, or any
   attribute. Only `newlink` + `customlink` links.

## Output
End with a compact table of what was linked and an explicit line:
"Related links preserved: yes/no". If anything was ambiguous (missing shape,
unexpected extra colour, name that didn't parse), list it and ask rather than guess.
