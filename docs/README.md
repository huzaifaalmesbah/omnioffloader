# OmniOffloader — public docs site

The user documentation, built with [Astro Starlight](https://starlight.astro.build/). This folder is excluded from the plugin bundle and the WordPress.org deploy (`.distignore`). Internal project notes live in `../project/`, never here.

```bash
pnpm install     # run inside docs/ — it is its own pnpm workspace
pnpm dev         # http://localhost:4321
pnpm build       # static site in dist/
```

- Pages: `src/content/docs/<section>/*.md(x)`. The sidebar is generated per folder; order pages with `sidebar.order` in the frontmatter.
- **Changelog:** generated from `../readme.txt` by `scripts/changelog.mjs` on every dev/build run. Edit the readme, never `changelog.md` (it is gitignored).
- Keep pages in sync with the plugin: WP-CLI pages mirror `includes/Cli/*Command.php`, provider pages mirror each provider's `credential_fields()`, and the hooks page mirrors the `apply_filters` / `do_action` calls.
- Never link to the plugin's GitHub repository — it is private.

## Deploy (Vercel)

Import the repository in Vercel and set **Root Directory** to `docs`. `vercel.json` sets the install/build commands, and its ignore step (`scripts/vercel-ignore.sh`) decides what builds:

- **Production** builds only with a release — the merged Release Please PR (`chore: release x.y.z`) — so the live docs never describe features the released plugin doesn't have yet. For a docs fix that can't wait, put `[deploy docs]` in the commit message (or redeploy from Vercel).
- **Previews** (pull requests, branches) build whenever `docs/` or `readme.txt` changed.

Merge the release PR with **squash** (the default): its commit message must start with `chore: release`. Once a custom domain is connected, set the `DOCS_SITE_URL` environment variable (used for the sitemap and canonical URLs).
