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

### Step 2: Run SQL Migrations
To set up your database, execute the SQL migrations provided:
```bash
php migration/run.php
```

### Step 3: Configure Your Environment
Copy the example environment file and then edit it with your specific configurations:
```bash
cp .env.example .env
nano .env
```

In the .env file, ensure you update the following settings:

Database connection details
API keys if applicable
Any other environment-specific variables

## Running the Application
Once you have configured your environment, start your PHP server to run the application:

```bash
php -S localhost:8000
```

Access the application through your web browser:

```bash
http://localhost:8000
```