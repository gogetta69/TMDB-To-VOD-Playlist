const fs = require('fs');
const path = require('path');
const { firefox } = require('playwright');
const crypto = require('crypto');

// Define the LRU Cache class
class LRUCache {
    constructor(limit) {
        this.limit = limit;
        this.cache = new Map();
    }

    get(key) {
        if (!this.cache.has(key)) return null;
        const value = this.cache.get(key);
        this.cache.delete(key);
        this.cache.set(key, value);
        return value;
    }

    set(key, value) {
        if (this.cache.size >= this.limit) {
            const firstKey = this.cache.keys().next().value;
            this.cache.delete(firstKey);
        }
        this.cache.set(key, value);
    }
}

const COOKIES_FILE = path.resolve(__dirname, 'cookies.json');
const CACHE_DURATION_MS = 120000; // 120 seconds
const CACHE_LIMIT = 100; // Limit the cache to 100 entries

// Initialize the LRU Cache
const cache = new LRUCache(CACHE_LIMIT);

async function saveCookies(context) {
    const cookies = await context.cookies();
    fs.writeFileSync(COOKIES_FILE, JSON.stringify(cookies, null, 2));
    console.error('Cookies saved.');
}

async function loadCookies(context) {
    if (fs.existsSync(COOKIES_FILE)) {
        const cookies = JSON.parse(fs.readFileSync(COOKIES_FILE));
        await context.addCookies(cookies);
        console.error('Cookies loaded.');
    }
}

function getCacheKey(url) {
    return crypto.createHash('sha256').update(url).digest('hex');
}

function getCachedResponse(url) {
    const cacheKey = getCacheKey(url);
    const cachedEntry = cache.get(cacheKey);
    if (cachedEntry && (Date.now() - cachedEntry.timestamp) < CACHE_DURATION_MS) {
        return cachedEntry.response;
    }
    return null;
}

function setCacheResponse(url, response) {
    const cacheKey = getCacheKey(url);
    cache.set(cacheKey, { response, timestamp: Date.now() });
    console.error('Response cached for URL:', url);
}

(async () => {
    const targetUrlArg = process.argv[2];

    if (!targetUrlArg) {
        console.error('No URL provided.');
        process.exit(1);
    }

    const cachedResponse = getCachedResponse(targetUrlArg);
    if (cachedResponse) {
        console.log(JSON.stringify(cachedResponse));
        process.exit(0);
    }

    let browser;
    try {
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

        const context = await browser.newContext({
            userAgent: 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:126.0) Gecko/20100101 Firefox/126.0'
        });

        await loadCookies(context);
        const page = await context.newPage();

        // Intercept and print all requests to the console
        page.on('request', request => {
            console.error('Request:', request.url());
        });

        // Intercept responses to detect Cloudflare and capture cf_clearance cookie
        page.on('response', async response => {
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
        });

        // Monitor requests for specific strings
        let matchingUrl = null;
        page.on('request', request => {
            const requestUrl = request.url();
            if ((requestUrl.includes('.m3u8') || requestUrl.includes('expires')) && requestUrl.includes('thetvapp')) {
                console.error('Matching URL found:', requestUrl);
                matchingUrl = requestUrl;
                saveCookies(context).then(async () => {
                    const response = { status: 'ok', url: matchingUrl };
                    setCacheResponse(targetUrlArg, response);
                    await browser.close();
                    console.error('Browser closed.');
                    console.log(JSON.stringify(response));
                    process.exit(0);  // Ensure the script exits
                }).catch(error => {
                    console.error('Error saving cookies:', error);
                });
            }
        });

        // Navigate to the provided URL
        console.error('Navigating to URL:', targetUrlArg);
        await page.goto(targetUrlArg, { waitUntil: 'domcontentloaded' });

        // Click the button with ID #loadVideoBtnTwo
        await page.click('#loadVideoBtnTwo');
        console.error('Clicked button #loadVideoBtnTwo.');

        // Keep the browser open for the timeout period to inspect URLs
        setTimeout(async () => {
            if (!matchingUrl) {
                const errorResponse = { status: 'error', message: 'No matching URL found.' };
                setCacheResponse(targetUrlArg, errorResponse);
                console.error('No matching URL found within the timeout period.');
                await browser.close();
                console.error('Browser closed after timeout.');
                console.log(JSON.stringify(errorResponse));
                process.exit(1);  // Ensure the script exits
            }
        }, 30000); // 30 seconds

    } catch (error) {
        console.error('An error occurred:', error);
        if (browser) {
            await browser.close();
            console.error('Browser closed due to error.');
        }
    }
})();
