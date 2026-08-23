const { execSync } = require('child_process');
const fs = require('fs');
const path = require('path');

const isOrg = process.argv.includes('--org');
const pluginSlug = 'omnioffloader';
const rootDir = path.resolve(__dirname, '..');
const distDir = path.join(rootDir, 'dist');
const targetDir = path.join(distDir, pluginSlug);
const zipFile = path.join(rootDir, `${pluginSlug}.zip`);

console.log(`\n🚀 Starting ${isOrg ? 'WordPress.org submission' : 'Production'} packaging...`);

// 1. Build frontend & i18n
console.log('📦 Step 1: Building production assets and languages...');
execSync('npm run build:release', { cwd: rootDir, stdio: 'inherit' });

// 2. Install production-only composer dependencies (no dev tools in autoloader)
console.log('🐘 Step 2: Preparing production Composer autoloader (--no-dev)...');
execSync('composer install --no-dev --optimize-autoloader', { cwd: rootDir, stdio: 'inherit' });

// 3. Prepare clean dist directory
console.log('📂 Step 3: Copying clean plugin files to dist directory...');
if (fs.existsSync(distDir)) {
	fs.rmSync(distDir, { recursive: true, force: true });
}
fs.mkdirSync(targetDir, { recursive: true });

const copyList = [
	'omnioffloader.php',
	'readme.txt',
	'uninstall.php',
	'includes',
	'languages',
	'build',
	'vendor',
];

if (isOrg) {
	copyList.push('src', 'package.json', 'composer.json', 'tsconfig.json');
}

for (const item of copyList) {
	const srcPath = path.join(rootDir, item);
	const destPath = path.join(targetDir, item);
	if (fs.existsSync(srcPath)) {
		fs.cpSync(srcPath, destPath, { recursive: true });
	}
}

// 4. Create ZIP archive with top-level folder 'omnioffloader/'
console.log('🗜️  Step 4: Creating ZIP archive...');
if (fs.existsSync(zipFile)) {
	fs.unlinkSync(zipFile);
}
execSync(`find "${distDir}" -name ".DS_Store" -delete || true`);
execSync(`cd "${distDir}" && zip -r "${zipFile}" "${pluginSlug}"`, { stdio: 'inherit' });

// 5. Cleanup dist directory
fs.rmSync(distDir, { recursive: true, force: true });

// 6. Restore composer dev dependencies for local environment
console.log('🔄 Step 5: Restoring local development Composer dependencies...');
execSync('composer install --optimize-autoloader', { cwd: rootDir, stdio: 'inherit' });

const stats = fs.statSync(zipFile);
const sizeMb = (stats.size / (1024 * 1024)).toFixed(2);
console.log(`\n✅ Success! Created ${pluginSlug}.zip (${sizeMb} MB) with top-level '${pluginSlug}/' directory.\n`);
