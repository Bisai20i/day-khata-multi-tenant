---
paths:
  - 'routes/*.php,resources/js/lib/nav-items.js,config/**,composer.json,package.json'
---

# Js Lib

## Shared/wiring files: coordinator edits only, workers request
When work is split across parallel subagents (see `todo/START.md`), route files, `nav-items.js`, `config/*`, and the two dependency manifests are edited only by the coordinating session — never by a worker subagent, even if its task seems to need a route added. A worker lists the exact change needed (file, what, why) as a "cross-file request" in its final report; the coordinator applies it after reviewing the combined diff.
