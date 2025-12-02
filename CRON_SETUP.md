# SMS Cron Job Тохируулах Зааварчилгаа

## Windows Task Scheduler ашиглах

1. **Task Scheduler нээх**
   - `Win + R` товчлуур дарж `taskschd.msc` бичээд Enter дар

2. **Шинэ Task үүсгэх**
   - Зүүн талд "Task Scheduler Library" дээр дарна
   - Баруун талд "Create Basic Task..." товчийг дарна

3. **Тохиргоо**
   - **Name:** `SMS Reminder Sender`
   - **Trigger:** Daily, 10:00 AM (эсвэл өөрийн хүссэн цаг)
   - **Action:** Start a program
   - **Program:** `C:\wamp64\bin\php\php8.2.0\php.exe` (өөрийн PHP хувилбарын зам)
   - **Arguments:** `C:\wamp64\www\booking\cron_sms.php`
   - **Start in:** `C:\wamp64\www\booking`

4. **Давтамж тохируулах**
   - Task үүсгэсний дараа Properties нээнэ
   - Triggers tab -> Edit
   - "Repeat task every" -> 1 hour эсвэл 30 minutes гэж тохируулж болно

## Командаар турших

```cmd
C:\wamp64\bin\php\php8.2.0\php.exe C:\wamp64\www\booking\cron_sms.php
```

## Гар аргаар ажиллуулах

Хөгжүүлэлтийн үед browser-аас шууд дуудаж болно:
```
http://localhost/booking/cron_sms.php
```

## SMS Хүснэгт

- `sms_schedule` - Төлөвлөгдсөн SMS-үүд
  - `type`: `reminder` (сануулга), `aftercare` (эмчилгээний дараах)
  - `status`: `pending`, `sent`, `failed`
  - `scheduled_at`: Илгээх огноо, цаг

## Эмчилгээний тохиргоо

`treatments.php` хуудсаас эмчилгээний төрөл нэмнэ:
- **Aftercare Days**: Эмчилгээнээс хэдэн хоногийн дараа aftercare SMS илгээх
- **Aftercare Message**: Aftercare SMS текст

Жишээ:
- Botox: 14 хоногийн дараа "Botox emchilgee amjilttai bolson baina uu? Daraa ughaa tsag zaaval 70001234 ruu zalgarai"
