# Deferred: needs a product decision before building

Do not build these as part of `start`. Ask the user when the plan is finished.

| Item | Why it is deferred |
|---|---|
| In-place editing of posted documents | Immutability by design; posted bills are corrected by cancel or return. Editing would need a versioned-document design for IRD. |
| POS cross-terminal stock reservation | Needs a reservation model and expiry rules. Phase 2 adds row locks, which already stop two terminals selling the last unit at posting time. |
| Item gallery (multiple images) | Low business value, storage decisions per tenant. |
| Sales agent commission statement, multiple agents per line, commission reversal on returns | Needs commission policy decisions (when earned, clawback rules). |
| Unified capital sales/purchases cross-report | Low priority once capital documents are in the VAT books (T10). |
| Forced first-login password change for new employees | Security policy decision. |
| Quotation partial fulfilment across several sales | Scoped already in `mem.md`; changes Quotation's 1:1 conversion model. |
| Zero-value bills (100% discount, free goods only) | Needs a rule for posting a bill with no ledger amounts (IRD still wants the number). The calculator rejects them with a clear message for now. |
| Converting a quotation without the stock check (legacy bypassed it) | Would allow overselling; keep the check unless the user wants it. |
| Zero-padded invoice numbers | Would change the printed format mid-series; only consider at a fiscal year boundary. |
| CBMS / IRD real-time bill sync | Neither app has it; requires IRD API credentials and certification. |
| Ecommerce, Advertise, Slider, Custom pages | Confirmed intentional cuts. |
| Legacy company switcher and application reset | Replaced by per-domain tenancy; document only. |
| Employee privilege granularity | Documented non-goal; admin/staff split is applied in Phase 2. |
