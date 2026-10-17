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

Import the repository in Vercel, set **Root Directory** to `docs`, and set **Production Branch** (Settings → Git) to **`docs-live`**. `vercel.json` sets the install/build commands; its ignore step (`scripts/vercel-ignore.sh`) builds every production push and builds previews when `docs/` or `readme.txt` changed.

**Branches:**

- **`docs-live`** — the docs of the released plugin; the live site. A release moves it forward to the release commit (`publish-docs` in `release-please.yml`), so docs for new features go live with the plugin.
- **`main`** — next release. Feature PRs merge their docs here; they show only in previews until the release.

**Quick fix to the live docs** (a typo, a wrong step) without publishing unreleased docs: branch from `docs-live`, open the PR **into `docs-live`**, merge it — it's live right away. `docs-live.yml` then merges the fix into `main` (a merge commit, so the next release can fast-forward `docs-live`); on a conflict it opens a PR to resolve, which must be merged with a **merge commit**, not squash.

Once a custom domain is connected, set the `DOCS_SITE_URL` environment variable (used for the sitemap and canonical URLs).
