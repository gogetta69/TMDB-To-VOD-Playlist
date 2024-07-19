#!/bin/bash

# Change to the directory of the script
cd "$(dirname "$0")"

# Stop the server with PM2
pm2 stop HeadlessVidX

if [ $? -ne 0 ]; then
    echo "Failed to stop the Node.js server with PM2. Exiting."
    exit 1
fi

echo "Server stopped successfully."
