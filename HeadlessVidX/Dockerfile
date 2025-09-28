# ------------------------------------------------------------
# Base image: Node.js 20.x on Debian Bullseye
# ------------------------------------------------------------
FROM node:20-bullseye

# ------------------------------------------------------------
# Basic tooling (needed for playwright-install-deps)
# ------------------------------------------------------------
RUN apt-get update && apt-get install -y \
    curl \
    unzip \
    xvfb \
    speech-dispatcher \
    speech-dispatcher-espeak \
 && rm -rf /var/lib/apt/lists/*
 
# ------------------------------------------------------------
# App lives in /app
# ------------------------------------------------------------
WORKDIR /app
RUN mkdir -p /app/files      # volume mount target

# 1) Copy manifest first, install prod dependencies
COPY package*.json ./
RUN npm install --omit=dev

# ------------------------------------------------------------
# 2) Install Playwright + browsers
#    The line below pulls Playwright plus Chromium, Firefox, Chrome.
#    Uncomment ANY of the extra RUN lines to add more bundles.
# ------------------------------------------------------------
RUN npm install playwright@latest \
 && npx playwright install --with-deps chromium firefox chrome

# Optional extra browser bundles (uncomment as needed):
# RUN npx playwright install --with-deps chrome-beta
# RUN npx playwright install --with-deps chrome-dev
# RUN npx playwright install --with-deps chrome-canary
# RUN npx playwright install --with-deps msedge
# RUN npx playwright install --with-deps msedge-beta
# RUN npx playwright install --with-deps msedge-dev
# RUN npx playwright install --with-deps msedge-canary

# ------------------------------------------------------------
# 3) Copy the rest of your source code
# ------------------------------------------------------------
COPY . .

# (Optional) build step for frontend / TS
# RUN npm run build

# ------------------------------------------------------------
# Expose port & start server
# ------------------------------------------------------------
EXPOSE 3202
CMD ["node", "index.js"]
