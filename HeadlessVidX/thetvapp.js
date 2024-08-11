const fs = require('fs');
const path = require('path');
const { firefox } = require('playwright');
const crypto = require('crypto');
const UserAgent = require('user-agents');

const COOKIES_FILE = path.resolve(__dirname, 'cookies.json');
const CACHE_FILE = path.resolve(__dirname, 'cache.json');
const CACHE_MAX_SIZE = 10 * 1024 * 1024; // 10 MB
const CACHE_EXPIRY_MS = 60 * 60 * 1000; // 1 hour

// Function to read cache
function readCache() {
    if (fs.existsSync(CACHE_FILE)) {
        return JSON.parse(fs.readFileSync(CACHE_FILE, 'utf8'));
    }
    return {};
}

// Function to write cache
function writeCache(cache) {
    fs.writeFileSync(CACHE_FILE, JSON.stringify(cache, null, 2));
}

// Function to check cache size
function checkCacheSize() {
    if (fs.existsSync(CACHE_FILE)) {
        const stats = fs.statSync(CACHE_FILE);
        return stats.size > CACHE_MAX_SIZE;
    }
    return false;
}

// Function to save cookies
async function saveCookies(context) {
    const cookies = await context.cookies();
    fs.writeFileSync(COOKIES_FILE, JSON.stringify(cookies, null, 2));
    console.error('Cookies saved.');
}

// Function to load cookies
async function loadCookies(context) {
    if (fs.existsSync(COOKIES_FILE)) {
        const cookies = JSON.parse(fs.readFileSync(COOKIES_FILE));
        await context.addCookies(cookies);
        console.error('Cookies loaded.');
    }
}

// Determine viewport size based on user agent
function getViewportSize(userAgentString) {
    if (/Mobile|Android/.test(userAgentString)) {
        return { width: 375, height: 667 }; // mobile devices
    } else if (/Tablet|iPad/.test(userAgentString)) {
        return { width: 768, height: 1024 }; // tablet devices
    } else {
        return { width: 1280, height: 720 }; // desktop computer
    }
}

// Function to check if a cache entry is stale
function isStale(cacheEntry) {
    const now = Date.now();
    return cacheEntry.status === 'running' && (now - cacheEntry.timestamp > 60000); // 60 seconds
}

(async () => {
    const targetUrlArg = process.argv[2];

    if (!targetUrlArg) {
        console.error('No URL provided.');
        process.exit(1);
    }

    let browser;
    let timeoutId;

    try {
        const cache = readCache();

        const now = Date.now();
        let cacheEntry = cache[targetUrlArg];

        // Check if the entry is stale
        if (cacheEntry && isStale(cacheEntry)) {
            console.error('Stale cache entry detected, resetting status:', targetUrlArg);
            cacheEntry = null; // Reset cache entry to allow the new request to proceed
        }

        // If the URL is cached and not expired, serve it
        if (cacheEntry && now - cacheEntry.timestamp < CACHE_EXPIRY_MS) {
            if (cacheEntry.status === 'finished') {
                console.error('URL retrieved from cache:', targetUrlArg);
                console.log(JSON.stringify({ status: 'ok', url: cacheEntry.url }));
                process.exit(0);
            } else if (cacheEntry.status === 'running') {
                // Wait for the other request to finish
                console.error('Another request is running for:', targetUrlArg);
                while (cacheEntry.status === 'running') {
                    await new Promise(resolve => setTimeout(resolve, 1000)); // Check every 1 second
                    cacheEntry = readCache()[targetUrlArg]; // Reload cache entry
                }
                if (cacheEntry.status === 'finished') {
                    console.error('URL retrieved from cache after waiting:', targetUrlArg);
                    console.log(JSON.stringify({ status: 'ok', url: cacheEntry.url }));
                    process.exit(0);
                }
            }
        }

        // Mark the URL as running in the cache
        cache[targetUrlArg] = { status: 'running', timestamp: now };
        writeCache(cache);

        console.error('Launching browser...');
        browser = await firefox.launch({
            headless: true,
            args: [
                '--no-sandbox',
                '--disable-setuid-sandbox',
                '--disable-infobars',
                '--ignore-certificate-errors',
                '--ignore-certificate-errors-spki-list'
            ]
        });

        // Generate a random user agent
        const userAgent = new UserAgent();
        const userAgentString = userAgent.toString();
        const viewportSize = getViewportSize(userAgentString);

        const context = await browser.newContext({
            viewport: viewportSize,
            userAgent: userAgentString
        });

        await loadCookies(context);
        const page = await context.newPage();

        // Intercept and print all requests to the console
        page.on('request', request => {
            console.error('Request:', request.url());
        });

        // Intercept responses to detect Cloudflare and capture cf_clearance cookie only on initial navigation
        page.on('response', async response => {
            if (response.url() === targetUrlArg) {
                const headers = response.headers();
                if (headers['cf-chl-bypass'] || headers['cf-cache-status']) {
                    console.error('Cloudflare detected on:', response.url());
                }

                const cookies = await context.cookies();
                const cfClearance = cookies.find(cookie => cookie.name === 'cf_clearance');
                if (cfClearance) {
                    console.error('CF Clearance Cookie:', cfClearance.value);
                    await saveCookies(context);
                }
            }
        });

        // Monitor requests for specific strings
        page.on('request', async request => {
            const requestUrl = request.url();
            if ((requestUrl.includes('.m3u8') || requestUrl.includes('expires')) && requestUrl.includes('thetvapp')) {
                console.error('Matching URL found:', requestUrl);
                clearTimeout(timeoutId);

                // Update cache entry with finished status and URL
                cache[targetUrlArg] = {
                    url: requestUrl,
                    status: 'finished',
                    timestamp: now
                };

                // Check and flush cache if necessary
                if (checkCacheSize()) {
                    writeCache({});
                    console.error('Cache size exceeded, flushed cache.');
                } else {
                    writeCache(cache);
                }

                const response = { status: 'ok', url: requestUrl };
                await browser.close();
                console.error('Browser closed.');
                console.log(JSON.stringify(response));
                process.exit(0);
            }
        });

        // Navigate to the provided URL
        console.error('Navigating to URL:', targetUrlArg);
        await page.goto(targetUrlArg, { waitUntil: 'domcontentloaded' });

        // Find the visible button with the class 'video-button'
        const visibleButton = await page.$('.video-button:not([style*="display: none"])');
        if (visibleButton) {
            await visibleButton.click();
            console.error('Clicked on the visible button with class "video-button".');
        } else {
            console.error('No visible button with class "video-button" found.');
            await browser.close(); // Ensure the browser is closed if no button is found
            console.error('Browser closed due to no visible button.');
            process.exit(1);
        }

        // Keep the browser open for the timeout period to inspect URLs
        timeoutId = setTimeout(async () => {
            const errorResponse = { status: 'error', message: 'No matching URL found.' };
            console.error('No matching URL found within the timeout period.');

            // Mark the URL as finished with an error status in the cache
            cache[targetUrlArg] = {
                status: 'error',
                timestamp: now
            };
            writeCache(cache);

            await browser.close();
            console.error('Browser closed after timeout.');
            console.log(JSON.stringify(errorResponse));
            process.exit(1);
        }, 20000);

    } catch (error) {
        console.error('An error occurred:', error);
        
        // Mark the URL as finished with an error status in the cache
        const cache = readCache();
        cache[targetUrlArg] = {
            status: 'error',
            timestamp: Date.now()
        };
        writeCache(cache);

        if (browser) {
            await browser.close();
            console.error('Browser closed due to error.');
        }
        process.exit(1);
    }
})();
