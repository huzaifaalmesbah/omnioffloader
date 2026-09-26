# AGENTS.md — Universal AI Agent Guidelines & Architecture

> **Notice to AI Coding Assistants**: This file is the primary instruction manual for any AI agent working on this codebase (including **Google Antigravity**, **Gemini CLI**, **OpenAI Codex**, **Claude Code**, **Cursor**, **Windsurf**, **GitHub Copilot**, and **Aider**).
> 
> You MUST read this document, adhere to the architectural rules, respect the safety invariants, consult the local skills, and run the mandatory verification checks after every development task.

---

## 1. Project Overview & Core Tech Stack

* **Plugin Slug**: `omnioffloader`
* **Version**: `0.1.3` (⚠️ **VERSION CEILING: `0.9.0`**. Never bump, tag, or release `1.0.0` or higher without explicit permission)
* **PHP Requirements**: PHP 8.1+ with Composer PSR-4 autoloading (`OmniOffloader\` → `includes/`).
* **Static Analysis**: **PHPStan Level 8** (`szepeviktor/phpstan-wordpress`).
* **Coding Standards**: WordPress Coding Standards (**WPCS 3.1**).
* **Frontend UI**: Single-page application (SPA) in `src/` using **React 19** via `@wordpress/element`, `@wordpress/components`, `@wordpress/i18n`, `@wordpress/icons`, and `@wordpress/api-fetch`.
* **Frontend Tooling**: **pnpm** (pinned via `packageManager` in `package.json`), `@wordpress/scripts`, TypeScript 5.7+ (`strict: true`, `noEmit: true`).
* **Documentation**: Independent **Astro Starlight** workspace in `docs/` deployed to Vercel.
* **Screenshots**: Automated headless Chrome screenshot & annotation pipeline via `scripts/capture-screenshots.js`.

---

## 2. ⚡ Mandatory AI Verification Mandate (CRITICAL)

As an AI agent, you **MUST NOT** consider a task complete until you have executed and verified the following checks in the repository terminal:

```bash
# 1. PHPStan Static Analysis (Level 8) - MUST PASS WITH 0 ERRORS
composer phpstan

# 2. Strict TypeScript Type Check - MUST PASS WITH 0 ERRORS
pnpm check-types

# 3. Production Assets Compile - MUST PASS WITH 0 ERRORS
pnpm build

# 4. Coding Standards Check
composer phpcs
```

If any check reports an error or warning, **you must diagnose and fix it immediately** before concluding your work.

---

## 3. 📚 Documentation & Screenshot Automation

### 3.1 Public User Documentation (`docs/` — Astro Starlight)
The public documentation website lives in `docs/` as a standalone Astro Starlight project:
* **Workspace**: Independent `pnpm` workspace excluded from the plugin bundle (`.distignore`).
* **Commands**:
  * `pnpm --filter docs dev` — Start the local documentation preview server at `http://localhost:4321`.
  * `pnpm --filter docs build` — Build static production HTML and verify Pagefind search indexing.
* **Changelog**: Generated automatically from `../readme.txt` by `docs/scripts/changelog.mjs` on every dev/build run. **Always edit `readme.txt`**, never `changelog.md` directly.
* **Synchronization Mandate**: When user-facing features, settings, CLI commands/flags, provider fields, or hooks change, update the matching markdown documentation in `docs/src/content/docs/` in the same commit.
* **Privacy**: Never link to the private GitHub repository from public documentation.

### 3.2 Internal Architecture & Notes (`project/`)
All developer notes, architectural design specifications, roadmaps, security analyses, and audits are strictly segregated into `project/`:
* `project/PLAN.md` — Core architectural blueprint, phase maps, and technical invariants.
* `project/AUDIT.md` — Security and pre-release audit logs.
* `project/roadmap/` — Future milestone planning.

### 3.3 Automated Screenshot Engine (`pnpm screenshots`)
High-resolution screenshots are generated automatically by `scripts/capture-screenshots.js` using Puppeteer Core:
* **Command**: `pnpm screenshots`
* **Features**:
  * Automatically resolves local WordPress path and site URL from WP-CLI / local environment.
  * Authenticates using a dynamic WordPress auth cookie generated via `wp eval`.
  * Captures 2x retina full-frame screenshots to `docs/public/images/screenshots/<view>.png`.
  * Renders numbered callout pill badge overlays (`<view>-marked.png`) for technical documentation.
  * Crops and formats standardized WordPress.org assets (`.wordpress-org/screenshot-{1..N}.png`).

---

## 4. 🛡️ Critical Safety Invariants (NEVER VIOLATE)

1. **Never delete a local file before the cloud upload is verified** (`object_exists` on the main key).
2. **Never delete a cloud object before the local restore is verified** (all expected files present; delete-cloud is opt-in, default OFF).
3. **Restores write atomically**: temp file + `rename()`; destination confined to `wp_upload_dir()` basedir (`realpath` check).
4. **On any failure**, an attachment must always retain at least one valid copy and truthful metadata; errors go to `omnioffloader_error_log`.
5. **Guard `dirname()` returning `'.'`** when building object keys (avoids `./file.jpg` broken keys).
6. **Never log or echo credentials**; REST API never returns raw secrets (`is_set: true` with masked values).
7. **CLI destructive paths** (`--delete-cloud`) require `WP_CLI::confirm()` or `--yes`; every write command supports `--dry-run`.
8. **Only one bulk job at a time**: offload, restore, and retention runs (admin queue or CLI) all share local files. Concurrency conflicts are strictly checked.
9. **Offload / Bring Back is administrator-only**: requires `manage_options` capability (plus `edit_post` / `delete_post`).
10. **A site copy never writes to or deletes from the bucket** (`Core\SafeMode` protection).
11. **A site never writes to or deletes from a bucket folder another site uses** (`Core\FolderClaim`).

---

## 5. 🧠 Agent Skills Directory (`.agents/skills/`)

Consult the official WordPress Agent Skills located in `.agents/skills/` before designing or modifying code:

| Skill Name | Path | Purpose |
|---|---|---|
| **wordpress-router** | `.agents/skills/wordpress-router/SKILL.md` | Routing architectural decisions |
| **wp-plugin-development** | `.agents/skills/wp-plugin-development/SKILL.md` | Lifecycle, hooks, and settings |
| **wp-rest-api** | `.agents/skills/wp-rest-api/SKILL.md` | REST controller schemas and permissions |
| **wp-phpstan** | `.agents/skills/wp-phpstan/SKILL.md` | PHPStan Level 8 typing and stubs |
| **wp-performance** | `.agents/skills/wp-performance/SKILL.md` | Performance and query optimization |
| **wp-wpcli-and-ops** | `.agents/skills/wp-wpcli-and-ops/SKILL.md` | Custom WP-CLI commands and batch operations |
| **wp-plugin-directory-guidelines** | `.agents/skills/wp-plugin-directory-guidelines/SKILL.md` | WordPress.org review and guideline compliance |

---

## 6. 🛠️ Development & Build Commands

```bash
pnpm start            # Development build with watch mode (wp-scripts)
pnpm build            # Production build
pnpm check-types      # tsc --noEmit — strict TypeScript check
pnpm lint:js          # ESLint (WordPress TS config)
pnpm screenshots      # Capture automated retina screenshots and docs callouts
pnpm --filter docs dev    # Start Astro Starlight docs preview server
pnpm --filter docs build  # Build production static documentation
pnpm bundle           # Build + distribution zip
pnpm bundle:org       # Format dist/ for WordPress.org SVN deploy
composer phpstan      # PHPStan level 8
composer phpcs        # WordPress Coding Standards
```
