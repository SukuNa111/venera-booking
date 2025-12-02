# Venera-Dent Booking System - AI Coding Instructions

## Architecture Overview

This is a PHP/MySQL dental clinic booking system with multi-clinic support. The app runs on WAMP (Windows Apache MySQL PHP) stack.

### Directory Structure
- `public/` - All user-facing pages (entry point: `index.php`)
- `config.php` - Database connection, auth, helpers, SMS (Twilio) integration
- `partials/` - Shared UI components (`sidebar.php`)
- `db/` - SQL schema (`booking_app.sql`) and settings (`settings.json`)
- `public/js/calendar.js` - Main calendar logic (day/week/month views)

### Key Data Entities
- **Users** (`users`): roles are `admin`, `reception`, `doctor` - authenticated via phone + PIN
- **Doctors** (`doctors`): linked to clinics, have `working_hours` per day_of_week (0=Sunday)
- **Bookings** (`bookings`): patient appointments tied to doctor/clinic/date/time
- **Clinics** (`clinics`): multiple locations (venera, luxor, khatan) with codes

## Critical Patterns

### Database Access
Always use the `db()` singleton from `config.php`:
```php
$st = db()->prepare("SELECT * FROM bookings WHERE clinic=? AND date=?");
$st->execute([$clinic, $date]);
$rows = $st->fetchAll();
```

### API Pattern
`public/api.php` handles all AJAX calls. Actions are determined by `$_GET['action']`:
- `doctors` - list doctors with working_hours
- `bookings`, `bookings_week`, `bookings_month` - fetch appointments
- `create`, `update`, `delete` - booking CRUD
- `update_my_hours` - doctor self-service hours

Response format: `json_exit(['ok'=>true, 'data'=>$result])` or `['ok'=>false, 'msg'=>'error']`

### Auth & Role Guards
```php
require_login();           // Redirects to login.php if not authenticated
require_role(['admin']);   // 403 if role mismatch
$user = current_user();    // Returns ['id', 'name', 'role', 'clinic_id']
```
Doctors/receptionists are **restricted to their clinic** - the code overrides `$clinic` param for these roles.

### Working Hours Validation
Before creating bookings, always validate against `working_hours`:
```php
$dow = (int)date('w', strtotime($date)); // PHP: 0=Sun, 6=Sat
$stWh = db()->prepare("SELECT start_time, end_time, is_available FROM working_hours WHERE doctor_id=? AND day_of_week=?");
```

### SMS Integration
`sendSMS($phone, $message, $booking_id)` in `config.php`:
- Uses Skytel WEB2SMS API (http://web2sms.skytel.mn)
- Phone numbers should be 8-digit Mongolian numbers
- Falls back to `sms_log` table for logging if `SMS_DISABLED=true` in `.env`

## Settings Configuration
`db/settings.json` stores app preferences (theme colors, default view, send_reminders). Load with:
```php
$settings = json_decode(file_get_contents(__DIR__.'/../db/settings.json'), true);
```

## Frontend/Calendar
`public/js/calendar.js` uses vanilla JS with these globals:
- `DOCTORS` array populated via `loadDoctors()`
- `CURRENT_CLINIC`, `VIEW_MODE` (day/week/month)
- Status colors: online (blue), arrived (amber), paid (green), pending (purple), cancelled (red)

## Development Notes

### Local Setup
1. Place in `C:\wamp64\www\booking\`
2. Import `db/booking_app.sql` into MySQL (port 3307, database `hospital_db`)
3. Access at `http://localhost/booking/public/`

### Default Credentials
- Admin: phone `99999999`, PIN `1234`
- All seeded users use PIN hash for `1234`

### Adding New API Actions
Add to `api.php` following existing pattern:
```php
if ($action === 'your_action' && $_SERVER['REQUEST_METHOD']==='POST') {
  try {
    // validate input, execute queries
    json_exit(['ok'=>true, 'data'=>$result]);
  } catch (Exception $e) {
    json_exit(['ok'=>false, 'msg'=>$e->getMessage()], 500);
  }
}
```

### Creating Doctor Records
When adding doctors via `doctors.php`, the system auto-creates:
1. User record with role='doctor'
2. Default `working_hours` entries (Mon-Fri 09:00-18:00)
