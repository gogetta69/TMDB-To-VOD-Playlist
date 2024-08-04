const fs = require('fs');
const path = require('path');
const { firefox } = require('playwright');
const crypto = require('crypto');
const UserAgent = require('user-agents');

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

function getRandomOffset() {
    return Math.floor(Math.random() * 21) - 10; // Random number between -10 and 10
}

function getViewportSize(userAgentString) {
    if (/Mobile|Android/.test(userAgentString)) {
        return { width: 375, height: 667 }; // mobile devices
    } else if (/Tablet|iPad/.test(userAgentString)) {
        return { width: 768, height: 1024 }; // tablet devices
    } else {
        return { width: 1280, height: 720 }; // desktop computer
    }
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
    let timeoutId;

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
                const response = { status: 'ok', url: requestUrl };
                setCacheResponse(targetUrlArg, response);
                await browser.close();
                console.error('Browser closed.');
                console.log(JSON.stringify(response));
                process.exit(0);
            }
        });

        // Navigate to the provided URL
        console.error('Navigating to URL:', targetUrlArg);
        await page.goto(targetUrlArg, { waitUntil: 'domcontentloaded' });

        // Adjust click coordinates based on viewport size
        let baseX = 490;
        let baseY = 340;
        if (viewportSize.width < 768) { // Mobile
            baseX = 240; 
            baseY = 300; 
        } else if (viewportSize.width < 1024) { // Tablet
            baseX = 370;
            baseY = 320;
        }
        const offsetX = baseX + getRandomOffset();
        const offsetY = baseY + getRandomOffset();
        await page.mouse.click(offsetX, offsetY);
        console.error(`Clicked at position (${offsetX}, ${offsetY}) for viewport size (${viewportSize.width}, ${viewportSize.height}).`);

        // Keep the browser open for the timeout period to inspect URLs
        timeoutId = setTimeout(async () => {
            const errorResponse = { status: 'error', message: 'No matching URL found.' };
            setCacheResponse(targetUrlArg, errorResponse);
            console.error('No matching URL found within the timeout period.');
            await browser.close();
            console.error('Browser closed after timeout.');
            console.log(JSON.stringify(errorResponse));
            process.exit(1);
        }, 20000); 

    } catch (error) {
        console.error('An error occurred:', error);
        if (browser) {
            await browser.close();
            console.error('Browser closed due to error.');
        }
        process.exit(1);
    }
})();
