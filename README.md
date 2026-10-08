# nwr-search (Nowera Search)

WordPress plugin pre rýchle vyhľadávanie cez vlastný Typesense server. Indexuje produkty WooCommerce, stránky, články, vlastné typy obsahu (vrátane ACPT polí) aj dokumenty. Funguje s Bricks, Elementorom aj klasickou témou.

## Štruktúra
| Cesta | Obsah |
|---|---|
| `plugin/nwr-search` | samotný plugin (to, čo ide do ZIPu) |
| `plugin/tests/playground` | WordPress Playground blueprinty a testovacie skripty |
| `infra/typesense` | Docker Compose pre Typesense za Traefikom (`.env.example`) |
| `infra/typesense-dashboard` | Docker Compose pre admin dashboard na vlastnej doméne |
| `infra/sites` | kľúče jednotlivých webov (`<collection>.env`, nikdy sa necommitujú) |
| `tools/provision-site.mjs` | vytvorí pre web dva kľúče obmedzené na jeho kolekciu |

## Ako to funguje
- Každý web má vlastnú kolekciu (`www.example.com` → `example_com`) a dva kľúče:
  - **site**: zápis do svojej kolekcie, iba na serveri (WordPress).
  - **search**: iba `documents:search`, ide do prehliadača.
- Kľúče nemajú práva na aliasy (kľúč s aliasmi by vedel presmerovať cudziu kolekciu).
- Synchronizácia pri ukladaní obsahu + reindex na pozadí (Action Scheduler / WP-Cron), reindex prebieha na mieste bez výpadku.
- Frontend: shortcode `[nwr_search]` (box / panel v hlavičke), `[nwr_search_results]` (stránka s výsledkami a filtrami), Bricks elementy s rovnakým výstupom. Voliteľne prevezme WordPress vyhľadávanie (`?s=` → stránka s výsledkami).
- WP-CLI: `wp nwr-search …`.

## Nový web
1. `cp infra/typesense/.env.example infra/typesense/.env` a doplniť master kľúč a host (raz pre server).
2. `node tools/provision-site.mjs example_com` → `infra/sites/example_com.env`.
3. ZIP: `cd plugin && zip -r ../dist/nwr-search-X.Y.Z.zip nwr-search -x '*.DS_Store'`, nahrať a aktivovať.
4. V administrácii Nowera Search zadať host, kolekciu a oba kľúče, spustiť reindex.

## Server
```bash
cd infra/typesense && docker compose up -d
cd infra/typesense-dashboard && docker compose up -d
```
Obe služby čakajú na externú sieť `root_default` s Traefikom (cert resolver `mytlschallenge`). Dashboard musí bežať na inej doméne ako API, lebo si admin kľúč drží v `localStorage`.

## Testy (WordPress Playground)
```bash
npx @wp-playground/cli@latest server --port=9400 --php=8.3 --login --blueprint=plugin/tests/playground/blueprint.json --mount=plugin/nwr-search:/wordpress/wp-content/plugins/nwr-search --mount=plugin/tests/playground/scripts:/wordpress/nwr-tests --mount=infra/sites:/nwr-sites
```
- `blueprint.json`: WooCommerce obchod, `blueprint-universal.json`: web bez WooCommerce (Bricks + ACPT).
- Skripty na `/nwr-tests/<skript>.php` (seed, reindex, frontend, výsledky, boost, preklady…). `frontend-setup.php` a `results-setup.php` berú `?site=<collection>` a používajú iba verejný search kľúč.
