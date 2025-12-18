
# Venera-Dent Booking System — Copilot Instructions

## Architecture & Key Components

- **Stack:** PHP (vanilla, no framework), MySQL/PostgreSQL, runs on Apache (WAMP/LAMP/Docker).
- **public/**: All user-facing endpoints (entry: public/index.php). Each major feature (bookings, doctors, clinics, etc.) has its own PHP file.
- **config.php**: Central for DB connection, authentication, and SMS (Skytel) integration. Always use the `db()` singleton for queries.
- **db/**: Contains schema (booking_app.sql), settings (settings.json), and SQL migrations.
- **partials/**: Shared UI components (e.g., sidebar.php).
- **public/js/calendar.js**: Main calendar logic (day/week/month views, status colors, doctor/clinic selection).

## Data Model & Roles

- **Users**: Roles are `admin`, `reception`, `doctor`. Auth via phone + PIN. Role/clinic restrictions are enforced in code.
- **Doctors**: Linked to clinics, have per-day working hours (see working_hours table).
- **Bookings**: Appointments tied to doctor, clinic, date, and time.
- **Clinics**: Multi-location support (e.g., venera, luxor, khatan).

## API & Patterns

- **API Endpoint:** All AJAX/API handled by public/api.php. Action is determined by `$_GET['action']`.
  - Example: `/api.php?action=bookings&date=YYYY-MM-DD&clinic=venera`
  - Response: Always use `json_exit(['ok'=>true, 'data'=>$result])` or `['ok'=>false, 'msg'=>'error']`
- **Adding API Actions:** Follow the try/catch pattern for new actions (see api.php for examples).
- **Auth Guards:** Use `require_login()` and `require_role(['admin'])` for access control. Use `current_user()` for user context.
- **Clinic Restriction:** Doctors/receptionists are always restricted to their own clinic in backend logic.

## Booking & Working Hours Logic

- **Validation:** Always check doctor working hours before creating bookings.
  - Use: `SELECT start_time, end_time FROM working_hours WHERE doctor_id=? AND day_of_week=?`
- **Doctor Creation:** Adding a doctor auto-creates a user and default working hours (Mon-Fri 09:00-18:00).

## SMS Integration

- Use `sendSMS($phone, $message, $booking_id)` from config.php.
- Skytel WEB2SMS API; logs to sms_log if SMS is disabled.

## Settings & Configuration

- App preferences in db/settings.json (theme, reminders, etc.), loaded via `json_decode(file_get_contents(...))`.

## Frontend Patterns

- **Calendar:** Vanilla JS, globals: `DOCTORS`, `CURRENT_CLINIC`, `VIEW_MODE`.
- **Status Colors:** online (blue), arrived (amber), paid (green), pending (purple), cancelled (red).

## Local Development

- Place code in `C:\wamp64\www\booking\` (or `/opt/booking` for Docker).
- Import db/booking_app.sql to MySQL/PostgreSQL.
- Access via `http://localhost/booking/public/`
- Default admin: phone `99999999`, PIN `1234`.

## Examples

- **DB Query:**
  ```php
  $st = db()->prepare("SELECT * FROM bookings WHERE clinic=? AND date=?");
  $st->execute([$clinic, $date]);
  $rows = $st->fetchAll();
  ```
- **API Action:**
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
