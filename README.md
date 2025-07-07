# Twitch Widget

## Overview
This application manages Twitch widgets for streamers. It specifically supports the creation of "Charity Stream widgets," which include:

- **Donation Bar Widget:** Displays the progress of fundraising.
- **Alert Widget:** Shows alerts for donations and other stream interactions.

## Prerequisites
To run this application, you will need:
- PHP 8.3+
- MySQL
- Node.js (for frontend dependencies)
- Composer (for PHP dependencies)

## Installation

### Step 1: Clone the Repository
First, clone the repository to your local machine or server:
```bash
git clone [repository-url]
cd twitch-widget
```

### Step 2: Install Dependencies
Install PHP dependencies using Composer:
```bash
composer install
```

Install frontend dependencies using npm:
```bash
npm install
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

### Step 4: Run SQL Migrations
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

## Architecture & Code Organization

### Framework & Technologies
- **Backend:** PHP 8.3 with Slim Framework 4
- **Frontend:** Bootstrap 5.3, CountUp.js, Moment.js
- **Database:** MySQL
- **Template Engine:** Twig
- **Dependency Injection:** PHP-DI
- **Cloud Storage:** Azure Blob Storage
- **Email Service:** Mailchimp Transactional (Mandrill)

### Project Structure

```
twitch-widget/
├── public/                 # Web root directory
│   ├── css/               # Compiled CSS files
│   ├── js/                # Compiled JavaScript files
│   └── index.php          # Application entry point
├── src/                   # Application source code
│   ├── Controllers/       # Request handlers
│   ├── Models/           # Data models
│   ├── Repositories/     # Data access layer
│   ├── Services/         # Business logic services
│   ├── Middlewares/      # Request/response middleware
│   └── views/            # Twig templates
├── migrations/           # Database migration files
└── vendor/              # Composer dependencies
```

### Key Components

#### Controllers (`src/Controllers/`)
- **HomeController:** Handles public pages (home, password reset)
- **LoginController:** Manages authentication and OAuth flow
- **AdminController:** Admin panel functionality
- **ApiController:** API endpoints for external integrations
- **WidgetController:** Widget rendering and data endpoints

#### Models (`src/Models/`)
- **User:** User account management
- **Stream:** Stream configuration and data
- **Event:** Event management
- **WidgetAlert/WidgetDonation:** Widget-specific data models
- **AccessToken/AuthorizationCode:** OAuth token management

#### Repositories (`src/Repositories/`)
- **UserRepository:** User data operations
- **StreamRepository:** Stream data operations
- **EventRepository:** Event data operations
- **WidgetRepository:** Widget data operations
- **FileManager:** File upload/management via Azure Blob Storage

#### Services (`src/Services/`)
- **ApiWrapper:** External API integrations and OAuth handling

#### Middlewares (`src/Middlewares/`)
- **AuthMiddleware:** Session-based authentication
- **AuthAdminMiddleware:** Admin role verification
- **AuthApiMiddleware:** API authentication

### Widget System
The application provides two main widget types:

1. **Alert Widget** (`/widget-stream-alert/{id}`)
   - Displays real-time donation alerts
   - Updates via AJAX polling
   - Customizable styling and animations

2. **Donation Bar Widget** (`/widget-stream-donation/{id}`)
   - Shows fundraising progress
   - Real-time goal tracking
   - Animated progress bars

### Database Migrations
Database schema is managed through SQL migration files in the `migrations/` directory:
- `00-init-db.sql`: Initial database setup
- `01-add-goal-widget-text.sql`: Widget text configuration
- `02-add-user-table.sql`: User management
- `03-update-widget-alert-box.sql`: Alert widget improvements
- `04-new-bar.sql`: Donation bar widget
- `05-manage-user-reset-pwd.sql`: Password reset functionality
- `06-manage-event.sql`: Event management
- `07-improve-user-rights.sql`: User permissions

### Frontend Assets
JavaScript and CSS files are organized in `public/js/` and `public/css/`:
- **admin.js:** Admin panel functionality
- **alert.js:** Alert widget logic
- **event.js:** Event widget logic
- **stream.js:** Stream management
- **main.css:** Global styles

### Environment Configuration
Key environment variables include:
- Database connection (DBURL, DBPORT, DBNAME, DBUSER, DBPASSWORD)
- OAuth configuration (CLIENT_ID, CLIENT_SECRET, API_URL)
- Azure Blob Storage (BLOB_CONNECTION_STRING, BLOB_URL)
- Email service (MANDRILL_API)
- Application domain (WEBSITE_DOMAIN)
