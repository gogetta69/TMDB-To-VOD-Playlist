#!/usr/bin/env node
/**
 * playwright-firefox.js
 * 
 * Standalone Playwright Firefox Manager
 * Handles installation, updates, and version management
 * 
 * Usage:
 *   As a module: const browserManager = require('./playwright-firefox');
 *   As a CLI: node playwright-firefox.js [--check|--update|--force-update|--status]
 */

const path = require('path');
const fs = require('fs');
const https = require('https');
const { exec } = require('child_process');
const { promisify } = require('util');
const execAsync = promisify(exec);

// ================================================================================
// CONFIGURATION
// ================================================================================

const CONFIG = {
    // Playwright CDN base URL
    CDN_BASE: 'https://playwright.azureedge.net/builds/firefox',
    
    // Version manifest URL (you can host your own or use a default)
    VERSION_CHECK_URL: 'https://registry.npmjs.org/playwright',
    
    // Local storage paths
    SCRIPT_DIR: path.dirname(require.main?.filename || __dirname),
    MS_PLAYWRIGHT_DIR: 'ms-playwright',
    VERSION_FILE: 'browser-version.json',
    
    // Timeouts
    DOWNLOAD_TIMEOUT: 300000, // 5 minutes
    LAUNCH_TIMEOUT: 5000,
    
    // Auto-update settings
    CHECK_UPDATE_INTERVAL: 86400000, // 24 hours
    AUTO_UPDATE: false, // Set to true to enable auto-updates
    
    // Logging
    VERBOSE: process.env.PLAYWRIGHT_VERBOSE === '1',
    SILENT: process.env.PLAYWRIGHT_SILENT === '1'
};

// ================================================================================
// BROWSER MANAGER CLASS
// ================================================================================

class PlaywrightFirefoxManager {
    constructor(options = {}) {
        this.config = { ...CONFIG, ...options };
        this.scriptDir = this.config.SCRIPT_DIR;
        this.msPlaywrightDir = path.join(this.scriptDir, this.config.MS_PLAYWRIGHT_DIR);
        this.versionFile = path.join(this.msPlaywrightDir, this.config.VERSION_FILE);
        
        this.isCompiledMode = false;
        this.firefoxPath = null;
        this.browserVersion = null;
        this.lastUpdateCheck = null;
    }

    // ================================================================================
    // LOGGING
    // ================================================================================

    log(message, type = 'info') {
        if (this.config.SILENT) return;
        
        const timestamp = new Date().toISOString().split('T')[1].split('.')[0];
        const prefix = {
            'error': '❌',
            'success': '✅',
            'warning': '⚠️',
            'info': 'ℹ️',
            'download': '⬇️',
            'update': '🔄',
            'debug': '🔍'
        }[type] || 'ℹ️';
        
        const color = {
            'error': '\x1b[31m',
            'success': '\x1b[32m',
            'warning': '\x1b[33m',
            'info': '\x1b[36m',
            'debug': '\x1b[90m'
        }[type] || '\x1b[0m';
        
        const reset = '\x1b[0m';
        
        console.log(`${color}[${timestamp}] ${prefix} ${message}${reset}`);
    }

    debug(message) {
        if (this.config.VERBOSE) {
            this.log(message, 'debug');
        }
    }

    // ================================================================================
    // INITIALIZATION
    // ================================================================================

    async initialize() {
        try {
            this.log('Playwright Firefox Manager v1.0.0');
            this.debug(`Script directory: ${this.scriptDir}`);
            
            // Detect if we're in compiled mode
            this.isCompiledMode = !fs.existsSync(path.join(this.scriptDir, 'node_modules'));
            this.debug(`Compiled mode: ${this.isCompiledMode}`);
            
            // Load version info
            await this.loadVersionInfo();
            
            // Check for updates if enabled
            if (this.config.AUTO_UPDATE && this.shouldCheckForUpdate()) {
                await this.checkForUpdates();
            }
            
            // Check existing installation
            const canUseExisting = await this.checkExistingFirefox();
            if (canUseExisting) {
                this.log('Firefox is available via system Playwright', 'success');
                return { success: true, path: null, version: 'system' };
            }
            
            // Check local installation
            const hasLocal = await this.checkLocalBrowser();
            if (hasLocal) {
                this.log(`Using local Firefox v${this.browserVersion} from: ${this.firefoxPath}`, 'success');
                return { success: true, path: this.firefoxPath, version: this.browserVersion };
            }
            
            // Download if needed
            this.log('Firefox not found - downloading...', 'warning');
            const downloaded = await this.downloadFirefox();
            
            if (downloaded) {
                this.log(`Firefox v${this.browserVersion} ready`, 'success');
                return { success: true, path: this.firefoxPath, version: this.browserVersion };
            }
            
            throw new Error('Failed to initialize Firefox');
            
        } catch (error) {
            this.log(`Initialization failed: ${error.message}`, 'error');
            return { success: false, error: error.message };
        }
    }

    // ================================================================================
    // VERSION MANAGEMENT
    // ================================================================================

    async loadVersionInfo() {
        try {
            if (fs.existsSync(this.versionFile)) {
                const data = JSON.parse(fs.readFileSync(this.versionFile, 'utf8'));
                this.lastUpdateCheck = data.lastUpdateCheck || null;
                this.debug(`Last update check: ${this.lastUpdateCheck ? new Date(this.lastUpdateCheck).toISOString() : 'never'}`);
            }
        } catch (error) {
            this.debug(`Could not load version info: ${error.message}`);
        }
    }

    async saveVersionInfo() {
        try {
            if (!fs.existsSync(this.msPlaywrightDir)) {
                fs.mkdirSync(this.msPlaywrightDir, { recursive: true });
            }
            
            const data = {
                version: this.browserVersion,
                lastUpdateCheck: this.lastUpdateCheck,
                installedAt: new Date().toISOString()
            };
            
            fs.writeFileSync(this.versionFile, JSON.stringify(data, null, 2));
        } catch (error) {
            this.debug(`Could not save version info: ${error.message}`);
        }
    }

    shouldCheckForUpdate() {
        if (!this.lastUpdateCheck) return true;
        
        const now = Date.now();
        const lastCheck = new Date(this.lastUpdateCheck).getTime();
        return (now - lastCheck) > this.config.CHECK_UPDATE_INTERVAL;
    }

    async checkForUpdates() {
        try {
            this.log('Checking for browser updates...', 'update');
            this.lastUpdateCheck = Date.now();
            
            const latestVersion = await this.getLatestVersion();
            const currentVersion = this.browserVersion || '0';
            
            if (this.compareVersions(latestVersion, currentVersion) > 0) {
                this.log(`Update available: v${currentVersion} → v${latestVersion}`, 'update');
                
                if (this.config.AUTO_UPDATE) {
                    this.log('Auto-updating...', 'update');
                    await this.downloadFirefox(latestVersion);
                }
                
                return { updateAvailable: true, current: currentVersion, latest: latestVersion };
            }
            
            this.log('Browser is up to date', 'success');
            await this.saveVersionInfo();
            return { updateAvailable: false, current: currentVersion };
            
        } catch (error) {
            this.debug(`Update check failed: ${error.message}`);
            return { updateAvailable: false, error: error.message };
        }
    }

    async getLatestVersion() {
        // For now, return a static version
        // In production, you'd fetch this from Playwright's registry
        return '1490';
        
        // Uncomment for dynamic version checking:
        /*
        return new Promise((resolve, reject) => {
            https.get(this.config.VERSION_CHECK_URL, (res) => {
                let data = '';
                res.on('data', chunk => data += chunk);
                res.on('end', () => {
                    try {
                        const pkg = JSON.parse(data);
                        const version = pkg['dist-tags']?.latest || '1490';
                        // Extract Firefox version from Playwright version
                        // This would need proper mapping
                        resolve('1490');
                    } catch (e) {
                        resolve('1490');
                    }
                });
            }).on('error', () => resolve('1490'));
        });
        */
    }

    compareVersions(v1, v2) {
        const parts1 = String(v1).split('.').map(Number);
        const parts2 = String(v2).split('.').map(Number);
        
        for (let i = 0; i < Math.max(parts1.length, parts2.length); i++) {
            const part1 = parts1[i] || 0;
            const part2 = parts2[i] || 0;
            
            if (part1 > part2) return 1;
            if (part1 < part2) return -1;
        }
        
        return 0;
    }

    // ================================================================================
    // BROWSER DETECTION
    // ================================================================================

    async checkExistingFirefox() {
        if (this.isCompiledMode) {
            this.debug('Skipping system Firefox check in compiled mode');
            return false;
        }
        
        try {
            const { firefox } = require('playwright');
            
            const browser = await Promise.race([
                firefox.launch({ headless: true }),
                new Promise((_, reject) => 
                    setTimeout(() => reject(new Error('Timeout')), this.config.LAUNCH_TIMEOUT)
                )
            ]);
            
            if (browser) {
                await browser.close();
                return true;
            }
        } catch (error) {
            this.debug(`System Firefox not available: ${error.message}`);
        }
        
        return false;
    }

    async checkLocalBrowser() {
        if (!fs.existsSync(this.msPlaywrightDir)) {
            return false;
        }
        
        const entries = fs.readdirSync(this.msPlaywrightDir);
        const firefoxDirs = entries.filter(dir => dir.startsWith('firefox-'));
        
        if (firefoxDirs.length === 0) {
            return false;
        }
        
        // Get the latest version
        const latestDir = firefoxDirs.sort((a, b) => {
            const versionA = parseInt(a.split('-')[1] || '0');
            const versionB = parseInt(b.split('-')[1] || '0');
            return versionB - versionA;
        })[0];
        
        const firefoxPath = path.join(this.msPlaywrightDir, latestDir);
        
        // Verify installation
        if (this.verifyInstallation(firefoxPath)) {
            this.firefoxPath = firefoxPath;
            this.browserVersion = latestDir.split('-')[1];
            return true;
        }
        
        return false;
    }

    verifyInstallation(firefoxPath) {
        const platform = process.platform;
        let executablePaths = [];
        
        if (platform === 'win32') {
            executablePaths = [
                path.join(firefoxPath, 'firefox.exe'),
                path.join(firefoxPath, 'firefox', 'firefox.exe')
            ];
        } else if (platform === 'darwin') {
            executablePaths = [
                path.join(firefoxPath, 'Firefox.app', 'Contents', 'MacOS', 'firefox'),
                path.join(firefoxPath, 'firefox')
            ];
        } else {
            executablePaths = [
                path.join(firefoxPath, 'firefox', 'firefox'),
                path.join(firefoxPath, 'firefox-bin', 'firefox'),
                path.join(firefoxPath, 'firefox')
            ];
        }
        
        return executablePaths.some(path => fs.existsSync(path));
    }

    // ================================================================================
    // DOWNLOAD & INSTALLATION
    // ================================================================================

    async downloadFirefox(version = null) {
        try {
            if (!fs.existsSync(this.msPlaywrightDir)) {
                fs.mkdirSync(this.msPlaywrightDir, { recursive: true });
            }
            
            const downloadInfo = await this.getDownloadInfo(version);
            if (!downloadInfo) {
                throw new Error('Could not determine download URL');
            }
            
            const { url, version: targetVersion } = downloadInfo;
            const targetDir = path.join(this.msPlaywrightDir, `firefox-${targetVersion}`);
            
            // Check if already exists
            if (fs.existsSync(targetDir) && this.verifyInstallation(targetDir)) {
                this.log(`Firefox v${targetVersion} already installed`);
                this.firefoxPath = targetDir;
                this.browserVersion = targetVersion;
                return true;
            }
            
            // Download
            this.log(`Downloading Firefox v${targetVersion}...`, 'download');
            const tempFile = path.join(this.msPlaywrightDir, `firefox-${targetVersion}-temp.${this.getArchiveExtension()}`);
            
            const downloaded = await this.downloadFile(url, tempFile);
            if (!downloaded) {
                throw new Error('Download failed');
            }
            
            // Extract
            this.log('Extracting Firefox...', 'info');
            await this.extractArchive(tempFile, targetDir);
            
            // Cleanup temp file
            try {
                fs.unlinkSync(tempFile);
            } catch (e) {
                // Ignore
            }
            
            // Verify installation
            if (!this.verifyInstallation(targetDir)) {
                throw new Error('Installation verification failed');
            }
            
            // Cleanup old versions
            await this.cleanupOldVersions(targetVersion);
            
            // Update state
            this.firefoxPath = targetDir;
            this.browserVersion = targetVersion;
            
            // Save version info
            await this.saveVersionInfo();
            
            return true;
            
        } catch (error) {
            this.log(`Download failed: ${error.message}`, 'error');
            return false;
        }
    }

    async getDownloadInfo(version = null) {
        const platform = process.platform;
        const arch = process.arch;
        
        const targetVersion = version || await this.getLatestVersion();
        
        let filename;
        if (platform === 'win32') {
            filename = arch === 'x64' ? 'firefox-win64.zip' : 'firefox-win32.zip';
        } else if (platform === 'darwin') {
            filename = arch === 'arm64' ? 'firefox-mac-arm64.zip' : 'firefox-mac.zip';
        } else {
            filename = 'firefox-linux.tar.gz';
        }
        
        return {
            url: `${this.config.CDN_BASE}/${targetVersion}/${filename}`,
            version: targetVersion
        };
    }

    getArchiveExtension() {
        return process.platform === 'linux' ? 'tar.gz' : 'zip';
    }

    async downloadFile(url, destination) {
        return new Promise((resolve) => {
            const file = fs.createWriteStream(destination);
            let downloadedBytes = 0;
            let totalBytes = 0;
            let lastProgress = 0;
            
            const request = https.get(url, (response) => {
                // Handle redirects
                if (response.statusCode === 301 || response.statusCode === 302) {
                    file.close();
                    this.downloadFile(response.headers.location, destination).then(resolve);
                    return;
                }
                
                if (response.statusCode !== 200) {
                    file.close();
                    try { fs.unlinkSync(destination); } catch (e) {}
                    this.log(`Download failed: HTTP ${response.statusCode}`, 'error');
                    resolve(false);
                    return;
                }
                
                totalBytes = parseInt(response.headers['content-length'], 10);
                const totalMB = (totalBytes / 1024 / 1024).toFixed(2);
                this.log(`Download size: ${totalMB} MB`, 'info');
                
                response.on('data', (chunk) => {
                    downloadedBytes += chunk.length;
                    file.write(chunk);
                    
                    const progress = Math.floor((downloadedBytes / totalBytes) * 100);
                    if (progress >= lastProgress + 10) {
                        this.log(`Progress: ${progress}%`, 'download');
                        lastProgress = progress;
                    }
                });
                
                response.on('end', () => {
                    file.close();
                    this.log('Download complete', 'success');
                    resolve(true);
                });
                
                response.on('error', (err) => {
                    file.close();
                    try { fs.unlinkSync(destination); } catch (e) {}
                    this.log(`Download error: ${err.message}`, 'error');
                    resolve(false);
                });
            });
            
            request.on('error', (err) => {
                file.close();
                try { fs.unlinkSync(destination); } catch (e) {}
                this.log(`Request error: ${err.message}`, 'error');
                resolve(false);
            });
            
            // Timeout
            request.setTimeout(this.config.DOWNLOAD_TIMEOUT, () => {
                request.destroy();
                file.close();
                try { fs.unlinkSync(destination); } catch (e) {}
                this.log('Download timeout', 'error');
                resolve(false);
            });
        });
    }

    async extractArchive(archivePath, destination) {
        const platform = process.platform;
        
        try {
            if (!fs.existsSync(destination)) {
                fs.mkdirSync(destination, { recursive: true });
            }
            
            let command;
            
            if (platform === 'win32') {
                // Windows: Use PowerShell
                command = `powershell -NoProfile -Command "Expand-Archive -Path '${archivePath}' -DestinationPath '${destination}' -Force"`;
            } else if (archivePath.endsWith('.tar.gz')) {
                // Linux: Use tar
                command = `tar -xzf "${archivePath}" -C "${destination}"`;
            } else {
                // macOS: Use unzip
                command = `unzip -q "${archivePath}" -d "${destination}"`;
            }
            
            await execAsync(command);
            this.log('Extraction complete', 'success');
            return true;
            
        } catch (error) {
            this.log(`Extraction failed: ${error.message}`, 'error');
            
            // Try fallback with adm-zip if available
            try {
                const AdmZip = require('adm-zip');
                const zip = new AdmZip(archivePath);
                zip.extractAllTo(destination, true);
                this.log('Extraction complete (fallback)', 'success');
                return true;
            } catch (e) {
                throw error;
            }
        }
    }

    async cleanupOldVersions(currentVersion) {
        try {
            const entries = fs.readdirSync(this.msPlaywrightDir);
            const firefoxDirs = entries.filter(dir => dir.startsWith('firefox-'));
            
            for (const dir of firefoxDirs) {
                const version = dir.split('-')[1];
                if (this.compareVersions(currentVersion, version) > 0) {
                    const oldPath = path.join(this.msPlaywrightDir, dir);
                    this.log(`Removing old Firefox v${version}`, 'info');
                    fs.rmSync(oldPath, { recursive: true, force: true });
                }
            }
        } catch (error) {
            this.debug(`Cleanup warning: ${error.message}`);
        }
    }

    // ================================================================================
    // EXPORTS & UTILITIES
    // ================================================================================

    getEnvironmentVariables() {
        if (this.firefoxPath && this.isCompiledMode) {
            return {
                PLAYWRIGHT_BROWSERS_PATH: this.msPlaywrightDir,
                PLAYWRIGHT_SKIP_BROWSER_DOWNLOAD: '1'
            };
        }
        return {};
    }

    getLaunchOptions() {
        const options = {};
        
        if (this.firefoxPath && this.isCompiledMode) {
            const platform = process.platform;
            let executablePath;
            
            if (platform === 'win32') {
                executablePath = path.join(this.firefoxPath, 'firefox', 'firefox.exe');
                if (!fs.existsSync(executablePath)) {
                    executablePath = path.join(this.firefoxPath, 'firefox.exe');
                }
            } else if (platform === 'darwin') {
                executablePath = path.join(this.firefoxPath, 'Firefox.app', 'Contents', 'MacOS', 'firefox');
            } else {
                executablePath = path.join(this.firefoxPath, 'firefox', 'firefox');
            }
            
            if (fs.existsSync(executablePath)) {
                options.executablePath = executablePath;
            }
        }
        
        return options;
    }

    async getStatus() {
        return {
            initialized: !!this.firefoxPath,
            isCompiledMode: this.isCompiledMode,
            browserPath: this.firefoxPath,
            browserVersion: this.browserVersion,
            platform: process.platform,
            lastUpdateCheck: this.lastUpdateCheck
        };
    }

    async forceUpdate() {
        this.log('Forcing browser update...', 'update');
        const latestVersion = await this.getLatestVersion();
        return await this.downloadFirefox(latestVersion);
    }
}

// ================================================================================
// CLI INTERFACE
// ================================================================================

async function cli() {
    const args = process.argv.slice(2);
    const command = args[0] || '--check';
    
    const manager = new PlaywrightFirefoxManager({
        SILENT: false,
        VERBOSE: args.includes('--verbose')
    });
    
    console.log('=====================================');
    console.log('   Playwright Firefox Manager');
    console.log('=====================================\n');
    
    switch (command) {
        case '--check':
        case '-c':
            await manager.initialize();
            break;
            
        case '--update':
        case '-u':
            const update = await manager.checkForUpdates();
            if (update.updateAvailable) {
                await manager.forceUpdate();
            }
            break;
            
        case '--force-update':
        case '-f':
            await manager.forceUpdate();
            break;
            
        case '--status':
        case '-s':
            const status = await manager.getStatus();
            console.log('Browser Status:');
            console.log(JSON.stringify(status, null, 2));
            break;
            
        case '--help':
        case '-h':
            console.log('Usage: node playwright-firefox.js [command]');
            console.log('');
            console.log('Commands:');
            console.log('  --check, -c         Check and install if needed (default)');
            console.log('  --update, -u        Check for updates');
            console.log('  --force-update, -f  Force update to latest version');
            console.log('  --status, -s        Show current status');
            console.log('  --verbose           Enable verbose logging');
            console.log('  --help, -h          Show this help');
            break;
            
        default:
            console.error(`Unknown command: ${command}`);
            console.log('Use --help for usage information');
            process.exit(1);
    }
    
    console.log('\n=====================================');
}

// ================================================================================
// MODULE EXPORTS
// ================================================================================

// If running directly, execute CLI
if (require.main === module) {
    cli().catch(error => {
        console.error('Fatal error:', error);
        process.exit(1);
    });
}

// Export for use as module
module.exports = PlaywrightFirefoxManager;
module.exports.default = PlaywrightFirefoxManager;
module.exports.createManager = (options) => new PlaywrightFirefoxManager(options);