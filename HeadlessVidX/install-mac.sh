#!/bin/bash

# Define the URLs and script paths
NVM_SETUP_URL="https://raw.githubusercontent.com/nvm-sh/nvm/v0.39.3/install.sh"
NVM_SETUP_SCRIPT="install_nvm.sh"

# Function to check if a command is available
function command_exists() {
    command -v "$1" &> /dev/null
}

# Check if Homebrew is installed
if ! command_exists brew; then
    echo "Homebrew is not installed. Installing Homebrew..."
    /bin/bash -c "$(curl -fsSL https://raw.githubusercontent.com/Homebrew/install/HEAD/install.sh)"
    echo "Homebrew installed successfully."
fi

# Check if curl is installed
if ! command_exists curl; then
    echo "curl is not installed. Installing curl..."
    brew install curl
    echo "curl installed successfully."
fi

# Check if nvm is installed
if ! command_exists nvm; then
    echo "Downloading nvm installer..."
    curl -o- $NVM_SETUP_URL > $NVM_SETUP_SCRIPT

    if [ -f $NVM_SETUP_SCRIPT ]; then
        echo "Installing nvm..."
        bash $NVM_SETUP_SCRIPT
        echo "nvm installed successfully."
    else
        echo "Failed to download nvm installer. Exiting."
        exit 1
    fi
fi

# Load nvm for the current shell session
export NVM_DIR="$HOME/.nvm"
echo "Loading nvm..."
[ -s "$NVM_DIR/nvm.sh" ] && \. "$NVM_DIR/nvm.sh" # This loads nvm
[ -s "$NVM_DIR/bash_completion" ] && \. "$NVM_DIR/bash_completion" # This loads nvm bash_completion

# Verify nvm installation
if ! command_exists nvm; then
    echo "nvm installation not found after loading. Exiting."
    exit 1
fi

# Install the required Node.js version
echo "Installing Node.js v20.13.1..."
nvm install 20.13.1
echo "Node.js v20.13.1 installed successfully."

# Use the required Node.js version
echo "Switching to Node.js v20.13.1..."
nvm use 20.13.1
echo "Using Node.js v20.13.1."

# Set the default Node.js version
nvm alias default 20.13.1
echo "Default Node.js version set to v20.13.1."

# Add nvm to .bashrc if not already present
if ! grep -q 'export NVM_DIR="$HOME/.nvm"' ~/.bashrc; then
    echo 'export NVM_DIR="$HOME/.nvm"' >> ~/.bashrc
    echo '[ -s "$NVM_DIR/nvm.sh" ] && \. "$NVM_DIR/nvm.sh"' >> ~/.bashrc
    echo '[ -s "$NVM_DIR/bash_completion" ] && \. "$NVM_DIR/bash_completion"' >> ~/.bashrc
    echo "nvm setup added to .bashrc."
fi

# Create .nvmrc file
echo "v20.13.1" > .nvmrc
echo ".nvmrc file created successfully."

# Install project dependencies
echo "Installing project dependencies..."
npm install
echo "Project dependencies installed successfully."

# Source .bashrc to update current session
echo "Sourcing .bashrc to update current session..."
source ~/.bashrc

# Add the Node.js version's bin directory to the PATH for the current session
export PATH="$NVM_DIR/versions/node/$(nvm current)/bin:$PATH"
echo "Node.js bin directory added to PATH: $PATH"

# Verify node installation
if ! command_exists node; then
    echo "Node.js installation not found after updating PATH. Exiting."
    exit 1
fi

# Install PM2 globally
echo "Installing PM2 globally..."
npm install -g pm2
echo "PM2 installed successfully."

# Start your Node.js server with PM2 and a custom name
echo "Starting your Node.js server with PM2..."
pm2 start index.js --name HeadlessVidX
echo "Node.js server started with PM2."

# Install Playwright dependencies
echo "Installing Playwright dependencies..."
npx playwright install-deps
echo "Playwright dependencies installed successfully."

# Install Playwright
echo "Installing Playwright..."
npm install -g playwright
echo "Playwright installed successfully."

# Set up PM2 to restart on reboot
echo "Setting up PM2 to restart on reboot..."
pm2 startup
eval $(pm2 startup | grep sudo)

# Save the PM2 process list
echo "Saving the PM2 process list..."
pm2 save
echo "PM2 process list saved."

echo "Installation and PM2 setup completed successfully."

