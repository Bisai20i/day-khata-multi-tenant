---
paths:
  - 'resources/js/**'
---

# Js

## Keep Vue/JS files around 500 lines (max ~750)
Target ~500 lines per .vue/.js file, +-250 (so roughly 250-750). Never let a file grow past ~750; when one nears it, extract composables (resources/js/composables), lib helpers (resources/js/lib) or child components instead of adding more. The lower bound is a guide, not a reason to merge small focused components. Known offenders still to split: Sales/Pos.vue, Sales/Create.vue, Purchases/Create.vue, Inventory/Items/Index.vue, Sales/Returns/Create.vue, lib/money.js.
