````markdown
# Venera-Dent — v5 (Working Hours + Doctor Management)
- CSS тусад нь файл байхгүй — бүх style нь index.php дотор
- Календарь 09:00–18:00 grid, цагийн тоо
- Статусын өнгө ялгалт (онлайн/ирсэн/цуцлагдсан/…)
- Клиник сонгох (Venera-Dent / Golden Luxor / Goo Khatan)
- Хэрэглэгчийн утас автоматаар санана (localStorage)
- CRUD: нэмэх/засах/устгах
- API: /api.php?action=bookings&date=YYYY-MM-DD&clinic=venera

## Шинэ функцүүд (v5)
- **Working Hours System**: Эмч бүрийн өдөр хоног ажиллах цагийн расписание
- **Doctor Management**: Doctors хуудсанд эмч нэмэх/засах/хасах функц
- **Off-Hours Visualization**: Calendar дээр эмч ажиллахгүй цагийг өөр өнгөөр харуулах
- **API Endpoints**: 
  - `POST api.php?action=add_doctor` - Эмч нэмэх
  - `POST api.php?action=edit_doctor` - Эмч засах
  - `POST api.php?action=delete_doctor` - Эмч хасах

## Суулгах
1) Хавтсыг `C:\wamp64\www\booking\` байршуул
2) DB schema:
```
SOURCE db/schema.sql;
```
3) Нээ:
```
http://localhost/booking/public/
```

## Database Changes
- Эмчийн clinic хүснэгтээр эмнэлгийн id оруулсан
- working_hours хүснэгт нэмсэн (doctor_id, day_of_week, start_time, end_time)

````
1WSHQ4E2LZLSVQJNE2PWRHWH