#!/bin/bash

# Change to the directory of the script
cd "$(dirname "$0")"

# Start the server with PM2
pm2 start index.js --name HeadlessVidX

if [ $? -ne 0 ]; then
    echo "Failed to start the Node.js server with PM2. Exiting."
    exit 1
fi

# Wait a few seconds to allow the server to start and log the IP and port
sleep 5

# Display the logs to show IP and Port
pm2 logs HeadlessVidX --lines 10

echo "Server started successfully."
