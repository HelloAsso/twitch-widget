# Twitch Widget

## Overview
This application manages Twitch widgets for streamers. It specifically supports the creation of "Charity Stream widgets," which include:

- **Donation Bar Widget:** Displays the progress of fundraising.
- **Alert Widget:** Shows alerts for donations and other stream interactions.

## Prerequisites
To run this application, you will need:
- PHP
- MySQL

## Installation

### Step 1: Clone the Repository
First, clone the repository to your local machine or server:
```bash
git clone [repository-url]
cd twitch-widget
```

### Step 2: Configure Your Environment
Copy the example environment file and then edit it with your specific configurations:
```bash
cp .env.example .env
nano .env
```

In the .env file, ensure you update the following settings:

Database connection details
API keys if applicable
Any other environment-specific variables

### Step 3: Run SQL Migrations
To set up your database, execute the SQL migrations provided:
```bash
php migrations/run.php
```

## Running the Application
Once you have configured your environment, start your PHP server to run the application:

```bash
php -S localhost:8000
```

Access the application through your web browser:

```bash
http://localhost:8000
```

To secure your local development server with HTTPS, you can utilize ngrok. This tool provides a straightforward way to expose your localhost to the web while automatically equipping it with a valid SSL certificate. To start using ngrok and enable HTTPS, simply run the following command in your terminal:

```bash
ngrok http 8000
```

This command will create a secure tunnel to your localhost server running on port 8000, allowing you to safely test your application's HTTPS functionality. Ensure that your application is running on port 8000 or adjust the port number in the command accordingly.
Also update WEBSITE_DOMAIN from you env file
