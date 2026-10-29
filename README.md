# MedBot

MedBot is a PHP-based medication management dashboard for healthcare or clinic operations. It helps track patients, prescriptions, dose schedules, drug interaction alerts, and robot activity from a single web interface.

## Features
- Dashboard overview with key operational metrics
- Patient management
- Prescription creation and tracking
- Dose scheduling and administration status
- Interaction alerts for medication conflicts
- Robot status and activity log
- Pathfinding/navigation view for robot movement

## Tech Stack
- PHP
- MySQL
- Apache HTTP server
- JavaScript and CSS

## Project Structure
- `index.php` — dashboard landing page
- `layout.php` — shared layout/navigation shell
- `patients.php` — patient operations
- `prescription.php` — prescription management
- `schedule.php` — schedule view
- `robot.php` — robot interface
- `pathfinding.php` — robot navigation map
- `api/` — backend endpoints for dashboard actions
- `db/schema.sql` — database schema
- `assets/` — UI assets and scripts

## Local Development
1. Create a MySQL database named `medbot`.
2. Import the schema:

```bash
mysql -u root -p medbot < db/schema.sql
```

3. Start the PHP built-in server from the repository root:

```bash
php -S 0.0.0.0:8000
```

4. Open the app in your browser:

```text
http://localhost:8000/
```

## Environment Variables
The app reads database settings from environment variables when available. The expected variables are:

- `DB_HOST`
- `DB_PORT`
- `DB_NAME`
- `DB_USER`
- `DB_PASS`

Example:

```bash
export DB_HOST=127.0.0.1
export DB_PORT=3306
export DB_NAME=medbot
export DB_USER=root
export DB_PASS=yourpassword
```

## Render Deployment
This app can be deployed to Render as a PHP web service with a MySQL database.

### Recommended Setup
- Web Service: PHP app
- Database: Render MySQL or external MySQL instance
- Build/Start: use a Dockerized PHP + Apache deployment or a PHP runtime with the app served correctly

### Render Environment Variables
Set these in the Render dashboard or via `render.yaml`:

```text
DB_HOST
DB_PORT
DB_NAME
DB_USER
DB_PASS
APP_ENV=production
```

### Database Initialization
After deployment, import the schema into the MySQL database:

```bash
mysql --host="$DB_HOST" --port="$DB_PORT" --user="$DB_USER" --password="$DB_PASS" "$DB_NAME" < db/schema.sql
```

## Notes
- This project uses legacy URL patterns and is designed to run under app paths such as `/medbot/`.
- The application expects a MySQL-backed backend and will fail without the database schema loaded.
- The app is best suited for a simple PHP hosting environment or a Render web service paired with a managed MySQL database.

## License
This project is provided as-is for educational or internal operational use.
