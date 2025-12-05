# ===========================================
# ОЛОН ЭМНЭЛЭГТ АШИГЛАХ ЗААВАР
# Multi-Clinic Deployment Guide
# ===========================================

## 🏥 Архитектур

```
┌─────────────────────────────────────────────────────────────┐
│                      NGINX (SSL/HTTPS)                       │
│  venera.domain.mn  │  luxor.domain.mn  │  khatan.domain.mn  │
└─────────────────────────────────────────────────────────────┘
         │                    │                    │
         ▼                    ▼                    ▼
┌─────────────┐      ┌─────────────┐      ┌─────────────┐
│   Venera    │      │   Luxor     │      │   Khatan    │
│   :8080     │      │   :8081     │      │   :8082     │
└─────────────┘      └─────────────┘      └─────────────┘
         │                    │                    │
         └────────────────────┼────────────────────┘
                              ▼
                    ┌─────────────────┐
                    │   PostgreSQL    │
                    │     :5432       │
                    └─────────────────┘
```

## 🔐 Аюулгүй Байдал

### 1. Environment Variables (.env файл)
```bash
# ХЭЗЭЭ Ч код дотор нууц үг бичих ХОРИОТОЙ!
cp .env.example .env
nano .env
```

### 2. Database Password
```bash
# Хүчтэй нууц үг хэрэглэх
DB_PASSWORD=M0ng0l!@#Str0ng2024

# PostgreSQL-д шинэ user үүсгэх
psql -U postgres
CREATE USER booking_app WITH PASSWORD 'SecurePassword123!';
GRANT ALL PRIVILEGES ON DATABASE hospital_db TO booking_app;
```

### 3. SSL Certificate (Let's Encrypt)
```bash
# Certbot суулгах
apt install certbot python3-certbot-nginx

# SSL авах
certbot --nginx -d venera.yourdomain.mn
certbot --nginx -d luxor.yourdomain.mn
certbot --nginx -d khatan.yourdomain.mn

# Auto-renew
certbot renew --dry-run
```

### 4. Firewall (UFW)
```bash
# Зөвхөн шаардлагатай портууд
ufw default deny incoming
ufw default allow outgoing
ufw allow ssh
ufw allow 80/tcp
ufw allow 443/tcp
ufw enable

# PostgreSQL-г зөвхөн Docker network-ээс
# 5432 портыг гаднаас хаах!
```

### 5. Rate Limiting (Login хамгаалалт)
```nginx
# nginx.conf дотор
limit_req_zone $binary_remote_addr zone=login:10m rate=5r/m;

server {
    location /login.php {
        limit_req zone=login burst=3 nodelay;
        proxy_pass http://localhost:8080;
    }
}
```

## 🚀 Deployment Алхам

### 1. Сервер бэлтгэх
```bash
# Ubuntu 22.04 LTS recommended
apt update && apt upgrade -y
apt install docker.io docker-compose nginx certbot -y
```

### 2. Код хуулах
```bash
cd /opt
git clone https://github.com/SukuNa111/venera-booking.git booking
cd booking
```

### 3. Environment тохируулах
```bash
cp .env.example .env
nano .env
# DB_PASSWORD, SMS_TOKEN зэргийг өөрчлөх
```

### 4. Docker эхлүүлэх
```bash
# Олон эмнэлэгт
cd deploy
docker-compose -f docker-compose.multi-clinic.yml up -d

# Нэг эмнэлэгт
docker-compose up -d
```

### 5. Database import
```bash
docker exec -i booking_postgres psql -U postgres hospital_db < db/postgresql_schema.sql
```

### 6. Nginx тохируулах
```bash
cp deploy/nginx-multi-clinic.conf /etc/nginx/sites-available/booking
ln -s /etc/nginx/sites-available/booking /etc/nginx/sites-enabled/
nginx -t && systemctl reload nginx
```

## 📊 Эмнэлэг Тус Бүрийн Тохиргоо

### clinics хүснэгт дотор
```sql
INSERT INTO clinics (code, name, address, phone) VALUES
('venera', 'Venera Dent', 'УБ, СБД, 1-р хороо', '77001234'),
('luxor', 'Golden Luxor', 'УБ, БЗД, 3-р хороо', '77005678'),
('khatan', 'Khatan Dental', 'УБ, ХУД, 5-р хороо', '77009012');
```

### Эмч бүртгэх
```sql
-- Эмч нь clinic_id-аар хязгаарлагдана
INSERT INTO doctors (name, phone, clinic_id) VALUES
('Д.Болд', '99001111', 'venera'),
('Б.Сарнай', '99002222', 'luxor');
```

## 🔄 Backup & Restore

### Автомат Backup (Cron)
```bash
# /etc/cron.d/booking-backup
0 2 * * * root docker exec booking_postgres pg_dump -U postgres hospital_db | gzip > /backup/hospital_$(date +\%Y\%m\%d).sql.gz
0 3 * * 0 root find /backup -name "*.sql.gz" -mtime +30 -delete
```

### Restore
```bash
gunzip -c /backup/hospital_20241205.sql.gz | docker exec -i booking_postgres psql -U postgres hospital_db
```

## 📱 SMS Token Тохиргоо

Эмнэлэг бүр өөрийн Skytel token-той:
```env
SMS_TOKEN_VENERA=venera_skytel_token_here
SMS_TOKEN_LUXOR=luxor_skytel_token_here
SMS_TOKEN_KHATAN=khatan_skytel_token_here
```

## ⚠️ Чухал Анхааруулга

1. **ХЭЗЭЭ Ч** config.php дотор нууц үг бичэхгүй
2. `.env` файлыг `.gitignore`-д нэмсэн байх
3. Production дээр `APP_DEBUG=false` байх
4. SSL/HTTPS заавал хэрэглэх
5. Database-г гаднаас хандах боломжгүй болгох
6. Тогтмол backup хийх
7. Log файлуудыг хянах

## 🔍 Мониторинг

```bash
# Container status
docker ps

# Logs харах
docker logs booking_venera -f

# Database connections
docker exec booking_postgres psql -U postgres -c "SELECT * FROM pg_stat_activity;"
```
