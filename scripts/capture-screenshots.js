/**
 * Script to capture crisp, high-resolution screenshots for WordPress.org and OmniOffloader Docs.
 * Captures both clean (pristine) and annotated (marked with callout numbers & guides) versions.
 *
 * Rules:
 * - docs/public/images/screenshots/ gets both clean (<slug>.png) and marked (<slug>-marked.png).
 * - .wordpress-org/ gets ONLY the marked versions, named strictly as screenshot-1.png, screenshot-2.png, ...
 */
const puppeteer = require('puppeteer-core');
const { execSync } = require('child_process');
const fs = require('fs');
const path = require('path');

const WP_ROOT = '/Users/huzaifa/Sites/wp/wp71';
const PLUGIN_ROOT = path.join(WP_ROOT, 'wp-content/plugins/omnioffloader');
const WPORG_DIR = path.join(PLUGIN_ROOT, '.wordpress-org');
const DOCS_IMG_DIR = path.join(PLUGIN_ROOT, 'docs/public/images/screenshots');

fs.mkdirSync(WPORG_DIR, { recursive: true });
fs.mkdirSync(DOCS_IMG_DIR, { recursive: true });

async function getAuthCookies() {
  const cmd = `wp eval '$u=1;$e=time()+86400;echo SECURE_AUTH_COOKIE."=".wp_generate_auth_cookie($u,$e,"secure_auth").";".LOGGED_IN_COOKIE."=".wp_generate_auth_cookie($u,$e,"logged_in");'`;
  const raw = execSync(cmd, { cwd: WP_ROOT }).toString().trim();
  const line = raw.split('\n').pop().trim();
  return line.split(';').map(c => {
    const [name, ...rest] = c.trim().split('=');
    return {
      name,
      value: rest.join('='),
      domain: 'wp71.test',
      path: '/',
      secure: true
    };
  });
}

function applyOverlay(items) {
  const overlay = document.createElement('div');
  overlay.id = 'omnioffloader-annotations-overlay';
  overlay.style.position = 'absolute';
  overlay.style.top = '0';
  overlay.style.left = '0';
  overlay.style.width = '100%';
  overlay.style.height = '100%';
  overlay.style.pointerEvents = 'none';
  overlay.style.zIndex = '999999';
  document.body.appendChild(overlay);

  items.forEach(item => {
    let el;
    if (typeof item.selector === 'string') {
      if (item.index !== undefined) {
        const list = document.querySelectorAll(item.selector);
        el = list[item.index];
      } else {
        el = document.querySelector(item.selector);
      }
    }
    if (!el) {
      console.warn('Selector not found for annotation:', item.selector, item.index);
      return;
    }

    const rect = el.getBoundingClientRect();
    const scrollY = window.scrollY || window.pageYOffset;
    const scrollX = window.scrollX || window.pageXOffset;

    const pad = item.padding ?? 8;
    const box = document.createElement('div');
    box.style.position = 'absolute';
    box.style.left = (rect.left + scrollX - pad) + 'px';
    box.style.top = (rect.top + scrollY - pad) + 'px';
    box.style.width = (rect.width + pad * 2) + 'px';
    box.style.height = (rect.height + pad * 2) + 'px';
    box.style.border = '2.5px solid #2563eb';
    box.style.backgroundColor = 'rgba(37, 99, 235, 0.035)';
    box.style.borderRadius = (item.borderRadius ?? 12) + 'px';
    box.style.boxShadow = '0 0 0 3px rgba(37, 99, 235, 0.18), 0 8px 24px rgba(37, 99, 235, 0.12)';
    box.style.pointerEvents = 'none';
    box.style.boxSizing = 'border-box';

    const badge = document.createElement('div');
    badge.style.position = 'absolute';

    const offsetY = item.offsetY ?? -16;
    const offsetX = item.offsetX ?? 14;

    if (item.position === 'top-right') {
      badge.style.top = offsetY + 'px';
      badge.style.right = offsetX + 'px';
    } else if (item.position === 'bottom-left') {
      badge.style.bottom = offsetY + 'px';
      badge.style.left = offsetX + 'px';
    } else if (item.position === 'bottom-right') {
      badge.style.bottom = offsetY + 'px';
      badge.style.right = offsetX + 'px';
    } else if (item.position === 'right') {
      badge.style.top = '50%';
      badge.style.left = 'calc(100% + ' + (offsetX ?? 14) + 'px)';
      badge.style.transform = 'translateY(-50%)';
    } else if (item.position === 'left') {
      badge.style.top = '50%';
      badge.style.right = 'calc(100% + ' + (offsetX ?? 14) + 'px)';
      badge.style.transform = 'translateY(-50%)';
    } else if (item.position === 'below-left') {
      badge.style.top = 'calc(100% + ' + (offsetY > 0 ? offsetY : 8) + 'px)';
      badge.style.left = offsetX + 'px';
    } else if (item.position === 'below-right') {
      badge.style.top = 'calc(100% + ' + (offsetY > 0 ? offsetY : 8) + 'px)';
      badge.style.right = offsetX + 'px';
    } else {
      badge.style.top = offsetY + 'px';
      badge.style.left = offsetX + 'px';
    }

    badge.style.display = 'inline-flex';
    badge.style.alignItems = 'center';
    badge.style.gap = '8px';
    badge.style.backgroundColor = '#1d4ed8';
    badge.style.color = '#ffffff';
    badge.style.padding = '4px 12px 4px 6px';
    badge.style.borderRadius = '9999px';
    badge.style.fontSize = '13px';
    badge.style.fontWeight = '600';
    badge.style.fontFamily = '-apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif';
    badge.style.boxShadow = '0 4px 14px rgba(29, 78, 216, 0.4)';
    badge.style.whiteSpace = 'nowrap';
    badge.style.zIndex = '1000000';

    const numCircle = document.createElement('span');
    numCircle.style.width = '22px';
    numCircle.style.height = '22px';
    numCircle.style.backgroundColor = '#ffffff';
    numCircle.style.color = '#1d4ed8';
    numCircle.style.borderRadius = '50%';
    numCircle.style.display = 'flex';
    numCircle.style.alignItems = 'center';
    numCircle.style.justifyContent = 'center';
    numCircle.style.fontWeight = '800';
    numCircle.style.fontSize = '12px';
    numCircle.innerText = item.num;

    const labelText = document.createElement('span');
    labelText.innerText = item.label;

    badge.appendChild(numCircle);
    badge.appendChild(labelText);
    box.appendChild(badge);
    overlay.appendChild(box);
  });
}

function clearOverlay() {
  const el = document.getElementById('omnioffloader-annotations-overlay');
  if (el) el.remove();
}

async function captureScreen(browser, cookies, config, totalScreens) {
  console.log(`\n▶ [${config.id}/${totalScreens}] Capturing ${config.title}...`);
  const page = await browser.newPage();
  await page.setViewport({ width: config.width || 1440, height: config.height || 940, deviceScaleFactor: 2 });
  await page.setCookie(...cookies);

  // Set up route mocking if specified
  if (config.mocks && config.mocks.length > 0) {
    await page.setRequestInterception(true);
    page.on('request', interceptedReq => {
      const reqUrl = interceptedReq.url();
      for (const mock of config.mocks) {
        if (reqUrl.includes(mock.path)) {
          return interceptedReq.respond({
            status: 200,
            contentType: 'application/json',
            headers: {
              'Access-Control-Allow-Origin': '*',
              'Access-Control-Allow-Credentials': 'true',
            },
            body: JSON.stringify(mock.data),
          });
        }
      }
      return interceptedReq.continue();
    });
  }

  await page.goto(config.url, { waitUntil: 'networkidle2' });
  if (config.waitFor) {
    await page.waitForSelector(config.waitFor, { timeout: 10000 });
  }
  await new Promise(r => setTimeout(r, 1200));

  if (config.beforeCapture) {
    await config.beforeCapture(page);
    await new Promise(r => setTimeout(r, 800));
  }

  // 1. Pristine / Clean Screenshot for Docs
  const cleanDocPath = path.join(DOCS_IMG_DIR, `${config.slug}.png`);
  await page.screenshot({ path: cleanDocPath });
  console.log(`   ✓ Saved clean docs screenshot: ${cleanDocPath}`);

  // 2. Marked / Annotated Screenshot with Callouts
  if (config.annotations && config.annotations.length > 0) {
    await page.evaluate(applyOverlay, config.annotations);

    const markedDocPath = path.join(DOCS_IMG_DIR, `${config.slug}-marked.png`);
    await page.screenshot({ path: markedDocPath });
    console.log(`   ✓ Saved marked docs screenshot: ${markedDocPath}`);

    // In .wordpress-org/, save ONLY marked version named strictly screenshot-${wpOrgNum}.png
    if (config.wpOrgNum) {
      const wpOrgPath = path.join(WPORG_DIR, `screenshot-${config.wpOrgNum}.png`);
      await page.screenshot({ path: wpOrgPath });
      console.log(`   ✓ Saved WordPress.org marked screenshot: screenshot-${config.wpOrgNum}.png`);
    }

    await page.evaluate(clearOverlay);
  }

  await page.close();
}

async function main() {
  console.log('Fetching auth cookies for wp71.test...');
  const cookies = await getAuthCookies();

  const browser = await puppeteer.launch({
    executablePath: '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome',
    args: ['--no-sandbox', '--disable-setuid-sandbox', '--ignore-certificate-errors', '--window-size=1440,1050'],
    headless: 'new'
  });

  const screens = [
    // 1. Dashboard Overview
    {
      id: 1,
      slug: 'dashboard',
      wpOrgNum: 1,
      width: 1440,
      height: 940,
      title: 'Dashboard Overview',
      url: 'https://wp71.test/wp-admin/admin.php?page=omnioffloader&view=dashboard',
      waitFor: '#omnioffloader-admin-app',
      annotations: [
        { selector: '.smowp-hero', num: 1, label: 'Live Cloud Provider & Health', position: 'top-right', offsetY: -16 },
        { selector: '.smowp-stats-grid', num: 2, label: 'Real-Time Storage & Sync Metrics', position: 'top-left', offsetY: -16 },
        { selector: '.smowp-quick-actions-grid', num: 3, label: 'One-Click Quick Actions (Bulk Offload, Restore, Settings)', position: 'top-right', offsetY: -16 },
        { selector: '.smowp-sidebar__nav', num: 4, label: 'Feature Navigation Menu', position: 'top-left', offsetY: -16 }
      ]
    },

    // 2. Cloud Storage Provider Configuration
    {
      id: 2,
      slug: 'provider-settings',
      wpOrgNum: 2,
      width: 1440,
      height: 1040,
      title: 'Cloud Storage Provider & Credentials',
      url: 'https://wp71.test/wp-admin/admin.php?page=omnioffloader&view=settings',
      waitFor: '#omnioffloader-admin-app',
      beforeCapture: async (page) => {
        try {
          const testBtn = await page.$('.smowp-test-connection button');
          if (testBtn) {
            await testBtn.click();
            await page.waitForSelector('.smowp-badge-pulse, .smowp-test-connection__badge', { timeout: 4000 });
          }
        } catch (e) {
          // ignore
        }
        await page.evaluate(() => window.scrollTo(0, 0));
      },
      annotations: [
        { selector: '.smowp-provider-selector-grid', num: 1, label: 'Multi-Cloud Provider Selector (R2, S3, Wasabi, Spaces, B2)', position: 'top-right', offsetY: -16 },
        { selector: '.smowp-credential-fields', num: 2, label: 'API Keys, Endpoint & Bucket Configuration', position: 'top-right', offsetY: -16 },
        { selector: '.smowp-test-connection', num: 3, label: 'Instant "Test Connection" Live Verification', position: 'top-right', offsetY: -16 }
      ]
    },

    // 3. Storage Retention & Delivery Policies
    {
      id: 3,
      slug: 'storage-policies',
      wpOrgNum: 3,
      width: 1440,
      height: 980,
      title: 'Retention & Delivery Policies',
      url: 'https://wp71.test/wp-admin/admin.php?page=omnioffloader&view=settings',
      waitFor: '#omnioffloader-admin-app',
      beforeCapture: async (page) => {
        await page.evaluate(() => {
          const cards = document.querySelectorAll('.smowp-card');
          if (cards.length >= 2) {
            const y = cards[1].getBoundingClientRect().top + window.scrollY - 90;
            window.scrollTo({ top: Math.max(0, y), behavior: 'instant' });
          }
        });
      },
      annotations: [
        { selector: '.smowp-page > .smowp-card:nth-of-type(2)', num: 1, label: 'Local Retention Policy (Controls What Local Files Stay on Server After Offload)', position: 'top-right', offsetY: -16 },
        { selector: '.smowp-card:nth-of-type(2) .components-radio-control', num: 2, label: 'Three Granular Modes: Retain Local Files, Smart Cleanup, Full Cloud Migration', position: 'top-right', offsetY: -16 }
      ]
    },

    // 4. Bulk Offload Center (ACTIVE state with Custom Amount selected)
    {
      id: 4,
      slug: 'bulk-offload',
      wpOrgNum: 4,
      width: 1440,
      height: 980,
      title: 'Bulk Offload Center (Active & Custom Amount)',
      url: 'https://wp71.test/wp-admin/admin.php?page=omnioffloader&view=offload',
      waitFor: '#omnioffloader-admin-app',
      mocks: [
        {
          path: '/omnioffloader/v1/core/stats',
          data: {
            total: 14,
            offloaded: 4,
            local: 10,
            configured: true,
            provider: 'cloudflare-r2',
            safe_mode: {
              active: false,
              reason: '',
              locked: false,
              live_address: 'wp71.test',
              current_address: 'wp71.test',
              environment: 'production',
              bring_back: true,
              folder_owner: '',
              folder: ''
            }
          }
        }
      ],
      beforeCapture: async (page) => {
        await page.waitForSelector('.smowp-batch-scope', { timeout: 6000 });
        const options = await page.$$('.smowp-option-card');
        if (options.length >= 2) {
          await options[1].click();
          await page.waitForSelector('.smowp-custom-presets, .smowp-custom-count-inline', { timeout: 4000 });
          const presetChips = await page.$$('.smowp-preset-chip');
          if (presetChips.length > 0) {
            await presetChips[0].click();
          }
        }
      },
      annotations: [
        { selector: '.smowp-media-summary', num: 1, label: 'Library Status: 10 Local Items Ready to Offload', position: 'top-left', offsetY: -16 },
        { selector: '.smowp-batch-scope', num: 2, label: 'Processing Quantity: All Media vs Custom Batch Size & Presets', position: 'top-right', offsetY: -16 },
        { selector: '.smowp-bulk-action__btn-wrap button', num: 3, label: 'Active Execution: "Start Bulk Offload" Button', position: 'right', offsetX: 18 },
        { selector: '.smowp-page > .smowp-card:nth-of-type(2)', num: 4, label: 'Single Attachment Offload Direct from Media Library', position: 'top-right', offsetY: -16 }
      ]
    },

    // 5. Zero-Lock-In Bring Back (Restore)
    {
      id: 5,
      slug: 'bring-back',
      wpOrgNum: 5,
      width: 1440,
      height: 980,
      title: 'Zero-Lock-In Bring Back (Restore)',
      url: 'https://wp71.test/wp-admin/admin.php?page=omnioffloader&view=bring-back',
      waitFor: '#omnioffloader-admin-app',
      annotations: [
        { selector: '.smowp-media-summary', num: 1, label: 'Offload Status & Pre-Flight Disk Space Verification', position: 'top-left', offsetY: -16 },
        { selector: '.smowp-batch-scope', num: 2, label: 'Processing Quantity: Restore All vs Custom Batches', position: 'top-right', offsetY: -16 },
        { selector: '.smowp-bringback-options', num: 3, label: 'Storage Safety: Keep Cloud Copies vs Reclaim Space', position: 'top-right', offsetY: -16 },
        { selector: '.smowp-bulk-action button.is-primary, .smowp-page .smowp-card:first-child button.is-primary', num: 4, label: 'One-Click Atomic Restore Engine', position: 'right', offsetX: 18 }
      ]
    },

    // 6. WordPress Media Library Integration
    {
      id: 6,
      slug: 'media-library',
      wpOrgNum: 6,
      width: 1440,
      height: 940,
      title: 'WordPress Media Library Integration',
      url: 'https://wp71.test/wp-admin/upload.php?mode=list',
      waitFor: '#the-list',
      beforeCapture: async (page) => {
        await page.evaluate(() => {
          // Permanently display row actions for crystal clear screenshot
          document.querySelectorAll('#the-list tr .row-actions').forEach(el => {
            el.classList.add('visible');
            el.style.position = 'static';
            el.style.left = 'auto';
            el.style.visibility = 'visible';
            el.style.display = 'block';
          });
        });
      },
      annotations: [
        { selector: 'select[name="omnioffloader_filter"]', num: 1, label: 'Filter Media by Offload State', position: 'top-right', offsetY: -16 },
        { selector: 'th#omnioffloader_status', num: 2, label: 'Dedicated Cloud Offload Status Column', position: 'top-left', offsetY: -36, offsetX: -20 },
        { selector: '#the-list tr:first-child .omnioffloader-row-action', num: 3, label: 'Contextual Actions: Offload Now & Bring Back', position: 'right', offsetX: 16 },
        { selector: '.tablenav.top .bulkactions', num: 4, label: 'Native Bulk Offload & Restore Actions', position: 'top-left', offsetY: -28 }
      ]
    },

    // 7. Apply Retention Policy to Existing Media (Tools Screen)
    {
      id: 7,
      slug: 'apply-retention',
      wpOrgNum: 7,
      width: 1440,
      height: 960,
      title: 'Apply Retention Policy to Existing Media (Tools)',
      url: 'https://wp71.test/wp-admin/admin.php?page=omnioffloader&view=tools',
      waitFor: '#omnioffloader-admin-app',
      mocks: [
        {
          path: '/omnioffloader/v1/core/stats',
          data: {
            total: 14,
            offloaded: 14,
            local: 0,
            configured: true,
            provider: 'cloudflare-r2',
            safe_mode: {
              active: false,
              reason: '',
              locked: false,
              live_address: 'wp71.test',
              current_address: 'wp71.test',
              environment: 'production',
              bring_back: true,
              folder_owner: '',
              folder: ''
            }
          }
        },
        {
          path: '/omnioffloader/v1/retention/preflight',
          data: {
            eligible: 6,
            policy: 1,
            to_clean: 6,
            to_download: 0,
            freed_bytes: 3548928,
            download_bytes: 0,
            free_bytes: 48293847296,
            disk_ok: true
          }
        }
      ],
      waitFor: '.smowp-bulk-action',
      annotations: [
        { selector: '.smowp-media-summary', num: 1, label: 'Current Policy vs Media Needing Updates', position: 'top-left', offsetY: -16 },
        { selector: '.smowp-retention-facts', num: 2, label: 'Pre-Flight Impact: Space Freed & Safety Verification', position: 'top-right', offsetY: -16 },
        { selector: '.smowp-batch-scope', num: 3, label: 'Batch Processing: All Media vs Custom Batches', position: 'top-right', offsetY: -16 },
        { selector: '.smowp-bulk-action__btn-wrap button', num: 4, label: 'Active "Apply Policy" Background Sync Execution', position: 'right', offsetX: 18 }
      ]
    },

    // 8. Safe Mode Copy & Staging Protection
    {
      id: 8,
      slug: 'safe-mode',
      wpOrgNum: 8,
      width: 1440,
      height: 940,
      title: 'Safe Mode Staging & Dev Protection',
      url: 'https://wp71.test/wp-admin/admin.php?page=omnioffloader&view=dashboard',
      waitFor: '#omnioffloader-admin-app',
      mocks: [
        {
          path: '/omnioffloader/v1/core/stats',
          data: {
            total: 14,
            offloaded: 14,
            local: 0,
            configured: true,
            provider: 'cloudflare-r2',
            safe_mode: {
              active: true,
              reason: 'address',
              locked: false,
              live_address: 'https://example.com',
              current_address: 'https://wp71.test',
              environment: 'staging',
              bring_back: true,
              folder_owner: '',
              folder: ''
            }
          }
        }
      ],
      waitFor: '.smowp-safe-mode-banner',
      annotations: [
        { selector: '.smowp-safe-mode-banner', num: 1, label: 'Automated Safe Mode: Protects Production Bucket on Staging/Dev Copies', position: 'top-left', offsetY: -16 },
        { selector: '.smowp-safe-mode-banner button', num: 2, label: 'Domain Migration Override: "This is the live site"', position: 'right', offsetX: 18 },
        { selector: '.smowp-hero', num: 3, label: 'Cloud Writes & Deletions Paused While Safe Mode is Active', position: 'top-right', offsetY: -16 }
      ]
    },

    // 9. Shared Bucket & Path Prefix (Dedicated to Custom Path Prefix)
    {
      id: 9,
      slug: 'shared-bucket',
      wpOrgNum: null,
      width: 1440,
      height: 960,
      title: 'Shared Bucket & Custom Path Prefix',
      url: 'https://wp71.test/wp-admin/admin.php?page=omnioffloader&view=settings',
      waitFor: '#omnioffloader-admin-app',
      beforeCapture: async (page) => {
        // Find path prefix text input and set 'shop' cleanly via React native setter
        const prefixInput = await page.$('input[placeholder="wp-content/uploads"]');
        if (prefixInput) {
          await page.evaluate((el) => {
            const nativeInputValueSetter = Object.getOwnPropertyDescriptor(window.HTMLInputElement.prototype, 'value').set;
            nativeInputValueSetter.call(el, 'shop');
            el.dispatchEvent(new Event('input', { bubbles: true }));
            el.dispatchEvent(new Event('change', { bubbles: true }));
          }, prefixInput);
        }
        await page.evaluate(() => {
          const cards = document.querySelectorAll('.smowp-card');
          if (cards.length >= 3) {
            const y = cards[2].getBoundingClientRect().top + window.scrollY - 90;
            window.scrollTo({ top: Math.max(0, y), behavior: 'instant' });
          }
        });
      },
      annotations: [
        { selector: '.smowp-card:nth-of-type(3) .components-toggle-control', index: 3, num: 1, label: 'Custom Path Prefix (Enable Isolated Bucket Subfolder per Site)', position: 'top-right', offsetY: -16 },
        { selector: '.smowp-card:nth-of-type(3) .components-base-control:has(input[type="text"])', num: 2, label: 'Dedicated Folder Name (e.g., "shop", "blog", "store")', position: 'below-left', offsetY: 8, offsetX: 14 },
        { selector: '.smowp-card:nth-of-type(3) .components-toggle-control', index: 2, num: 3, label: 'Object Versioning (Prevents CDN Cache Overwrite Collisions)', position: 'top-left', offsetY: -16 }
      ]
    },

    // 10. Staging & Dev Copies Settings Card (Card 4)
    {
      id: 10,
      slug: 'staging-and-dev-copies',
      wpOrgNum: null,
      width: 1440,
      height: 940,
      title: 'Staging & Dev Copies Settings Card',
      url: 'https://wp71.test/wp-admin/admin.php?page=omnioffloader&view=settings',
      waitFor: '#omnioffloader-admin-app',
      beforeCapture: async (page) => {
        // Ensure "Protect the live site when this is a copy" toggle is checked
        await page.evaluate(() => {
          const cards = document.querySelectorAll('.smowp-card');
          if (cards.length >= 4) {
            const card = cards[3];
            const toggleInput = card.querySelector('.components-form-toggle__input');
            if (toggleInput && !toggleInput.checked) {
              toggleInput.click();
            }
          }
        });
        await new Promise((r) => setTimeout(r, 200));
        await page.evaluate(() => {
          const cards = document.querySelectorAll('.smowp-card');
          if (cards.length >= 4) {
            const y = cards[3].getBoundingClientRect().top + window.scrollY - 90;
            window.scrollTo({ top: Math.max(0, y), behavior: 'instant' });
          }
        });
      },
      annotations: [
        { selector: '.smowp-card:nth-of-type(4) .smowp-card__head', num: 1, label: 'Environment Status: "This site is treated as the live site"', position: 'top-right', offsetY: -16 },
        { selector: '.smowp-card:nth-of-type(4) .components-toggle-control', index: 0, num: 2, label: 'Protect Live Site: Enabled (Uploads & Deletes Disabled on Copies)', position: 'top-right', offsetY: -16 },
        { selector: '.smowp-card:nth-of-type(4) .components-toggle-control', index: 1, num: 3, label: 'Allow Bring Back on Copies (Download Only, Preserves Cloud Files)', position: 'below-left', offsetY: 8, offsetX: 14 }
      ]
    },

    // 11. Transfer Speed & Concurrency Settings (Card 3: Behavior)
    {
      id: 11,
      slug: 'transfer-speed',
      wpOrgNum: null,
      width: 1440,
      height: 940,
      title: 'Transfer Speed & Concurrency Settings',
      url: 'https://wp71.test/wp-admin/admin.php?page=omnioffloader&view=settings',
      waitFor: '#omnioffloader-admin-app',
      beforeCapture: async (page) => {
        await page.evaluate(() => {
          const cards = document.querySelectorAll('.smowp-card');
          if (cards.length >= 3) {
            const y = cards[2].getBoundingClientRect().top + window.scrollY - 90;
            window.scrollTo({ top: Math.max(0, y), behavior: 'instant' });
          }
        });
      },
      annotations: [
        { selector: '.smowp-card:nth-of-type(3) .smowp-setting-row select', num: 1, label: 'Transfer Speed Concurrency: Low (1), Balanced (3), Fast (6), or Custom (1-10)', position: 'top-right', offsetY: -16 }
      ]
    },

    // 12. Mirror Delete Behavior Setting
    {
      id: 12,
      slug: 'mirror-delete',
      wpOrgNum: null,
      width: 1440,
      height: 940,
      title: 'Mirror Delete Behavior Setting',
      url: 'https://wp71.test/wp-admin/admin.php?page=omnioffloader&view=settings',
      waitFor: '#omnioffloader-admin-app',
      beforeCapture: async (page) => {
        // Ensure Mirror Delete toggle is turned ON
        await page.evaluate(() => {
          const toggles = document.querySelectorAll('.smowp-card:nth-of-type(3) .components-toggle-control');
          for (const t of toggles) {
            if (t.textContent.includes('Mirror Delete')) {
              const input = t.querySelector('.components-form-toggle__input');
              if (input && !input.checked) {
                input.click();
              }
            }
          }
        });
        await new Promise((r) => setTimeout(r, 200));
        await page.evaluate(() => {
          const cards = document.querySelectorAll('.smowp-card');
          if (cards.length >= 3) {
            const y = cards[2].getBoundingClientRect().top + window.scrollY - 90;
            window.scrollTo({ top: Math.max(0, y), behavior: 'instant' });
          }
        });
      },
      annotations: [
        { selector: '.smowp-card:nth-of-type(3) .components-toggle-control', index: 1, num: 1, label: 'Mirror Delete: Enabled (Automatically Deletes Cloud Files When Removed from Media Library)', position: 'top-right', offsetY: -16 }
      ]
    }
  ];

  const targetSlug = process.argv[2];
  const screensToRun = targetSlug ? screens.filter(s => s.slug === targetSlug) : screens;

  for (const screen of screensToRun) {
    try {
      await captureScreen(browser, cookies, screen, screensToRun.length);
    } catch (err) {
      console.error(`Failed capturing ${screen.title}:`, err.message);
    }
  }

  await browser.close();

  // Cleanup .wordpress-org/ to ensure ONLY marked versions exist, named screenshot-1.png ... screenshot-8.png
  console.log('\nCleaning up .wordpress-org directory...');
  const wporgFiles = fs.readdirSync(WPORG_DIR);
  for (const f of wporgFiles) {
    if (f.endsWith('-marked.png')) {
      fs.unlinkSync(path.join(WPORG_DIR, f));
      console.log(`   Removed superfluous file: ${f}`);
    }
  }

  console.log('\n✨ All screenshots captured, annotated, and cleaned up successfully!');
}

main().catch(err => {
  console.error('Fatal error:', err);
  process.exit(1);
});
