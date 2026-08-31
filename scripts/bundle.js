const { execSync } = require('child_process');
const fs = require('fs');
const path = require('path');

const isOrg = process.argv.includes('--org');
const pluginSlug = 'omnioffloader';
const rootDir = path.resolve(__dirname, '..');
const distDir = path.join(rootDir, 'dist');
const targetDir = path.join(distDir, pluginSlug);
const zipFile = path.join(rootDir, `${pluginSlug}.zip`);

console.log(`\n🚀 Starting ${isOrg ? 'WordPress.org submission' : 'Production'} packaging for ${pluginSlug}...`);

// 1. Build frontend & i18n
console.log('📦 Step 1: Building production assets and languages...');
execSync('npm run build:release', { cwd: rootDir, stdio: 'inherit' });

// 2. Install production-only composer dependencies (no dev tools in autoloader)
console.log('🐘 Step 2: Preparing production Composer autoloader (--no-dev)...');
const composerEnv = { ...process.env, COMPOSER_HOME: process.env.COMPOSER_HOME || '/tmp' };
try {
	execSync('composer install --no-dev --optimize-autoloader', { cwd: rootDir, env: composerEnv, stdio: 'inherit' });
} catch (e) {
	console.warn('⚠️ Warning: Composer install returned non-zero, continuing with existing vendor if present.');
}

// 3. Prepare clean dist directory
console.log('📂 Step 3: Copying plugin files to dist directory...');
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

// 4. Clean non-permitted and dev files from target directory
console.log('🧹 Step 4: Purging non-permitted files, tests, and dev artifacts from package...');

function cleanDirectory(dir) {
	if (!fs.existsSync(dir)) return;
	const entries = fs.readdirSync(dir, { withFileTypes: true });

	for (const entry of entries) {
		const fullPath = path.join(dir, entry.name);

		if (entry.isDirectory()) {
			const dirName = entry.name.toLowerCase();
			if (
				dirName === '.git' ||
				dirName === '.github' ||
				dirName === '.gitlab' ||
				dirName === 'tests' ||
				dirName === 'test' ||
				dirName === 'docs' ||
				dirName === 'doc' ||
				dirName === 'examples' ||
				dirName === 'demo'
			) {
				fs.rmSync(fullPath, { recursive: true, force: true });
			} else {
				cleanDirectory(fullPath);
			}
		} else {
			const ext = path.extname(entry.name).toLowerCase();
			const fileName = entry.name.toLowerCase();

			// Non-permitted extensions and dev files
			const forbiddenExts = [
				'.py',
				'.sh',
				'.bat',
				'.cmd',
				'.so',
				'.dll',
				'.dylib',
				'.exe',
				'.bin',
				'.c',
				'.h',
				'.cpp',
				'.neon',
				'.dist',
				'.fixed',
				'.diff',
				'.inc',
				'.sample',
				'.editorconfig',
				'.yml',
				'.yaml',
			];

			const forbiddenFiles = [
				'.ds_store',
				'thumbs.db',
				'format-check.py',
				'.gitignore',
				'.gitattributes',
				'.editorconfig',
				'phpunit.xml',
				'phpunit.xml.dist',
				'phpstan.neon',
				'phpstan.neon.dist',
				'phpcs.xml',
				'phpcs.xml.dist',
				'makefile',
				'dockerfile',
			];

			if (forbiddenExts.includes(ext) || forbiddenFiles.includes(fileName)) {
				fs.rmSync(fullPath, { force: true });
			}
		}
	}
}

function removeEmptyDirs(dir) {
	if (!fs.existsSync(dir)) return;
	const entries = fs.readdirSync(dir, { withFileTypes: true });

	for (const entry of entries) {
		const fullPath = path.join(dir, entry.name);
		if (entry.isDirectory()) {
			removeEmptyDirs(fullPath);
		}
	}

	// Re-check after removing child directories
	const remaining = fs.readdirSync(dir);
	if (remaining.length === 0 && dir !== targetDir) {
		fs.rmdirSync(dir);
	}
}

// Remove vendor/bin if present
const vendorBin = path.join(targetDir, 'vendor', 'bin');
if (fs.existsSync(vendorBin)) {
	fs.rmSync(vendorBin, { recursive: true, force: true });
}

cleanDirectory(targetDir);
removeEmptyDirs(targetDir);

// 5. Create ZIP archive with top-level folder 'omnioffloader/'
console.log('🗜️  Step 5: Creating ZIP archive...');
if (fs.existsSync(zipFile)) {
	fs.unlinkSync(zipFile);
}
execSync(`find "${distDir}" -name ".DS_Store" -delete || true`);
execSync(`cd "${distDir}" && zip -r "${zipFile}" "${pluginSlug}"`, { stdio: 'inherit' });

// 6. Cleanup dist directory
fs.rmSync(distDir, { recursive: true, force: true });

// 7. Verification and stats
const stats = fs.statSync(zipFile);
const sizeMb = (stats.size / (1024 * 1024)).toFixed(2);
console.log(`\n✅ Success! Created ${pluginSlug}.zip (${sizeMb} MB) with top-level '${pluginSlug}/' directory.\n`);

